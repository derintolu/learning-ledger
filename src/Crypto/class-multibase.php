<?php
/**
 * Multibase encodings and Ed25519 multikeys.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Crypto;

use LearningLedger\Util\Invalid_Input;

/**
 * Base58btc (`z`) and base64url (`u`) multibase, and the `0xed01` Ed25519 multikey.
 */
final class Multibase {

	const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

	/**
	 * Base58btc with the `z` prefix.
	 *
	 * @param string $raw Bytes.
	 *
	 * @return string
	 */
	public static function base58btc_encode( string $raw ): string {
		$zeros = strspn( $raw, "\0" );
		$rest  = substr( $raw, $zeros );
		$bytes = '' === $rest ? array() : array_values( unpack( 'C*', $rest ) );
		$out   = '';
		while ( $bytes ) {
			$rem  = 0;
			$next = array();
			foreach ( $bytes as $byte ) {
				$acc = ( $rem << 8 ) | $byte;
				$q   = intdiv( $acc, 58 );
				$rem = $acc % 58;
				if ( $q > 0 || $next ) {
					$next[] = $q;
				}
			}
			$out   = self::ALPHABET[ $rem ] . $out;
			$bytes = $next;
		}
		return 'z' . str_repeat( '1', $zeros ) . $out;
	}

	/**
	 * Decode a `z`-prefixed base58btc string.
	 *
	 * @param string $encoded Encoded value.
	 *
	 * @return string Bytes.
	 *
	 * @throws Invalid_Input On a missing prefix or a character outside the alphabet.
	 */
	public static function base58btc_decode( string $encoded ): string {
		if ( '' === $encoded || 'z' !== $encoded[0] ) {
			throw new Invalid_Input( 'Expected a base58btc multibase value.', 'invalid' );
		}
		$body = substr( $encoded, 1 );
		if ( strspn( $body, self::ALPHABET ) !== strlen( $body ) ) {
			throw new Invalid_Input( 'Invalid base58btc character.', 'invalid' );
		}
		$zeros = strspn( $body, '1' );
		$bytes = array();
		$len   = strlen( $body );
		for ( $i = $zeros; $i < $len; $i++ ) {
			$carry = strpos( self::ALPHABET, $body[ $i ] );
			foreach ( $bytes as $k => $byte ) {
				$carry      += $byte * 58;
				$bytes[ $k ] = $carry & 0xff;
				$carry     >>= 8;
			}
			while ( $carry > 0 ) {
				$bytes[] = $carry & 0xff;
				$carry >>= 8;
			}
		}
		return str_repeat( "\0", $zeros ) . ( $bytes ? pack( 'C*', ...array_reverse( $bytes ) ) : '' );
	}

	/**
	 * Base64url (no padding) with the `u` prefix.
	 *
	 * @param string $raw Bytes.
	 *
	 * @return string
	 */
	public static function base64url_encode( string $raw ): string {
		return 'u' . rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- multibase encoding.
	}

	/**
	 * Decode a `u`-prefixed base64url string.
	 *
	 * @param string $encoded Encoded value.
	 *
	 * @return string Bytes.
	 *
	 * @throws Invalid_Input On a missing prefix or invalid characters.
	 */
	public static function base64url_decode( string $encoded ): string {
		if ( '' === $encoded || 'u' !== $encoded[0] ) {
			throw new Invalid_Input( 'Expected a base64url multibase value.', 'invalid' );
		}
		$body = substr( $encoded, 1 );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]*$/', $body ) || 1 === strlen( $body ) % 4 ) {
			throw new Invalid_Input( 'Invalid base64url value.', 'invalid' );
		}
		$raw = base64_decode( strtr( $body, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- multibase decoding.
		if ( false === $raw ) {
			throw new Invalid_Input( 'Invalid base64url value.', 'invalid' );
		}
		return $raw;
	}

	/**
	 * Ed25519 public key as a multikey (`z6Mk...`).
	 *
	 * @param string $public_raw Raw 32-byte public key.
	 *
	 * @return string
	 *
	 * @throws Invalid_Input On a key that is not 32 bytes.
	 */
	public static function ed25519_multikey( string $public_raw ): string {
		if ( 32 !== strlen( $public_raw ) ) {
			throw new Invalid_Input( 'Ed25519 public key must be 32 bytes.', 'invalid' );
		}
		return self::base58btc_encode( "\xed\x01" . $public_raw );
	}

	/**
	 * Raw Ed25519 public key from a multikey.
	 *
	 * @param string $multikey Multikey.
	 *
	 * @return string 32 bytes.
	 *
	 * @throws Invalid_Input On a wrong codec or length.
	 */
	public static function from_ed25519_multikey( string $multikey ): string {
		$raw = self::base58btc_decode( $multikey );
		if ( 34 !== strlen( $raw ) || "\xed\x01" !== substr( $raw, 0, 2 ) ) {
			throw new Invalid_Input( 'Not an Ed25519 multikey.', 'invalid' );
		}
		return substr( $raw, 2 );
	}
}
