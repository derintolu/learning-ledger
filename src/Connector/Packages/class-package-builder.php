<?php
/**
 * Exports a program as the standard's course package.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never echoed; HTTP layers JSON-encode them.

use LearningLedger\Cmi5\Course_Structure_Parser;
use LearningLedger\Connector\Lms\Course_Tree;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Programs\Program;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Package\Package_Validator;
use LearningLedger\Package\Package_Writer;
use LearningLedger\Package\Secrets;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Temp_File;

/**
 * Program, path courses and lessons in; a validated authoring or distribution ZIP out.
 */
final class Package_Builder {

	/**
	 * Key under course.json `extensions` for the path data the schema has no field for.
	 */
	public const EXTENSION = 'https://github.com/derintolu/learning-ledger/extensions/course';

	/**
	 * The course-xapi-mcp version the packages follow (`specVersion`).
	 */
	public const SPEC_VERSION = '0.2';

	/**
	 * Path course meta: the block description, when the package's differs from the tagline.
	 */
	public const BLOCK_DESCRIPTION = '_lledger_block_description';

	/**
	 * Program meta: the imported package's `<cxm:course mcp>`, exported when no course server URL is set.
	 */
	public const PACKAGE_MCP = '_lledger_package_mcp';

	private const MAX_META_BYTES = 1048576;

	/**
	 * Program storage.
	 *
	 * @var Program_Repository
	 */
	private Program_Repository $programs;

	/**
	 * Constructor.
	 *
	 * @param Program_Repository|null $programs Program storage.
	 */
	public function __construct( ?Program_Repository $programs = null ) {
		$this->programs = $programs ?? new Program_Repository();
	}

	/**
	 * Builds the package.
	 *
	 * The program version is bumped (and the parsed structure stored) when the full flavour's ETag changes.
	 *
	 * @param integer $program_id Program post id.
	 * @param string  $flavour    Either full or public.
	 *
	 * @return Built_Package
	 * @throws Invalid_Input Codes bad_flavour, not_found, no_adapter, missing_stable_key (path is the post id), validation.
	 */
	public function build( int $program_id, string $flavour ): Built_Package {
		if ( ! in_array( $flavour, array( 'full', 'public' ), true ) ) {
			throw new Invalid_Input( 'Flavour must be full or public.', 'bad_flavour', $flavour );
		}
		$program = $this->programs->get( $program_id );
		if ( null === $program ) {
			throw new Invalid_Input( 'Program not found.', 'not_found', (string) $program_id );
		}
		$parts = $this->gather( $program );

		$version = max( 1, $program->version );
		$full    = $this->zip( $program, $parts, 'full', $version );
		$etag    = Package_Writer::etag( $full );
		$changed = $program->version < 1 || $etag !== $program->etag;
		if ( $changed ) {
			$version = $program->version + 1;
			$full    = $this->zip( $program, $parts, 'full', $version );
			$etag    = Package_Writer::etag( $full );
		}
		self::validate( $full, 'full' );
		$public = null;
		if ( 'public' === $flavour ) {
			$public = $this->zip( $program, $parts, 'public', $version );
			self::validate( $public, 'public' );
		}
		if ( $changed ) {
			$this->programs->bump_version( $program_id, $etag );
			$this->programs->set_structure( $program_id, Course_Structure_Parser::parse( $parts['xml'] ) );
		}
		if ( null === $public ) {
			return new Built_Package( $full, $etag, $version, 'full', $parts['warnings'] );
		}
		return new Built_Package( $public, Package_Writer::etag( $public ), $version, 'public', $parts['warnings'] );
	}

	/**
	 * Reads the program's courses and lessons.
	 *
	 * @param Program $p Program.
	 *
	 * @return array{xml:string,lessons:array<string,array>,paths:array,tagline:array,warnings:string[]}
	 * @throws Invalid_Input Codes no_adapter, not_found, missing_stable_key.
	 */
	private function gather( Program $p ): array {
		$paths       = array();
		$lessons     = array();
		$lesson_meta = array();
		$warnings    = array();
		$extensions  = array();
		$path_json   = array();

		foreach ( $p->course_ids as $course_id ) {
			$adapter = Integration_Loader::for_post( $course_id );
			if ( null === $adapter ) {
				throw new Invalid_Input( 'No active LMS integration handles this course.', 'no_adapter', (string) $course_id );
			}
			$tree = $adapter->course_tree( $course_id );
			$key  = $p->path_keys[ $course_id ] ?? (string) $tree->stable_key;
			if ( '' === $key ) {
				throw new Invalid_Input( 'The path course has no stable key.', 'missing_stable_key', (string) $course_id );
			}
			$paths[] = array( $key, $tree );

			foreach ( $tree->lessons as $node ) {
				$lesson_key = (string) $node->stable_key;
				if ( '' === $lesson_key ) {
					throw new Invalid_Input( 'The lesson has no stable key.', 'missing_stable_key', (string) $node->id );
				}
				$post = get_post( $node->id );
				if ( ! $post ) {
					throw new Invalid_Input( 'The lesson post does not exist.', 'not_found', (string) $node->id );
				}
				$built                      = Lesson_Json_Builder::build( $post );
				$lessons[ $lesson_key ]     = $built['json'];
				$warnings                   = array_merge( $warnings, $built['warnings'] );
				$lesson_meta[ $lesson_key ] = array(
					'checkpoint'  => isset( $built['json']['checkpoint'] ),
					'description' => (string) $post->post_excerpt,
				);
			}

			$path_json[ $key ] = $this->path_entry( $course_id, $tree );
			$ext               = array();
			foreach ( array(
				'preview'    => '_lledger_preview',
				'finalFiles' => '_lledger_final_files',
				'landing'    => '_lledger_landing',
			) as $field => $meta_key ) {
				$value = $this->meta_json( $course_id, $meta_key );
				if ( ! empty( $value ) ) {
					$ext[ $field ] = $value;
				}
			}
			if ( $ext ) {
				$extensions[ $key ] = $ext;
			}
		}

		return array(
			'xml'        => Cmi5_Xml_Builder::build( $p, $paths, $lesson_meta ),
			'lessons'    => $lessons,
			'paths'      => $path_json,
			'extensions' => $extensions,
			'warnings'   => $warnings,
		);
	}

	/**
	 * A course.json path entry.
	 *
	 * @param integer     $course_id Path course id.
	 * @param Course_Tree $tree      Course tree.
	 *
	 * @return array
	 */
	private function path_entry( int $course_id, Course_Tree $tree ): array {
		$tagline = (string) get_post_field( 'post_excerpt', $course_id );
		$entry   = array( 'tagline' => '' !== $tagline ? $tagline : $tree->title );
		$hints   = (string) get_post_meta( $course_id, '_lledger_placement_hints', true );
		if ( '' !== $hints ) {
			$entry['placementHints'] = $hints;
		}
		return $entry;
	}

	/**
	 * Assembles one flavour's ZIP.
	 *
	 * @param Program $p       Program.
	 * @param array   $parts   Result of gather().
	 * @param string  $flavour Either full or public.
	 * @param integer $version Version number.
	 *
	 * @return string
	 */
	private function zip( Program $p, array $parts, string $flavour, int $version ): string {
		$lessons = $parts['lessons'];
		if ( 'public' === $flavour ) {
			$lessons = array_map( array( self::class, 'public_lesson' ), $lessons );
		}
		return Package_Writer::write( $parts['xml'], $this->course_json( $p, $parts, $flavour, $version ), $lessons, array() );
	}

	/**
	 * A lesson as the public flavour carries it: secrets and fallback options removed.
	 *
	 * @param array $lesson Lesson document.
	 *
	 * @return array
	 */
	private static function public_lesson( array $lesson ): array {
		$lesson = Secrets::strip( $lesson );
		unset( $lesson['extensions'][ Lesson_Json_Builder::EXTENSION ]['fallbackOptions'] );
		if ( isset( $lesson['extensions'] ) ) {
			if ( array() === ( $lesson['extensions'][ Lesson_Json_Builder::EXTENSION ] ?? array() ) ) {
				unset( $lesson['extensions'][ Lesson_Json_Builder::EXTENSION ] );
			}
			if ( array() === $lesson['extensions'] ) {
				unset( $lesson['extensions'] );
			}
		}
		return $lesson;
	}

	/**
	 * The course.json document.
	 *
	 * @param Program $p       Program.
	 * @param array   $parts   Result of gather().
	 * @param string  $flavour Either full or public.
	 * @param integer $version Version number.
	 *
	 * @return array
	 */
	private function course_json( Program $p, array $parts, string $flavour, int $version ): array {
		// An imported package's own version and language are kept; otherwise they are derived.
		$language = (string) get_post_meta( $p->id, '_lledger_language', true );
		$language = '' !== $language ? $language : strtolower( (string) get_bloginfo( 'language' ) );
		$semver   = (string) get_post_meta( $p->id, '_lledger_package_version', true );
		$course   = array(
			'specVersion' => self::SPEC_VERSION,
			'flavour'     => 'full' === $flavour ? 'authoring' : 'distribution',
			'version'     => 1 === preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $semver ) ? $semver : '1.0.' . $version,
			'language'    => '' !== $language ? $language : 'en',
		);
		$licence  = (string) get_post_meta( $p->id, '_lledger_licence', true );
		if ( '' !== $licence ) {
			$course['license'] = $licence;
		}
		$rules = array_values( array_filter( array_map( 'trim', (array) preg_split( '/\R/', $p->rules ) ), 'strlen' ) );
		if ( $rules ) {
			$course['rules'] = $rules;
		}
		$course['placement'] = $this->placement( $p );
		$course['custody']   = $p->custody;
		$practice            = (string) get_post_meta( $p->id, '_lledger_allow_practice', true );
		if ( '' !== $practice ) {
			$course['allowPractice'] = '1' === $practice;
		}
		$course['paths'] = $parts['paths'];
		if ( $p->badges ) {
			$course['badges'] = $p->badges;
		}
		if ( $p->badge_issuer ) {
			$course['badgeIssuer'] = $p->badge_issuer;
		}
		$extensions = $parts['extensions'];
		if ( 'public' === $flavour ) {
			foreach ( $extensions as $key => $ext ) {
				unset( $ext['finalFiles'] );
				if ( $ext ) {
					$extensions[ $key ] = $ext;
				} else {
					unset( $extensions[ $key ] );
				}
			}
		}
		// Other namespaces' extensions from an imported package, kept verbatim.
		$all = Lesson_Json_Builder::foreign_extensions( $p->id, self::EXTENSION );
		if ( $extensions ) {
			$all[ self::EXTENSION ] = array( 'paths' => $extensions );
		}
		if ( $all ) {
			$course['extensions'] = $all;
		}
		return $course;
	}

	/**
	 * The placement guide: the program's guide object, or its text as the intro.
	 *
	 * @param Program $p Program.
	 *
	 * @return array{intro:string,maxQuestions:int}
	 */
	private function placement( Program $p ): array {
		$guide = $p->placement['guide'] ?? array();
		$intro = is_string( $guide ) ? $guide : (string) ( $guide['intro'] ?? '' );
		$max   = is_array( $guide ) ? (int) ( $guide['maxQuestions'] ?? 0 ) : 0;
		return array(
			'intro'        => '' !== $intro ? $intro : 'Ask a few short questions to find the right starting point.',
			'maxQuestions' => $max > 0 ? min( 10, $max ) : 2,
		);
	}

	/**
	 * A JSON value stored in post meta.
	 *
	 * @param integer $post_id Post id.
	 * @param string  $key     Meta key.
	 *
	 * @return mixed Null when absent or malformed.
	 */
	private function meta_json( int $post_id, string $key ): mixed {
		$raw = (string) get_post_meta( $post_id, $key, true );
		if ( '' === $raw ) {
			return null;
		}
		try {
			return Json::decode_strict( $raw, self::MAX_META_BYTES );
		} catch ( Invalid_Input $e ) {
			return null;
		}
	}

	/**
	 * Validates ZIP bytes in a flavour.
	 *
	 * @param string $zip     ZIP bytes.
	 * @param string $flavour Either full or public.
	 *
	 * @return void
	 * @throws Invalid_Input Code validation, carrying the validator's errors in the message.
	 */
	private static function validate( string $zip, string $flavour ): void {
		$file = Temp_File::create( 'lledger-package' );
		try {
			file_put_contents( $file, $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file.
			$result = Package_Validator::validate( Package::from_zip( $file, new Limits() ), $flavour );
		} finally {
			wp_delete_file( $file );
		}
		if ( $result->ok() ) {
			return;
		}
		$errors = $result->errors();
		$lines  = array_map(
			static fn( array $e ): string => $e['code'] . ' ' . $e['path'] . ': ' . $e['message'],
			$errors
		);
		throw new Invalid_Input( 'The package is not valid. ' . implode( ' | ', $lines ), 'validation', $errors[0]['path'] );
	}
}
