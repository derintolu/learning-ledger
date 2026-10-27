<?php
/**
 * A learner's progress in a course.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * Progress.
 */
final class Progress {

	/**
	 * Constructor.
	 *
	 * @param int[]      $completed_lesson_ids  Completed lessons.
	 * @param int[]      $tested_out_lesson_ids Waived or tested-out lessons.
	 * @param boolean    $course_completed      Whether the course is complete.
	 * @param array|null $certificate           Certificate data, if any.
	 */
	public function __construct(
		public readonly array $completed_lesson_ids,
		public readonly array $tested_out_lesson_ids,
		public readonly bool $course_completed,
		public readonly ?array $certificate
	) {}
}
