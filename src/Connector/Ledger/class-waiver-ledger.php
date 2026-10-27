<?php
/**
 * Waiver ledger.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Ledger;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from Tables::name().

use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;

/**
 * Lessons a learner has waived, one row per user and lesson.
 */
final class Waiver_Ledger {

	/**
	 * Record a waiver. Recording the same user and lesson again replaces the row.
	 *
	 * @param integer $user         User ID.
	 * @param integer $lesson       Lesson post ID.
	 * @param string  $registration Registration UUID.
	 * @param string  $statement_id Waiving statement UUID.
	 * @param string  $reason       Reason.
	 * @param boolean $carried_over Whether the waiver was carried over from an earlier registration.
	 *
	 * @return void
	 */
	public function record( int $user, int $lesson, string $registration, string $statement_id, string $reason, bool $carried_over ): void {
		global $wpdb;
		$table = Tables::name( 'waivers' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"INSERT INTO {$table} (user_id, lesson_id, registration, statement_id, reason, carried_over, created_at)
				VALUES (%d, %d, UNHEX(%s), UNHEX(%s), %s, %d, %s)
				ON DUPLICATE KEY UPDATE registration = VALUES(registration), statement_id = VALUES(statement_id),
				reason = VALUES(reason), carried_over = VALUES(carried_over), created_at = VALUES(created_at)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder
				$user,
				$lesson,
				bin2hex( Uuid::to_bin( $registration ) ),
				bin2hex( Uuid::to_bin( $statement_id ) ),
				$reason,
				$carried_over ? 1 : 0,
				Clock::now()->format( 'Y-m-d H:i:s.u' )
			)
		);
	}

	/**
	 * One waiver.
	 *
	 * @param integer $user   User ID.
	 * @param integer $lesson Lesson post ID.
	 *
	 * @return array|null
	 */
	public function get( int $user, int $lesson ): ?array {
		global $wpdb;
		$table = Tables::name( 'waivers' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND lesson_id = %d", $user, $lesson ),
			ARRAY_A
		);
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Whether a lesson is waived for a user.
	 *
	 * @param integer $user   User ID.
	 * @param integer $lesson Lesson post ID.
	 *
	 * @return boolean
	 */
	public function is_waived( int $user, int $lesson ): bool {
		return null !== $this->get( $user, $lesson );
	}

	/**
	 * Remove a waiver (a lesson later completed for real loses its marker).
	 *
	 * @param integer $user   User ID.
	 * @param integer $lesson Lesson post ID.
	 *
	 * @return void
	 */
	public function clear( int $user, int $lesson ): void {
		global $wpdb;
		$wpdb->delete(
			Tables::name( 'waivers' ),
			array(
				'user_id'   => $user,
				'lesson_id' => $lesson,
			),
			array( '%d', '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Every waiver of a user, by lesson ID.
	 *
	 * @param integer $user User ID.
	 *
	 * @return array[]
	 */
	public function for_user( int $user ): array {
		global $wpdb;
		$table = Tables::name( 'waivers' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY lesson_id", $user ),
			ARRAY_A
		);
		return array_map( array( self::class, 'shape' ), $rows ? $rows : array() );
	}

	/**
	 * Delete every waiver of a user.
	 *
	 * @param integer $user User ID.
	 *
	 * @return integer Rows deleted.
	 */
	public function delete_user( int $user ): int {
		global $wpdb;
		$n = $wpdb->delete( Tables::name( 'waivers' ), array( 'user_id' => $user ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
			'lesson_id'    => (int) $row['lesson_id'],
			'registration' => Uuid::from_bin( $row['registration'] ),
			'statement_id' => Uuid::from_bin( $row['statement_id'] ),
			'reason'       => (string) $row['reason'],
			'carried_over' => (bool) (int) $row['carried_over'],
			'created_at'   => (string) $row['created_at'],
		);
	}
}
