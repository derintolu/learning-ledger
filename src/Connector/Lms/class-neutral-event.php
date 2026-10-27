<?php
/**
 * A neutral, LMS-independent event.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * Event emitted by an adapter.
 */
final class Neutral_Event {

	const ENROLLED         = 'enrolled';
	const COURSE_STARTED   = 'course_started';
	const LESSON_STARTED   = 'lesson_started';
	const LESSON_COMPLETED = 'lesson_completed';
	const QUIZ_SUBMITTED   = 'quiz_submitted';
	const QUIZ_GRADED      = 'quiz_graded';
	const COURSE_COMPLETED = 'course_completed';
	const PROGRESS_RESET   = 'progress_reset';
	const UNENROLLED       = 'unenrolled';

	/**
	 * Time of the event.
	 *
	 * @var \DateTimeImmutable
	 */
	public readonly \DateTimeImmutable $occurred_at;

	/**
	 * Constructor.
	 *
	 * @param string                  $type         One of the type constants.
	 * @param string                  $lms          Integration id.
	 * @param integer                 $user_id      User ID.
	 * @param integer                 $course_id    Course ID.
	 * @param integer|null            $lesson_id    Lesson ID.
	 * @param integer|null            $quiz_id      Quiz ID.
	 * @param float|null              $score_scaled Score from 0 to 1.
	 * @param boolean|null            $success      Passed or failed.
	 * @param \DateTimeImmutable|null $occurred_at  Time; defaults to now.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $lms,
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly ?int $lesson_id = null,
		public readonly ?int $quiz_id = null,
		public readonly ?float $score_scaled = null,
		public readonly ?bool $success = null,
		?\DateTimeImmutable $occurred_at = null
	) {
		$this->occurred_at = $occurred_at ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
