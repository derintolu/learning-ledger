<?php
/**
 * RSA key storage for VC-JWT badges.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Jwt;

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Crypto\Secret_Box;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;

/**
 * The RSA-2048 key VC-JWT badges are signed with (RS256), from the same tiers as the Ed25519
 * badge key: a wp-config constant, a key file outside the web root, or the keys table (purpose
 * `badge-rsa`) with the private key PEM sealed by Secret_Box. Keys never go in wp_options.
 *
 * The key id is the RFC 7638 JWK thumbprint (SHA-256, base64url).
 */
final class Rsa_Key_Store {

	/**
	 * Purpose column value for these keys.
	 */
	const PURPOSE = 'badge-rsa';

	/**
	 * Secret_Box context for sealed private keys.
	 */
	const SEAL_CONTEXT = 'badge-rsa-key';

	/**
	 * Key size, in bits, of generated keys and the smallest accepted from a constant or file.
	 */
	const BITS = 2048;

	/**
	 * Where the RSA key is stored.
	 *
	 * @return string `constant`, `file` or `database` (the Key_Store tier names).
	 */
	public static function tier(): string {
		if ( '' !== self::constant_value() ) {
			return Key_Store::TIER_CONSTANT;
		}
		if ( null !== self::key_file() ) {
			return Key_Store::TIER_FILE;
		}
		return Key_Store::TIER_DATABASE;
	}

	/**
	 * The key new VC-JWT badges are signed with. In the database tier it is created on first use.
	 *
	 * Two requests that find no key at the same moment each create one and sign with their own.
	 * Both keys stay published, and later calls use the oldest unretired key.
	 *
	 * @return array{kid:string,private_pem:string,public_jwk:array{kty:string,n:string,e:string,alg:string,use:string}}
	 *
	 * @throws \RuntimeException When the configured key is not an RSA private key of at least 2048 bits, or storage fails.
	 */
	public static function active(): array {
		switch ( self::tier() ) {
			case Key_Store::TIER_CONSTANT:
				return self::from_private_pem( self::constant_value(), 'LLEDGER_BADGE_RSA_KEY' );
			case Key_Store::TIER_FILE:
				return self::from_private_pem( self::file_pem(), 'LLEDGER_BADGE_RSA_KEY_FILE' );
			default:
				return self::database_active() ?? self::generate();
		}
	}

	/**
	 * Every RSA key that has not been removed, oldest first, public halves only. Never creates a key.
	 * A stored key whose public half cannot be read is skipped (unreadable() reports it).
	 *
	 * @return array<int, array{kid:string,public_jwk:array}>
	 *
	 * @throws \RuntimeException When the constant or file key cannot be read.
	 */
	public static function published(): array {
		if ( Key_Store::TIER_DATABASE !== self::tier() ) {
			$key = self::active();
			return array(
				array(
					'kid'        => $key['kid'],
					'public_jwk' => $key['public_jwk'],
				),
			);
		}
		$keys = array();
		foreach ( self::database_rows() as $row ) {
			try {
				$jwk = self::jwk_from_public_pem( (string) $row->public_key );
			} catch ( \RuntimeException $e ) {
				continue;
			}
			$keys[] = array(
				'kid'        => (string) $row->kid,
				'public_jwk' => $jwk,
			);
		}
		return $keys;
	}

	/**
	 * Keys that cannot be read: stored keys whose public half does not parse, or whose sealed
	 * private key cannot be opened or does not match its key id; or, in the constant and file
	 * tiers, the tier name when that key is unusable. Nothing is created or printed.
	 *
	 * @return string[] Key ids (or a tier name).
	 */
	public static function unreadable(): array {
		if ( Key_Store::TIER_DATABASE !== self::tier() ) {
			try {
				self::active();
				return array();
			} catch ( \RuntimeException $e ) {
				return array( self::tier() );
			}
		}
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT kid, public_key, secret_sealed FROM ' . Tables::name( 'keys' ) . ' WHERE purpose = %s AND removed_at IS NULL ORDER BY created_at ASC, kid ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
				self::PURPOSE
			)
		);
		$bad  = array();
		foreach ( $rows ? $rows : array() as $row ) {
			try {
				$jwk     = self::jwk_from_public_pem( (string) $row->public_key );
				$private = self::from_private_pem( Secret_Box::open( (string) $row->secret_sealed, self::SEAL_CONTEXT ), 'stored key' );
				$usable  = hash_equals( (string) $row->kid, self::thumbprint( $jwk ) ) && hash_equals( (string) $row->kid, $private['kid'] );
			} catch ( \RuntimeException $e ) {
				$usable = false;
			}
			if ( ! $usable ) {
				$bad[] = (string) $row->kid;
			}
		}
		return $bad;
	}

	/**
	 * RFC 7638 thumbprint of an RSA JWK.
	 *
	 * @param array $jwk Public JWK with `n` and `e`.
	 *
	 * @return string Base64url SHA-256 (43 characters).
	 */
	public static function thumbprint( array $jwk ): string {
		$canonical = '{"e":"' . $jwk['e'] . '","kty":"RSA","n":"' . $jwk['n'] . '"}';
		return self::b64url( hash( 'sha256', $canonical, true ) );
	}

	/**
	 * Create and store a new key (database tier).
	 *
	 * @return array{kid:string,private_pem:string,public_jwk:array}
	 *
	 * @throws \RuntimeException When OpenSSL cannot create the key or the insert fails.
	 */
	private static function generate(): array {
		$res = openssl_pkey_new(
			array(
				'private_key_bits' => self::BITS,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$pem = '';
		if ( false === $res || ! openssl_pkey_export( $res, $pem ) ) {
			throw new \RuntimeException( 'OpenSSL could not create the RSA badge key.' );
		}
		$key        = self::from_private_pem( $pem, 'generated key' );
		$public_pem = (string) openssl_pkey_get_details( $res )['key'];
		global $wpdb;
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Tables::name( 'keys' ),
			array(
				'kid'           => $key['kid'],
				'purpose'       => self::PURPOSE,
				'public_key'    => $public_pem,
				'secret_sealed' => Secret_Box::seal( $pem, self::SEAL_CONTEXT ),
				'created_at'    => Clock::now()->format( 'Y-m-d H:i:s.u' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		if ( 1 !== $inserted ) {
			throw new \RuntimeException( 'Could not store the RSA badge key.' );
		}
		Audit_Log::record(
			'badge_rsa_key_generated',
			array(
				'kid'  => $key['kid'],
				'tier' => Key_Store::TIER_DATABASE,
			),
			self::audit_actor()
		);
		return $key;
	}

	/**
	 * The oldest unretired stored key, unsealed, or null when there is none.
	 *
	 * @return array{kid:string,private_pem:string,public_jwk:array}|null
	 *
	 * @throws \RuntimeException When the sealed key cannot be opened.
	 */
	private static function database_active(): ?array {
		global $wpdb;
		$sealed = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT secret_sealed FROM ' . Tables::name( 'keys' ) . ' WHERE purpose = %s AND retired_at IS NULL AND removed_at IS NULL ORDER BY created_at ASC, kid ASC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
				self::PURPOSE
			)
		);
		if ( null === $sealed ) {
			return null;
		}
		if ( ! is_string( $sealed ) || '' === $sealed ) {
			throw new \RuntimeException( 'RSA badge key secret is unavailable.' );
		}
		return self::from_private_pem( Secret_Box::open( $sealed, self::SEAL_CONTEXT ), 'stored key' );
	}

	/**
	 * Stored keys, oldest first. The sealed private key is not selected here.
	 *
	 * @return object[]
	 */
	private static function database_rows(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT kid, public_key FROM ' . Tables::name( 'keys' ) . ' WHERE purpose = %s AND removed_at IS NULL ORDER BY created_at ASC, kid ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
				self::PURPOSE
			)
		);
		return $rows ? $rows : array();
	}

	/**
	 * Key id, PEM and public JWK of an RSA private key.
	 *
	 * @param string $pem    Private key PEM.
	 * @param string $source Where it came from, for the error message.
	 *
	 * @return array{kid:string,private_pem:string,public_jwk:array}
	 *
	 * @throws \RuntimeException When it is not an RSA private key of at least 2048 bits.
	 */
	private static function from_private_pem( #[\SensitiveParameter] string $pem, string $source ): array {
		$res     = openssl_pkey_get_private( $pem );
		$details = false === $res ? false : openssl_pkey_get_details( $res );
		if ( false === $details || OPENSSL_KEYTYPE_RSA !== $details['type'] || $details['bits'] < self::BITS ) {
			throw new \RuntimeException( esc_html( $source ) . ' must hold an RSA private key of at least 2048 bits (PEM).' );
		}
		$jwk = self::jwk( $details['rsa']['n'], $details['rsa']['e'] );
		return array(
			'kid'         => self::thumbprint( $jwk ),
			'private_pem' => $pem,
			'public_jwk'  => $jwk,
		);
	}

	/**
	 * Public JWK of a stored public key PEM.
	 *
	 * @param string $public_pem Public key PEM.
	 *
	 * @return array
	 *
	 * @throws \RuntimeException When it is not an RSA public key.
	 */
	private static function jwk_from_public_pem( string $public_pem ): array {
		$res     = openssl_pkey_get_public( $public_pem );
		$details = false === $res ? false : openssl_pkey_get_details( $res );
		if ( false === $details || OPENSSL_KEYTYPE_RSA !== $details['type'] ) {
			throw new \RuntimeException( 'A stored RSA badge key is unreadable.' );
		}
		return self::jwk( $details['rsa']['n'], $details['rsa']['e'] );
	}

	/**
	 * Public RSA JWK. It never carries a private member.
	 *
	 * @param string $n Modulus (big-endian bytes).
	 * @param string $e Public exponent (big-endian bytes).
	 *
	 * @return array{kty:string,n:string,e:string,alg:string,use:string}
	 */
	private static function jwk( string $n, string $e ): array {
		return array(
			'kty' => 'RSA',
			'n'   => self::b64url( $n ),
			'e'   => self::b64url( $e ),
			'alg' => 'RS256',
			'use' => 'sig',
		);
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Bytes.
	 *
	 * @return string
	 */
	private static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWK encoding.
	}

	/**
	 * Private key PEM from the key file.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When the file cannot be read.
	 */
	private static function file_pem(): string {
		$path = self::key_file();
		$pem  = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local key file.
		if ( false === $pem ) {
			throw new \RuntimeException( 'LLEDGER_BADGE_RSA_KEY_FILE could not be read.' );
		}
		return $pem;
	}

	/**
	 * Real path of the key file when it is readable, owner-only and outside ABSPATH.
	 *
	 * @return string|null
	 */
	private static function key_file(): ?string {
		$path = defined( 'LLEDGER_BADGE_RSA_KEY_FILE' ) ? (string) constant( 'LLEDGER_BADGE_RSA_KEY_FILE' ) : '';
		if ( '' === $path ) {
			return null;
		}
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return null;
		}
		$perms = fileperms( $real );
		if ( false === $perms || 0 !== ( $perms & 0077 ) ) {
			return null;
		}
		$root = realpath( ABSPATH );
		$root = trailingslashit( wp_normalize_path( false === $root ? ABSPATH : $root ) );
		if ( str_starts_with( trailingslashit( wp_normalize_path( $real ) ), $root ) ) {
			return null;
		}
		return $real;
	}

	/**
	 * Value of LLEDGER_BADGE_RSA_KEY (a private key PEM), or '' when undefined or empty.
	 *
	 * @return string
	 */
	private static function constant_value(): string {
		return defined( 'LLEDGER_BADGE_RSA_KEY' ) ? (string) constant( 'LLEDGER_BADGE_RSA_KEY' ) : '';
	}

	/**
	 * Audit actor for the current request.
	 *
	 * @return string|null
	 */
	private static function audit_actor(): ?string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		$user = get_current_user_id();
		return $user ? 'user:' . $user : null;
	}
}
