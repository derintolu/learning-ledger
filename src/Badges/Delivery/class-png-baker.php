<?php
/**
 * Open Badges 3.0 PNG baking.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Delivery;

use LearningLedger\Util\Invalid_Input;

/**
 * Bakes a credential into a PNG and reads it back (OB 3.0 §5.3.1).
 *
 * The credential goes in an uncompressed `iTXt` chunk with the keyword
 * `openbadgecredential`, placed just before `IEND`; any earlier credential chunk is
 * removed, so a PNG carries at most one. Its text is the credential's JSON, or the
 * compact JWS of a VC-JWT.
 */
final class Png_Baker {

	/**
	 * PNG file signature.
	 */
	const SIGNATURE = "\x89PNG\r\n\x1a\n";

	/**
	 * The `iTXt` keyword of a baked credential.
	 */
	const KEYWORD = 'openbadgecredential';

	/**
	 * Largest credential text read back from a compressed chunk, in bytes.
	 */
	const MAX_INFLATED = 4194304;

	/**
	 * Bake a credential into a PNG.
	 *
	 * @param string $png             PNG bytes.
	 * @param string $credential_json The credential: its JSON, or a VC-JWT string.
	 *
	 * @return string The baked PNG.
	 *
	 * @throws Invalid_Input When the bytes are not a well-formed PNG.
	 */
	public static function bake( string $png, string $credential_json ): string {
		$chunks = self::chunks( $png );
		if ( null === $chunks ) {
			throw new Invalid_Input( 'Not a well-formed PNG.', 'invalid_png', 'png' );
		}
		// Keyword, null, compression flag 0, compression method 0, empty language tag and translated keyword (each null-terminated), text.
		$baked = self::chunk( 'iTXt', self::KEYWORD . "\0\0\0\0\0" . $credential_json );
		$out   = self::SIGNATURE;
		foreach ( $chunks as [ $type, $data, $raw ] ) {
			if ( 'iTXt' === $type && str_starts_with( $data, self::KEYWORD . "\0" ) ) {
				continue;
			}
			if ( 'IEND' === $type ) {
				$out .= $baked;
			}
			$out .= $raw;
		}
		return $out;
	}

	/**
	 * The credential baked into a PNG.
	 *
	 * @param string $png PNG bytes.
	 *
	 * @return string|null The credential text, or null when there is none or the bytes are not a well-formed PNG.
	 */
	public static function extract( string $png ): ?string {
		foreach ( self::chunks( $png ) ?? array() as [ $type, $data ] ) {
			if ( 'iTXt' === $type ) {
				$text = self::credential_text( $data );
				if ( null !== $text ) {
					return $text;
				}
			}
		}
		return null;
	}

	/**
	 * Split a PNG into chunks, checking every length and CRC.
	 *
	 * @param string $png PNG bytes.
	 *
	 * @return array<int, array{0:string,1:string,2:string}>|null [type, data, raw bytes] per chunk,
	 *                                                         or null unless the PNG starts with IHDR and ends with IEND.
	 */
	private static function chunks( string $png ): ?array {
		if ( ! str_starts_with( $png, self::SIGNATURE ) ) {
			return null;
		}
		$chunks = array();
		$length = strlen( $png );
		$pos    = strlen( self::SIGNATURE );
		while ( $pos + 12 <= $length ) {
			$size = unpack( 'N', substr( $png, $pos, 4 ) )[1];
			if ( $size > $length - $pos - 12 ) {
				return null;
			}
			$type = substr( $png, $pos + 4, 4 );
			$data = substr( $png, $pos + 8, $size );
			if ( 1 !== preg_match( '/\A[A-Za-z]{4}\z/', $type ) || crc32( $type . $data ) !== unpack( 'N', substr( $png, $pos + 8 + $size, 4 ) )[1] ) {
				return null;
			}
			$chunks[] = array( $type, $data, substr( $png, $pos, $size + 12 ) );
			$pos     += $size + 12;
			if ( 'IEND' === $type ) {
				break;
			}
		}
		if ( array() === $chunks || 'IHDR' !== $chunks[0][0] || 'IEND' !== $chunks[ count( $chunks ) - 1 ][0] ) {
			return null;
		}
		return $chunks;
	}

	/**
	 * One chunk: length, type, data and the CRC of type and data.
	 *
	 * @param string $type Chunk type.
	 * @param string $data Chunk data.
	 *
	 * @return string
	 */
	private static function chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	/**
	 * The text of an iTXt chunk when its keyword is `openbadgecredential`.
	 *
	 * @param string $data The `iTXt` chunk data.
	 *
	 * @return string|null
	 */
	private static function credential_text( string $data ): ?string {
		$prefix = self::KEYWORD . "\0";
		if ( ! str_starts_with( $data, $prefix ) || strlen( $data ) < strlen( $prefix ) + 2 ) {
			return null;
		}
		$flag   = ord( $data[ strlen( $prefix ) ] );
		$method = ord( $data[ strlen( $prefix ) + 1 ] );
		$rest   = substr( $data, strlen( $prefix ) + 2 );
		// Skip the language tag and the translated keyword.
		$parts = explode( "\0", $rest, 3 );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		$text = $parts[2];
		if ( 0 === $flag ) {
			return $text;
		}
		if ( 1 === $flag && 0 === $method ) {
			$inflated = @gzuncompress( $text, self::MAX_INFLATED ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt data yields false, handled below.
			return false === $inflated ? null : $inflated;
		}
		return null;
	}
}
