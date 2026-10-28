<?php
/**
 * LearnPress event listeners (direction 2).
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Learnpress;

use LearningLedger\Connector\Lms\Event_Sink;
use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Programs\Program_Repository;
use LearnPress\Models\UserItems\UserQuizModel;

// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- LearnPress hook names.

/**
 * Turns LearnPress's public actions (argument lists as in LearnPress 4.4.12)
 * into neutral events. Runs during a learner's action: each callback only
 * builds one event and hands it to the sink, which decides what to store.
 */
final class Learnpress_Listeners {

	/**
	 * Sink.
	 *
	 * @var Event_Sink
	 */
	private Event_Sink $sink;

	/**
	 * Hook into LearnPress's actions.
	 *
	 * @param Event_Sink $sink Sink.
	 *
	 * @return void
	 */
	public function register( Event_Sink $sink ): void {
		$this->sink = $sink;
		add_action( 'learn-press/user-completed-lesson', array( $this, 'on_lesson_completed' ), 10, 3 );
		add_action( 'learn-press/user-course-finished', array( $this, 'on_course_finished' ), 10, 2 );
		add_action( 'learn-press/user/quiz-finished', array( $this, 'on_quiz_finished' ), 10, 4 );
	}

	/**
	 * `learn-press/user-completed-lesson( $lesson_id, $course_id, $user_id )`.
	 *
	 * @param mixed $lesson_id Lesson ID.
	 * @param mixed $course_id Course ID.
	 * @param mixed $user_id   User ID.
	 *
	 * @return void
	 */
	public function on_lesson_completed( mixed $lesson_id, mixed $course_id, mixed $user_id ): void {
		$this->emit( Neutral_Event::LESSON_COMPLETED, (int) $user_id, (int) $course_id, (int) $lesson_id );
	}

	/**
	 * `learn-press/user-course-finished( $course_id, $user_id, $result )`.
	 *
	 * @param mixed $course_id Course ID.
	 * @param mixed $user_id   User ID.
	 *
	 * @return void
	 */
	public function on_course_finished( mixed $course_id, mixed $user_id ): void {
		$this->emit( Neutral_Event::COURSE_COMPLETED, (int) $user_id, (int) $course_id );
	}

	/**
	 * `learn-press/user/quiz-finished( $quiz_id, $course_id, $user_id[, $user_quiz] )`.
	 * The REST route passes the attempt; LP_User::finish_quiz() does not, so
	 * it is looked up. The attempt's result is a percentage and a pass flag.
	 * Not emitted for courses in a program.
	 *
	 * @param mixed $quiz_id   Quiz ID.
	 * @param mixed $course_id Course ID.
	 * @param mixed $user_id   User ID.
	 * @param mixed $user_quiz UserQuizModel, when given.
	 *
	 * @return void
	 */
	public function on_quiz_finished( mixed $quiz_id, mixed $course_id, mixed $user_id, mixed $user_quiz = null ): void {
		$quiz   = (int) $quiz_id;
		$course = (int) $course_id;
		$user   = (int) $user_id;
		if ( $user <= 0 || $course <= 0 || $quiz <= 0 ) {
			return;
		}
		// A program's activities are its lessons (AUs) and blocks. A LearnPress quiz belongs to
		// neither, so a grade there would be reported against the whole block.
		if ( null !== ( new Program_Repository() )->for_lms_course( $course ) ) {
			return;
		}
		if ( ! $user_quiz instanceof UserQuizModel ) {
			$user_quiz = UserQuizModel::find_user_item( $user, $quiz, LP_QUIZ_CPT, $course, LP_COURSE_CPT );
		}
		$scaled  = null;
		$success = null;
		if ( $user_quiz instanceof UserQuizModel ) {
			$result = $user_quiz->get_result();
			if ( isset( $result['result'] ) && is_numeric( $result['result'] ) ) {
				$scaled  = round( (float) $result['result'] / 100, 4 );
				$success = ! empty( $result['pass'] );
			}
		}
		$this->emit( Neutral_Event::QUIZ_GRADED, $user, $course, 0, $quiz, $scaled, $success );
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
				Learnpress_Adapter::ID,
				$user,
				$course,
				$lesson > 0 ? $lesson : null,
				$quiz > 0 ? $quiz : null,
				$scaled,
				$success
			)
		);
	}
}
