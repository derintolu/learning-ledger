<?php
/**
 * The connector's Ed25519 request-signing key.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Pairing;

use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Secret_Box;
use LearningLedger\Crypto\Signed_Request;

/**
 * Key pair generated on first use; the seed is sealed in an option (autoload off).
 */
final class Connector_Keys {

	const OPTION  = 'lledger_connector_signing_key';
	const CONTEXT = 'connector-signing';

	/**
	 * Public key, standard base64.
	 *
	 * @return string
	 */
	public static function public_b64(): string {
		return base64_encode( self::pair()['public'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- public key transport encoding.
	}

	/**
	 * Key id of the public key.
	 *
	 * @return string
	 */
	public static function key_id(): string {
		return Ed25519::key_id( self::pair()['public'] );
	}

	/**
	 * Signature headers for a request to `$url`.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Full URL; its path and query are signed.
	 * @param string $body   Raw body.
	 *
	 * @return array<string,string>
	 */
	public static function sign( string $method, string $url, string $body ): array {
		$parts          = wp_parse_url( $url );
		$path_and_query = ( is_array( $parts ) && isset( $parts['path'] ) ) ? $parts['path'] : '/';
		if ( is_array( $parts ) && isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$path_and_query .= '?' . $parts['query'];
		}
		$pair = self::pair();
		return Signed_Request::sign( Ed25519::key_id( $pair['public'] ), $pair['secret'], $method, $path_and_query, $body );
	}

	/**
	 * Load the key pair, generating it on first use.
	 *
	 * @return array{public:string,secret:string}
	 *
	 * @throws \RuntimeException When the stored key cannot be opened.
	 */
	private static function pair(): array {
		$sealed = get_option( self::OPTION );
		if ( false === $sealed ) {
			$seed = random_bytes( 32 );
			if ( add_option( self::OPTION, Secret_Box::seal( $seed, self::CONTEXT ), '', false ) ) {
				return Ed25519::from_seed( $seed );
			}
			// Another request stored a key first; use that one.
			$sealed = get_option( self::OPTION );
		}
		$seed = Secret_Box::open( (string) $sealed, self::CONTEXT );
		if ( 32 !== strlen( $seed ) ) {
			throw new \RuntimeException( 'Stored connector signing key is malformed.' );
		}
		return Ed25519::from_seed( $seed );
	}
}
