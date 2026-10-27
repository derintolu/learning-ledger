<?php
/**
 * Clock.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Util;

/**
 * Source of "now" that tests can freeze.
 */
final class Clock {

	/**
	 * Frozen time, if any.
	 *
	 * @var \DateTimeImmutable|null
	 */
	private static ?\DateTimeImmutable $frozen = null;

	/**
	 * Current time in UTC with microseconds.
	 *
	 * @return \DateTimeImmutable
	 */
	public static function now(): \DateTimeImmutable {
		return self::$frozen ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Freeze the clock (tests); pass null to unfreeze.
	 *
	 * @param \DateTimeImmutable|null $t Time.
	 *
	 * @return void
	 */
	public static function freeze( ?\DateTimeImmutable $t ): void {
		self::$frozen = $t;
	}
}
