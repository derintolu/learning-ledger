<?php
/**
 * How waived AUs count toward satisfaction.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Cmi5;

/**
 * Waiver policies for Satisfaction_Result queries.
 */
final class Waiver_Policy {

	/**
	 * The cmi5 meaning: every waived AU is satisfied.
	 */
	const ALL = 'all';

	/**
	 * Badges with countWaived: waived AUs count unless carried over from an earlier record version.
	 */
	const EXCLUDE_CARRIED_OVER = 'exclude-carried-over';

	/**
	 * Badges without countWaived: no waived AU counts.
	 */
	const NONE = 'none';
}
