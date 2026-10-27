<?php
/**
 * Site-keyed HMAC for short-lived signed links.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Crypto;

/**
 * HMAC-SHA256 keyed by the WordPress auth salt and a purpose label.
 */
final class Hmac {

	/**
	 * Base key used instead of the auth salt (tests only).
	 *
	 * @var string|null
	 */
	private static ?string $test_key = null;

	/**
	 * Override the base key (tests only). Null restores the auth salt.
	 *
	 * @param string|null $key Base key.
	 *
	 * @return void
	 */
	public static function set_key_for_tests( ?string $key ): void {
		self::$test_key = $key;
	}

	/**
	 * Raw MAC.
	 *
	 * @param string $data    Data.
	 * @param string $purpose Purpose label; separates keys per use.
	 *
	 * @return string 32 raw bytes.
	 */
	public static function sign( string $data, string $purpose ): string {
		$base = self::$test_key ?? wp_salt( 'auth' );
		return hash_hmac( 'sha256', $data, $base . $purpose, true );
	}

	/**
	 * Constant-time verification.
	 *
	 * @param string $mac     Raw MAC.
	 * @param string $data    Data.
	 * @param string $purpose Purpose label.
	 *
	 * @return boolean
	 */
	public static function verify( string $mac, string $data, string $purpose ): bool {
		return hash_equals( self::sign( $data, $purpose ), $mac );
	}
}
