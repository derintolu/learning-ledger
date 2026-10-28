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
	 * Shared program and hash writes.
	 *
	 * @var Import_Writer
	 */
	private Import_Writer $writer;

	/**
	 * Constructor.
	 *
	 * @param LMS_Adapter|null        $adapter Adapter override; null looks the adapter up by id.
	 * @param Program_Repository|null $repo    Repository.
	 */
	public function __construct( ?LMS_Adapter $adapter = null, ?Program_Repository $repo = null ) {
		$this->adapter = $adapter;
		$this->repo    = $repo ?? new Program_Repository();
		$this->writer  = new Import_Writer( $this->repo );
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
			return Import_Report::failed( array( Import_Writer::error( 'adapter_unavailable', '', 'The LMS integration is not available.' ) ) );
		}

		$structure = Course_Structure_Parser::parse( $p->cmi5_xml() );
		$key       = Program_Module::sanitize_stable_key( (string) ( $course_json['key'] ?? Import_Writer::last_segment( $structure->course_id() ) ) );
		if ( '' === $key ) {
			return Import_Report::failed( array( Import_Writer::error( 'program_key', '', 'The course has no usable key.' ) ) );
		}

		$plan = self::plan( $structure );

		$program_id = $this->program( $key, $structure, $course_json, $plan['placement'] );
		if ( 0 === $program_id ) {
			return Import_Report::failed( array( Import_Writer::error( 'program_write', '', 'The program could not be saved.' ) ) );
		}
		$old_courses = $this->repo->get( $program_id )?->course_ids ?? array();

		$created = array();
		$updated = array();
		try {
			$hashes     = $this->writer->hashes( $program_id );
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
			error_log( 'Learning Ledger import failed: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operators need the cause.
			/**
			 * A package import was interrupted by an exception.
			 *
			 * @param \Throwable $e The cause.
			 */
			do_action( 'learning_ledger/import_error', $e ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			return Import_Report::failed( array( Import_Writer::error( 'import_failed', '', 'The import was interrupted (' . ( new \ReflectionClass( $e ) )->getShortName() . '). Run it again to resume.' ) ), $program_id, $created, $updated );
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
					'key'   => Program_Module::sanitize_stable_key( $child->key() ?? Import_Writer::last_segment( $child->id() ) ),
					'block' => $child,
					'aus'   => Import_Writer::flatten( $child ),
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
		$id = $this->writer->program( $key, Import_Writer::pick( $structure->title(), $key ) );
		if ( 0 === $id ) {
			return 0;
		}

		update_post_meta( $id, '_lledger_course_iri', $structure->course_id() );
		update_post_meta( $id, '_lledger_iri_base', self::iri_base( $structure ) );
		update_post_meta( $id, '_lledger_rules', sanitize_textarea_field( implode( "\n", array_map( 'strval', (array) ( $course_json['rules'] ?? array() ) ) ) ) );
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
		if ( isset( $course_json['license'] ) ) {
			update_post_meta( $id, '_lledger_licence', sanitize_text_field( (string) $course_json['license'] ) );
		} else {
			delete_post_meta( $id, '_lledger_licence' );
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
		$ext   = (array) ( $course_json['extensions'][ Package_Builder::EXTENSION ]['paths'][ $course['key'] ] ?? array() );
		$meta  = array();
		foreach ( array(
			'preview'    => '_lledger_preview',
			'finalFiles' => '_lledger_final_files',
			'landing'    => '_lledger_landing',
		) as $field => $meta_key ) {
			if ( isset( $ext[ $field ] ) ) {
				$meta[ $meta_key ] = Json::encode_exact( $ext[ $field ] );
			}
		}
		if ( isset( $path['placementHints'] ) ) {
			$meta['_lledger_placement_hints'] = sanitize_textarea_field( (string) $path['placementHints'] );
		}
		$title       = sanitize_text_field( Import_Writer::pick( $block->title(), $course['key'] ) );
		$tagline     = sanitize_text_field( (string) ( $path['tagline'] ?? '' ) );
		$description = Import_Writer::pick( Import_Writer::description_of( $block ), '' );
		$content     = Import_Writer::paragraph( $description );
		$spec        = new Course_Spec( $course['key'], $title, $content, 'publish', $meta );
		$hash        = hash( 'sha256', (string) wp_json_encode( array( $adapter->id(), $title, $tagline, $content, $meta ) ) );

		return $this->writer->write_item(
			$program_id,
			$hashes,
			'course:' . $course['key'],
			$hash,
			$created,
			$updated,
			static function () use ( $adapter, $spec, $tagline ): int {
				$id = $adapter->upsert_course( $spec );
				// The path tagline is the course excerpt, which Package_Builder exports.
				if ( $id > 0 && get_post( $id ) && get_post_field( 'post_excerpt', $id, 'raw' ) !== $tagline ) {
					wp_update_post(
						wp_slash(
							array(
								'ID'           => $id,
								'post_excerpt' => $tagline,
							)
						)
					);
				}
				return $id;
			}
		);
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
		$content = null === $lesson ? Import_Writer::paragraph( Import_Writer::pick( $au->description(), '' ) ) : Lesson_Blocks_Builder::build( $lesson );
		$content = wp_kses_post( $content );
		$title   = sanitize_text_field( Import_Writer::pick( $au->title(), $key ) );

		$meta = array();
		if ( null !== $lesson ) {
			if ( isset( $lesson['minutes'] ) ) {
				$meta['_lledger_minutes'] = (int) $lesson['minutes'];
			}
			if ( isset( $lesson['sources'] ) ) {
				$meta['_lledger_sources'] = Json::encode_exact( $lesson['sources'] );
			}
			if ( isset( $lesson['starterFiles'] ) ) {
				$meta['_lledger_starter_files'] = Json::encode_exact( self::file_rows( (array) $lesson['starterFiles'] ) );
			}
			$licence = $lesson['extensions'][ Lesson_Json_Builder::EXTENSION ]['license'] ?? null;
			if ( is_string( $licence ) && '' !== $licence ) {
				$meta['_lledger_licence'] = sanitize_text_field( $licence );
			}
		}

		$spec = new Lesson_Spec( $key, $title, $content, $order, 'publish', $meta );
		$hash = hash( 'sha256', (string) wp_json_encode( array( $adapter->id(), $course_id, $title, $content, $order, $meta ) ) );
		$this->writer->write_item( $program_id, $hashes, 'lesson:' . $key, $hash, $created, $updated, fn(): int => $adapter->upsert_lesson( $course_id, $spec ) );
	}

	/**
	 * The schema's path => content map as the {path, content} rows the lesson editor and builder use.
	 *
	 * @param array<string,string> $files Path => content.
	 *
	 * @return array<int,array{path:string,content:string}>
	 */
	private static function file_rows( array $files ): array {
		$rows = array();
		foreach ( $files as $path => $content ) {
			$rows[] = array(
				'path'    => (string) $path,
				'content' => (string) $content,
			);
		}
		return $rows;
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
			$this->writer->save_hashes( $program_id, $hashes );
		}
		return $drafted;
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
	 * Stable key of an AU.
	 *
	 * @param Au $au AU.
	 *
	 * @return string
	 */
	private static function key_of( Au $au ): string {
		return Program_Module::sanitize_stable_key( $au->key() ?? Import_Writer::last_segment( $au->id() ) );
	}
}
