<?php
/**
 * Writes a package ZIP deterministically.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Package;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never echoed; HTTP layers JSON-encode them.

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;

/**
 * The same input gives the same bytes: sorted entries, fixed modification time, no extra fields,
 * and a fixed archive comment.
 */
final class Package_Writer {

	/**
	 * DOS date of 1980-01-01 (year 0, month 1, day 1). The DOS time is 00:00:00.
	 */
	private const DOS_DATE = 0x0021;

	/**
	 * Archive comment. Without one a ZIP ends in NUL bytes (the end record's high offset bytes
	 * and an empty comment length), which some hosts strip from PHP output, truncating it.
	 */
	public const COMMENT = 'Learning Ledger package';

	/**
	 * Builds the ZIP.
	 *
	 * @param string               $cmi5_xml    Contents of cmi5.xml.
	 * @param array                $course_json Document for cxm/course.json.
	 * @param array<string,array>  $lessons     Lesson documents by key.
	 * @param array<string,string> $files       File bytes by path relative to cxm/files/.
	 *
	 * @return string ZIP bytes.
	 * @throws Invalid_Input Codes bad_key, zip_path, zip_too_many, zip_too_large.
	 */
	public static function write( string $cmi5_xml, array $course_json, array $lessons, array $files ): string {
		$entries = array(
			'cmi5.xml'           => $cmi5_xml,
			Package::COURSE_JSON => Json::encode_exact( $course_json ),
		);
		foreach ( $lessons as $key => $lesson ) {
			$key = (string) $key;
			if ( 1 !== preg_match( '/^[a-z0-9-]{1,64}$/', $key ) ) {
				throw new Invalid_Input( 'Lesson key is not valid.', 'bad_key', $key );
			}
			$entries[ 'cxm/lessons/' . $key . '.json' ] = Json::encode_exact( $lesson );
		}
		foreach ( $files as $path => $bytes ) {
			$full = 'cxm/files/' . $path;
			Package::check_name( $full );
			$entries[ $full ] = (string) $bytes;
		}
		ksort( $entries, SORT_STRING );
		if ( count( $entries ) > 0xFFFF ) {
			throw new Invalid_Input( 'Too many files for a package.', 'zip_too_many' );
		}

		$local   = '';
		$central = '';
		foreach ( $entries as $name => $data ) {
			$name = (string) $name;
			$size = strlen( $data );
			if ( $size > 0x7FFFFFFF ) {
				throw new Invalid_Input( 'A file is too large for a package.', 'zip_too_large', $name );
			}
			$crc  = hash( 'crc32b', $data, true );
			$crc  = unpack( 'N', $crc )[1];
			$zlib = gzdeflate( $data, 9 );
			if ( strlen( $zlib ) >= $size ) {
				$method = 0;
				$body   = $data;
			} else {
				$method = 8;
				$body   = $zlib;
			}
			$csize  = strlen( $body );
			$offset = strlen( $local );
			$flags  = 0x0800;
			$nlen   = strlen( $name );

			$local   .= pack( 'VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, 0, self::DOS_DATE, $crc, $csize, $size, $nlen, 0 ) . $name . $body;
			$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, ( 3 << 8 ) | 20, 20, $flags, $method, 0, self::DOS_DATE, $crc, $csize, $size, $nlen, 0, 0, 0, 0, 0100644 << 16, $offset ) . $name;
		}
		$end = pack( 'VvvvvVVv', 0x06054b50, 0, 0, count( $entries ), count( $entries ), strlen( $central ), strlen( $local ), strlen( self::COMMENT ) ) . self::COMMENT;

		return $local . $central . $end;
	}

	/**
	 * Strong ETag for ZIP bytes.
	 *
	 * @param string $zip ZIP bytes.
	 *
	 * @return string
	 */
	public static function etag( string $zip ): string {
		return '"' . hash( 'sha256', $zip ) . '"';
	}
}
