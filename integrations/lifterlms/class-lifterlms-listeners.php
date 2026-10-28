<?php
/**
 * LifterLMS event listeners (direction 2).
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Lifterlms;

use LearningLedger\Connector\Lms\Event_Sink;
use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Programs\Program_Repository;

/**
 * Turns LifterLMS's public actions (argument lists as in LifterLMS 10.3.2)
 * into neutral events. Runs during a learner's action: each callback only
 * builds events and hands them to the sink, which decides what to store
 * (and drops what the connector itself caused).
 */
final class Lifterlms_Listeners {

	/**
	 * Sink.
	 *
	 * @var Event_Sink
	 */
	private Event_Sink $sink;

	/**
	 * Last course completed, by student, during this request. LifterLMS
	 * completes a track from inside the completion of one of its courses.
	 *
	 * @var array<int, int>
	 */
	private array $completing = array();

	/**
	 * Hook into LifterLMS's actions.
	 *
	 * @param Event_Sink $sink Sink.
	 *
	 * @return void
	 */
	public function register( Event_Sink $sink ): void {
		$this->sink = $sink;
		add_action( 'llms_user_enrolled_in_course', array( $this, 'on_enrolled' ), 10, 2 );
		add_action( 'llms_user_removed_from_course', array( $this, 'on_removed' ), 10, 2 );
		add_action( 'lifterlms_lesson_completed', array( $this, 'on_lesson_completed' ), 10, 2 );
		add_action( 'lifterlms_section_completed', array( $this, 'on_section_completed' ), 10, 2 );
		add_action( 'lifterlms_course_completed', array( $this, 'on_course_completed' ), 10, 2 );
		add_action( 'lifterlms_course_track_completed', array( $this, 'on_track_completed' ), 10, 2 );
		add_action( 'lifterlms_quiz_completed', array( $this, 'on_quiz_completed' ), 10, 3 );
	}

	/**
	 * `llms_user_enrolled_in_course( $user_id, $course_id )`.
	 *
	 * @param mixed $user_id   User ID.
	 * @param mixed $course_id Course ID.
	 *
	 * @return void
	 */
	public function on_enrolled( mixed $user_id, mixed $course_id ): void {
		$this->emit( Neutral_Event::ENROLLED, (int) $user_id, (int) $course_id );
	}

	/**
	 * `llms_user_removed_from_course( $user_id, $course_id, $trigger, $new_status )`.
	 *
	 * @param mixed $user_id   User ID.
	 * @param mixed $course_id Course ID.
	 *
	 * @return void
	 */
	public function on_removed( mixed $user_id, mixed $course_id ): void {
		$this->emit( Neutral_Event::UNENROLLED, (int) $user_id, (int) $course_id );
	}

	/**
	 * `lifterlms_lesson_completed( $student_id, $lesson_id )`.
	 *
	 * @param mixed $user_id   User ID.
	 * @param mixed $lesson_id Lesson ID.
	 *
	 * @return void
	 */
	public function on_lesson_completed( mixed $user_id, mixed $lesson_id ): void {
		$lesson = (int) $lesson_id;
		$this->emit( Neutral_Event::LESSON_COMPLETED, (int) $user_id, Lifterlms_Adapter::course_of_lesson( $lesson ), $lesson );
	}

	/**
	 * `lifterlms_section_completed( $student_id, $section_id )`: `completed`
	 * on the section's activity, inside its course.
	 *
	 * @param mixed $user_id    User ID.
	 * @param mixed $section_id Section ID.
	 *
	 * @return void
	 */
	public function on_section_completed( mixed $user_id, mixed $section_id ): void {
		$section = llms_get_post( (int) $section_id );
		if ( ! $section instanceof \LLMS_Section ) {
			return;
		}
		$this->emit_marked( 'section', (int) $section_id, (int) $user_id, (int) $section->get( 'parent_course' ) );
	}

	/**
	 * `lifterlms_course_completed( $student_id, $course_id )`.
	 *
	 * @param mixed $user_id   User ID.
	 * @param mixed $course_id Course ID.
	 *
	 * @return void
	 */
	public function on_course_completed( mixed $user_id, mixed $course_id ): void {
		$this->completing[ (int) $user_id ] = (int) $course_id;
		$this->emit( Neutral_Event::COURSE_COMPLETED, (int) $user_id, (int) $course_id );
	}

	/**
	 * `lifterlms_course_track_completed( $student_id, $term_id )` (spec §9.4):
	 * `completed` on the track's activity, recorded in the registration of the
	 * course whose completion completed the track. A track completed without
	 * one of its courses completing in this request has no course and is dropped.
	 *
	 * @param mixed $user_id User ID.
	 * @param mixed $term_id Track term ID.
	 *
	 * @return void
	 */
	public function on_track_completed( mixed $user_id, mixed $term_id ): void {
		$user   = (int) $user_id;
		$course = $this->completing[ $user ] ?? 0;
		if ( $course <= 0 || ! has_term( (int) $term_id, 'course_track', $course ) ) {
			return;
		}
		$this->emit_marked( 'track', (int) $term_id, $user, $course );
	}

	/**
	 * `lifterlms_quiz_completed( $student_id, $quiz_id, $attempt )`. A graded
	 * attempt (pass or fail) is a graded quiz; one waiting for review is a
	 * submission without a score. LifterLMS fires this again once the review
	 * grades the attempt.
	 *
	 * @param mixed $user_id User ID.
	 * @param mixed $quiz_id Quiz ID.
	 * @param mixed $attempt LLMS_Quiz_Attempt.
	 *
	 * @return void
	 */
	public function on_quiz_completed( mixed $user_id, mixed $quiz_id, mixed $attempt = null ): void {
		if ( ! $attempt instanceof \LLMS_Quiz_Attempt ) {
			return;
		}
		$quiz   = (int) $quiz_id;
		$lesson = (int) $attempt->get( 'lesson_id' );
		$status = (string) $attempt->get( 'status' );
		$course = Lifterlms_Adapter::course_of_lesson( $lesson );
		if ( 'pass' === $status || 'fail' === $status ) {
			$this->emit( Neutral_Event::QUIZ_GRADED, (int) $user_id, $course, $lesson, $quiz, self::scaled( $attempt->get( 'grade' ) ), 'pass' === $status );
		} elseif ( 'pending' === $status ) {
			$this->emit( Neutral_Event::QUIZ_SUBMITTED, (int) $user_id, $course, $lesson, $quiz );
		}
	}

	/**
	 * Emit a section or track completion, marked for Lifterlms_Iris. Courses
	 * in a program are skipped: program IRIs have no section or track level.
	 *
	 * @param string  $kind   Kind, `section` or `track`.
	 * @param integer $id     Section post ID or track term ID.
	 * @param integer $user   User ID.
	 * @param integer $course Course ID.
	 *
	 * @return void
	 */
	private function emit_marked( string $kind, int $id, int $user, int $course ): void {
		if ( $user <= 0 || $course <= 0 || $id <= 0 || null !== ( new Program_Repository() )->for_lms_course( $course ) ) {
			return;
		}
		$this->sink->emit( Lifterlms_Iris::mark( new Neutral_Event( Neutral_Event::COURSE_COMPLETED, Lifterlms_Adapter::ID, $user, $course ), $kind, $id ) );
	}

	/**
	 * Build the event and hand it to the sink. Events without a user or a course are dropped.
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
		if ( $user <= 0 || $course <= 0 ) {
			return;
		}
		$this->sink->emit(
			new Neutral_Event(
				$type,
				Lifterlms_Adapter::ID,
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
	 * LifterLMS percentage grade to a 0..1 score; null when not graded.
	 *
	 * @param mixed $grade Grade.
	 *
	 * @return float|null
	 */
	private static function scaled( mixed $grade ): ?float {
		if ( ! is_numeric( $grade ) ) {
			return null;
		}
		return round( (float) $grade / 100, 4 );
	}
}
