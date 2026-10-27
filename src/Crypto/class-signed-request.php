<?php
/**
 * Ed25519-signed HTTP requests between sites.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Crypto;

/**
 * Builds and verifies the `X-Learning-Ledger-*` signature headers.
 */
final class Signed_Request {

	const HEADER_KEY       = 'X-Learning-Ledger-Key';
	const HEADER_TIMESTAMP = 'X-Learning-Ledger-Timestamp';
	const HEADER_NONCE     = 'X-Learning-Ledger-Nonce';
	const HEADER_SIGNATURE = 'X-Learning-Ledger-Signature';

	/**
	 * Allowed clock skew in seconds, each way.
	 */
	const WINDOW = 300;

	/**
	 * Seconds a nonce is remembered.
	 */
	const NONCE_TTL = 600;

	/**
	 * Canonical string that is signed.
	 *
	 * @param string $method         HTTP method.
	 * @param string $path_and_query Path and query string.
	 * @param string $body           Raw body.
	 * @param string $timestamp      Unix seconds, decimal.
	 * @param string $nonce          Base64url nonce.
	 *
	 * @return string
	 */
	public static function canonical( string $method, string $path_and_query, string $body, string $timestamp, string $nonce ): string {
		return strtoupper( $method ) . "\n" . $path_and_query . "\n" . hash( 'sha256', $body ) . "\n" . $timestamp . "\n" . $nonce;
	}

	/**
	 * Signature headers for a request.
	 *
	 * @param string       $key_id         Key id of the signing key.
	 * @param string       $secret         Raw 64-byte Ed25519 secret key.
	 * @param string       $method         HTTP method.
	 * @param string       $path_and_query Path and query string.
	 * @param string       $body           Raw body.
	 * @param integer|null $now            Unix seconds; defaults to the current time.
	 *
	 * @return array<string,string>
	 */
	public static function sign( string $key_id, string $secret, string $method, string $path_and_query, string $body, ?int $now = null ): array {
		$timestamp = (string) ( $now ?? time() );
		$nonce     = Tokens::random( 16 );
		$signature = Ed25519::sign( self::canonical( $method, $path_and_query, $body, $timestamp, $nonce ), $secret );
		return array(
			self::HEADER_KEY       => $key_id,
			self::HEADER_TIMESTAMP => $timestamp,
			self::HEADER_NONCE     => $nonce,
			self::HEADER_SIGNATURE => base64_encode( $signature ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- signature transport encoding.
		);
	}

	/**
	 * Verify a signed request. The nonce is recorded only after the signature checks out.
	 *
	 * @param array       $headers        Request headers; names are matched case-insensitively and `_` equals `-`.
	 * @param string      $method         HTTP method.
	 * @param string      $path_and_query Path and query string.
	 * @param string      $body           Raw body.
	 * @param callable    $public_key_for Key id => raw 32-byte public key, or null when unknown.
	 * @param Nonce_Store $nonces         Seen-nonce store.
	 * @param integer     $now            Unix seconds.
	 *
	 * @return string Key id.
	 *
	 * @throws Signature_Error With reason `missing_header`, `unknown_key`, `stale`, `replay` or `bad_signature`.
	 */
	public static function verify( array $headers, string $method, string $path_and_query, string $body, callable $public_key_for, Nonce_Store $nonces, int $now ): string {
		$normalized = array();
		foreach ( $headers as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = reset( $value );
			}
			if ( is_string( $value ) ) {
				$normalized[ str_replace( '_', '-', strtolower( (string) $name ) ) ] = $value;
			}
		}
		$values = array();
		foreach ( array( self::HEADER_KEY, self::HEADER_TIMESTAMP, self::HEADER_NONCE, self::HEADER_SIGNATURE ) as $name ) {
			$value = $normalized[ strtolower( $name ) ] ?? '';
			if ( '' === $value ) {
				throw new Signature_Error( 'missing_header' );
			}
			$values[] = $value;
		}
		list( $key_id, $timestamp, $nonce, $signature_b64 ) = $values;

		$public = $public_key_for( $key_id );
		if ( ! is_string( $public ) || 32 !== strlen( $public ) ) {
			throw new Signature_Error( 'unknown_key' );
		}

		if ( 1 !== preg_match( '/^[0-9]{1,12}$/', $timestamp ) || abs( $now - (int) $timestamp ) > self::WINDOW ) {
			throw new Signature_Error( 'stale' );
		}

		$signature = base64_decode( $signature_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- signature transport encoding.
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce )
			|| false === $signature
			|| ! Ed25519::verify( $signature, self::canonical( $method, $path_and_query, $body, $timestamp, $nonce ), $public ) ) {
			throw new Signature_Error( 'bad_signature' );
		}

		if ( ! $nonces->remember( $key_id, $nonce, self::NONCE_TTL ) ) {
			throw new Signature_Error( 'replay' );
		}
		return $key_id;
	}
}
