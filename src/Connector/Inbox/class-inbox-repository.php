<?php
/**
 * Connector inbox storage.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Inbox;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table names come from Tables::name(); every value goes through prepare().

use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

/**
 * Statements received from the LRS (webhook, poll or backfill), de-duplicated by statement id.
 *
 * Status is one of pending, unmatched, processed or flagged.
 */
final class Inbox_Repository {

	const PENDING   = 'pending';
	const UNMATCHED = 'unmatched';
	const PROCESSED = 'processed';
	const FLAGGED   = 'flagged';

	const VOIDED = 'http://adlnet.gov/expapi/verbs/voided';

	const ADDED     = 'added';
	const DUPLICATE = 'duplicate';
	const INVALID   = 'invalid';
	const ERROR     = 'error';

	/**
	 * Store a statement. Statements without a valid id, actor IFI, verb or object are refused.
	 *
	 * @param array  $statement Statement as an associative array.
	 * @param string $source    One of webhook, poll or backfill.
	 *
	 * @return boolean True when a new row was stored, false for a duplicate, an invalid statement or a database error.
	 */
	public function add( array $statement, string $source ): bool {
		return self::ADDED === $this->store( $statement, $source );
	}

	/**
	 * Store a statement and say what happened.
	 *
	 * @param array  $statement Statement as an associative array.
	 * @param string $source    One of webhook, poll or backfill.
	 *
	 * @return string ADDED, DUPLICATE, INVALID or ERROR (the database refused the insert).
	 */
	public function store( array $statement, string $source ): string {
		global $wpdb;
		$table = Tables::name( 'conn_inbox' );

		$id     = $statement['id'] ?? null;
		$verb   = $statement['verb']['id'] ?? null;
		$object = $statement['object'] ?? null;
		$actor  = $statement['actor'] ?? null;
		if ( ! is_string( $id ) || ! Uuid::is_valid( $id ) || ! is_string( $verb ) || '' === $verb || strlen( $verb ) > 255 || ! is_array( $object ) || ! is_array( $actor ) ) {
			return self::INVALID;
		}
		$object_id = $object['id'] ?? null;
		if ( ! is_string( $object_id ) || '' === $object_id ) {
			return self::INVALID;
		}
		if ( 'StatementRef' === ( $object['objectType'] ?? null ) ) {
			if ( ! Uuid::is_valid( $object_id ) ) {
				return self::INVALID;
			}
			$object_id = strtolower( $object_id );
		}

		try {
			$actor_hash = Agent_Identity::from_array( $actor )->hash();
			$body       = Json::encode_exact( $statement );
		} catch ( Invalid_Input $e ) {
			return self::INVALID;
		}

		$registration = $statement['context']['registration'] ?? null;
		$registration = is_string( $registration ) && Uuid::is_valid( $registration ) ? Uuid::to_bin( $registration ) : Uuid::NIL_BIN;

		$now    = Timestamp::to_db( Clock::now() );
		$stored = $now;
		if ( is_string( $statement['stored'] ?? null ) ) {
			try {
				$stored = Timestamp::to_db( Timestamp::parse( $statement['stored'], '1.0.3' ) );
			} catch ( Invalid_Input $e ) {
				$stored = $now;
			}
		}

		$n = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$table}` (statement_id, registration, actor_hash, verb_id, object_id, `stored`, body, user_id, status, source, received_at, processed_at)
				VALUES (%s, %s, %s, %s, %s, %s, %s, NULL, %s, %s, %s, NULL)",
				Uuid::to_bin( $id ),
				$registration,
				$actor_hash,
				$verb,
				$object_id,
				$stored,
				$body,
				self::PENDING,
				substr( sanitize_key( $source ), 0, 8 ),
				$now
			)
		);
		if ( false === $n ) {
			return self::ERROR;
		}
		return 1 === $n ? self::ADDED : self::DUPLICATE;
	}

	/**
	 * Pending rows, oldest stored first.
	 *
	 * @param integer $limit Maximum rows.
	 *
	 * @return array[]
	 */
	public function pending( int $limit ): array {
		global $wpdb;
		$table = Tables::name( 'conn_inbox' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE status = %s ORDER BY `stored`, received_at, statement_id LIMIT %d",
				self::PENDING,
				max( 1, $limit )
			),
			ARRAY_A
		);
		return self::shape_all( $rows );
	}

	/**
	 * Pending rows of one registration, oldest stored first.
	 *
	 * @param string  $registration Registration UUID.
	 * @param integer $limit        Maximum rows.
	 *
	 * @return array[]
	 */
	public function pending_for_registration( string $registration, int $limit ): array {
		global $wpdb;
		$table = Tables::name( 'conn_inbox' );
		if ( ! Uuid::is_valid( $registration ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE registration = %s AND status = %s ORDER BY `stored`, received_at, statement_id LIMIT %d",
				Uuid::to_bin( $registration ),
				self::PENDING,
				max( 1, $limit )
			),
			ARRAY_A
		);
		return self::shape_all( $rows );
	}

	/**
	 * Every row of a registration, whatever its status, in stored order.
	 *
	 * @param string $registration Registration UUID.
	 *
	 * @return array[]
	 */
	public function for_registration( string $registration ): array {
		global $wpdb;
		$table = Tables::name( 'conn_inbox' );
		if ( ! Uuid::is_valid( $registration ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE registration = %s ORDER BY `stored`, received_at, statement_id",
				Uuid::to_bin( $registration )
			),
			ARRAY_A
		);
		return self::shape_all( $rows );
	}

	/**
	 * Set a row's status, and its user when given. Leaving pending stamps processed_at.
	 *
	 * @param string       $id     Statement UUID.
	 * @param string       $status New status.
	 * @param integer|null $user   Matched user id.
	 *
	 * @return void
	 */
	public function mark( string $id, string $status, ?int $user = null ): void {
		global $wpdb;
		$table = Tables::name( 'conn_inbox' );
		if ( ! Uuid::is_valid( $id ) ) {
			return;
		}
		$at = self::PENDING === $status ? null : Timestamp::to_db( Clock::now() );
		if ( null === $user ) {
			$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET status = %s, processed_at = NULLIF(%s, '') WHERE statement_id = %s", $status, (string) $at, Uuid::to_bin( $id ) ) );
			return;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET status = %s, user_id = %d, processed_at = NULLIF(%s, '') WHERE statement_id = %s", $status, $user, (string) $at, Uuid::to_bin( $id ) ) );
	}

	/**
	 * Ids of this registration's statements that a stored voiding statement targets.
	 *
	 * @param string $registration Registration UUID.
	 *
	 * @return string[]
	 */
	public function voided_ids( string $registration ): array {
		global $wpdb;
		$table = Tables::name( 'conn_inbox' );
		if ( ! Uuid::is_valid( $registration ) ) {
			return array();
		}
		$ids = array_column( $this->for_registration( $registration ), 'statement_id' );
		if ( array() === $ids ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
		$voided       = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT object_id FROM `{$table}` WHERE verb_id = %s AND object_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...array_merge( array( self::VOIDED ), $ids )
			)
		);
		return array_values( array_intersect( $ids, (array) $voided ) );
	}

	/**
	 * Put an actor's unmatched rows back to pending, e.g. after a new identity link.
	 *
	 * @param string $actor_hash Raw SHA-1 actor hash.
	 *
	 * @return integer Rows changed.
	 */
	public function rematch_actor( string $actor_hash ): int {
		global $wpdb;
		$table = Tables::name( 'conn_inbox' );
		$n     = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = %s, processed_at = NULL WHERE actor_hash = %s AND status = %s",
				self::PENDING,
				$actor_hash,
				self::UNMATCHED
			)
		);
		return false === $n ? 0 : (int) $n;
	}

	/**
	 * Drop unmatched rows received more than `$days` days ago.
	 *
	 * @param integer $days Age in days.
	 *
	 * @return integer Rows deleted.
	 */
	public function prune_unmatched( int $days = 30 ): int {
		global $wpdb;
		$table  = Tables::name( 'conn_inbox' );
		$cutoff = Timestamp::to_db( Clock::now()->modify( '-' . max( 0, $days ) . ' days' ) );
		$n      = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE status = %s AND received_at < %s",
				self::UNMATCHED,
				$cutoff
			)
		);
		return false === $n ? 0 : (int) $n;
	}

	/**
	 * Every row matched to a user, in stored order.
	 *
	 * @param integer $user User id.
	 *
	 * @return array[]
	 */
	public function for_user( int $user ): array {
		global $wpdb;
		$table = Tables::name( 'conn_inbox' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY `stored`, statement_id", $user ),
			ARRAY_A
		);
		return self::shape_all( $rows );
	}

	/**
	 * Delete every row matched to a user, and every row (matched or not) about the user's actors:
	 * the site-issued account and the explicitly linked identities. Call it before the identity
	 * links are removed.
	 *
	 * @param integer $user User id.
	 *
	 * @return integer Rows deleted.
	 */
	public function delete_user( int $user ): int {
		global $wpdb;
		$table  = Tables::name( 'conn_inbox' );
		$hashes = array();
		$name   = (string) get_user_meta( $user, Identity_Map::META, true );
		$actors = array();
		if ( '' !== $name ) {
			$actors[] = array(
				'account' => array(
					'homePage' => ( new Identity_Map() )->home_page(),
					'name'     => $name,
				),
			);
		}
		foreach ( ( new Identity_Map() )->links_for_user( $user ) as $link ) {
			$actors[] = is_array( $link['actor'] ) ? $link['actor'] : array();
		}
		foreach ( $actors as $actor ) {
			try {
				$hashes[] = Agent_Identity::from_array( $actor )->hash();
			} catch ( Invalid_Input $e ) {
				continue;
			}
		}

		if ( array() === $hashes ) {
			$n = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE user_id = %d", $user ) );
			return false === $n ? 0 : (int) $n;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );
		$n            = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE user_id = %d OR actor_hash IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...array_merge( array( $user ), $hashes )
			)
		);
		return false === $n ? 0 : (int) $n;
	}

	/**
	 * Convert database rows.
	 *
	 * @param array|null $rows Rows.
	 *
	 * @return array[]
	 */
	private static function shape_all( ?array $rows ): array {
		return array_map( array( self::class, 'shape' ), $rows ? $rows : array() );
	}

	/**
	 * One row in its public shape.
	 *
	 * @param array $row Database row.
	 *
	 * @return array{statement_id:string,registration:string,actor_hash:string,verb_id:string,object_id:string,stored:string,statement:array,user_id:?int,status:string,source:string,received_at:string,processed_at:?string}
	 */
	private static function shape( array $row ): array {
		$statement = json_decode( (string) $row['body'], true );
		return array(
			'statement_id' => Uuid::from_bin( (string) $row['statement_id'] ),
			'registration' => Uuid::NIL_BIN === $row['registration'] ? '' : Uuid::from_bin( (string) $row['registration'] ),
			'actor_hash'   => (string) $row['actor_hash'],
			'verb_id'      => (string) $row['verb_id'],
			'object_id'    => (string) $row['object_id'],
			'stored'       => (string) $row['stored'],
			'statement'    => is_array( $statement ) ? $statement : array(),
			'user_id'      => null === $row['user_id'] ? null : (int) $row['user_id'],
			'status'       => (string) $row['status'],
			'source'       => (string) $row['source'],
			'received_at'  => (string) $row['received_at'],
			'processed_at' => null === $row['processed_at'] ? null : (string) $row['processed_at'],
		);
	}
}
