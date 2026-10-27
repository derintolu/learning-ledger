<?php
/**
 * Database server version check for the LRS role.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Db;

/**
 * The LRS role needs MySQL 8.0+ or MariaDB 10.6+ (DATETIME(6), reliable InnoDB).
 */
final class Db_Requirements {

	public const MYSQL_MIN   = '8.0.0';
	public const MARIADB_MIN = '10.6.0';

	/**
	 * Check the database server.
	 *
	 * @return true|\WP_Error
	 */
	public static function check() {
		global $wpdb;
		$server = (string) apply_filters( 'learning_ledger/db_version_string', (string) $wpdb->db_server_info() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.

		$is_mariadb = false !== stripos( $server, 'mariadb' );
		// MariaDB may report itself behind the replication-protocol prefix "5.5.5-".
		$version = $is_mariadb ? preg_replace( '/^5\.5\.5-/', '', $server ) : $server;
		$minimum = $is_mariadb ? self::MARIADB_MIN : self::MYSQL_MIN;

		if ( preg_match( '/^(\d+\.\d+(?:\.\d+)?)/', $version, $m ) && version_compare( $m[1], $minimum, '>=' ) ) {
			return true;
		}

		return new \WP_Error(
			'lledger_db_version',
			sprintf(
				/* translators: %s: database server version string. */
				__( 'The Learning Ledger LRS role needs MySQL 8.0 or later, or MariaDB 10.6 or later. This database server reports version %s.', 'learning-ledger' ),
				$server
			)
		);
	}
}
