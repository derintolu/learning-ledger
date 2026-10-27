<?php
/**
 * Outbox table.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Outbox;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table names come from Tables::name(); the outbox is not cached.

use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

/**
 * Rows of `lledger_conn_outbox`: statements waiting to be PUT to the LRS.
 * Status `pending` (due at `next_attempt_at`), `sent`, `conflict` or `failed`.
 */
final class Outbox_Repository {

	const PENDING  = 'pending';
	const SENT     = 'sent';
	const CONFLICT = 'conflict';
	const FAILED   = 'failed';

	/**
	 * Queue a statement with one INSERT. A statement id already queued is kept as it is.
	 *
	 * @param array   $statement Statement with an `id`.
	 * @param integer $course_id LMS course id.
	 *
	 * @return boolean Whether a row was added.
	 */
	public function add( array $statement, int $course_id ): bool {
		global $wpdb;
		$table = Tables::name( 'conn_outbox' );
		$now   = Timestamp::to_db( Clock::now() );
		$n     = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (statement_id, course_id, body, status, attempts, next_attempt_at, created_at) VALUES (UNHEX(%s), %d, %s, %s, 0, %s, %s)",
				bin2hex( Uuid::to_bin( (string) $statement['id'] ) ),
				$course_id,
				Json::encode_exact( $statement ),
				self::PENDING,
				$now,
				$now
			)
		);
		return 1 === $n;
	}

	/**
	 * Pending rows that are due, oldest first.
	 *
	 * @param integer $limit Most rows.
	 *
	 * @return object[] Rows with `id`, `statement_id` (UUID text), `body`, `attempts`, `next_attempt_at`, `created_at`.
	 */
	public function due( int $limit ): array {
		global $wpdb;
		$table = Tables::name( 'conn_outbox' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, statement_id, course_id, body, attempts, next_attempt_at, created_at FROM {$table} WHERE status = %s AND next_attempt_at <= %s ORDER BY next_attempt_at, id LIMIT %d",
				self::PENDING,
				Timestamp::to_db( Clock::now() ),
				max( 1, $limit )
			)
		);
		foreach ( $rows as $row ) {
			$row->statement_id = Uuid::from_bin( $row->statement_id );
		}
		return $rows;
	}

	/**
	 * Hold a due row for this runner by moving its due time forward; false if another runner has it.
	 *
	 * @param object $row   Row from due().
	 * @param string $until Database time to hold it until.
	 *
	 * @return boolean
	 */
	public function claim( object $row, string $until ): bool {
		global $wpdb;
		$table = Tables::name( 'conn_outbox' );
		return 1 === $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET next_attempt_at = %s WHERE id = %d AND status = %s AND next_attempt_at = %s",
				$until,
				(int) $row->id,
				self::PENDING,
				(string) $row->next_attempt_at
			)
		);
	}

	/**
	 * Set a row's status, attempts and due time.
	 *
	 * @param integer $id       Row id.
	 * @param string  $status   Status.
	 * @param integer $attempts Attempts made.
	 * @param string  $next     Database time of the next attempt.
	 *
	 * @return void
	 */
	public function update( int $id, string $status, int $attempts, string $next ): void {
		global $wpdb;
		$wpdb->update(
			Tables::name( 'conn_outbox' ),
			array(
				'status'          => $status,
				'attempts'        => $attempts,
				'next_attempt_at' => $next,
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}
}
