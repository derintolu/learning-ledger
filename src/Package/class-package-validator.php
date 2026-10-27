<?php
/**
 * Validates a course package.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Package;

use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Cmi5\Course_Structure_Parser;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Vendor\Opis\JsonSchema\Errors\ValidationError;
use LearningLedger\Vendor\Opis\JsonSchema\Validator;

/**
 * XSD, JSON Schema, reference and secret checks.
 */
final class Package_Validator {

	const SCHEMA_DIR = __DIR__ . '/../../resources/cxm/schema/1.0/';

	const KEY_PATTERN = '/^[a-z0-9-]{1,64}$/';

	/**
	 * Most schema errors reported per document.
	 */
	private const MAX_SCHEMA_ERRORS = 20;

	/**
	 * Validates a package.
	 *
	 * @param Package $p       Package.
	 * @param string  $flavour Either full (authoring) or public (distribution: secrets absent).
	 *
	 * @return Validation_Result
	 */
	public static function validate( Package $p, string $flavour ): Validation_Result {
		$is_public = 'public' === $flavour;
		$errors    = array();

		$structure = null;
		try {
			$structure = Course_Structure_Parser::parse( $p->cmi5_xml() );
		} catch ( Invalid_Input $e ) {
			$errors[] = self::error( 'xsd', '/cmi5.xml', $e->getMessage() );
		}

		if ( $p->is_cxm() ) {
			self::schema_errors( $errors, self::load_json( $p, Package::COURSE_JSON ), 'course', '/course', $is_public );
		}
		$lessons = array();
		foreach ( $p->lesson_keys() as $key ) {
			$path   = 'cxm/lessons/' . $key . '.json';
			$loaded = self::load_json( $p, $path );
			self::schema_errors( $errors, $loaded, 'lesson', '/lessons/' . $key, $is_public );
			if ( $is_public && is_array( $loaded['array'] ?? null ) ) {
				foreach ( Secrets::present( $loaded['array'] ) as $pointer ) {
					$errors[] = self::error( 'secret_in_public', '/lessons/' . $key . $pointer, 'A public package must not carry secret field ' . $pointer . '.' );
				}
			}
			if ( is_array( $loaded['array'] ?? null ) ) {
				$lessons[ $path ] = $loaded['array'];
			}
		}

		if ( $is_public ) {
			self::stray_secret_errors( $errors, $p );
		}

		if ( null !== $structure ) {
			self::structure_errors( $errors, $p, $structure, $lessons );
		}

		return new Validation_Result( $errors );
	}

	/**
	 * Secret fields in JSON entries that are neither course.json, a lesson file nor under cxm/files/.
	 *
	 * @param array   $errors Errors, appended to.
	 * @param Package $p      Package.
	 *
	 * @return void
	 */
	private static function stray_secret_errors( array &$errors, Package $p ): void {
		$known = array_map(
			static fn( string $k ): string => 'cxm/lessons/' . $k . '.json',
			$p->lesson_keys()
		);
		foreach ( $p->entries() as $entry ) {
			if ( '.json' !== substr( $entry, -5 ) || Package::COURSE_JSON === $entry || 0 === strpos( $entry, 'cxm/files/' ) || in_array( $entry, $known, true ) ) {
				continue;
			}
			$loaded = self::load_json( $p, $entry );
			if ( is_array( $loaded['array'] ?? null ) ) {
				foreach ( Secrets::present( $loaded['array'] ) as $pointer ) {
					$errors[] = self::error( 'secret_in_public', '/' . $entry . $pointer, 'A public package must not carry secret field ' . $pointer . '.' );
				}
			}
		}
	}

	/**
	 * Keys, lesson references and branch targets.
	 *
	 * @param array               $errors    Errors, appended to.
	 * @param Package             $p         Package.
	 * @param Course_Structure    $structure Parsed structure.
	 * @param array<string,array> $lessons   Decoded lesson JSON by entry path.
	 *
	 * @return void
	 */
	private static function structure_errors( array &$errors, Package $p, Course_Structure $structure, array $lessons ): void {
		$seen = array();
		foreach ( array(
			'block' => $structure->blocks(),
			'au'    => $structure->aus(),
		) as $kind => $items ) {
			foreach ( $items as $item ) {
				$key = $item->key();
				if ( null === $key ) {
					continue;
				}
				$where = '/' . $kind . '/' . $key;
				if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
					$errors[] = self::error( 'bad_key', $where, 'cxm:key must match [a-z0-9-]{1,64}.' );
				}
				if ( isset( $seen[ $key ] ) ) {
					$errors[] = self::error( 'duplicate_key', $where, 'cxm:key "' . $key . '" is used more than once.' );
				}
				$seen[ $key ] = true;
			}
		}

		$entries = $p->entries();
		foreach ( $structure->aus() as $au ) {
			$src = $au->lesson_src();
			if ( null === $src ) {
				continue;
			}
			$name = $au->key() ?? $au->id();
			if ( ! in_array( $src, $entries, true ) ) {
				$errors[] = self::error( 'missing_lesson_json', '/au/' . $name . '/lesson', 'cxm:lesson src "' . $src . '" is not in the package.' );
				continue;
			}
			$lesson = $lessons[ $src ] ?? null;
			if ( null === $lesson ) {
				continue;
			}
			$file     = basename( $src, '.json' );
			$branches = is_array( $lesson['checkpoint'] ?? null ) && is_array( $lesson['checkpoint']['branches'] ?? null ) ? $lesson['checkpoint']['branches'] : array();
			foreach ( $branches as $i => $branch ) {
				$to = is_array( $branch ) ? ( $branch['to'] ?? null ) : null;
				if ( ! is_string( $to ) ) {
					continue;
				}
				$where  = '/lessons/' . $file . '/checkpoint/branches/' . $i . '/to';
				$target = $structure->au_by_key( $to );
				if ( null === $target ) {
					$errors[] = self::error( 'branch_target_unknown', $where, 'Branch target "' . $to . '" is not a lesson in this course.' );
				} elseif ( $target->parent_block_id() !== $au->parent_block_id() ) {
					$errors[] = self::error( 'branch_cross_path', $where, 'Branch target "' . $to . '" is in a different path.' );
				}
			}
		}
	}

	/**
	 * Reads a JSON entry as an array (for structure checks) and as objects (for Opis).
	 *
	 * @param Package $p    Package.
	 * @param string  $path Entry path.
	 *
	 * @return array{array?:array,object?:mixed,error?:string}
	 */
	private static function load_json( Package $p, string $path ): array {
		try {
			$raw   = $p->file( $path, Package::MAX_JSON_BYTES );
			$array = Json::decode_strict( $raw, Package::MAX_JSON_BYTES );
			if ( ! is_array( $array ) ) {
				return array( 'error' => 'The JSON document must be an object.' );
			}
			return array(
				'array'  => $array,
				'object' => json_decode( $raw, false, 64 ),
			);
		} catch ( Invalid_Input $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}

	/**
	 * Validates one document against a bundled schema.
	 *
	 * @param array   $errors    Errors, appended to.
	 * @param array   $loaded    Result of load_json().
	 * @param string  $schema    Either course or lesson.
	 * @param string  $prefix    Path prefix for reported errors.
	 * @param boolean $is_public Relax required secret fields.
	 *
	 * @return void
	 */
	private static function schema_errors( array &$errors, array $loaded, string $schema, string $prefix, bool $is_public ): void {
		if ( isset( $loaded['error'] ) ) {
			$errors[] = self::error( 'schema', $prefix, $loaded['error'] );
			return;
		}
		$definition = self::schema( $schema, $is_public );
		$result     = ( new Validator() )->validate( $loaded['object'], $definition );
		if ( $result->isValid() ) {
			return;
		}
		$leaves = array();
		self::leaves( $result->error(), $leaves );
		foreach ( array_slice( $leaves, 0, self::MAX_SCHEMA_ERRORS ) as $leaf ) {
			$errors[] = self::error( 'schema', $prefix . $leaf['path'], $leaf['message'] );
		}
	}

	/**
	 * Innermost errors, de-duplicated.
	 *
	 * @param ValidationError $error  Error.
	 * @param array           $leaves Collected, appended to.
	 *
	 * @return void
	 */
	private static function leaves( ValidationError $error, array &$leaves ): void {
		$subs = $error->subErrors();
		if ( ! $subs ) {
			$pointer = '';
			foreach ( $error->data()->fullPath() as $segment ) {
				$pointer .= '/' . str_replace( array( '~', '/' ), array( '~0', '~1' ), (string) $segment );
			}
			$leaves[ $pointer . '|' . $error->message() ] = array(
				'path'    => $pointer,
				'message' => $error->message(),
			);
			return;
		}
		foreach ( $subs as $sub ) {
			self::leaves( $sub, $leaves );
		}
	}

	/**
	 * A bundled schema, with the secret fields made optional for the public flavour.
	 *
	 * @param string  $name      Either course or lesson.
	 * @param boolean $is_public Public flavour.
	 *
	 * @return object
	 */
	private static function schema( string $name, bool $is_public ): object {
		$raw    = file_get_contents( self::SCHEMA_DIR . $name . '.schema.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled file.
		$schema = json_decode( (string) $raw, false, 64 );
		if ( $is_public && 'lesson' === $name ) {
			foreach ( Secrets::FIELDS as $pointer ) {
				$segments = explode( '/', substr( $pointer, 1 ) );
				$last     = array_pop( $segments );
				$node     = $schema;
				foreach ( $segments as $segment ) {
					$node = $node->properties->{$segment} ?? null;
					if ( null === $node ) {
						continue 2;
					}
				}
				if ( isset( $node->required ) ) {
					$node->required = array_values( array_diff( $node->required, array( $last ) ) );
				}
			}
		}
		return $schema;
	}

	/**
	 * Builds an error row.
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
