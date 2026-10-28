<?php
/**
 * Integration test bootstrap (WordPress test suite).
 *
 * @package LearningLedger
 */

$lledger_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $lledger_tests_dir ) {
	$lledger_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}
if ( ! $lledger_tests_dir ) {
	$lledger_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $lledger_tests_dir . '/includes/functions.php' ) ) {
	exit( 'WordPress test suite not found. Set WP_TESTS_DIR or WP_PHPUNIT__DIR.' . PHP_EOL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExitOutputNotEscaped
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $lledger_tests_dir . '/includes/functions.php';

// A test run in a separate process loads this bootstrap again. The parent
// already installed WordPress; reinstalling would reset auto-increment IDs
// mid-suite, so later tests reuse IDs that static caches still hold.
if ( function_exists( '__phpunit_run_isolated_test' ) ) {
	putenv( 'WP_TESTS_SKIP_INSTALL=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Read by the WordPress test bootstrap below.
}

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$sensei = WP_PLUGIN_DIR . '/sensei-lms/sensei-lms.php';
		if ( file_exists( $sensei ) ) {
			require_once $sensei;
		}
		$lifterlms = WP_PLUGIN_DIR . '/lifterlms/lifterlms.php';
		if ( file_exists( $lifterlms ) ) {
			require_once $lifterlms;
		}
		$tutor = WP_PLUGIN_DIR . '/tutor/tutor.php';
		if ( file_exists( $tutor ) ) {
			require_once $tutor;
		}
		require_once dirname( __DIR__ ) . '/learning-ledger.php';
	}
);

require $lledger_tests_dir . '/includes/bootstrap.php';
