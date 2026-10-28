<?php
/**
 * Unit test bootstrap (no WordPress).
 *
 * @package LearningLedger
 */

// src/autoload.php exits unless ABSPATH is defined, as WordPress defines it.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
}

define( 'LEARNING_LEDGER_DIR', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/src/autoload.php';

// Dummy WordPress salts so crypto helpers can be unit tested without WordPress.
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'unit-test-auth-key-0123456789abcdef0123456789abcdef' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'unit-test-secure-auth-key-0123456789abcdef0123456789abcdef' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
}
