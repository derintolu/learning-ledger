<?php
/**
 * Bitstring Status Lists for badge revocation.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Status;

use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Badges\Signing\Eddsa_Rdfc_2022;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;

/**
 * Allocates status list indexes and keeps each list's signed
 * `BitstringStatusListCredential` (statusPurpose `revocation`) current.
 *
 * Indexes are handed out in a random order: the n-th allocation in a list gets
 * P(n), where P is a keyed pseudo-random permutation of the list's indexes, so
 * every index is used at most once and an index says nothing about issue order.
 * The counter `used` is taken with one atomic UPDATE, so concurrent requests
 * never share an allocation.
 */
final class Status_List_Repository {

	const PURPOSE = 'revocation';

	/**
	 * Verifier cache lifetime in milliseconds; the route's max-age matches it.
	 */
	const TTL_MS = 300000;

	/**
	 * Option prefix for each list's permutation key (followed by the list id).
	 */
	const KEY_OPTION_PREFIX = 'lledger_status_list_key_';

	/**
	 * Feistel rounds of the index permutation.
	 */
	private const ROUNDS = 8;

	/**
	 * Seconds to wait for a list lock.
	 */
	private const LOCK_TIMEOUT = 10;

	/**
	 * Entries in each new list.
	 *
	 * @var int
	 */
	private int $size;

	/**
	 * Constructor.
	 *
	 * @param integer $size Entries in each new list (a positive multiple of 8). Only tests pass a smaller size.
	 *
	 * @throws Invalid_Input On a size that is not a positive multiple of 8 within Bitstring::SIZE.
	 */
	public function __construct( int $size = Bitstring::SIZE ) {
		if ( $size < 8 || $size > Bitstring::SIZE || 0 !== $size % 8 ) {
			throw new Invalid_Input( 'Status list size must be a positive multiple of 8.', 'invalid', 'size' );
		}
		$this->size = $size;
	}

	/**
	 * Allocate an unused index. A new list starts once the newest one is 80% used.
	 *
	 * @return array{list_id:int,index:int}
	 *
	 * @throws \RuntimeException When no badge key is active or the database write fails.
	 */
	public function allocate(): array {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$list = $this->current();
			if ( null !== $list ) {
				$seq = $this->take( (int) $list['id'] );
				if ( null !== $seq ) {
					return array(
						'list_id' => (int) $list['id'],
						'index'   => self::permute( $seq, (int) $list['size'], $this->permutation_key( (int) $list['id'] ) ),
					);
				}
			}
			$this->create();
		}
		throw new \RuntimeException( 'Could not allocate a status list index.' );
	}

	/**
	 * Mark an index revoked and re-sign the list.
	 *
	 * @param integer $list_id List id.
	 * @param integer $index   Index.
	 *
	 * @return void
	 *
	 * @throws Invalid_Input When the list does not exist or the index is outside it.
	 * @throws \RuntimeException When the list cannot be locked, signed or written.
	 */
	public function set_revoked( int $list_id, int $index ): void {
		global $wpdb;
		$this->with_lock(
			'list_' . $list_id,
			function () use ( $wpdb, $list_id, $index ): void {
				$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare( "SELECT size, bits FROM %i WHERE id = %d AND purpose = %s AND credential <> ''", Tables::name( 'status_lists' ), $list_id, self::PURPOSE ),
					ARRAY_A
				);
				if ( ! is_array( $row ) ) {
					throw new Invalid_Input( 'No such status list.', 'not_found', 'list_id' );
				}
				$bits = (string) $row['bits'];
				if ( Bitstring::get( $bits, $index ) ) {
					return;
				}
				$bits    = Bitstring::set( $bits, $index, true );
				$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						'UPDATE %i SET bits = UNHEX(%s), credential = %s, updated = %s WHERE id = %d',
						Tables::name( 'status_lists' ),
						bin2hex( $bits ),
						$this->signed_json( $list_id, $bits ),
						self::now(),
						$list_id
					)
				);
				if ( 1 !== $updated ) {
					throw new \RuntimeException( 'Could not write the status list.' );
				}
			}
		);
	}

	/**
	 * Re-sign a list with the active key, unless that key already signed it. Takes the same
	 * lock as set_revoked() and signs the stored bits, so no revocation is lost.
	 *
	 * @param integer $list_id List id.
	 *
	 * @return boolean Whether the list was re-signed.
	 *
	 * @throws Invalid_Input When the list does not exist.
	 * @throws \RuntimeException When no badge key is active, or the list cannot be locked, signed or written.
	 */
	public function resign( int $list_id ): bool {
		$key = Key_Store::active();
		if ( null === $key ) {
			throw new \RuntimeException( 'No active badge key to sign a status list with.' );
		}
		$vm       = Did_Document::verification_method_for( $key );
		$resigned = false;
		global $wpdb;
		$this->with_lock(
			'list_' . $list_id,
			function () use ( $wpdb, $list_id, $vm, &$resigned ): void {
				$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare( "SELECT bits, credential FROM %i WHERE id = %d AND purpose = %s AND credential <> ''", Tables::name( 'status_lists' ), $list_id, self::PURPOSE ),
					ARRAY_A
				);
				if ( ! is_array( $row ) ) {
					throw new Invalid_Input( 'No such status list.', 'not_found', 'list_id' );
				}
				$credential = json_decode( (string) $row['credential'], true );
				if ( ( $credential['proof']['verificationMethod'] ?? null ) === $vm ) {
					return;
				}
				$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						'UPDATE %i SET credential = %s, updated = %s WHERE id = %d',
						Tables::name( 'status_lists' ),
						$this->signed_json( $list_id, (string) $row['bits'] ),
						self::now(),
						$list_id
					)
				);
				if ( 1 !== $updated ) {
					throw new \RuntimeException( 'Could not write the status list.' );
				}
				$resigned = true;
			}
		);
		return $resigned;
	}

	/**
	 * The list's signed BitstringStatusListCredential.
	 *
	 * @param integer $list_id List id.
	 *
	 * @return array
	 *
	 * @throws Invalid_Input When the list does not exist.
	 */
	public function credential( int $list_id ): array {
		global $wpdb;
		$json       = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT credential FROM %i WHERE id = %d AND purpose = %s AND credential <> ''", Tables::name( 'status_lists' ), $list_id, self::PURPOSE )
		);
		$credential = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $credential ) ) {
			throw new Invalid_Input( 'No such status list.', 'not_found', 'list_id' );
		}
		return $credential;
	}

	/**
	 * The BitstringStatusListEntry a credential carries as its `credentialStatus`.
	 *
	 * @param integer $list_id List id.
	 * @param integer $index   Index.
	 *
	 * @return array
	 */
	public function entry( int $list_id, int $index ): array {
		$url = self::url( $list_id );
		return array(
			'id'                   => $url . '#' . $index,
			'type'                 => 'BitstringStatusListEntry',
			'statusPurpose'        => self::PURPOSE,
			'statusListIndex'      => (string) $index,
			'statusListCredential' => $url,
		);
	}

	/**
	 * Public URL of a list credential.
	 *
	 * @param integer $list_id List id.
	 *
	 * @return string
	 */
	public static function url( int $list_id ): string {
		return rest_url( 'learning-ledger/v1/status-lists/' . $list_id );
	}

	/**
	 * The newest signed list, or null when there is none.
	 *
	 * @return array{id:string,size:string,used:string}|null
	 */
	private function current(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id, size, used FROM %i WHERE purpose = %s AND credential <> '' ORDER BY id DESC LIMIT 1", Tables::name( 'status_lists' ), self::PURPOSE ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Atomically take the next allocation, if it keeps the list at or under 80% used.
	 *
	 * @param integer $list_id List id.
	 *
	 * @return integer|null Zero-based allocation number, or null when the list is full.
	 *
	 * @throws \RuntimeException When the update fails.
	 */
	private function take( int $list_id ): ?int {
		global $wpdb;
		// LAST_INSERT_ID(expr) hands this connection the value it wrote, so no re-read can race.
		$taken = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'UPDATE %i SET used = LAST_INSERT_ID(used + 1) WHERE id = %d AND (used + 1) * 5 <= size * 4', Tables::name( 'status_lists' ), $list_id )
		);
		if ( false === $taken ) {
			throw new \RuntimeException( 'Could not allocate a status list index.' );
		}
		if ( 1 !== $taken ) {
			return null;
		}
		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ) - 1; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Start a new signed list, unless another request already started one with room.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When no badge key is active or the database write fails.
	 */
	private function create(): void {
		global $wpdb;
		$this->with_lock(
			'new',
			function () use ( $wpdb ): void {
				$list = $this->current();
				if ( null !== $list && ( (int) $list['used'] + 1 ) * 5 <= (int) $list['size'] * 4 ) {
					return;
				}
				if ( null === Key_Store::active() ) {
					throw new \RuntimeException( 'No active badge key to sign a status list with.' );
				}
				$bits     = str_repeat( "\0", intdiv( $this->size, 8 ) );
				$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"INSERT INTO %i (purpose, size, used, bits, credential, updated) VALUES (%s, %d, 0, UNHEX(%s), '', %s)",
						Tables::name( 'status_lists' ),
						self::PURPOSE,
						$this->size,
						bin2hex( $bits ),
						self::now()
					)
				);
				$list_id  = (int) $wpdb->insert_id;
				if ( 1 !== $inserted || $list_id < 1 ) {
					throw new \RuntimeException( 'Could not create a status list.' );
				}
				try {
					if ( ! add_option( self::KEY_OPTION_PREFIX . $list_id, bin2hex( random_bytes( 32 ) ), '', false ) ) {
						throw new \RuntimeException( 'Could not store the status list key.' );
					}
					$signed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prepare( 'UPDATE %i SET credential = %s WHERE id = %d', Tables::name( 'status_lists' ), $this->signed_json( $list_id, $bits ), $list_id )
					);
					if ( 1 !== $signed ) {
						throw new \RuntimeException( 'Could not store the status list credential.' );
					}
				} catch ( \Throwable $e ) {
					$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Tables::name( 'status_lists' ), $list_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					delete_option( self::KEY_OPTION_PREFIX . $list_id );
					throw $e;
				}
			}
		);
	}

	/**
	 * Build and sign a list credential, as JSON.
	 *
	 * @param integer $list_id List id.
	 * @param string  $bits    Raw bitstring.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When no badge key is active or encoding fails.
	 */
	private function signed_json( int $list_id, string $bits ): string {
		$key = Key_Store::active();
		if ( null === $key ) {
			throw new \RuntimeException( 'No active badge key to sign a status list with.' );
		}
		$now        = Clock::now();
		$url        = self::url( $list_id );
		$credential = array(
			'@context'          => array( 'https://www.w3.org/ns/credentials/v2' ),
			'id'                => $url,
			'type'              => array( 'VerifiableCredential', 'BitstringStatusListCredential' ),
			'issuer'            => Did_Document::did(),
			'validFrom'         => $now->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'credentialSubject' => array(
				'id'            => $url . '#list',
				'type'          => 'BitstringStatusList',
				'statusPurpose' => self::PURPOSE,
				'encodedList'   => Bitstring::encode( $bits ),
				'ttl'           => self::TTL_MS,
			),
		);
		$signed     = Eddsa_Rdfc_2022::sign( $credential, Did_Document::verification_method_for( $key ), $key->secret(), $now );
		$json       = wp_json_encode( $signed, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'Could not encode the status list credential.' );
		}
		return $json;
	}

	/**
	 * A list's permutation key.
	 *
	 * @param integer $list_id List id.
	 *
	 * @return string Raw 32 bytes.
	 *
	 * @throws \RuntimeException When the key is missing (a new key would reuse indexes).
	 */
	private function permutation_key( int $list_id ): string {
		$hex = get_option( self::KEY_OPTION_PREFIX . $list_id );
		if ( ! is_string( $hex ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $hex ) ) {
			throw new \RuntimeException( 'The status list key is missing.' );
		}
		return (string) hex2bin( $hex );
	}

	/**
	 * Keyed permutation of [0, $size): a balanced Feistel network over the
	 * smallest even bit width covering $size, with cycle walking back into range.
	 *
	 * @param integer $n    Value in [0, $size).
	 * @param integer $size Domain size.
	 * @param string  $key  Permutation key.
	 *
	 * @return integer
	 */
	private static function permute( int $n, int $size, string $key ): int {
		$width = 2;
		while ( ( 1 << $width ) < $size ) {
			$width += 2;
		}
		$half = intdiv( $width, 2 );
		$mask = ( 1 << $half ) - 1;
		do {
			$left  = $n >> $half;
			$right = $n & $mask;
			for ( $round = 0; $round < self::ROUNDS; $round++ ) {
				$f     = unpack( 'N', hash_hmac( 'sha256', pack( 'NN', $round, $right ), $key, true ) )[1] & $mask;
				$next  = $left ^ $f;
				$left  = $right;
				$right = $next;
			}
			$n = ( $left << $half ) | $right;
		} while ( $n >= $size );
		return $n;
	}

	/**
	 * Run a callback holding a MySQL named lock. Named locks belong to the
	 * connection, not a transaction, so they do not commit a caller's transaction.
	 *
	 * @param string   $name     Lock name suffix.
	 * @param callable $callback Work to do.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the lock cannot be taken.
	 */
	private function with_lock( string $name, callable $callback ): void {
		global $wpdb;
		$lock = substr( $wpdb->prefix . 'lledger_status_' . $name, 0, 64 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw new \RuntimeException( 'Could not lock the status list.' );
		}
		try {
			$callback();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Current UTC time for DATETIME(6) columns.
	 *
	 * @return string
	 */
	private static function now(): string {
		return Clock::now()->format( 'Y-m-d H:i:s.u' );
	}
}
