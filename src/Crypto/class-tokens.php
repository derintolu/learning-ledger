<?php
/**
 * Random bearer tokens and their stored hashes.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Crypto;

/**
 * Token generation and hashing.
 */
final class Tokens {

	/**
	 * Random token.
	 *
	 * @param integer $bytes Entropy in bytes.
	 *
	 * @return string Base64url without padding.
	 */
	public static function random( int $bytes = 32 ): string {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url token encoding.
	}

	/**
	 * Hash for storage and lookup.
	 *
	 * @param string $token Token.
	 *
	 * @return string Raw SHA-256.
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token, true );
	}
}
