<?php
/**
 * Imports any cmi5 package into Sensei for the player.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Import;

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Block;
use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Cmi5\Course_Structure_Parser;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\LMS_Adapter;
use LearningLedger\Connector\Packages\Import_Report;
use LearningLedger\Connector\Packages\Markdown_To_Blocks;
use LearningLedger\Connector\Packages\Package_Importer;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Db\Tables;
use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Player\Store\Au_Store;
use LearningLedger\Player\Store\Content_Host;
use LearningLedger\Util\Invalid_Input;

/**
 * One mapping for every package: top-level blocks become Sensei courses (nested
 * blocks flattened into them), AUs directly under the course go into one course
 * titled after it, and each AU becomes a lesson holding a cmi5-lesson block.
 * Courses and lessons are keyed by their cmi5 ids, so a re-import changes only
 * what changed. A package with a cxm/ folder goes through Package_Importer.
 */
final class Player_Importer {

	/**
	 * Lesson meta holding the AU id.
	 */
	public const AU_META = '_lledger_au_id';

	/**
	 * Program meta holding the content store package id.
	 */
	public const PACKAGE_META = '_lledger_cmi5_package';

	/**
	 * Program meta holding the SHA-256 of the stored ZIP.
	 */
	public const SHA_META = '_lledger_cmi5_sha256';

	/**
	 * Program meta holding the content hash of each written course and lesson.
	 */
	private const HASH_META = '_lledger_import_hash';

	/**
	 * Query parameters the LMS adds at launch, which an AU URL must not carry (cmi5 8.1).
	 */
	private const LAUNCH_PARAMS = array( 'endpoint', 'fetch', 'actor', 'registration', 'activityId' );

	/**
	 * Adapter to use instead of the loaded Sensei one (tests).
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
	 * @param LMS_Adapter|null        $adapter Adapter override; null uses the loaded Sensei adapter.
	 * @param Program_Repository|null $repo    Repository.
	 */
	public function __construct( ?LMS_Adapter $adapter = null, ?Program_Repository $repo = null ) {
		$this->adapter = $adapter;
		$this->repo    = $repo ?? new Program_Repository();
	}

	/**
	 * Imports a package ZIP or a standalone cmi5.xml.
	 *
	 * @param string $zip_or_xml_path ZIP (32- or 64-bit) or cmi5.xml on disk.
	 * @param string $source_url      URL the package came from, recorded on the store row.
	 *
	 * @return Import_Report
	 */
	public function import( string $zip_or_xml_path, string $source_url = '' ): Import_Report {
		$limits = new Limits();
		try {
			$is_zip  = self::is_zip( $zip_or_xml_path );
			$package = $is_zip ? Package::from_zip( $zip_or_xml_path, $limits ) : Package::from_xml( self::read_xml( $zip_or_xml_path ) );
			if ( self::has_cxm( $package ) ) {
				return $this->import_cxm( $package );
			}
			$structure = Course_Structure_Parser::parse( $package->cmi5_xml() );
			$paths     = $is_zip ? array_flip( Au_Store::paths( $package->entries() ) ) : null;
		} catch ( Invalid_Input $e ) {
			return Import_Report::failed( array( self::from_exception( $e ) ) );
		}

		$errors = array_merge( self::duplicate_errors( $structure ), self::url_errors( $structure, $paths ) );
		if ( $errors ) {
			return Import_Report::failed( $errors );
		}

		$adapter = $this->adapter ?? Integration_Loader::adapter( 'sensei' );
		if ( null === $adapter ) {
			return Import_Report::failed( array( self::error( 'adapter_unavailable', '', 'The Sensei integration is not available.' ) ) );
		}

		$program_id = $this->program( $structure );
		if ( 0 === $program_id ) {
			return Import_Report::failed( array( self::error( 'program_write', '', 'The program could not be saved.' ) ) );
		}

		$created = array();
		$updated = array();
		try {
			$package_id = self::has_relative( $structure ) ? $this->store( $program_id, $zip_or_xml_path, $source_url ) : 0;
			$structure  = self::resolve( $structure, $package_id );

			$hashes     = $this->hashes( $program_id );
			$course_ids = array();
			$path_keys  = array();
			foreach ( self::plan( $structure ) as $course ) {
				$course_id               = $this->write_course( $adapter, $program_id, $hashes, $course, $created, $updated );
				$course_ids[]            = $course_id;
				$path_keys[ $course_id ] = $course['key'];
				foreach ( $course['aus'] as $i => $au ) {
					$this->write_lesson( $adapter, $program_id, $hashes, $course_id, $i + 1, $au, $created, $updated );
				}
			}

			update_post_meta( $program_id, '_lledger_courses', $course_ids );
			update_post_meta( $program_id, '_lledger_path_keys', $path_keys );
			$this->repo->set_structure( $program_id, $structure );
		} catch ( Invalid_Input $e ) {
			return Import_Report::failed( array( self::from_exception( $e ) ), $program_id, $created, $updated );
		} catch ( \Throwable $e ) {
			error_log( 'Learning Ledger cmi5 import failed: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operators need the cause.
			return Import_Report::failed( array( self::error( 'import_failed', '', 'The import was interrupted (' . ( new \ReflectionClass( $e ) )->getShortName() . '). Run it again to resume.' ) ), $program_id, $created, $updated );
		}

		return new Import_Report( $program_id, $created, $updated );
	}

	/**
	 * Imports a course-xapi-mcp package with Package_Importer and marks its program as played.
	 *
	 * @param Package $package Package.
	 *
	 * @return Import_Report
	 */
	private function import_cxm( Package $package ): Import_Report {
		$report = ( new Package_Importer( $this->adapter, $this->repo ) )->import( $package, 'sensei' );
		if ( $report->program_id > 0 ) {
			update_post_meta( $report->program_id, '_lledger_player', 1 );
		}
		return $report;
	}

	/**
	 * Whether the file starts like a ZIP archive.
	 *
	 * @param string $path File.
	 *
	 * @return boolean
	 * @throws Invalid_Input Code not_found when the file cannot be read.
	 */
	private static function is_zip( string $path ): bool {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new Invalid_Input( 'The package file cannot be read.', 'not_found' );
		}
		$head = (string) file_get_contents( $path, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return "PK\x03\x04" === $head || "PK\x05\x06" === $head;
	}

	/**
	 * Contents of a standalone cmi5.xml.
	 *
	 * @param string $path File.
	 *
	 * @return string
	 * @throws Invalid_Input Code too_large over Package::MAX_XML_BYTES.
	 */
	private static function read_xml( string $path ): string {
		if ( (int) filesize( $path ) > Package::MAX_XML_BYTES ) {
			throw new Invalid_Input( 'The cmi5.xml file is too large.', 'too_large' );
		}
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
	}

	/**
	 * Whether the package has a cxm/ folder.
	 *
	 * @param Package $package Package.
	 *
	 * @return boolean
	 */
	private static function has_cxm( Package $package ): bool {
		foreach ( $package->entries() as $entry ) {
			if ( str_starts_with( $entry, 'cxm/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Errors for ids used more than once among the course, its blocks and AUs (cmi5 13.1.4).
	 *
	 * @param Course_Structure $structure Structure.
	 *
	 * @return array<array{code:string,path:string,message:string}>
	 */
	private static function duplicate_errors( Course_Structure $structure ): array {
		$seen   = array( $structure->course_id() => true );
		$errors = array();
		foreach ( self::walk( $structure->children() ) as $item ) {
			$id = $item->id();
			if ( isset( $seen[ $id ] ) ) {
				$errors[] = self::error( 'duplicate_id', '/' . ( $item instanceof Au ? 'au' : 'block' ), 'The id "' . $id . '" is used more than once.' );
			}
			$seen[ $id ] = true;
		}
		return $errors;
	}

	/**
	 * Every block and AU, in document order, including the duplicates the structure's index hides.
	 *
	 * @param array<Au|Block> $children Children.
	 *
	 * @return array<Au|Block>
	 */
	private static function walk( array $children ): array {
		$out = array();
		foreach ( $children as $child ) {
			$out[] = $child;
			if ( $child instanceof Block ) {
				$out = array_merge( $out, self::walk( $child->children() ) );
			}
		}
		return $out;
	}

	/**
	 * Errors for AU URLs that are not http(s), escape the package, are missing from it or carry launch parameters.
	 *
	 * @param Course_Structure       $structure Structure.
	 * @param array<string,int>|null $paths     Normalised ZIP paths as keys, or null for a standalone XML.
	 *
	 * @return array<array{code:string,path:string,message:string}>
	 */
	private static function url_errors( Course_Structure $structure, ?array $paths ): array {
		$errors = array();
		foreach ( self::walk( $structure->children() ) as $au ) {
			if ( ! $au instanceof Au ) {
				continue;
			}
			$url   = $au->url();
			$where = '/au/' . $au->id() . '/url';
			if ( '' === $url || 1 === preg_match( '/[\s\x00-\x1f\x7f\\\\]/', $url ) ) {
				$errors[] = self::error( 'au_url', $where, 'The AU URL is not a valid URL.' );
				continue;
			}
			if ( self::is_absolute( $url ) ) {
				$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
				if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
					$errors[] = self::error( 'au_url', $where, 'An absolute AU URL must be an http or https URL.' );
					continue;
				}
			} elseif ( null === $paths ) {
				$errors[] = self::error( 'au_url_relative', $where, 'A standalone cmi5.xml needs absolute AU URLs.' );
				continue;
			} else {
				$path = Au_Store::normalise( rawurldecode( self::split( $url )['path'] ) );
				if ( null === $path ) {
					$errors[] = self::error( 'au_url', $where, 'The AU URL points outside the package.' );
					continue;
				}
				if ( ! isset( $paths[ $path ] ) ) {
					$errors[] = self::error( 'au_url_missing', $where, 'The AU URL names a file that is not in the package.' );
					continue;
				}
			}
			foreach ( explode( '&', self::split( $url )['query'] ) as $pair ) {
				$name = rawurldecode( explode( '=', $pair, 2 )[0] );
				if ( in_array( $name, self::LAUNCH_PARAMS, true ) ) {
					$errors[] = self::error( 'au_url_query', $where, 'The AU URL must not carry the launch parameter "' . $name . '".' );
					break;
				}
			}
		}
		return $errors;
	}

	/**
	 * Whether a URL has a scheme.
	 *
	 * @param string $url URL.
	 *
	 * @return boolean
	 */
	private static function is_absolute( string $url ): bool {
		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9+.-]*:/', $url );
	}

	/**
	 * Path, query and fragment of a relative URL.
	 *
	 * @param string $url Relative URL.
	 *
	 * @return array{path:string,query:string,fragment:string}
	 */
	private static function split( string $url ): array {
		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash + 1 );
			$url      = substr( $url, 0, $hash );
		}
		$query = '';
		$mark  = strpos( $url, '?' );
		if ( false !== $mark ) {
			$query = substr( $url, $mark + 1 );
			$url   = substr( $url, 0, $mark );
		}
		return array(
			'path'     => $url,
			'query'    => $query,
			'fragment' => $fragment,
		);
	}

	/**
	 * Whether any AU URL is relative.
	 *
	 * @param Course_Structure $structure Structure.
	 *
	 * @return boolean
	 */
	private static function has_relative( Course_Structure $structure ): bool {
		foreach ( $structure->aus() as $au ) {
			if ( ! self::is_absolute( $au->url() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Creates or finds the program, keyed by the course id, and marks it as a player program.
	 *
	 * @param Course_Structure $structure Structure.
	 *
	 * @return integer Post id, 0 on failure.
	 */
	private function program( Course_Structure $structure ): int {
		$key   = self::key_for( $structure->course_id() );
		$title = sanitize_text_field( self::pick( $structure->title(), $structure->course_id() ) );
		$id    = $this->repo->find_by_key( $key );
		if ( null === $id ) {
			$new = wp_insert_post(
				wp_slash(
					array(
						'post_type'   => Program_Repository::POST_TYPE,
						'post_status' => 'publish',
						'post_title'  => $title,
					)
				),
				true
			);
			if ( is_wp_error( $new ) || 0 === (int) $new ) {
				return 0;
			}
			$id = (int) $new;
			update_post_meta( $id, '_lledger_stable_key', $key );
		} elseif ( get_post_field( 'post_title', $id, 'raw' ) !== $title ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'         => $id,
						'post_title' => $title,
					)
				)
			);
		}
		update_post_meta( $id, '_lledger_player', 1 );
		update_post_meta( $id, '_lledger_custody', 'lms-launched' );
		update_post_meta( $id, '_lledger_course_iri', $structure->course_id() );
		update_post_meta( $id, '_lledger_iri_base', self::iri_base( $structure ) );
		return $id;
	}

	/**
	 * Prefix that the program's AU activity ids share: the one AU's id plus a slash, or the
	 * longest common prefix of the AU ids cut after its last slash.
	 *
	 * @param Course_Structure $structure Structure.
	 *
	 * @return string
	 */
	private static function iri_base( Course_Structure $structure ): string {
		$ids = array_map( static fn( Au $au ): string => $au->id(), $structure->aus() );
		if ( 1 === count( $ids ) ) {
			return $ids[0] . '/';
		}
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
	 * Puts the ZIP in the content store, reusing the stored copy when the ZIP is unchanged.
	 *
	 * @param integer $program_id Program id.
	 * @param string  $zip_path   ZIP on disk.
	 * @param string  $source_url Package URL, if any.
	 *
	 * @return integer Package id.
	 * @throws Invalid_Input From Au_Store::begin.
	 */
	private function store( int $program_id, string $zip_path, string $source_url ): int {
		global $wpdb;
		$sha = (string) hash_file( 'sha256', $zip_path );
		$old = (int) get_post_meta( $program_id, self::PACKAGE_META, true );
		if ( $old > 0 && (string) get_post_meta( $program_id, self::SHA_META, true ) === $sha
			&& in_array( Au_Store::status( $old ), array( Au_Store::STATUS_PENDING, Au_Store::STATUS_READY ), true ) ) {
			return $old;
		}

		$id  = Au_Store::begin( $zip_path, $program_id );
		$url = esc_url_raw( $source_url, array( 'http', 'https' ) );
		if ( '' !== $url ) {
			// The row's source_url held the local ZIP copy; extraction finds it through store_dir.
			$wpdb->update( Tables::name( 'cmi5_packages' ), array( 'source_url' => $url ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		update_post_meta( $program_id, self::PACKAGE_META, $id );
		update_post_meta( $program_id, self::SHA_META, $sha );
		if ( $old > 0 ) {
			Au_Store::delete( $old );
		}
		return $id;
	}

	/**
	 * The structure with each relative AU URL resolved under the package's content base.
	 *
	 * @param Course_Structure $structure  Structure.
	 * @param integer          $package_id Package id, 0 when nothing is stored.
	 *
	 * @return Course_Structure
	 */
	private static function resolve( Course_Structure $structure, int $package_id ): Course_Structure {
		if ( $package_id <= 0 ) {
			return $structure;
		}
		$data             = $structure->to_array();
		$data['children'] = self::resolve_children( $data['children'], Content_Host::base_url( $package_id ) );
		return Course_Structure::from_array( $data );
	}

	/**
	 * Resolves AU URLs in a child list's array form.
	 *
	 * @param array  $children Children.
	 * @param string $base     Content base URL with a trailing slash.
	 *
	 * @return array
	 */
	private static function resolve_children( array $children, string $base ): array {
		foreach ( $children as $i => $child ) {
			if ( 'block' === $child['type'] ) {
				$children[ $i ]['children'] = self::resolve_children( $child['children'], $base );
			} elseif ( ! self::is_absolute( $child['url'] ) ) {
				$parts                 = self::split( $child['url'] );
				$children[ $i ]['url'] = $base . Au_Store::normalise( $parts['path'] )
					. ( '' === $parts['query'] ? '' : '?' . $parts['query'] )
					. ( '' === $parts['fragment'] ? '' : '#' . $parts['fragment'] );
			}
		}
		return $children;
	}

	/**
	 * Courses in document order: one per top-level block, plus one for the AUs directly under the course.
	 *
	 * @param Course_Structure $structure Structure.
	 *
	 * @return array<array{key:string,title:array,description:array,aus:Au[]}>
	 */
	private static function plan( Course_Structure $structure ): array {
		$courses = array();
		$root    = null;
		foreach ( $structure->children() as $child ) {
			if ( $child instanceof Block ) {
				$data      = $child->to_array();
				$courses[] = array(
					'key'         => self::key_for( $child->id() ),
					'title'       => $child->title(),
					'description' => (array) ( $data['description'] ?? array() ),
					'aus'         => self::flatten( $child ),
				);
				continue;
			}
			if ( null === $root ) {
				$data      = $structure->to_array();
				$root      = count( $courses );
				$courses[] = array(
					'key'         => self::key_for( $structure->course_id() ),
					'title'       => $structure->title(),
					'description' => (array) ( $data['description'] ?? array() ),
					'aus'         => array(),
				);
			}
			$courses[ $root ]['aus'][] = $child;
		}
		return $courses;
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
			$aus = array_merge( $aus, $child instanceof Block ? self::flatten( $child ) : array( $child ) );
		}
		return $aus;
	}

	/**
	 * Creates or updates one course unless its content is unchanged.
	 *
	 * @param LMS_Adapter $adapter    Adapter.
	 * @param integer     $program_id Program id.
	 * @param array       $hashes     Import hashes, updated in place.
	 * @param array       $course     Planned course.
	 * @param string[]    $created    Created refs, appended to.
	 * @param string[]    $updated    Updated refs, appended to.
	 *
	 * @return integer Course id.
	 */
	private function write_course( LMS_Adapter $adapter, int $program_id, array &$hashes, array $course, array &$created, array &$updated ): int {
		$title       = sanitize_text_field( self::pick( $course['title'], $course['key'] ) );
		$description = sanitize_textarea_field( self::pick( $course['description'], '' ) );
		$content     = '' === trim( $description ) ? '' : Markdown_To_Blocks::block( 'core/paragraph', array(), '<p>' . esc_html( $description ) . '</p>' );
		$spec        = new Course_Spec( $course['key'], $title, $content, 'publish', array() );
		$hash        = hash( 'sha256', (string) wp_json_encode( array( $adapter->id(), $title, $content ) ) );
		return $this->write_item( $program_id, $hashes, 'course:' . $course['key'], $hash, $created, $updated, fn(): int => $adapter->upsert_course( $spec ) );
	}

	/**
	 * Creates or updates one lesson unless its content is unchanged.
	 *
	 * @param LMS_Adapter $adapter    Adapter.
	 * @param integer     $program_id Program id.
	 * @param array       $hashes     Import hashes, updated in place.
	 * @param integer     $course_id  Course id.
	 * @param integer     $order      Position, from 1.
	 * @param Au          $au         AU.
	 * @param string[]    $created    Created refs, appended to.
	 * @param string[]    $updated    Updated refs, appended to.
	 *
	 * @return void
	 */
	private function write_lesson( LMS_Adapter $adapter, int $program_id, array &$hashes, int $course_id, int $order, Au $au, array &$created, array &$updated ): void {
		$key     = self::key_for( $au->id() );
		$title   = sanitize_text_field( self::pick( $au->title(), $au->id() ) );
		$content = get_comment_delimited_block_content( 'learning-ledger/cmi5-lesson', array( 'auId' => $au->id() ), '' );
		$meta    = array( self::AU_META => $au->id() );
		$spec    = new Lesson_Spec( $key, $title, $content, $order, 'publish', $meta );
		$hash    = hash( 'sha256', (string) wp_json_encode( array( $adapter->id(), $course_id, $title, $content, $order, $meta ) ) );
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
	 * @throws \RuntimeException When the adapter writes nothing.
	 */
	private function write_item( int $program_id, array &$hashes, string $ref, string $hash, array &$created, array &$updated, callable $write ): int {
		$known = $hashes[ $ref ] ?? null;
		if ( is_array( $known ) && ( $known['h'] ?? '' ) === $hash && (int) ( $known['id'] ?? 0 ) > 0 && get_post( (int) $known['id'] ) ) {
			return (int) $known['id'];
		}
		$id = (int) $write();
		if ( $id <= 0 ) {
			throw new \RuntimeException( 'The LMS did not save ' . $ref ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- logged, never echoed.
		}
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
	 * Stable key for a cmi5 id: its last path segment (at most 40 characters) and 12 hex
	 * characters of its SHA-256, so keys are readable, unique and the same on every import.
	 *
	 * @param string $id Course, block or AU id.
	 *
	 * @return string
	 */
	private static function key_for( string $id ): string {
		$path = (string) wp_parse_url( $id, PHP_URL_PATH );
		$slug = trim( substr( Program_Module::sanitize_stable_key( basename( rtrim( $path, '/' ) ) ), 0, 40 ), '-' );
		return ( '' === $slug ? 'cmi5' : $slug ) . '-' . substr( hash( 'sha256', $id ), 0, 12 );
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
	 * A validator-shaped error from an exception.
	 *
	 * @param Invalid_Input $e Exception.
	 *
	 * @return array{code:string,path:string,message:string}
	 */
	private static function from_exception( Invalid_Input $e ): array {
		return self::error( $e->error_code(), $e->path(), $e->getMessage() );
	}

	/**
	 * A validator-shaped error.
	 *
	 * @param string $code    Code.
	 * @param string $path    Path.
	 * @param string $message Message.
	 *
	 * @return array{code:string,path:string,message:string}
	 */
	private static function error( string $code, string $path, string $message ): array {
		return array(
			'code'    => $code,
			'path'    => $path,
			'message' => $message,
		);
	}
}
