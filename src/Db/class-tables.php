<?php
/**
 * Table names.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Db;

/**
 * Maps a short table name to its full, prefixed name.
 */
final class Tables {

	/**
	 * Full table name, e.g. `wp_lledger_statements` for `statements`.
	 *
	 * @param string $short Short name as used in Schema::tables().
	 *
	 * @return string
	 */
	public static function name( string $short ): string {
		global $wpdb;
		return $wpdb->prefix . 'lledger_' . $short;
	}
}
