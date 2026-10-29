<?php
/**
 * A stand-in for Batcache's batcache_cancel() that counts its calls.
 *
 * @package LearningLedger
 */

if ( ! function_exists( 'batcache_cancel' ) ) {
	/**
	 * Stand-in for Batcache's batcache_cancel(): counts the calls.
	 */
	function batcache_cancel(): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- the host's function.
		$GLOBALS['lledger_batcache_cancels'] = ( $GLOBALS['lledger_batcache_cancels'] ?? 0 ) + 1;
	}
}
