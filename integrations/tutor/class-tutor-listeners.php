<?php
/**
 * Tutor LMS event listeners (direction 2).
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Tutor;

use LearningLedger\Connector\Lms\Event_Sink;
use LearningLedger\Connector\Lms\Neutral_Event;

// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Tutor's own hook names.

/**
 * Turns Tutor's public actions (argument lists as in Tutor LMS 3.9.7) into
 * neutral events. Runs during a learner's action: each callback only builds
 * the event and hands it to the sink, which decides what to store.
 */
final class Tutor_Listeners {

	/**
	 * Sink.
	 *
	 * @var Event_Sink
	 */
	private Event_Sink $sink;

	/**
	 * Lessons already reported in this request, as "user:lesson". Tutor's
	 * "Mark as complete" form fires both lesson hooks for one completion.
	 *
	 * @var array<string, true>
	 */
	private array $completed = array();

	/**
	 * Hook into Tutor's actions.
	 *
	 * @param Event_Sink $sink Sink.
	 *
	 * @return void
	 */
	public function register( Event_Sink $sink ): void {
		$this->sink = $sink;
		add_action( 'tutor_after_enrolled', array( $this, 'on_enrolled' ), 10, 2 );
		add_action( 'tutor_mark_lesson_complete_after', array( $this, 'on_lesson_completed' ), 10, 2 );
		add_action( 'tutor_lesson_completed_after', array( $this, 'on_lesson_completed' ), 10, 2 );
		add_action( 'tutor_quiz/attempt_ended', array( $this, 'on_attempt_ended' ), 10, 3 );
		add_action( 'tutor_course_complete_after', array( $this, 'on_course_completed' ), 10, 2 );
	}

	/**
	 * `tutor_after_enrolled( $course_id, $user_id, $enrolled_id )`, fired for a completed enrolment.
	 *
	 * @param mixed $course_id Course ID.
	 * @param mixed $user_id   User ID.
	 *
	 * @return void
	 */
	public function on_enrolled( mixed $course_id, mixed $user_id ): void {
		$this->emit( Neutral_Event::ENROLLED, (int) $user_id, (int) $course_id );
	}

	/**
	 * `tutor_mark_lesson_complete_after( $post_id, $user_id )` (every completion through
	 * LessonModel) and `tutor_lesson_completed_after( $lesson_id, $user_id )` (the form).
	 *
	 * @param mixed $lesson_id Lesson ID.
	 * @param mixed $user_id   User ID.
	 *
	 * @return void
	 */
	public function on_lesson_completed( mixed $lesson_id, mixed $user_id = 0 ): void {
		$lesson = (int) $lesson_id;
		$user   = (int) $user_id;
		if ( Tutor_Adapter::LESSON !== get_post_type( $lesson ) ) {
			return;
		}
		$key = $user . ':' . $lesson;
		if ( isset( $this->completed[ $key ] ) ) {
			return;
		}
		$this->completed[ $key ] = true;
		$this->emit( Neutral_Event::LESSON_COMPLETED, $user, Tutor_Adapter::course_of( $lesson ), $lesson );
	}

	/**
	 * `tutor_quiz/attempt_ended( $attempt_id, $course_id, $user_id )`. An attempt with
	 * a pass or fail result is also graded; one waiting for review is only submitted.
	 *
	 * @param mixed $attempt_id Attempt ID.
	 * @param mixed $course_id  Course ID.
	 * @param mixed $user_id    User ID.
	 *
	 * @return void
	 */
	public function on_attempt_ended( mixed $attempt_id, mixed $course_id = 0, mixed $user_id = 0 ): void {
		$attempt = tutor_utils()->get_attempt( (int) $attempt_id );
		if ( ! is_object( $attempt ) ) {
			return;
		}
		$quiz   = (int) $attempt->quiz_id;
		$user   = (int) $user_id > 0 ? (int) $user_id : (int) $attempt->user_id;
		$course = (int) $course_id > 0 ? (int) $course_id : Tutor_Adapter::course_of( $quiz );
		$result = (string) $attempt->result;
		$graded = in_array( $result, array( 'pass', 'fail' ), true );
		$scaled = $graded ? self::scaled( $attempt->earned_marks, $attempt->total_marks ) : null;

		$this->emit( Neutral_Event::QUIZ_SUBMITTED, $user, $course, 0, $quiz, $scaled );
		if ( $graded ) {
			$this->emit( Neutral_Event::QUIZ_GRADED, $user, $course, 0, $quiz, $scaled, 'pass' === $result );
		}
	}

	/**
	 * `tutor_course_complete_after( $course_id, $user_id )`.
	 *
	 * @param mixed $course_id Course ID.
	 * @param mixed $user_id   User ID.
	 *
	 * @return void
	 */
	public function on_course_completed( mixed $course_id, mixed $user_id = 0 ): void {
		$this->emit( Neutral_Event::COURSE_COMPLETED, (int) $user_id, (int) $course_id );
	}

	/**
	 * Build the event and hand it to the sink. Events without a user or a Tutor course are dropped.
	 *
	 * @param string       $type    Event type.
	 * @param integer      $user    User ID.
	 * @param integer      $course  Course ID.
	 * @param integer      $lesson  Lesson ID, 0 for none.
	 * @param integer      $quiz    Quiz ID, 0 for none.
	 * @param float|null   $scaled  Score from 0 to 1.
	 * @param boolean|null $success Passed.
	 *
	 * @return void
	 */
	private function emit( string $type, int $user, int $course, int $lesson = 0, int $quiz = 0, ?float $scaled = null, ?bool $success = null ): void {
		if ( $user <= 0 || $course <= 0 || Tutor_Adapter::COURSE !== get_post_type( $course ) ) {
			return;
		}
		$this->sink->emit(
			new Neutral_Event(
				$type,
				Tutor_Adapter::ID,
				$user,
				$course,
				$lesson > 0 ? $lesson : null,
				$quiz > 0 ? $quiz : null,
				$scaled,
				$success
			)
		);
	}

	/**
	 * Earned over total marks as a 0..1 score; null without a positive total.
	 *
	 * @param mixed $earned Earned marks.
	 * @param mixed $total  Total marks.
	 *
	 * @return float|null
	 */
	private static function scaled( mixed $earned, mixed $total ): ?float {
		if ( ! is_numeric( $earned ) || ! is_numeric( $total ) || (float) $total <= 0 ) {
			return null;
		}
		return round( max( 0.0, min( 1.0, (float) $earned / (float) $total ) ), 4 );
	}
}
