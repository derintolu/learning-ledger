<?php
/**
 * Signatures on outbound notifications (webhooks).
 *
 * @package LearningLedger
 */

namespace LearningLedger\Crypto;

/**
 * Ed25519 over `ts . "." . body`, transported as standard base64.
 */
final class Notification_Signature {

	/**
	 * Sign a notification body.
	 *
	 * @param string  $body   Body.
	 * @param string  $secret Ed25519 secret key.
	 * @param integer $ts     Unix seconds.
	 *
	 * @return string Base64 signature.
	 */
	public static function sign( string $body, string $secret, int $ts ): string {
		return base64_encode( Ed25519::sign( $ts . '.' . $body, $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- signature transport encoding.
	}

	/**
	 * Verify a notification signature.
	 *
	 * @param string $sig_b64    Base64 signature.
	 * @param string $ts         Timestamp as sent.
	 * @param string $body       Body.
	 * @param string $public_key Raw 32-byte public key.
	 *
	 * @return boolean
	 */
	public static function verify( string $sig_b64, string $ts, string $body, string $public_key ): bool {
		$sig = base64_decode( $sig_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- signature transport encoding.
		if ( false === $sig ) {
			return false;
		}
		return Ed25519::verify( $sig, $ts . '.' . $body, $public_key );
	}
}
