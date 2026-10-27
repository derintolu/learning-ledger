<?php
/**
 * Uninstall handler. Keeps all records unless LLEDGER_UNINSTALL_DELETE_DATA is true.
 *
 * @package LearningLedger
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'LLEDGER_UNINSTALL_DELETE_DATA' ) || true !== LLEDGER_UNINSTALL_DELETE_DATA ) {
	return;
}

global $wpdb;

$lledger_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'lledger_' ) . '%' ) );
foreach ( $lledger_tables as $lledger_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $lledger_table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'lledger_' ) . '%'
	)
);
