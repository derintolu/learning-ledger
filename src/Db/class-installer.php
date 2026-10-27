<?php
/**
 * Creates and upgrades the custom tables.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Db;

/**
 * Runs dbDelta over Schema::tables() and records the schema version.
 */
final class Installer {

	/**
	 * Option holding the installed schema version.
	 */
	public const OPTION = 'lledger_schema_version';

	/**
	 * Create or update every table, then store the schema version (not autoloaded).
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( array_values( Schema::tables() ) );
		update_option( self::OPTION, Schema::VERSION, false );
	}

	/**
	 * Install when the stored schema version is lower than Schema::VERSION. Hooked on `plugins_loaded`.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION, 0 ) < Schema::VERSION ) {
			self::install();
		}
	}
}
