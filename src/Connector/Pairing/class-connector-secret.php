<?php
/**
 * The connector's own LRS credential secret.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Pairing;

use LearningLedger\Crypto\Secret_Box;
use LearningLedger\Crypto\Tokens;

/**
 * Secret from the `LLEDGER_CONNECTOR_SECRET` constant, else a sealed option.
 * Only its SHA-256 ever leaves the site.
 */
final class Connector_Secret {

	const OPTION   = 'lledger_connector_secret';
	const CONTEXT  = 'connector-secret';
	const CONSTANT = 'LLEDGER_CONNECTOR_SECRET';

	/**
	 * The secret, or null when none is set or the stored one cannot be opened.
	 *
	 * @return string|null
	 */
	public static function get(): ?string {
		$constant = self::constant();
		if ( '' !== $constant ) {
			return $constant;
		}
		$sealed = get_option( self::OPTION );
		if ( ! is_string( $sealed ) || '' === $sealed ) {
			return null;
		}
		try {
			$secret = Secret_Box::open( $sealed, self::CONTEXT );
		} catch ( \RuntimeException $e ) {
			return null;
		}
		return '' === $secret ? null : $secret;
	}

	/**
	 * Generate and store a secret (32 random bytes, base64url) when there is
	 * no constant and no usable stored secret.
	 *
	 * @return void
	 */
	public static function generate(): void {
		if ( null !== self::get() ) {
			return;
		}
		$sealed = Secret_Box::seal( Tokens::random( 32 ), self::CONTEXT );
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $sealed, '', false );
			return;
		}
		update_option( self::OPTION, $sealed, false );
	}

	/**
	 * Lowercase hex SHA-256 of the secret, or null when there is none.
	 *
	 * @return string|null
	 */
	public static function sha256_hex(): ?string {
		$secret = self::get();
		return null === $secret ? null : hash( 'sha256', $secret );
	}

	/**
	 * Where the secret comes from.
	 *
	 * @return string|null `constant`, `option`, or null when there is none.
	 */
	public static function source(): ?string {
		if ( '' !== self::constant() ) {
			return 'constant';
		}
		return null === self::get() ? null : 'option';
	}

	/**
	 * Value of the constant, or '' when it is undefined or empty.
	 *
	 * @return string
	 */
	private static function constant(): string {
		return defined( self::CONSTANT ) ? (string) constant( self::CONSTANT ) : '';
	}
}
