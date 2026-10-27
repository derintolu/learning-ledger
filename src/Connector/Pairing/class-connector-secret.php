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

	const UNREADABLE         = 'unreadable';
	const UNREADABLE_MESSAGE = 'The stored connector secret cannot be opened; re-run with --regenerate-secret to replace it and re-register its hash on the LRS.';

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
	 * no constant and no stored secret. A stored secret that cannot be opened
	 * is replaced only when asked to.
	 *
	 * @param boolean $replace_unreadable Replace a stored secret that cannot be opened.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the stored secret cannot be opened and replacing it was not asked for.
	 */
	public static function generate( bool $replace_unreadable = false ): void {
		if ( null !== self::get() ) {
			return;
		}
		if ( self::is_unreadable() && ! $replace_unreadable ) {
			throw new \RuntimeException( self::UNREADABLE_MESSAGE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constant message.
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
	 * @return string|null `constant`, `option`, `unreadable` (stored but cannot be opened), or null when there is none.
	 */
	public static function source(): ?string {
		if ( '' !== self::constant() ) {
			return 'constant';
		}
		if ( null !== self::get() ) {
			return 'option';
		}
		return self::is_unreadable() ? self::UNREADABLE : null;
	}

	/**
	 * Whether an option secret is stored but cannot be opened.
	 *
	 * @return boolean
	 */
	private static function is_unreadable(): bool {
		$sealed = get_option( self::OPTION );
		return false !== $sealed && '' !== $sealed && null === self::get();
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
