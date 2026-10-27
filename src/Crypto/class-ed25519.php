<?php
/**
 * Ed25519 signatures with a native (ext-sodium) and a pure-PHP backend.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Crypto;

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Vendor\ParagonIE\Sodium\Core\Ed25519 as Compat_Ed25519;

/**
 * Detached Ed25519 signing and verification over raw byte strings.
 */
final class Ed25519 {

	const NATIVE = 'native';
	const COMPAT = 'compat';

	/**
	 * Backend forced by tests, or null for automatic selection.
	 *
	 * @var string|null
	 */
	private static ?string $forced = null;

	/**
	 * Active backend: `native` when ext-sodium is loaded, otherwise `compat`.
	 *
	 * @return string
	 */
	public static function backend(): string {
		if ( null !== self::$forced ) {
			return self::$forced;
		}
		return extension_loaded( 'sodium' ) ? self::NATIVE : self::COMPAT;
	}

	/**
	 * Force a backend (tests only). Null restores automatic selection.
	 *
	 * @param string|null $b Backend name or null.
	 *
	 * @return void
	 *
	 * @throws Invalid_Input On an unknown backend.
	 */
	public static function force_backend( ?string $b ): void {
		if ( null !== $b && self::NATIVE !== $b && self::COMPAT !== $b ) {
			throw new Invalid_Input( 'Unknown Ed25519 backend.', 'invalid' );
		}
		self::$forced = $b;
	}

	/**
	 * New random keypair.
	 *
	 * @return array{public:string,secret:string} Raw bytes; secret is 64 bytes.
	 */
	public static function keypair(): array {
		return self::from_seed( random_bytes( 32 ) );
	}

	/**
	 * Deterministic keypair from a 32-byte seed.
	 *
	 * @param string $seed32 Seed.
	 *
	 * @return array{public:string,secret:string}
	 *
	 * @throws Invalid_Input On a seed that is not 32 bytes.
	 */
	public static function from_seed( string $seed32 ): array {
		if ( 32 !== strlen( $seed32 ) ) {
			throw new Invalid_Input( 'Ed25519 seed must be 32 bytes.', 'invalid' );
		}
		if ( self::NATIVE === self::backend() ) {
			$kp = sodium_crypto_sign_seed_keypair( $seed32 );
			return array(
				'public' => sodium_crypto_sign_publickey( $kp ),
				'secret' => sodium_crypto_sign_secretkey( $kp ),
			);
		}
		$public = '';
		$secret = '';
		Compat_Ed25519::seed_keypair( $public, $secret, $seed32 );
		return array(
			'public' => $public,
			'secret' => $secret,
		);
	}

	/**
	 * Detached signature.
	 *
	 * @param string $msg    Message.
	 * @param string $secret Ed25519 secret key.
	 *
	 * @return string 64-byte signature.
	 *
	 * @throws Invalid_Input On a secret key that is not 64 bytes.
	 */
	public static function sign( string $msg, string $secret ): string {
		if ( 64 !== strlen( $secret ) ) {
			throw new Invalid_Input( 'Ed25519 secret key must be 64 bytes.', 'invalid' );
		}
		if ( self::NATIVE === self::backend() ) {
			return sodium_crypto_sign_detached( $msg, $secret );
		}
		return Compat_Ed25519::sign_detached( $msg, $secret );
	}

	/**
	 * Verify a detached signature. Malformed input yields false.
	 *
	 * @param string $sig    Raw 64-byte signature.
	 * @param string $msg    Message.
	 * @param string $public Raw 32-byte public key.
	 *
	 * @return boolean
	 */
	public static function verify( string $sig, string $msg, string $public ): bool {
		if ( 64 !== strlen( $sig ) || 32 !== strlen( $public ) ) {
			return false;
		}
		try {
			if ( self::NATIVE === self::backend() ) {
				return sodium_crypto_sign_verify_detached( $sig, $msg, $public );
			}
			return Compat_Ed25519::verify_detached( $sig, $msg, $public );
		} catch ( \SodiumException $e ) {
			return false;
		}
	}

	/**
	 * Key id: first 16 hex characters of SHA-256 of the public key.
	 *
	 * @param string $public Raw public key.
	 *
	 * @return string
	 */
	public static function key_id( string $public ): string {
		return substr( hash( 'sha256', $public ), 0, 16 );
	}
}
