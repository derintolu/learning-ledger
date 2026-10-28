<?php
/**
 * The issuer's did:web document.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Issuer;

use LearningLedger\Crypto\Multibase;

/**
 * Builds `did:web:<host>` (or `did:web:<host>:issuer`) and its DID document.
 */
final class Did_Document {

	/**
	 * Option choosing `host` (default) or `path` mode.
	 */
	const MODE_OPTION = 'lledger_did_mode';

	const CONTEXT = array( 'https://www.w3.org/ns/did/v1', 'https://w3id.org/security/multikey/v1' );

	/**
	 * Context defining `JsonWebKey` and `publicKeyJwk`, added only when such a method is published.
	 */
	const JWK_CONTEXT = 'https://w3id.org/security/jwk/v1';

	/**
	 * The issuer DID, from home_url(). A port is encoded as `%3A<port>`.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When home_url() has no host.
	 */
	public static function did(): string {
		$home = home_url();
		$host = wp_parse_url( $home, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			throw new \RuntimeException( 'The site URL has no host.' );
		}
		$port = wp_parse_url( $home, PHP_URL_PORT );
		$did  = 'did:web:' . strtolower( $host ) . ( $port ? '%3A' . (int) $port : '' );
		return 'path' === get_option( self::MODE_OPTION ) ? $did . ':issuer' : $did;
	}

	/**
	 * Verification method id for a key: `<did>#<kid>`.
	 *
	 * @param Issuer_Key $k Key.
	 *
	 * @return string
	 */
	public static function verification_method_for( Issuer_Key $k ): string {
		return self::did() . '#' . $k->kid;
	}

	/**
	 * The DID document: one Multikey per published key, all of them assertion methods, plus any
	 * methods the `learning_ledger/did_verification_methods` filter adds. The JWK context is
	 * included only when a JsonWebKey method is published.
	 *
	 * @return array
	 */
	public static function document(): array {
		$did     = self::did();
		$methods = array();
		foreach ( Key_Store::published() as $key ) {
			$methods[] = array(
				'id'                 => $did . '#' . $key->kid,
				'type'               => 'Multikey',
				'controller'         => $did,
				'publicKeyMultibase' => Multibase::ed25519_multikey( $key->public ),
			);
		}
		/**
		 * Filters the verification methods published in did.json.
		 *
		 * @param array $methods Verification method objects.
		 */
		$filtered = apply_filters( 'learning_ledger/did_verification_methods', $methods ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		$methods  = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $method ) {
			if ( is_array( $method ) && isset( $method['id'] ) && is_string( $method['id'] ) ) {
				$methods[] = $method;
			}
		}
		$context = self::CONTEXT;
		if ( in_array( 'JsonWebKey', array_column( $methods, 'type' ), true ) ) {
			$context[] = self::JWK_CONTEXT;
		}
		return array(
			'@context'           => $context,
			'id'                 => $did,
			'verificationMethod' => $methods,
			'assertionMethod'    => array_column( $methods, 'id' ),
		);
	}
}
