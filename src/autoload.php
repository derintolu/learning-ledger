<?php
/**
 * PSR-4-like autoloader following WordPress file naming.
 *
 * @package LearningLedger
 */

namespace LearningLedger;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LEARNING_LEDGER_DIR' ) ) {
	define( 'LEARNING_LEDGER_DIR', dirname( __DIR__ ) . '/' );
}

/**
 * Candidate file paths for a class, interface or trait, in lookup order.
 *
 * @param string $class_name Fully qualified name.
 *
 * @return string[]
 */
function autoload_candidates( string $class_name ): array {
	$class_name = ltrim( $class_name, '\\' );
	$prefix     = 'LearningLedger\\';
	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return array();
	}
	$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
	$base  = array_pop( $parts );
	$slug  = strtolower( str_replace( '_', '-', $base ) );

	if ( 'Tests' === ( $parts[0] ?? '' ) ) {
		array_shift( $parts );
		$dir = LEARNING_LEDGER_DIR . 'tests/Support/';
	} elseif ( 'Integrations' === ( $parts[0] ?? '' ) && count( $parts ) > 1 ) {
		array_shift( $parts );
		$parts[0] = strtolower( $parts[0] );
		$dir      = LEARNING_LEDGER_DIR . 'integrations/';
	} else {
		$dir = LEARNING_LEDGER_DIR . 'src/';
	}
	if ( $parts ) {
		$dir .= implode( '/', $parts ) . '/';
	}

	return array(
		$dir . 'class-' . $slug . '.php',
		$dir . 'interface-' . $slug . '.php',
		$dir . 'trait-' . $slug . '.php',
	);
}

spl_autoload_register(
	static function ( string $class_name ): void {
		foreach ( autoload_candidates( $class_name ) as $file ) {
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

if ( is_readable( LEARNING_LEDGER_DIR . 'vendor-prefixed/autoload.php' ) ) {
	require_once LEARNING_LEDGER_DIR . 'vendor-prefixed/autoload.php';
}
