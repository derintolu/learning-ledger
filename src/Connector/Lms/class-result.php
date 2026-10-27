<?php
/**
 * A neutral result to apply to an LMS.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * Result of an agent or LRS decision, applied by an adapter.
 */
final class Result {

	const LESSON_COMPLETED = 'lesson_completed';
	const LESSON_PASSED    = 'lesson_passed';
	const LESSON_FAILED    = 'lesson_failed';
	const LESSON_WAIVED    = 'lesson_waived';
	const BLOCK_SATISFIED  = 'block_satisfied';
	const COURSE_SATISFIED = 'course_satisfied';

	/**
	 * Constructor.
	 *
	 * @param string      $type         One of the type constants.
	 * @param integer     $user_id      User ID.
	 * @param Lms_Object  $object       Target object.
	 * @param string      $registration Registration UUID.
	 * @param string      $statement_id Source statement UUID.
	 * @param float|null  $score_scaled Score from 0 to 1.
	 * @param string|null $reason       Reason, for waivers.
	 * @param boolean     $carried_over Whether carried over from an earlier registration.
	 */
	public function __construct(
		public readonly string $type,
		public readonly int $user_id,
		public readonly Lms_Object $object, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound
		public readonly string $registration,
		public readonly string $statement_id,
		public readonly ?float $score_scaled = null,
		public readonly ?string $reason = null,
		public readonly bool $carried_over = false
	) {}
}
