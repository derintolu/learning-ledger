<?php
/**
 * UUID helpers.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Util;

/**
 * UUID generation, validation and binary conversion.
 */
final class Uuid {

	/**
	 * The nil UUID as 16 raw bytes.
	 */
	const NIL_BIN = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

	/**
	 * Canonical text pattern (case-insensitive).
	 */
	const PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

	/**
	 * Random (version 4) UUID.
	 *
	 * @return string Lowercase canonical UUID.
	 */
	public static function v4(): string {
		$b    = random_bytes( 16 );
		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
		return self::from_bin( $b );
	}

	/**
	 * Name-based (version 5, SHA-1) UUID.
	 *
	 * @param string $ns   Namespace UUID.
	 * @param string $name Name.
	 *
	 * @return string Lowercase canonical UUID.
	 * @throws Invalid_Input When the namespace is not a UUID.
	 */
	public static function v5( string $ns, string $name ): string {
		$b    = substr( sha1( self::to_bin( $ns ) . $name, true ), 0, 16 );
		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x50 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
		return self::from_bin( $b );
	}

	/**
	 * Whether a string is a canonical-form UUID (any case).
	 *
	 * @param string $s Candidate.
	 *
	 * @return boolean
	 */
	public static function is_valid( string $s ): bool {
		return 1 === preg_match( self::PATTERN, $s );
	}

	/**
	 * Lowercase a UUID.
	 *
	 * @param string $s UUID.
	 *
	 * @return string
	 * @throws Invalid_Input When not a UUID.
	 */
	public static function normalize( string $s ): string {
		if ( ! self::is_valid( $s ) ) {
			throw new Invalid_Input( 'Not a valid UUID.', 'invalid_uuid' );
		}
		return strtolower( $s );
	}

	/**
	 * UUID text to 16 raw bytes.
	 *
	 * @param string $s UUID.
	 *
	 * @return string
	 * @throws Invalid_Input When not a UUID.
	 */
	public static function to_bin( string $s ): string {
		return (string) hex2bin( str_replace( '-', '', self::normalize( $s ) ) );
	}

	/**
	 * 16 raw bytes to lowercase UUID text.
	 *
	 * @param string $b Raw bytes.
	 *
	 * @return string
	 * @throws Invalid_Input When not 16 bytes.
	 */
	public static function from_bin( string $b ): string {
		if ( 16 !== strlen( $b ) ) {
			throw new Invalid_Input( 'A binary UUID must be 16 bytes.', 'invalid_uuid' );
		}
		$h = bin2hex( $b );
		return substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-' . substr( $h, 12, 4 ) . '-' . substr( $h, 16, 4 ) . '-' . substr( $h, 20 );
	}
}
