<?php
/**
 * Authenticated symmetric encryption for secrets at rest.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Crypto;

use LearningLedger\Vendor\ParagonIE\Sodium\Compat as Sodium;

/**
 * XSalsa20-Poly1305 secretbox with a per-context key.
 */
final class Secret_Box {

	/**
	 * Seal a value.
	 *
	 * @param string $plain   Plaintext.
	 * @param string $context Context label the key is bound to.
	 *
	 * @return string Base64 of nonce followed by ciphertext.
	 */
	public static function seal( string $plain, string $context ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . Sodium::crypto_secretbox( $plain, $nonce, self::key( $context ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary transport encoding.
	}

	/**
	 * Open a sealed value.
	 *
	 * @param string $sealed  Output of seal().
	 * @param string $context Context label used when sealing.
	 *
	 * @return string Plaintext.
	 *
	 * @throws \RuntimeException When the value is malformed, tampered with, or sealed under another key.
	 */
	public static function open( string $sealed, string $context ): string {
		$raw = base64_decode( $sealed, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary transport encoding.
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			throw new \RuntimeException( 'Sealed value is malformed.' );
		}
		try {
			$plain = Sodium::crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key( $context )
			);
		} catch ( \SodiumException $e ) {
			$plain = false;
		}
		if ( ! is_string( $plain ) ) {
			throw new \RuntimeException( 'Sealed value could not be opened.' );
		}
		return $plain;
	}

	/**
	 * Where the root key comes from, for Site Health.
	 *
	 * @return string `constant` or `salts`.
	 */
	public static function key_source(): string {
		return '' !== self::constant_key() ? 'constant' : 'salts';
	}

	/**
	 * Per-context 32-byte key.
	 *
	 * The root is first reduced to 32 bytes because BLAKE2b keys are limited
	 * to 64 bytes and WordPress salts concatenated are longer.
	 *
	 * @param string $context Context label.
	 *
	 * @return string
	 */
	private static function key( string $context ): string {
		$root = self::constant_key();
		if ( '' === $root ) {
			$root = AUTH_KEY . SECURE_AUTH_KEY;
		}
		return Sodium::crypto_generichash( $context, Sodium::crypto_generichash( $root, '', 32 ), 32 );
	}

	/**
	 * Value of LLEDGER_ENCRYPTION_KEY, or '' when it is undefined or empty.
	 *
	 * @return string
	 */
	private static function constant_key(): string {
		return defined( 'LLEDGER_ENCRYPTION_KEY' ) ? (string) constant( 'LLEDGER_ENCRYPTION_KEY' ) : '';
	}
}
