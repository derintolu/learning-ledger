<?php
/**
 * Sensei LMS event listeners (direction 2).
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Sensei;

use LearningLedger\Connector\Lms\Event_Sink;
use LearningLedger\Connector\Lms\Neutral_Event;

/**
 * Turns Sensei's public actions (argument lists as in Sensei 4.26.4) into
 * neutral events. Runs during a learner's action: each callback only builds
 * one event and hands it to the sink, which decides what to store.
 */
final class Sensei_Listeners {

	/**
	 * Sink.
	 *
	 * @var Event_Sink
	 */
	private Event_Sink $sink;

	/**
	 * Hook into Sensei's actions.
	 *
	 * @param Event_Sink $sink Sink.
	 *
	 * @return void
	 */
	public function register( Event_Sink $sink ): void {
		$this->sink = $sink;
		add_action( 'sensei_course_enrolment_status_changed', array( $this, 'on_enrolment_changed' ), 10, 3 );
		add_action( 'sensei_user_course_start', array( $this, 'on_course_start' ), 10, 2 );
		add_action( 'sensei_user_lesson_start', array( $this, 'on_lesson_start' ), 10, 2 );
		add_action( 'sensei_user_lesson_end', array( $this, 'on_lesson_end' ), 10, 2 );
		add_action( 'sensei_user_quiz_submitted', array( $this, 'on_quiz_submitted' ), 10, 5 );
		add_action( 'sensei_user_quiz_grade', array( $this, 'on_quiz_grade' ), 10, 5 );
		add_action( 'sensei_user_course_end', array( $this, 'on_course_end' ), 10, 2 );
		add_action( 'sensei_user_lesson_reset', array( $this, 'on_lesson_reset' ), 10, 2 );
		add_action( 'sensei_user_course_reset', array( $this, 'on_course_reset' ), 10, 2 );
	}

	/**
	 * `sensei_course_enrolment_status_changed( $user_id, $course_id, $is_enrolled )`.
	 *
	 * @param mixed $user_id     User ID.
	 * @param mixed $course_id   Course ID.
	 * @param mixed $is_enrolled New enrolment status.
	 *
	 * @return void
	 */
	public function on_enrolment_changed( mixed $user_id, mixed $course_id, mixed $is_enrolled ): void {
		$this->course_event( $is_enrolled ? Neutral_Event::ENROLLED : Neutral_Event::UNENROLLED, $user_id, $course_id );
	}

	/**
	 * `sensei_user_course_start( $user_id, $course_id )`.
	 *
	 * @param mixed $user_id   User ID.
	 * @param mixed $course_id Course ID.
	 *
	 * @return void
	 */
	public function on_course_start( mixed $user_id, mixed $course_id ): void {
		$this->course_event( Neutral_Event::COURSE_STARTED, $user_id, $course_id );
	}

	/**
	 * `sensei_user_lesson_start( $user_id, $lesson_id )`.
	 *
	 * @param mixed $user_id   User ID.
	 * @param mixed $lesson_id Lesson ID.
	 *
	 * @return void
	 */
	public function on_lesson_start( mixed $user_id, mixed $lesson_id ): void {
		$this->lesson_event( Neutral_Event::LESSON_STARTED, $user_id, $lesson_id );
	}

	/**
	 * `sensei_user_lesson_end( $user_id, $lesson_id )`.
	 *
	 * @param mixed $user_id   User ID.
	 * @param mixed $lesson_id Lesson ID.
	 *
	 * @return void
	 */
	public function on_lesson_end( mixed $user_id, mixed $lesson_id ): void {
		$this->lesson_event( Neutral_Event::LESSON_COMPLETED, $user_id, $lesson_id );
	}

	/**
	 * `sensei_user_quiz_submitted( $user_id, $quiz_id, $grade, $quiz_pass_percentage, $quiz_grade_type )`.
	 * The grade is a percentage, or false while the quiz waits for manual grading.
	 *
	 * @param mixed $user_id    User ID.
	 * @param mixed $quiz_id    Quiz ID.
	 * @param mixed $grade      Grade (0 to 100) or false.
	 * @param mixed $pass       Pass percentage; unused, a submission has no success.
	 * @param mixed $grade_type Grade type; unused.
	 *
	 * @return void
	 */
	public function on_quiz_submitted( mixed $user_id, mixed $quiz_id, mixed $grade = false, mixed $pass = 0, mixed $grade_type = '' ): void {
		unset( $pass, $grade_type );
		$this->quiz_event( Neutral_Event::QUIZ_SUBMITTED, $user_id, $quiz_id, self::scaled( $grade ), null );
	}

	/**
	 * `sensei_user_quiz_grade( $user_id, $quiz_id, $grade, $quiz_passmark, $quiz_grade_type )`.
	 *
	 * @param mixed $user_id    User ID.
	 * @param mixed $quiz_id    Quiz ID.
	 * @param mixed $grade      Grade (0 to 100).
	 * @param mixed $passmark   Passmark (0 to 100).
	 * @param mixed $grade_type Grade type; unused.
	 *
	 * @return void
	 */
	public function on_quiz_grade( mixed $user_id, mixed $quiz_id, mixed $grade = 0, mixed $passmark = 0, mixed $grade_type = '' ): void {
		unset( $grade_type );
		$scaled = self::scaled( $grade );
		$this->quiz_event( Neutral_Event::QUIZ_GRADED, $user_id, $quiz_id, $scaled, null === $scaled ? null : (float) $grade >= (float) $passmark );
	}

	/**
	 * `sensei_user_course_end( $user_id, $course_id )`.
	 *
	 * @param mixed $user_id   User ID.
	 * @param mixed $course_id Course ID.
	 *
	 * @return void
	 */
	public function on_course_end( mixed $user_id, mixed $course_id ): void {
		$this->course_event( Neutral_Event::COURSE_COMPLETED, $user_id, $course_id );
	}

	/**
	 * `sensei_user_lesson_reset( $user_id, $lesson_id )`.
	 *
	 * @param mixed $user_id   User ID.
	 * @param mixed $lesson_id Lesson ID.
	 *
	 * @return void
	 */
	public function on_lesson_reset( mixed $user_id, mixed $lesson_id ): void {
		$this->lesson_event( Neutral_Event::PROGRESS_RESET, $user_id, $lesson_id );
	}

	/**
	 * `sensei_user_course_reset( $user_id, $course_id )`: a reset without a lesson ends the registration.
	 *
	 * @param mixed $user_id   User ID.
	 * @param mixed $course_id Course ID.
	 *
	 * @return void
	 */
	public function on_course_reset( mixed $user_id, mixed $course_id ): void {
		$this->course_event( Neutral_Event::PROGRESS_RESET, $user_id, $course_id );
	}

	/**
	 * Emit a course-level event.
	 *
	 * @param string $type      Event type.
	 * @param mixed  $user_id   User ID.
	 * @param mixed  $course_id Course ID.
	 *
	 * @return void
	 */
	private function course_event( string $type, mixed $user_id, mixed $course_id ): void {
		$this->emit( $type, (int) $user_id, (int) $course_id );
	}

	/**
	 * Emit a lesson-level event; the course is the lesson's `_lesson_course`.
	 *
	 * @param string $type      Event type.
	 * @param mixed  $user_id   User ID.
	 * @param mixed  $lesson_id Lesson ID.
	 *
	 * @return void
	 */
	private function lesson_event( string $type, mixed $user_id, mixed $lesson_id ): void {
		$lesson = (int) $lesson_id;
		$this->emit( $type, (int) $user_id, self::course_of( $lesson ), $lesson );
	}

	/**
	 * Emit a quiz event; a Sensei quiz's parent post is its lesson.
	 *
	 * @param string       $type    Event type.
	 * @param mixed        $user_id User ID.
	 * @param mixed        $quiz_id Quiz ID.
	 * @param float|null   $scaled  Score from 0 to 1.
	 * @param boolean|null $success Passed.
	 *
	 * @return void
	 */
	private function quiz_event( string $type, mixed $user_id, mixed $quiz_id, ?float $scaled, ?bool $success ): void {
		$quiz   = (int) $quiz_id;
		$lesson = $quiz > 0 ? (int) Sensei()->quiz->get_lesson_id( $quiz ) : 0;
		$this->emit( $type, (int) $user_id, self::course_of( $lesson ), $lesson, $quiz, $scaled, $success );
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
				Sensei_Integration::ID,
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
	 * Course of a lesson.
	 *
	 * @param integer $lesson Lesson ID.
	 *
	 * @return integer 0 when none.
	 */
	private static function course_of( int $lesson ): int {
		return $lesson > 0 ? (int) get_post_meta( $lesson, '_lesson_course', true ) : 0;
	}

	/**
	 * Sensei percentage grade to a 0..1 score; null when not graded.
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
