<?php
/**
 * Activation and deactivation.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Kernel;

use LearningLedger\Db\Installer;

/**
 * Plugin activation and deactivation hooks.
 */
final class Activation {

	/**
	 * Grant capabilities, install the schema, and schedule a rewrite flush.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Capabilities::install();
		Installer::install();
		Rewrites::schedule_flush();
	}

	/**
	 * Clear every `lledger_*` cron event and flush rewrite rules.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		$crons = _get_cron_array();
		$hooks = array();
		foreach ( is_array( $crons ) ? $crons : array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( 0 === strpos( (string) $hook, 'lledger_' ) ) {
					$hooks[ $hook ] = true;
				}
			}
		}
		foreach ( array_keys( $hooks ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		flush_rewrite_rules( false );
	}
}
