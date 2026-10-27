<?php
/**
 * Bitstring Status List bitstrings.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Status;

use LearningLedger\Crypto\Multibase;
use LearningLedger\Util\Invalid_Input;

/**
 * Raw status bitstrings and their `encodedList` form (W3C Bitstring Status List).
 * Index 0 is the most significant bit of the first byte.
 */
final class Bitstring {

	/**
	 * Entries per status list.
	 */
	const SIZE = 131072;

	/**
	 * Encode a bitstring as `encodedList`: `u` + base64url(GZIP(bits)), unpadded.
	 *
	 * @param string $bits Raw bitstring.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When compression fails.
	 */
	public static function encode( string $bits ): string {
		$gzip = gzencode( $bits, 9 );
		if ( false === $gzip ) {
			throw new \RuntimeException( 'Could not compress the status list.' );
		}
		return Multibase::base64url_encode( $gzip );
	}

	/**
	 * Decode an `encodedList` into its raw bitstring, at most SIZE bits long.
	 *
	 * @param string $encoded Encoded list.
	 *
	 * @return string
	 *
	 * @throws Invalid_Input When the value is not base64url multibase GZIP data, or decompresses past SIZE bits.
	 */
	public static function decode( string $encoded ): string {
		try {
			$gzip = Multibase::base64url_decode( $encoded );
		} catch ( Invalid_Input $e ) {
			throw new Invalid_Input( 'The encoded list is not base64url multibase.', 'invalid', 'encodedList' );
		}
		// gzdecode() reports corrupt or oversized input with a warning as well as false.
		set_error_handler( static fn(): bool => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Silences gzdecode() warnings; false is handled below.
		try {
			$bits = gzdecode( $gzip, self::SIZE / 8 );
		} finally {
			restore_error_handler();
		}
		// The length cap passed to gzdecode() bounds memory but is not exact.
		if ( ! is_string( $bits ) || '' === $bits || strlen( $bits ) > self::SIZE / 8 ) {
			throw new Invalid_Input( 'The encoded list is not a GZIP bitstring of at most 131072 entries.', 'invalid', 'encodedList' );
		}
		return $bits;
	}

	/**
	 * Set or clear one bit.
	 *
	 * @param string  $bits  Raw bitstring.
	 * @param integer $index Bit index.
	 * @param boolean $on    Whether to set the bit.
	 *
	 * @return string The updated bitstring.
	 *
	 * @throws Invalid_Input When the index is outside the bitstring.
	 */
	public static function set( string $bits, int $index, bool $on ): string {
		self::check( $bits, $index );
		$byte = intdiv( $index, 8 );
		$mask = 0x80 >> ( $index % 8 );
		$old  = ord( $bits[ $byte ] );

		$bits[ $byte ] = chr( $on ? ( $old | $mask ) : ( $old & ~$mask & 0xFF ) );
		return $bits;
	}

	/**
	 * Whether one bit is set.
	 *
	 * @param string  $bits  Raw bitstring.
	 * @param integer $index Bit index.
	 *
	 * @return boolean
	 *
	 * @throws Invalid_Input When the index is outside the bitstring.
	 */
	public static function get( string $bits, int $index ): bool {
		self::check( $bits, $index );
		return 0 !== ( ord( $bits[ intdiv( $index, 8 ) ] ) & ( 0x80 >> ( $index % 8 ) ) );
	}

	/**
	 * Reject an index outside the bitstring.
	 *
	 * @param string  $bits  Raw bitstring.
	 * @param integer $index Bit index.
	 *
	 * @return void
	 *
	 * @throws Invalid_Input When the index is out of range.
	 */
	private static function check( string $bits, int $index ): void {
		if ( $index < 0 || $index >= strlen( $bits ) * 8 ) {
			throw new Invalid_Input( 'Status list index is out of range.', 'invalid', 'index' );
		}
	}
}
