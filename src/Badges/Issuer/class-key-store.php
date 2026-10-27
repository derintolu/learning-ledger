<?php
/**
 * Issuer key storage.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Issuer;

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Secret_Box;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;

/**
 * Ed25519 badge keys, from (in order of preference) a wp-config constant, a key
 * file outside the web root, or the keys table with each seed sealed by Secret_Box.
 * Keys never go in wp_options.
 */
final class Key_Store {

	const TIER_CONSTANT = 'constant';
	const TIER_FILE     = 'file';
	const TIER_DATABASE = 'database';

	/**
	 * Purpose column value for these keys.
	 */
	const PURPOSE = 'badge';

	/**
	 * Secret_Box context for sealed seeds.
	 */
	const SEAL_CONTEXT = 'badge-key';

	/**
	 * Allowed key id characters (it appears in a DID URL fragment).
	 */
	private const KID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

	/**
	 * Where keys are stored.
	 *
	 * @return string `constant`, `file` or `database`.
	 */
	public static function tier(): string {
		if ( '' !== self::constant_value() ) {
			return self::TIER_CONSTANT;
		}
		if ( null !== self::key_file() ) {
			return self::TIER_FILE;
		}
		return self::TIER_DATABASE;
	}

	/**
	 * The key new badges are signed with.
	 *
	 * @return Issuer_Key|null
	 */
	public static function active(): ?Issuer_Key {
		$active = null;
		foreach ( self::published() as $key ) {
			if ( null === $key->retired_at ) {
				$active = $key;
			}
		}
		return $active;
	}

	/**
	 * Every key that has not been removed, oldest first. All of them stay in did.json.
	 *
	 * @return Issuer_Key[]
	 */
	public static function published(): array {
		switch ( self::tier() ) {
			case self::TIER_CONSTANT:
				return array( self::constant_key() );
			case self::TIER_FILE:
				return self::file_keys();
			default:
				return self::database_keys();
		}
	}

	/**
	 * A published key by id. Removed keys are not found.
	 *
	 * @param string $kid Key id.
	 *
	 * @return Issuer_Key|null
	 */
	public static function find( string $kid ): ?Issuer_Key {
		foreach ( self::published() as $key ) {
			if ( hash_equals( $key->kid, $kid ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Create the first key. Does nothing when an active key exists.
	 *
	 * @return Issuer_Key
	 *
	 * @throws \RuntimeException When the tier is managed outside WordPress and holds no active key.
	 */
	public static function generate(): Issuer_Key {
		$active = self::active();
		if ( null !== $active ) {
			return $active;
		}
		self::assert_managed();
		$key = self::insert_new();
		Audit_Log::record(
			'badge_key_generated',
			array(
				'kid'  => $key->kid,
				'tier' => self::TIER_DATABASE,
			),
			self::audit_actor()
		);
		return $key;
	}

	/**
	 * Add a new active key and retire the previous one, which stays published.
	 *
	 * @return Issuer_Key The new active key.
	 */
	public static function rotate(): Issuer_Key {
		self::assert_managed();
		$previous = array();
		foreach ( self::published() as $key ) {
			if ( null === $key->retired_at ) {
				$previous[] = $key->kid;
			}
		}
		$key = self::insert_new();
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . Tables::name( 'keys' ) . ' SET retired_at = %s WHERE purpose = %s AND kid <> %s AND retired_at IS NULL AND removed_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
				self::now(),
				self::PURPOSE,
				$key->kid
			)
		);
		Audit_Log::record(
			'badge_key_rotated',
			array(
				'kid'     => $key->kid,
				'retired' => $previous,
			),
			self::audit_actor()
		);
		return $key;
	}

	/**
	 * Remove a compromised key: it leaves did.json, so badges signed with it stop verifying.
	 *
	 * @param string $kid Key id.
	 *
	 * @return void
	 *
	 * @throws Invalid_Input When no published key has this id.
	 */
	public static function remove( string $kid ): void {
		self::assert_managed();
		if ( null === self::find( $kid ) ) {
			throw new Invalid_Input( 'No published badge key has this id.', 'not_found', 'kid' );
		}
		$now = self::now();
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . Tables::name( 'keys' ) . ' SET removed_at = %s, retired_at = COALESCE(retired_at, %s) WHERE purpose = %s AND kid = %s AND removed_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
				$now,
				$now,
				self::PURPOSE,
				$kid
			)
		);
		Audit_Log::record( 'badge_key_removed', array( 'kid' => $kid ), self::audit_actor() );
	}

	/**
	 * Insert a fresh key with its seed sealed.
	 *
	 * @return Issuer_Key
	 */
	private static function insert_new(): Issuer_Key {
		$seed = random_bytes( 32 );
		$pair = Ed25519::from_seed( $seed );
		$kid  = Ed25519::key_id( $pair['public'] );
		$now  = self::now();
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Tables::name( 'keys' ),
			array(
				'kid'           => $kid,
				'purpose'       => self::PURPOSE,
				'public_key'    => $pair['public'],
				'secret_sealed' => Secret_Box::seal( $seed, self::SEAL_CONTEXT ),
				'created_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $seed );
			sodium_memzero( $pair['secret'] );
		}
		return self::database_key( $kid, $pair['public'], $now, null );
	}

	/**
	 * Keys in the table, oldest first. The sealed seed is not selected here.
	 *
	 * @return Issuer_Key[]
	 */
	private static function database_keys(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT kid, public_key, created_at, retired_at FROM ' . Tables::name( 'keys' ) . ' WHERE purpose = %s AND removed_at IS NULL ORDER BY created_at ASC, kid ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
				self::PURPOSE
			)
		);
		$keys = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$keys[] = self::database_key( (string) $row->kid, (string) $row->public_key, $row->created_at, $row->retired_at );
		}
		return $keys;
	}

	/**
	 * Build a database-tier key.
	 *
	 * @param string      $kid        Key id.
	 * @param string      $public_raw Public key.
	 * @param string|null $created_at Created.
	 * @param string|null $retired_at Retired.
	 *
	 * @return Issuer_Key
	 */
	private static function database_key( string $kid, string $public_raw, ?string $created_at, ?string $retired_at ): Issuer_Key {
		return new Issuer_Key( $kid, $public_raw, $created_at, $retired_at, static fn(): string => self::database_secret( $kid ) );
	}

	/**
	 * Unseal a database key's secret.
	 *
	 * @param string $kid Key id.
	 *
	 * @return string 64-byte secret key.
	 *
	 * @throws \RuntimeException When the key is gone or its seed cannot be opened.
	 */
	private static function database_secret( string $kid ): string {
		global $wpdb;
		$sealed = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT secret_sealed FROM ' . Tables::name( 'keys' ) . ' WHERE purpose = %s AND kid = %s AND removed_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
				self::PURPOSE,
				$kid
			)
		);
		if ( ! is_string( $sealed ) || '' === $sealed ) {
			throw new \RuntimeException( 'Badge key secret is unavailable.' );
		}
		return self::secret_from_seed( Secret_Box::open( $sealed, self::SEAL_CONTEXT ) );
	}

	/**
	 * The key defined by LLEDGER_BADGE_KEY.
	 *
	 * @return Issuer_Key
	 */
	private static function constant_key(): Issuer_Key {
		$public_raw = Ed25519::from_seed( self::decode_seed( self::constant_value(), 'LLEDGER_BADGE_KEY' ) )['public'];
		return new Issuer_Key(
			Ed25519::key_id( $public_raw ),
			$public_raw,
			null,
			null,
			static fn(): string => self::secret_from_seed( self::decode_seed( self::constant_value(), 'LLEDGER_BADGE_KEY' ) )
		);
	}

	/**
	 * Keys listed in the key file.
	 *
	 * @return Issuer_Key[]
	 */
	private static function file_keys(): array {
		$keys = array();
		foreach ( self::file_entries() as $entry ) {
			$kid    = $entry['kid'];
			$keys[] = new Issuer_Key(
				$kid,
				Ed25519::from_seed( self::decode_seed( $entry['seed_b64'], 'LLEDGER_BADGE_KEY_FILE' ) )['public'],
				$entry['created_at'],
				$entry['retired_at'],
				static fn(): string => self::file_secret( $kid )
			);
		}
		return $keys;
	}

	/**
	 * Secret of a key in the key file, re-read from the file.
	 *
	 * @param string $kid Key id.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When the key is no longer in the file.
	 */
	private static function file_secret( string $kid ): string {
		foreach ( self::file_entries() as $entry ) {
			if ( hash_equals( $entry['kid'], $kid ) ) {
				return self::secret_from_seed( self::decode_seed( $entry['seed_b64'], 'LLEDGER_BADGE_KEY_FILE' ) );
			}
		}
		throw new \RuntimeException( 'Badge key secret is unavailable.' );
	}

	/**
	 * Parsed, validated key file entries.
	 *
	 * @return array<int, array{kid:string,seed_b64:string,created_at:?string,retired_at:?string}>
	 *
	 * @throws \RuntimeException When the file is not a JSON list of valid entries.
	 */
	private static function file_entries(): array {
		$path = self::key_file();
		$json = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local key file.
		$list = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
			throw new \RuntimeException( 'LLEDGER_BADGE_KEY_FILE must hold a JSON list of keys.' );
		}
		$entries = array();
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item['kid'] ?? null ) || 1 !== preg_match( self::KID_PATTERN, $item['kid'] ) || ! is_string( $item['seed_b64'] ?? null )
				|| ! self::is_optional_string( $item['created_at'] ?? null ) || ! self::is_optional_string( $item['retired_at'] ?? null ) ) {
				throw new \RuntimeException( 'LLEDGER_BADGE_KEY_FILE has an invalid entry.' );
			}
			$entries[] = array(
				'kid'        => $item['kid'],
				'seed_b64'   => $item['seed_b64'],
				'created_at' => $item['created_at'] ?? null,
				'retired_at' => $item['retired_at'] ?? null,
			);
		}
		return $entries;
	}

	/**
	 * Whether a value is a string or null.
	 *
	 * @param mixed $value Value.
	 *
	 * @return boolean
	 */
	private static function is_optional_string( mixed $value ): bool {
		return null === $value || is_string( $value );
	}

	/**
	 * Real path of the key file when it is readable, owner-only and outside ABSPATH.
	 *
	 * @return string|null
	 */
	private static function key_file(): ?string {
		$path = defined( 'LLEDGER_BADGE_KEY_FILE' ) ? (string) constant( 'LLEDGER_BADGE_KEY_FILE' ) : '';
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
	 * Value of LLEDGER_BADGE_KEY, or '' when undefined or empty.
	 *
	 * @return string
	 */
	private static function constant_value(): string {
		return defined( 'LLEDGER_BADGE_KEY' ) ? (string) constant( 'LLEDGER_BADGE_KEY' ) : '';
	}

	/**
	 * Decode a base64 32-byte seed.
	 *
	 * @param string $b64    Base64 seed.
	 * @param string $source Where it came from, for the error message.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When it is not base64 of 32 bytes.
	 */
	private static function decode_seed( string $b64, string $source ): string {
		$seed = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- key encoding.
		if ( false === $seed || 32 !== strlen( $seed ) ) {
			throw new \RuntimeException( esc_html( $source ) . ' must hold base64 of a 32-byte seed.' );
		}
		return $seed;
	}

	/**
	 * Ed25519 secret key from a seed.
	 *
	 * @param string $seed Seed of 32 bytes.
	 *
	 * @return string
	 */
	private static function secret_from_seed( string $seed ): string {
		return Ed25519::from_seed( $seed )['secret'];
	}

	/**
	 * Refuse changes when keys are managed outside WordPress.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException In the constant and file tiers.
	 */
	private static function assert_managed(): void {
		$tier = self::tier();
		if ( self::TIER_DATABASE !== $tier ) {
			throw new \RuntimeException( 'Badge keys in the ' . esc_html( $tier ) . ' tier are managed outside WordPress.' );
		}
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

	/**
	 * Current UTC time for DATETIME(6).
	 *
	 * @return string
	 */
	private static function now(): string {
		return Clock::now()->format( 'Y-m-d H:i:s.u' );
	}
}
