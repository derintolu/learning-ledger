<?php
/**
 * HTTP test bootstrap (tests against a running site).
 *
 * @package LearningLedger
 */

// src/autoload.php exits unless ABSPATH is defined, as WordPress defines it.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/src/autoload.php';

$lledger_base_url = getenv( 'LLEDGER_TEST_BASE_URL' );
define( 'LLEDGER_TEST_BASE_URL', $lledger_base_url ? $lledger_base_url : 'http://localhost:8888' );
