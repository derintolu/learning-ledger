<?php
/**
 * Deferred rewrite flush.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Kernel;

/**
 * Flushes rewrite rules once, late on `init`, after modules have added theirs.
 */
final class Rewrites {

	public const OPTION = 'lledger_flush_rewrites';

	/**
	 * Ask for a flush on the next request's `init`.
	 *
	 * @return void
	 */
	public static function schedule_flush(): void {
		update_option( self::OPTION, 1 );
	}

	/**
	 * Flush when scheduled. Hooked on `init` at priority 99. Soft flush: never writes .htaccess.
	 *
	 * @return void
	 */
	public static function maybe_flush(): void {
		if ( false === get_option( self::OPTION ) ) {
			return;
		}
		delete_option( self::OPTION );
		flush_rewrite_rules( false );
	}
}
