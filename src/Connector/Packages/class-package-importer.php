<?php
/**
 * Imports a course package into the LMS.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Block;
use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Cmi5\Course_Structure_Parser;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\LMS_Adapter;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Package\Package;
use LearningLedger\Package\Package_Validator;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;

/**
 * Creates or updates a program, its LMS courses and lessons by stable key. Safe to run again:
 * unchanged items are skipped, items missing from the package are drafted, learner progress is not touched.
 */
final class Package_Importer {

	private const HASH_META = '_lledger_import_hash';

	/**
	 * Adapter to use instead of the loaded one (tests).
	 *
	 * @var LMS_Adapter|null
	 */
	private ?LMS_Adapter $adapter;

	/**
	 * Programs.
	 *
	 * @var Program_Repository
	 */
	private Program_Repository $repo;

	/**
	 * Constructor.
	 *
	 * @param LMS_Adapter|null        $adapter Adapter override; null looks the adapter up by id.
	 * @param Program_Repository|null $repo    Repository.
	 */
	public function __construct( ?LMS_Adapter $adapter = null, ?Program_Repository $repo = null ) {
		$this->adapter = $adapter;
		$this->repo    = $repo ?? new Program_Repository();
	}

	/**
	 * Imports a package.
	 *
	 * @param Package $p          Package.
	 * @param string  $adapter_id LMS integration id.
	 *
	 * @return Import_Report
	 */
	public function import( Package $p, string $adapter_id = 'sensei' ): Import_Report {
		try {
			$course_json = $p->is_cxm() ? ( $p->course_json() ?? array() ) : array();
		} catch ( Invalid_Input $e ) {
			$course_json = array();
		}
		$flavour    = 'public' === ( $course_json['flavour'] ?? '' ) ? 'public' : 'full';
		$validation = Package_Validator::validate( $p, $flavour );
		if ( ! $validation->ok() ) {
			return Import_Report::failed( $validation->errors() );
		}

		$adapter = $this->adapter ?? Integration_Loader::adapter( $adapter_id );
		if ( null === $adapter ) {
			return Import_Report::failed( array( self::error( 'adapter_unavailable', 'The LMS integration is not available.' ) ) );
		}

		$structure = Course_Structure_Parser::parse( $p->cmi5_xml() );
		$key       = Program_Module::sanitize_stable_key( (string) ( $course_json['key'] ?? self::last_segment( $structure->course_id() ) ) );
		if ( '' === $key ) {
			return Import_Report::failed( array( self::error( 'program_key', 'The course has no usable key.' ) ) );
		}

		$plan = self::plan( $structure );

		$program_id = $this->program( $key, $structure, $course_json, $plan['placement'] );
		if ( 0 === $program_id ) {
			return Import_Report::failed( array( self::error( 'program_write', 'The program could not be saved.' ) ) );
		}
		$old_courses = $this->repo->get( $program_id )?->course_ids ?? array();

		$created = array();
		$updated = array();
		try {
			$hashes     = $this->hashes( $program_id );
			$course_ids = array();
			$path_keys  = array();
			$keep       = array();
			foreach ( $plan['courses'] as $course ) {
				$course_id               = $this->write_course( $adapter, $program_id, $hashes, $course, $course_json, $created, $updated );
				$course_ids[]            = $course_id;
				$path_keys[ $course_id ] = $course['key'];
				$keep[ $course_id ]      = array();
				foreach ( $course['aus'] as $i => $au ) {
					$lesson_key = self::key_of( $au );
					$this->write_lesson( $adapter, $program_id, $hashes, $course_id, $lesson_key, $i + 1, $au, $p, $course_json, $created, $updated );
					$keep[ $course_id ][] = $lesson_key;
				}
			}
			$drafted = $this->draft_missing( $adapter, $program_id, $hashes, array_values( array_unique( array_merge( $old_courses, $course_ids ) ) ), $keep );

			update_post_meta( $program_id, '_lledger_courses', $course_ids );
			update_post_meta( $program_id, '_lledger_path_keys', $path_keys );
			$this->repo->set_structure( $program_id, $structure );
		} catch ( \Throwable $e ) {
			return Import_Report::failed( array( self::error( 'import_failed', 'The import was interrupted. Run it again to resume.' ) ), $program_id, $created, $updated );
		}

		return new Import_Report( $program_id, $created, $updated, $drafted );
	}

	/**
	 * Top-level paths and the placement AU.
	 *
	 * @param Course_Structure $structure Structure.
	 *
	 * @return array{courses:array<array{key:string,block:Block,aus:Au[]}>,placement:?string}
	 */
	private static function plan( Course_Structure $structure ): array {
		$courses   = array();
		$placement = null;
		foreach ( $structure->children() as $child ) {
			if ( $child instanceof Block ) {
				$courses[] = array(
					'key'   => Program_Module::sanitize_stable_key( $child->key() ?? self::last_segment( $child->id() ) ),
					'block' => $child,
					'aus'   => self::flatten( $child ),
				);
			} elseif ( $child instanceof Au && 'placement' === $child->role() ) {
				$placement = self::key_of( $child );
			}
		}
		return array(
			'courses'   => $courses,
			'placement' => $placement,
		);
	}

	/**
	 * Every AU under a block, nested blocks flattened, in document order.
	 *
	 * @param Block $block Block.
	 *
	 * @return Au[]
	 */
	private static function flatten( Block $block ): array {
		$aus = array();
		foreach ( $block->children() as $child ) {
			if ( $child instanceof Block ) {
				$aus = array_merge( $aus, self::flatten( $child ) );
			} elseif ( $child instanceof Au ) {
				$aus[] = $child;
			}
		}
		return $aus;
	}

	/**
	 * Creates or updates the program post and its package-derived meta.
	 *
	 * @param string           $key         Stable key.
	 * @param Course_Structure $structure   Structure.
	 * @param array            $course_json Course JSON.
	 * @param string|null      $placement   Placement AU key.
	 *
	 * @return integer Post id, 0 on failure.
	 */
	private function program( string $key, Course_Structure $structure, array $course_json, ?string $placement ): int {
		$title = self::pick( $structure->title(), $key );
		$id    = $this->repo->find_by_key( $key );
		if ( null === $id ) {
			$new = wp_insert_post(
				array(
					'post_type'   => Program_Repository::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $title,
				),
				true
			);
			if ( is_wp_error( $new ) || 0 === (int) $new ) {
				return 0;
			}
			$id = (int) $new;
			update_post_meta( $id, '_lledger_stable_key', $key );
		} elseif ( get_the_title( $id ) !== $title ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $title,
				)
			);
		}

		update_post_meta( $id, '_lledger_course_iri', $structure->course_id() );
		update_post_meta( $id, '_lledger_iri_base', self::iri_base( $structure ) );
		update_post_meta( $id, '_lledger_rules', implode( "\n", array_map( 'strval', (array) ( $course_json['rules'] ?? array() ) ) ) );
		update_post_meta(
			$id,
			'_lledger_placement',
			wp_slash(
				(string) wp_json_encode(
					array(
						'au_key' => $placement,
						'guide'  => (object) ( $course_json['placement'] ?? array() ),
					)
				)
			)
		);
		update_post_meta( $id, '_lledger_badges', wp_slash( (string) wp_json_encode( $course_json['badges'] ?? array() ) ) );
		update_post_meta( $id, '_lledger_badge_issuer', wp_slash( (string) wp_json_encode( (object) ( $course_json['badgeIssuer'] ?? array() ) ) ) );
		if ( in_array( $course_json['custody'] ?? null, Program_Module::CUSTODY, true ) ) {
			update_post_meta( $id, '_lledger_custody', $course_json['custody'] );
		}
		return $id;
	}

	/**
	 * Longest common prefix of the AU ids, cut after its last slash.
	 *
	 * @param Course_Structure $structure Structure.
	 *
	 * @return string
	 */
	private static function iri_base( Course_Structure $structure ): string {
		$ids = array_map( static fn( Au $au ): string => $au->id(), $structure->aus() );
		if ( ! $ids ) {
			return trailingslashit( $structure->course_id() );
		}
		$prefix = $ids[0];
		foreach ( $ids as $id ) {
			$len = min( strlen( $prefix ), strlen( $id ) );
			$i   = 0;
			while ( $i < $len && $prefix[ $i ] === $id[ $i ] ) {
				++$i;
			}
			$prefix = substr( $prefix, 0, $i );
		}
		$slash = strrpos( $prefix, '/' );
		return false === $slash ? trailingslashit( $structure->course_id() ) : substr( $prefix, 0, $slash + 1 );
	}

	/**
	 * Creates or updates one course unless its content is unchanged.
	 *
	 * @param LMS_Adapter $adapter     Adapter.
	 * @param integer     $program_id  Program id.
	 * @param array       $hashes      Import hashes, updated in place.
	 * @param array       $course      Planned course.
	 * @param array       $course_json Course JSON.
	 * @param string[]    $created     Created refs, appended to.
	 * @param string[]    $updated     Updated refs, appended to.
	 *
	 * @return integer LMS course id.
	 */
	private function write_course( LMS_Adapter $adapter, int $program_id, array &$hashes, array $course, array $course_json, array &$created, array &$updated ): int {
		$block = $course['block'];
		$path  = (array) ( $course_json['paths'][ $course['key'] ] ?? array() );
		$meta  = array();
		foreach ( array(
			'preview'    => '_lledger_preview',
			'finalFiles' => '_lledger_final_files',
			'landing'    => '_lledger_landing',
		) as $field => $meta_key ) {
			if ( isset( $path[ $field ] ) ) {
				$meta[ $meta_key ] = Json::encode_exact( $path[ $field ] );
			}
		}
		$title       = self::pick( $block->title(), $course['key'] );
		$description = self::pick( self::description_of( $block ), '' );
		$content     = self::paragraph( $description );
		$spec        = new Course_Spec( $course['key'], $title, $content, 'publish', $meta );
		$hash        = hash( 'sha256', (string) wp_json_encode( array( $adapter->id(), $title, $content, $meta ) ) );

		return $this->write_item( $program_id, $hashes, 'course:' . $course['key'], $hash, $created, $updated, fn(): int => $adapter->upsert_course( $spec ) );
	}

	/**
	 * Creates or updates one lesson unless its content is unchanged.
	 *
	 * @param LMS_Adapter $adapter     Adapter.
	 * @param integer     $program_id  Program id.
	 * @param array       $hashes      Import hashes, updated in place.
	 * @param integer     $course_id   LMS course id.
	 * @param string      $key         Lesson stable key.
	 * @param integer     $order       Position, from 1.
	 * @param Au          $au          AU.
	 * @param Package     $p           Package.
	 * @param array       $course_json Course JSON.
	 * @param string[]    $created     Created refs, appended to.
	 * @param string[]    $updated     Updated refs, appended to.
	 *
	 * @return void
	 */
	private function write_lesson( LMS_Adapter $adapter, int $program_id, array &$hashes, int $course_id, string $key, int $order, Au $au, Package $p, array $course_json, array &$created, array &$updated ): void {
		$lesson  = $this->lesson_json( $p, $au );
		$content = null === $lesson ? self::paragraph( self::pick( $au->description(), '' ) ) : Lesson_Blocks_Builder::build( $lesson );
		$content = wp_kses_post( $content );
		$title   = sanitize_text_field( self::pick( $au->title(), $key ) );

		$meta = array();
		if ( null !== $lesson ) {
			if ( isset( $lesson['minutes'] ) ) {
				$meta['_lledger_minutes'] = (int) $lesson['minutes'];
			}
			if ( isset( $lesson['sources'] ) ) {
				$meta['_lledger_sources'] = Json::encode_exact( $lesson['sources'] );
			}
			if ( isset( $lesson['starterFiles'] ) ) {
				$meta['_lledger_starter_files'] = Json::encode_exact( $lesson['starterFiles'] );
			}
		}
		if ( isset( $course_json['license'] ) ) {
			$meta['_lledger_licence'] = sanitize_text_field( (string) $course_json['license'] );
		}

		$spec = new Lesson_Spec( $key, $title, $content, $order, 'publish', $meta );
		$hash = hash( 'sha256', (string) wp_json_encode( array( $adapter->id(), $course_id, $title, $content, $order, $meta ) ) );
		$this->write_item( $program_id, $hashes, 'lesson:' . $key, $hash, $created, $updated, fn(): int => $adapter->upsert_lesson( $course_id, $spec ) );
	}

	/**
	 * Runs the write for an item unless the stored hash matches, then records the new hash.
	 *
	 * @param integer  $program_id Program id.
	 * @param array    $hashes     Hashes, updated in place.
	 * @param string   $ref        Item ref.
	 * @param string   $hash       Content hash.
	 * @param string[] $created    Created refs, appended to.
	 * @param string[] $updated    Updated refs, appended to.
	 * @param callable $write      Performs the upsert and returns the LMS id.
	 *
	 * @return integer LMS id.
	 */
	private function write_item( int $program_id, array &$hashes, string $ref, string $hash, array &$created, array &$updated, callable $write ): int {
		$known = $hashes[ $ref ] ?? null;
		if ( is_array( $known ) && ( $known['h'] ?? '' ) === $hash && (int) ( $known['id'] ?? 0 ) > 0 ) {
			return (int) $known['id'];
		}
		$id = (int) $write();
		if ( null === $known ) {
			$created[] = $ref;
		} else {
			$updated[] = $ref;
		}
		$hashes[ $ref ] = array(
			'h'  => $hash,
			'id' => $id,
		);
		update_post_meta( $program_id, self::HASH_META, wp_slash( (string) wp_json_encode( $hashes ) ) );
		return $id;
	}

	/**
	 * Sets lessons that are no longer in the package to draft.
	 *
	 * @param LMS_Adapter         $adapter    Adapter.
	 * @param integer             $program_id Program id.
	 * @param array               $hashes     Hashes, updated in place.
	 * @param int[]               $courses    Old and new LMS course ids.
	 * @param array<int,string[]> $keep       Lesson keys in the package per new course id.
	 *
	 * @return string[] Refs of the lessons drafted.
	 */
	private function draft_missing( LMS_Adapter $adapter, int $program_id, array &$hashes, array $courses, array $keep ): array {
		$drafted = array();
		foreach ( $courses as $course_id ) {
			foreach ( $adapter->course_tree( $course_id )->lessons as $node ) {
				if ( null === $node->stable_key || in_array( $node->stable_key, $keep[ $course_id ] ?? array(), true ) ) {
					continue;
				}
				if ( 'draft' === get_post_status( $node->id ) ) {
					continue;
				}
				if ( get_post( $node->id ) ) {
					wp_update_post(
						array(
							'ID'          => $node->id,
							'post_status' => 'draft',
						)
					);
				}
				unset( $hashes[ 'lesson:' . $node->stable_key ] );
				$drafted[] = 'lesson:' . $node->stable_key;
			}
		}
		if ( $drafted ) {
			update_post_meta( $program_id, self::HASH_META, wp_slash( (string) wp_json_encode( $hashes ) ) );
		}
		return $drafted;
	}

	/**
	 * Stored hashes.
	 *
	 * @param integer $program_id Program id.
	 *
	 * @return array<string,array{h:string,id:int}>
	 */
	private function hashes( int $program_id ): array {
		$decoded = json_decode( (string) get_post_meta( $program_id, self::HASH_META, true ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Decoded lesson document of an AU, null when it has none.
	 *
	 * @param Package $p  Package.
	 * @param Au      $au AU.
	 *
	 * @return array|null
	 */
	private function lesson_json( Package $p, Au $au ): ?array {
		$src = $au->lesson_src();
		if ( null === $src || 1 !== preg_match( '#^cxm/lessons/([a-z0-9-]{1,64})\.json$#', $src, $m ) ) {
			return null;
		}
		return $p->lesson_json( $m[1] );
	}

	/**
	 * Description map of a block.
	 *
	 * @param Block $block Block.
	 *
	 * @return array<string,string>
	 */
	private static function description_of( Block $block ): array {
		$data = $block->to_array();
		return (array) ( $data['description'] ?? array() );
	}

	/**
	 * Stable key of an AU.
	 *
	 * @param Au $au AU.
	 *
	 * @return string
	 */
	private static function key_of( Au $au ): string {
		return Program_Module::sanitize_stable_key( $au->key() ?? self::last_segment( $au->id() ) );
	}

	/**
	 * A paragraph block, or nothing for empty text.
	 *
	 * @param string $text Plain text.
	 *
	 * @return string
	 */
	private static function paragraph( string $text ): string {
		return '' === trim( $text ) ? '' : Markdown_To_Blocks::block( 'core/paragraph', array(), '<p>' . esc_html( $text ) . '</p>' );
	}

	/**
	 * English text, else the first language, else the fallback.
	 *
	 * @param array<string,string> $langstrings Language to text.
	 * @param string               $fallback    Fallback.
	 *
	 * @return string
	 */
	private static function pick( array $langstrings, string $fallback ): string {
		$text = $langstrings['en'] ?? ( $langstrings ? reset( $langstrings ) : '' );
		return '' !== trim( (string) $text ) ? (string) $text : $fallback;
	}

	/**
	 * Last path segment of an IRI.
	 *
	 * @param string $iri IRI.
	 *
	 * @return string
	 */
	private static function last_segment( string $iri ): string {
		$path = (string) wp_parse_url( $iri, PHP_URL_PATH );
		return basename( rtrim( $path, '/' ) );
	}

	/**
	 * A validator-shaped error.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 *
	 * @return array{code:string,path:string,message:string}
	 */
	private static function error( string $code, string $message ): array {
		return array(
			'code'    => $code,
			'path'    => '',
			'message' => $message,
		);
	}
}
