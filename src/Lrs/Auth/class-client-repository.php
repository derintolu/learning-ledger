<?php
/**
 * Client storage.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table, cached in the object cache where it matters.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed; HTTP layers JSON-encode them.

/**
 * Reads and writes `lledger_clients`, cached in the object cache for 60 s and flushed on change.
 */
final class Client_Repository {

	const CACHE_GROUP = 'lledger_clients';
	const CACHE_TTL   = 60;

	/**
	 * Ids no client may take: the LRS's own authority name and the child-token username prefix.
	 */
	const RESERVED_ID = 'learning-ledger';

	/**
	 * Change keys accepted by update(), mapped to columns.
	 *
	 * @var array<string,string>
	 */
	const COLUMNS = array(
		'name'               => 'name',
		'secret_sha256_raw'  => 'secret_sha256',
		'scopes'             => 'scopes',
		'bindings'           => 'bindings',
		'cors_origins'       => 'cors_origins',
		'trusted'            => 'trusted_for_satisfaction',
		'rate_class'         => 'rate_class',
		'guard_enabled'      => 'guard_enabled',
		'reject_non_account' => 'reject_non_account',
		'expires_at'         => 'expires_at',
	);

	/**
	 * Find a client (revoked and expired ones included).
	 *
	 * @param string $id Client id.
	 *
	 * @return Client|null
	 */
	public function find( string $id ): ?Client {
		$cached = wp_cache_get( 'id:' . $id, self::CACHE_GROUP );
		if ( $cached instanceof Client ) {
			return $cached;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %s', Tables::name( 'clients' ), $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$client = self::hydrate( $row );
		wp_cache_set( 'id:' . $id, $client, self::CACHE_GROUP, self::CACHE_TTL );
		return $client;
	}

	/**
	 * Every client, revoked ones included, ordered by id.
	 *
	 * @return Client[]
	 */
	public function all(): array {
		$cached = wp_cache_get( 'all', self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', Tables::name( 'clients' ) ), ARRAY_A );
		$clients = array_map( array( self::class, 'hydrate' ), $rows ? $rows : array() );
		wp_cache_set( 'all', $clients, self::CACHE_GROUP, self::CACHE_TTL );
		return $clients;
	}

	/**
	 * Create a client.
	 *
	 * @param Client_Spec $s Spec.
	 *
	 * @return void
	 * @throws Invalid_Input On invalid fields, a reserved id or a duplicate id.
	 */
	public function create( Client_Spec $s ): void {
		if ( '' === $s->id || self::RESERVED_ID === $s->id || str_starts_with( strtolower( $s->id ), 'tok_' ) ) {
			throw new Invalid_Input( 'This client id is reserved.', 'reserved_id', 'id' );
		}
		$fields               = self::fields(
			array(
				'name'               => $s->name,
				'secret_sha256_raw'  => $s->secret_sha256_raw,
				'scopes'             => $s->scopes,
				'bindings'           => $s->bindings,
				'cors_origins'       => $s->cors_origins,
				'trusted'            => $s->trusted,
				'rate_class'         => $s->rate_class,
				'guard_enabled'      => $s->guard_enabled,
				'reject_non_account' => $s->reject_non_account,
				'expires_at'         => $s->expires_at,
			)
		);
		$fields['id']         = $s->id;
		$fields['created_at'] = Timestamp::to_db( Clock::now() );

		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( Tables::name( 'clients' ), $fields );
		$wpdb->suppress_errors( $suppress );
		if ( false === $ok ) {
			throw new Invalid_Input( 'A client with this id already exists.', 'duplicate_id', 'id' );
		}
		self::flush( $s->id );
	}

	/**
	 * Change fields of a client.
	 *
	 * @param string $id      Client id.
	 * @param array  $changes Keys as Client_Spec argument names (except id).
	 *
	 * @return void
	 * @throws Invalid_Input On unknown keys or invalid values.
	 */
	public function update( string $id, array $changes ): void {
		if ( array() === $changes ) {
			return;
		}
		global $wpdb;
		$wpdb->update( Tables::name( 'clients' ), self::fields( $changes ), array( 'id' => $id ) );
		self::flush( $id );
	}

	/**
	 * Revoke a client (its child tokens stop working with it).
	 *
	 * @param string $id Client id.
	 *
	 * @return void
	 */
	public function revoke( string $id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked_at = %s WHERE id = %s AND revoked_at IS NULL',
				Tables::name( 'clients' ),
				Timestamp::to_db( Clock::now() ),
				$id
			)
		);
		self::flush( $id );
	}

	/**
	 * Drop cached copies after a change.
	 *
	 * @param string $id Client id.
	 *
	 * @return void
	 */
	private static function flush( string $id ): void {
		wp_cache_delete( 'id:' . $id, self::CACHE_GROUP );
		wp_cache_delete( 'all', self::CACHE_GROUP );
		Origin_Index::flush();
	}

	/**
	 * Validate changes and map them to column values.
	 *
	 * @param array $changes Keys from COLUMNS.
	 *
	 * @return array<string,mixed>
	 * @throws Invalid_Input On unknown keys or invalid values.
	 */
	private static function fields( array $changes ): array {
		$out = array();
		foreach ( $changes as $key => $value ) {
			if ( ! isset( self::COLUMNS[ $key ] ) ) {
				throw new Invalid_Input( 'Unknown client field.', 'unknown_property', (string) $key );
			}
			switch ( $key ) {
				case 'name':
				case 'rate_class':
					if ( ! is_string( $value ) || '' === $value ) {
						throw new Invalid_Input( 'Expected a non-empty string.', 'type', $key );
					}
					break;
				case 'secret_sha256_raw':
					if ( ! is_string( $value ) || 32 !== strlen( $value ) ) {
						throw new Invalid_Input( 'The secret hash must be 32 raw bytes.', 'type', $key );
					}
					break;
				case 'scopes':
					if ( ! is_array( $value ) ) {
						throw new Invalid_Input( 'Expected a list of scopes.', 'type', $key );
					}
					Scopes::validate( $value );
					$value = wp_json_encode( array_values( array_unique( $value ) ) );
					break;
				case 'bindings':
					if ( ! is_array( $value ) ) {
						throw new Invalid_Input( 'Expected bindings.', 'type', $key );
					}
					$value = wp_json_encode( (object) Bindings::from_array( $value )->to_array() );
					break;
				case 'cors_origins':
					if ( ! is_array( $value ) || array_filter( $value, static fn( $o ) => ! is_string( $o ) || '' === $o ) ) {
						throw new Invalid_Input( 'Expected a list of origins.', 'type', $key );
					}
					$value = wp_json_encode( array_values( array_unique( $value ) ) );
					break;
				case 'expires_at':
					if ( null !== $value && ! $value instanceof \DateTimeImmutable ) {
						throw new Invalid_Input( 'Expected a time or null.', 'type', $key );
					}
					$value = null === $value ? null : Timestamp::to_db( $value );
					break;
				default:
					$value = $value ? 1 : 0;
			}
			$out[ self::COLUMNS[ $key ] ] = $value;
		}
		return $out;
	}

	/**
	 * Row to Client.
	 *
	 * @param array $row Database row.
	 *
	 * @return Client
	 */
	private static function hydrate( array $row ): Client {
		return new Client(
			$row['id'],
			$row['name'],
			$row['secret_sha256'],
			(array) json_decode( $row['scopes'], true ),
			Bindings::from_array( (array) json_decode( $row['bindings'], true ) ),
			(array) json_decode( $row['cors_origins'], true ),
			(bool) $row['trusted_for_satisfaction'],
			$row['rate_class'],
			(bool) $row['guard_enabled'],
			(bool) $row['reject_non_account'],
			null === $row['expires_at'] ? null : Timestamp::from_db( $row['expires_at'] ),
			Timestamp::from_db( $row['created_at'] ),
			null === $row['revoked_at'] ? null : Timestamp::from_db( $row['revoked_at'] )
		);
	}
}
