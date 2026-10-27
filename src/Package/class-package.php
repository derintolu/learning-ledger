<?php
/**
 * A course package: cmi5.xml plus the optional cxm/ folder.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Package;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never echoed; HTTP layers JSON-encode them.

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;

/**
 * Read-only view of a package, from a ZIP on disk or from bare cmi5 XML.
 */
final class Package {

	const COURSE_JSON = 'cxm/course.json';

	const MAX_JSON_BYTES = 8388608;

	/**
	 * Entry names (files only), sorted.
	 *
	 * @var string[]
	 */
	private array $names;

	/**
	 * ZIP path, or null for a package built from XML.
	 *
	 * @var string|null
	 */
	private ?string $zip_path;

	/**
	 * Declared uncompressed size per entry.
	 *
	 * @var array<string,int>
	 */
	private array $sizes;

	/**
	 * In-memory contents (from_xml only).
	 *
	 * @var array<string,string>
	 */
	private array $memory;

	/**
	 * Constructor.
	 *
	 * @param string[]             $names    Entry names.
	 * @param string|null          $zip_path ZIP path.
	 * @param array<string,int>    $sizes    Declared sizes.
	 * @param array<string,string> $memory   In-memory contents.
	 */
	private function __construct( array $names, ?string $zip_path, array $sizes, array $memory ) {
		sort( $names, SORT_STRING );
		$this->names    = $names;
		$this->zip_path = $zip_path;
		$this->sizes    = $sizes;
		$this->memory   = $memory;
	}

	/**
	 * A plain cmi5 package from its XML.
	 *
	 * @param string $xml Contents of cmi5.xml.
	 *
	 * @return Package
	 */
	public static function from_xml( string $xml ): Package {
		return new self( array( 'cmi5.xml' ), null, array(), array( 'cmi5.xml' => $xml ) );
	}

	/**
	 * Opens a ZIP, enforcing path and size rules before anything is extracted.
	 *
	 * @param string $path ZIP path.
	 * @param Limits $l    Limits.
	 *
	 * @return Package
	 * @throws Invalid_Input Codes zip_invalid, zip_path, zip_symlink, zip_too_large, zip_too_many, zip_ratio, no_cmi5_xml.
	 */
	public static function from_zip( string $path, Limits $l ): Package {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) {
			throw new Invalid_Input( 'The package is not a readable ZIP file.', 'zip_invalid' );
		}
		try {
			$count = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
			if ( $count > $l->max_files ) {
				throw new Invalid_Input( 'The package has too many files.', 'zip_too_many' );
			}
			$names = array();
			$sizes = array();
			$total = 0;
			for ( $i = 0; $i < $count; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( false === $stat ) {
					throw new Invalid_Input( 'The package is not a readable ZIP file.', 'zip_invalid' );
				}
				$name = (string) $stat['name'];
				self::check_name( $name );
				if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) && \ZipArchive::OPSYS_UNIX === $opsys && 0xA000 === ( ( $attr >> 16 ) & 0xF000 ) ) {
					throw new Invalid_Input( 'The package contains a symbolic link.', 'zip_symlink', $name );
				}
				if ( '/' === substr( $name, -1 ) ) {
					continue;
				}
				if ( isset( $sizes[ $name ] ) ) {
					throw new Invalid_Input( 'The package has a duplicate entry.', 'zip_path', $name );
				}
				$size   = (int) $stat['size'];
				$comp   = (int) $stat['comp_size'];
				$total += $size;
				if ( $total > $l->max_bytes ) {
					throw new Invalid_Input( 'The package is too large when unpacked.', 'zip_too_large' );
				}
				if ( $size > 1024 && $size > $l->max_ratio * max( 1, $comp ) ) {
					throw new Invalid_Input( 'The package compresses suspiciously well.', 'zip_ratio', $name );
				}
				$names[]        = $name;
				$sizes[ $name ] = $size;
			}
		} finally {
			$zip->close();
		}
		if ( ! in_array( 'cmi5.xml', $names, true ) ) {
			throw new Invalid_Input( 'The package has no cmi5.xml at its root.', 'no_cmi5_xml' );
		}
		$disk = filesize( $path );
		if ( false !== $disk && $total > 1024 && $total > $l->max_ratio * max( 1, $disk ) ) {
			throw new Invalid_Input( 'The package compresses suspiciously well.', 'zip_ratio' );
		}
		return new self( $names, $path, $sizes, array() );
	}

	/**
	 * Rejects names that could escape an extraction directory.
	 *
	 * @param string $name Entry name.
	 *
	 * @throws Invalid_Input Code zip_path.
	 *
	 * @return void
	 */
	public static function check_name( string $name ): void {
		$bad = '' === $name
			|| false !== strpos( $name, "\0" )
			|| false !== strpos( $name, '\\' )
			|| '/' === $name[0]
			|| 1 === preg_match( '#^[A-Za-z]:#', $name )
			|| in_array( '..', explode( '/', $name ), true );
		if ( $bad ) {
			throw new Invalid_Input( 'The package contains an unsafe path.', 'zip_path', str_replace( "\0", '', $name ) );
		}
	}

	/**
	 * The cmi5.xml contents.
	 *
	 * @return string
	 */
	public function cmi5_xml(): string {
		return $this->file( 'cmi5.xml' );
	}

	/**
	 * Whether the package carries cxm/course.json.
	 *
	 * @return boolean
	 */
	public function is_cxm(): bool {
		return in_array( self::COURSE_JSON, $this->names, true );
	}

	/**
	 * Decoded cxm/course.json.
	 *
	 * @return array|null Null when absent.
	 * @throws Invalid_Input When the JSON is malformed.
	 */
	public function course_json(): ?array {
		return $this->json( self::COURSE_JSON );
	}

	/**
	 * Decoded cxm/lessons/<key>.json.
	 *
	 * @param string $key Lesson key.
	 *
	 * @return array|null Null when absent.
	 * @throws Invalid_Input When the JSON is malformed.
	 */
	public function lesson_json( string $key ): ?array {
		return $this->json( 'cxm/lessons/' . $key . '.json' );
	}

	/**
	 * Lesson keys that have a JSON file, sorted.
	 *
	 * @return string[]
	 */
	public function lesson_keys(): array {
		$keys = array();
		foreach ( $this->names as $name ) {
			if ( 1 === preg_match( '#^cxm/lessons/([^/]+)\.json$#', $name, $m ) ) {
				$keys[] = $m[1];
			}
		}
		return $keys;
	}

	/**
	 * Entry paths under cxm/files/, as full entry paths.
	 *
	 * @return string[]
	 */
	public function files(): array {
		return array_values(
			array_filter(
				$this->names,
				static fn( string $n ): bool => 0 === strpos( $n, 'cxm/files/' )
			)
		);
	}

	/**
	 * Contents of an entry.
	 *
	 * @param string $path Entry path as listed by entries().
	 *
	 * @return string
	 * @throws Invalid_Input Codes not_found, zip_too_large (entry larger than its declared size).
	 */
	public function file( string $path ): string {
		if ( ! in_array( $path, $this->names, true ) ) {
			throw new Invalid_Input( 'The package has no such file.', 'not_found', $path );
		}
		if ( null === $this->zip_path ) {
			return $this->memory[ $path ];
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $this->zip_path, \ZipArchive::RDONLY ) ) {
			throw new Invalid_Input( 'The package is not a readable ZIP file.', 'zip_invalid' );
		}
		try {
			$declared = $this->sizes[ $path ];
			$data     = $zip->getFromName( $path, $declared + 1 );
		} finally {
			$zip->close();
		}
		if ( false === $data || strlen( $data ) > $declared ) {
			throw new Invalid_Input( 'A package entry does not match its declared size.', 'zip_too_large', $path );
		}
		return $data;
	}

	/**
	 * Every file path in the package, sorted.
	 *
	 * @return string[]
	 */
	public function entries(): array {
		return $this->names;
	}

	/**
	 * Decodes a JSON entry.
	 *
	 * @param string $path Entry path.
	 *
	 * @return array|null
	 * @throws Invalid_Input When malformed or not an object.
	 */
	private function json( string $path ): ?array {
		if ( ! in_array( $path, $this->names, true ) ) {
			return null;
		}
		$value = Json::decode_strict( $this->file( $path ), self::MAX_JSON_BYTES );
		if ( ! is_array( $value ) ) {
			throw new Invalid_Input( 'The JSON file is not an object.', 'malformed', $path );
		}
		return $value;
	}
}
