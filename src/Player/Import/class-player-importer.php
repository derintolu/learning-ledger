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
use LearningLedger\Connector\Packages\Import_Writer;
use LearningLedger\Connector\Packages\Package_Importer;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Db\Tables;
use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Player\Store\Au_Store;
use LearningLedger\Player\Store\Content_Host;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Invalid_Input;

/**
 * One mapping for every package: top-level blocks become Sensei courses (nested
 * blocks flattened into them), AUs directly under the course go into one course
 * titled after it, and each AU becomes a lesson holding a cmi5-lesson block.
 * Courses and lessons are keyed by their cmi5 ids, so a re-import changes only
 * what changed. A package with a cxm/ folder is checked the same way, then goes
 * through Package_Importer.
 */
final class Player_Importer {

	/**
	 * Lesson meta holding the AU id.
	 */
	public const AU_META = '_lledger_au_id';

	/**
	 * Course meta holding the id of the top-level block the course was made from.
	 */
	public const BLOCK_META = '_lledger_block_id';

	/**
	 * Program meta: the content store package the stored structure's AU URLs point at.
	 */
	public const PACKAGE_META = '_lledger_cmi5_package';

	/**
	 * Program meta: SHA-256 of that package's ZIP.
	 */
	public const SHA_META = '_lledger_cmi5_sha256';

	/**
	 * Program meta: earlier packages kept until the live one is ready.
	 */
	public const SUPERSEDED_META = '_lledger_cmi5_superseded';

	/**
	 * Job type that deletes superseded packages once the live one is ready.
	 */
	public const SWEEP_JOB = 'cmi5_supersede';

	/**
	 * Seconds between sweep attempts while the live package is still extracting.
	 */
	private const SWEEP_DELAY = 60;

	/**
	 * Sweep attempts before giving up on a package that never leaves pending.
	 */
	private const SWEEP_ATTEMPTS = 1440;

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
	 * Shared program and hash writes.
	 *
	 * @var Import_Writer
	 */
	private Import_Writer $writer;

	/**
	 * Constructor.
	 *
	 * @param LMS_Adapter|null        $adapter Adapter override; null uses the loaded Sensei adapter.
	 * @param Program_Repository|null $repo    Repository.
	 */
	public function __construct( ?LMS_Adapter $adapter = null, ?Program_Repository $repo = null ) {
		$this->adapter = $adapter;
		$this->repo    = $repo ?? new Program_Repository();
		$this->writer  = new Import_Writer( $this->repo );
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
		try {
			$is_zip    = self::is_zip( $zip_or_xml_path );
			$package   = $is_zip ? Package::from_zip( $zip_or_xml_path, new Limits() ) : Package::from_xml( self::read_xml( $zip_or_xml_path ) );
			$is_cxm    = self::has_cxm( $package );
			$structure = Course_Structure_Parser::parse( $package->cmi5_xml() );
			$paths     = $is_zip ? array_flip( Au_Store::paths( $package->entries() ) ) : null;
		} catch ( Invalid_Input $e ) {
			return Import_Report::failed( array( self::from_exception( $e ) ) );
		}

		$errors = array_merge( self::duplicate_errors( $structure ), self::url_errors( $structure, $paths, ! $is_cxm ) );
		if ( $errors ) {
			return Import_Report::failed( $errors );
		}
		if ( $is_cxm ) {
			return $this->import_cxm( $package );
		}

		$adapter = $this->adapter ?? Integration_Loader::adapter( 'sensei' );
		if ( null === $adapter ) {
			return Import_Report::failed( array( Import_Writer::error( 'adapter_unavailable', '', 'The Sensei integration is not available.' ) ) );
		}

		$new_program = false;
		$program_id  = $this->program( $structure, $new_program );
		if ( 0 === $program_id ) {
			return Import_Report::failed( array( Import_Writer::error( 'program_write', '', 'The program could not be saved.' ) ) );
		}

		$live       = (int) get_post_meta( $program_id, self::PACKAGE_META, true );
		$package_id = 0;
		$created    = array();
		$updated    = array();
		try {
			$package_id = self::has_relative( $structure ) ? $this->store( $program_id, $zip_or_xml_path, $source_url ) : 0;
			$structure  = self::resolve( $structure, $package_id );

			$hashes     = $this->writer->hashes( $program_id );
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
		} catch ( \Throwable $e ) {
			$dropped = $new_program && array() === $created;
			$this->abandon( $program_id, $dropped, $package_id, $live );
			$kept = $dropped ? 0 : $program_id;
			if ( $e instanceof Invalid_Input ) {
				return Import_Report::failed( array( self::from_exception( $e ) ), $kept, $created, $updated );
			}
			error_log( 'Learning Ledger cmi5 import failed: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operators need the cause.
			return Import_Report::failed( array( Import_Writer::error( 'import_failed', '', 'The import was interrupted (' . ( new \ReflectionClass( $e ) )->getShortName() . '). Run it again to resume.' ) ), $kept, $created, $updated );
		}

		$this->go_live( $program_id, $live, $package_id, $package_id > 0 ? (string) hash_file( 'sha256', $zip_or_xml_path ) : '' );
		return new Import_Report( $program_id, $created, $updated );
	}

	/**
	 * Deletes superseded packages once the live one is ready (or there is none);
	 * otherwise queues another attempt. Handler of the `cmi5_supersede` job.
	 *
	 * @param array $payload Holds `program_id` and `attempt`.
	 *
	 * @return void
	 */
	public static function sweep( array $payload ): void {
		$program_id = (int) ( $payload['program_id'] ?? 0 );
		$attempt    = (int) ( $payload['attempt'] ?? 0 );
		$old        = get_post_meta( $program_id, self::SUPERSEDED_META, true );
		$old        = is_array( $old ) ? array_map( 'intval', $old ) : array();
		if ( $program_id <= 0 || array() === $old ) {
			return;
		}
		$live   = (int) get_post_meta( $program_id, self::PACKAGE_META, true );
		$status = $live > 0 ? Au_Store::status( $live ) : Au_Store::STATUS_READY;
		if ( Au_Store::STATUS_PENDING === $status ) {
			if ( $attempt < self::SWEEP_ATTEMPTS ) {
				Job_Queue::push(
					self::SWEEP_JOB,
					array(
						'program_id' => $program_id,
						'attempt'    => $attempt + 1,
					),
					self::SWEEP_JOB . ':' . $program_id . ':' . ( $attempt + 1 ),
					self::SWEEP_DELAY
				);
			}
			return;
		}
		if ( Au_Store::STATUS_READY !== $status ) {
			return; // The live package failed; keep the older ones until a later import succeeds.
		}
		foreach ( $old as $id ) {
			if ( $id !== $live ) {
				Au_Store::delete( $id );
			}
		}
		delete_post_meta( $program_id, self::SUPERSEDED_META );
	}

	/**
	 * Checks a course-xapi-mcp package like any other, imports it with Package_Importer and marks its program as played.
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
	 * Undoes what a failed import left that nothing points at: the new package, and the
	 * program when this import created it and wrote nothing else.
	 *
	 * @param integer $program_id   Program id.
	 * @param boolean $drop_program Delete the program post.
	 * @param integer $package_id   Package begun by this import, or the reused live one.
	 * @param integer $live         Live package before this import.
	 *
	 * @return void
	 */
	private function abandon( int $program_id, bool $drop_program, int $package_id, int $live ): void {
		if ( $package_id > 0 && $package_id !== $live ) {
			Au_Store::delete( $package_id );
		}
		if ( $drop_program ) {
			wp_delete_post( $program_id, true );
		}
	}

	/**
	 * After the structure is stored: records the live package, marks the previous one
	 * superseded and sweeps (now, or once the new package is ready).
	 *
	 * @param integer $program_id Program id.
	 * @param integer $previous   Live package before this import, 0 for none.
	 * @param integer $live       Package the stored structure now points at, 0 for none.
	 * @param string  $sha        SHA-256 of its ZIP.
	 *
	 * @return void
	 */
	private function go_live( int $program_id, int $previous, int $live, string $sha ): void {
		if ( $live > 0 ) {
			update_post_meta( $program_id, self::PACKAGE_META, $live );
			update_post_meta( $program_id, self::SHA_META, $sha );
		} else {
			delete_post_meta( $program_id, self::PACKAGE_META );
			delete_post_meta( $program_id, self::SHA_META );
		}
		if ( $previous > 0 && $previous !== $live ) {
			$old   = get_post_meta( $program_id, self::SUPERSEDED_META, true );
			$old   = is_array( $old ) ? array_map( 'intval', $old ) : array();
			$old[] = $previous;
			update_post_meta( $program_id, self::SUPERSEDED_META, array_values( array_unique( $old ) ) );
		}
		self::sweep( array( 'program_id' => $program_id ) );
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
				$errors[] = Import_Writer::error( 'duplicate_id', '/' . ( $item instanceof Au ? 'au' : 'block' ), 'The id "' . $id . '" is used more than once.' );
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
	 * @param Course_Structure       $structure      Structure.
	 * @param array<string,int>|null $paths          Normalised ZIP paths as keys, or null for a standalone XML.
	 * @param boolean                $allow_relative Whether relative URLs may be hosted (false for cxm packages).
	 *
	 * @return array<array{code:string,path:string,message:string}>
	 */
	private static function url_errors( Course_Structure $structure, ?array $paths, bool $allow_relative ): array {
		$errors = array();
		foreach ( self::walk( $structure->children() ) as $au ) {
			if ( ! $au instanceof Au ) {
				continue;
			}
			$url   = $au->url();
			$where = '/au/' . $au->id() . '/url';
			if ( '' === $url || 1 === preg_match( '/[\s\x00-\x1f\x7f\\\\]/', $url ) ) {
				$errors[] = Import_Writer::error( 'au_url', $where, 'The AU URL is not a valid URL.' );
				continue;
			}
			if ( self::is_absolute( $url ) ) {
				$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
				if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
					$errors[] = Import_Writer::error( 'au_url', $where, 'An absolute AU URL must be an http or https URL.' );
					continue;
				}
			} elseif ( null === $paths || ! $allow_relative ) {
				$errors[] = Import_Writer::error( 'au_url_relative', $where, null === $paths ? 'A standalone cmi5.xml needs absolute AU URLs.' : 'A course-xapi-mcp package needs absolute AU URLs.' );
				continue;
			} else {
				$path = Au_Store::normalise( rawurldecode( self::split( $url )['path'] ) );
				if ( null === $path ) {
					$errors[] = Import_Writer::error( 'au_url', $where, 'The AU URL points outside the package.' );
					continue;
				}
				if ( ! isset( $paths[ $path ] ) ) {
					$errors[] = Import_Writer::error( 'au_url_missing', $where, 'The AU URL names a file that is not in the package.' );
					continue;
				}
			}
			foreach ( explode( '&', self::split( $url )['query'] ) as $pair ) {
				$name = rawurldecode( explode( '=', $pair, 2 )[0] );
				if ( in_array( $name, self::LAUNCH_PARAMS, true ) ) {
					$errors[] = Import_Writer::error( 'au_url_query', $where, 'The AU URL must not carry the launch parameter "' . $name . '".' );
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
	 * @param boolean          $created   Set to whether the program was created.
	 *
	 * @return integer Post id, 0 on failure.
	 */
	private function program( Course_Structure $structure, bool &$created ): int {
		$id = $this->writer->program( self::key_for( $structure->course_id() ), Import_Writer::pick( $structure->title(), $structure->course_id() ), $created );
		if ( 0 === $id ) {
			return 0;
		}
		update_post_meta( $id, '_lledger_player', 1 );
		update_post_meta( $id, '_lledger_custody', 'lms-launched' );
		update_post_meta( $id, '_lledger_course_iri', $structure->course_id() );
		return $id;
	}

	/**
	 * The package in the content store: the live one when the ZIP is unchanged, else a new one.
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
		$live = (int) get_post_meta( $program_id, self::PACKAGE_META, true );
		if ( $live > 0 && (string) get_post_meta( $program_id, self::SHA_META, true ) === (string) hash_file( 'sha256', $zip_path )
			&& in_array( Au_Store::status( $live ), array( Au_Store::STATUS_PENDING, Au_Store::STATUS_READY ), true ) ) {
			return $live;
		}

		$id  = Au_Store::begin( $zip_path, $program_id );
		$url = esc_url_raw( $source_url, array( 'http', 'https' ) );
		if ( '' !== $url ) {
			// The row's source_url held the local ZIP copy; extraction finds it through store_dir.
			$wpdb->update( Tables::name( 'cmi5_packages' ), array( 'source_url' => $url ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * @return array<array{key:string,block:string,title:array,description:array,aus:Au[]}>
	 */
	private static function plan( Course_Structure $structure ): array {
		$courses = array();
		$root    = null;
		foreach ( $structure->children() as $child ) {
			if ( $child instanceof Block ) {
				$courses[] = array(
					'key'         => self::key_for( $child->id() ),
					'block'       => $child->id(),
					'title'       => $child->title(),
					'description' => Import_Writer::description_of( $child ),
					'aus'         => Import_Writer::flatten( $child ),
				);
				continue;
			}
			if ( null === $root ) {
				$data      = $structure->to_array();
				$root      = count( $courses );
				$courses[] = array(
					'key'         => self::key_for( $structure->course_id() ),
					'block'       => '',
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
		$title   = sanitize_text_field( Import_Writer::pick( $course['title'], $course['key'] ) );
		$content = Import_Writer::paragraph( sanitize_textarea_field( Import_Writer::pick( $course['description'], '' ) ) );
		$meta    = '' === $course['block'] ? array() : array( self::BLOCK_META => $course['block'] );
		$spec    = new Course_Spec( $course['key'], $title, $content, 'publish', $meta );
		$hash    = hash( 'sha256', (string) wp_json_encode( array( $adapter->id(), $title, $content, $meta ) ) );
		return self::saved( $this->writer->write_item( $program_id, $hashes, 'course:' . $course['key'], $hash, $created, $updated, fn(): int => $adapter->upsert_course( $spec ) ), 'course:' . $course['key'] );
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
		$title   = sanitize_text_field( Import_Writer::pick( $au->title(), $au->id() ) );
		$content = get_comment_delimited_block_content( 'learning-ledger/cmi5-lesson', array( 'auId' => $au->id() ), '' );
		$meta    = array( self::AU_META => $au->id() );
		$spec    = new Lesson_Spec( $key, $title, $content, $order, 'publish', $meta );
		$hash    = hash( 'sha256', (string) wp_json_encode( array( $adapter->id(), $course_id, $title, $content, $order, $meta ) ) );
		self::saved( $this->writer->write_item( $program_id, $hashes, 'lesson:' . $key, $hash, $created, $updated, fn(): int => $adapter->upsert_lesson( $course_id, $spec ) ), 'lesson:' . $key );
	}

	/**
	 * An LMS id, or an exception when the adapter saved nothing.
	 *
	 * @param integer $id  LMS id.
	 * @param string  $ref Item ref.
	 *
	 * @return integer
	 * @throws \RuntimeException When the id is not positive.
	 */
	private static function saved( int $id, string $ref ): int {
		if ( $id <= 0 ) {
			throw new \RuntimeException( 'The LMS did not save ' . $ref ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- logged, never echoed.
		}
		return $id;
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
		$slug = trim( substr( Program_Module::sanitize_stable_key( Import_Writer::last_segment( $id ) ), 0, 40 ), '-' );
		return ( '' === $slug ? 'cmi5' : $slug ) . '-' . substr( hash( 'sha256', $id ), 0, 12 );
	}

	/**
	 * A validator-shaped error from an exception.
	 *
	 * @param Invalid_Input $e Exception.
	 *
	 * @return array{code:string,path:string,message:string}
	 */
	private static function from_exception( Invalid_Input $e ): array {
		return Import_Writer::error( $e->error_code(), $e->path(), $e->getMessage() );
	}
}
