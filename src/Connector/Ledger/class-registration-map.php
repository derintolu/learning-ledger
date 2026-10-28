<?php
/**
 * Registration map.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Ledger;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from Tables::name().

use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;

/**
 * One registration UUID per user and scope. Scope keys: `course:<id>`, `cmi5:<program id>`,
 * `preview:<program id>:<user>`.
 */
final class Registration_Map {

	/**
	 * The user's current registration for a scope, minting one when there is none or it has ended.
	 *
	 * @param integer $user      User ID.
	 * @param string  $scope_key Scope key.
	 *
	 * @return string UUID v4.
	 */
	public function get_or_create( int $user, string $scope_key ): string {
		global $wpdb;
		$table = Tables::name( 'registrations' );
		$new   = Uuid::v4();
		$hex   = bin2hex( Uuid::to_bin( $new ) );
		$now   = Clock::now()->format( 'Y-m-d H:i:s.u' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (user_id, scope_key, registration, created_at, ended_at) VALUES (%d, %s, UNHEX(%s), %s, NULL)",
				$user,
				$scope_key,
				$hex,
				$now
			)
		);
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET registration = UNHEX(%s), created_at = %s, ended_at = NULL WHERE user_id = %d AND scope_key = %s AND ended_at IS NOT NULL",
				$hex,
				$now,
				$user,
				$scope_key
			)
		);
		return (string) $this->find( $user, $scope_key );
	}

	/**
	 * The user's current registration for a scope, if there is one that has not ended.
	 *
	 * @param integer $user      User ID.
	 * @param string  $scope_key Scope key.
	 *
	 * @return string|null
	 */
	public function find( int $user, string $scope_key ): ?string {
		global $wpdb;
		$table = Tables::name( 'registrations' );
		$bin   = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT registration FROM {$table} WHERE user_id = %d AND scope_key = %s AND ended_at IS NULL", $user, $scope_key )
		);
		return null === $bin ? null : Uuid::from_bin( $bin );
	}

	/**
	 * End the current registration; the next get_or_create mints a new one.
	 *
	 * @param integer $user      User ID.
	 * @param string  $scope_key Scope key.
	 *
	 * @return void
	 */
	public function end( int $user, string $scope_key ): void {
		global $wpdb;
		$table = Tables::name( 'registrations' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET ended_at = %s WHERE user_id = %d AND scope_key = %s AND ended_at IS NULL",
				Clock::now()->format( 'Y-m-d H:i:s.u' ),
				$user,
				$scope_key
			)
		);
	}

	/**
	 * The user a registration belongs to.
	 *
	 * @param string $registration Registration UUID.
	 *
	 * @return integer|null
	 */
	public function user_for( string $registration ): ?int {
		global $wpdb;
		$table = Tables::name( 'registrations' );
		$id    = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT user_id FROM {$table} WHERE registration = UNHEX(%s) LIMIT 1", bin2hex( Uuid::to_bin( $registration ) ) )
		);
		return null === $id ? null : (int) $id;
	}

	/**
	 * The scope key a registration belongs to.
	 *
	 * @param string $registration Registration UUID.
	 *
	 * @return string|null Null when the map does not hold it.
	 */
	public function scope_for( string $registration ): ?string {
		global $wpdb;
		if ( ! Uuid::is_valid( $registration ) ) {
			return null;
		}
		$table = Tables::name( 'registrations' );
		$scope = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT scope_key FROM {$table} WHERE registration = UNHEX(%s) LIMIT 1", bin2hex( Uuid::to_bin( $registration ) ) )
		);
		return null === $scope ? null : (string) $scope;
	}

	/**
	 * Delete every registration of a user.
	 *
	 * @param integer $user User ID.
	 *
	 * @return integer Rows deleted.
	 */
	public function delete_user( int $user ): int {
		global $wpdb;
		$n = $wpdb->delete( Tables::name( 'registrations' ), array( 'user_id' => $user ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false === $n ? 0 : (int) $n;
	}
}
