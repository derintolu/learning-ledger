<?php
/**
 * Echo guard.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Ledger;

use LearningLedger\Connector\Lms\Lms_Object;

/**
 * Stops the connector sending back a change it just applied to the LMS.
 */
final class Echo_Guard {

	/**
	 * Nesting depth of while_applying calls.
	 *
	 * @var integer
	 */
	private static int $depth = 0;

	/**
	 * Run a callback with the guard active; the flag clears afterwards, also on an exception.
	 *
	 * @param callable $fn Callback.
	 *
	 * @return mixed The callback's return value.
	 */
	public static function while_applying( callable $fn ): mixed { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.fnFound
		++self::$depth;
		try {
			return $fn();
		} finally {
			--self::$depth;
		}
	}

	/**
	 * Whether the connector is applying a change right now.
	 *
	 * @return boolean
	 */
	public static function active(): bool {
		return self::$depth > 0;
	}

	/**
	 * Whether this change was applied by the connector earlier.
	 *
	 * @param integer    $user   User ID.
	 * @param Lms_Object $o      Object.
	 * @param string     $status Status.
	 *
	 * @return boolean
	 */
	public static function was_applied( int $user, Lms_Object $o, string $status ): bool {
		return ( new Apply_Ledger() )->has( $user, $o, $status );
	}
}
