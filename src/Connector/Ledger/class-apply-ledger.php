<?php
/**
 * Apply ledger.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Ledger;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from Tables::name().

use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;

/**
 * Records which statements the connector has applied to the LMS, so a replayed
 * statement is not applied twice and the LMS's own echo is not sent back.
 */
final class Apply_Ledger {

	/**
	 * Whether this user, object and status has been applied.
	 *
	 * @param integer    $user   User ID.
	 * @param Lms_Object $o      Object.
	 * @param string     $status Status.
	 *
	 * @return boolean
	 */
	public function has( int $user, Lms_Object $o, string $status ): bool {
		global $wpdb;
		$table = Tables::name( 'applied' );
		return null !== $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE user_id = %d AND object_kind = %s AND object_id = %d AND status = %s",
				$user,
				$o->kind,
				$o->id,
				$status
			)
		);
	}

	/**
	 * Record an applied statement. The first record for a primary key wins; repeats change nothing.
	 *
	 * @param integer      $user         User ID.
	 * @param Lms_Object   $o            Object.
	 * @param string       $status       Status.
	 * @param string       $statement_id Statement UUID.
	 * @param float|null   $score        Scaled score.
	 * @param boolean|null $success      Success.
	 *
	 * @return void
	 */
	public function record( int $user, Lms_Object $o, string $status, string $statement_id, ?float $score = null, ?bool $success = null ): void {
		global $wpdb;
		$table = Tables::name( 'applied' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (user_id, object_kind, object_id, status, statement_id, score_scaled, success, flagged, applied_at)
				VALUES (%d, %s, %d, %s, UNHEX(%s), NULLIF(%s, ''), NULLIF(%s, ''), 0, %s)",
				$user,
				$o->kind,
				$o->id,
				$status,
				bin2hex( Uuid::to_bin( $statement_id ) ),
				null === $score ? '' : (string) $score,
				null === $success ? '' : ( $success ? '1' : '0' ),
				Clock::now()->format( 'Y-m-d H:i:s.u' )
			)
		);
	}

	/**
	 * The latest recorded score for a lesson.
	 *
	 * @param integer $user   User ID.
	 * @param integer $lesson Lesson post ID.
	 *
	 * @return array{score_scaled:?float,success:?bool}|null
	 */
	public function score( int $user, int $lesson ): ?array {
		global $wpdb;
		$table = Tables::name( 'applied' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT score_scaled, success FROM {$table}
				WHERE user_id = %d AND object_kind = 'lesson' AND object_id = %d AND (score_scaled IS NOT NULL OR success IS NOT NULL)
				ORDER BY applied_at DESC LIMIT 1",
				$user,
				$lesson
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		return array(
			'score_scaled' => null === $row['score_scaled'] ? null : (float) $row['score_scaled'],
			'success'      => null === $row['success'] ? null : (bool) (int) $row['success'],
		);
	}

	/**
	 * Flag every row applied from a statement that was later voided.
	 *
	 * @param string $statement_id Statement UUID.
	 *
	 * @return integer Rows newly flagged.
	 */
	public function flag_void( string $statement_id ): int {
		global $wpdb;
		$table = Tables::name( 'applied' );
		$n     = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "UPDATE {$table} SET flagged = 1 WHERE statement_id = UNHEX(%s) AND flagged = 0", bin2hex( Uuid::to_bin( $statement_id ) ) )
		);
		return false === $n ? 0 : (int) $n;
	}

	/**
	 * Every flagged row.
	 *
	 * @return array[]
	 */
	public function flagged(): array {
		global $wpdb;
		$table = Tables::name( 'applied' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE flagged = 1 ORDER BY applied_at, user_id", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( self::class, 'shape' ), $rows ? $rows : array() );
	}

	/**
	 * Every row of a user.
	 *
	 * @param integer $user User ID.
	 *
	 * @return array[]
	 */
	public function for_user( int $user ): array {
		global $wpdb;
		$table = Tables::name( 'applied' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY applied_at, object_kind, object_id, status", $user ),
			ARRAY_A
		);
		return array_map( array( self::class, 'shape' ), $rows ? $rows : array() );
	}

	/**
	 * Delete every row of a user.
	 *
	 * @param integer $user User ID.
	 *
	 * @return integer Rows deleted.
	 */
	public function delete_user( int $user ): int {
		global $wpdb;
		$n = $wpdb->delete( Tables::name( 'applied' ), array( 'user_id' => $user ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false === $n ? 0 : (int) $n;
	}

	/**
	 * Convert a database row to the public shape.
	 *
	 * @param array $row Row.
	 *
	 * @return array
	 */
	private static function shape( array $row ): array {
		return array(
			'user_id'      => (int) $row['user_id'],
			'object_kind'  => (string) $row['object_kind'],
			'object_id'    => (int) $row['object_id'],
			'status'       => (string) $row['status'],
			'statement_id' => Uuid::from_bin( $row['statement_id'] ),
			'score_scaled' => null === $row['score_scaled'] ? null : (float) $row['score_scaled'],
			'success'      => null === $row['success'] ? null : (bool) (int) $row['success'],
			'flagged'      => (bool) (int) $row['flagged'],
			'applied_at'   => (string) $row['applied_at'],
		);
	}
}
