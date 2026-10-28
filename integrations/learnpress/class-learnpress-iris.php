<?php
/**
 * Direction-2 activity IRIs for LearnPress courses outside a program.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Learnpress;

use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Programs\Program_Repository;

/**
 * Courses in a program use Program_Iris instead. A LearnPress quiz is a
 * course item beside the lessons, so its IRI hangs off the course.
 */
final class Learnpress_Iris {

	const META = '_lledger_activity_base';

	/**
	 * Course IRI: the activity base, fixed in post meta on first use so a
	 * later change of home_url() does not change it.
	 *
	 * @param integer $id Course ID.
	 *
	 * @return string
	 */
	public static function course( int $id ): string {
		$base = (string) get_post_meta( $id, self::META, true );
		if ( '' === $base ) {
			$base = home_url( '/activities/courses/' . $id );
			// First-use write, an allowed exception to the one-INSERT rule (F22).
			update_post_meta( $id, self::META, $base );
		}
		return $base;
	}

	/**
	 * Course IRI without fixing it: the stored base, or the default form when
	 * none is stored yet. Used by resolve(), which must not write.
	 *
	 * @param integer $id Course ID.
	 *
	 * @return string
	 */
	public static function peek( int $id ): string {
		$base = (string) get_post_meta( $id, self::META, true );
		return '' === $base ? home_url( '/activities/courses/' . $id ) : $base;
	}

	/**
	 * Lesson IRI.
	 *
	 * @param integer $course Course ID.
	 * @param integer $lesson Lesson ID.
	 *
	 * @return string
	 */
	public static function lesson( int $course, int $lesson ): string {
		return self::course( $course ) . '/lessons/' . $lesson;
	}

	/**
	 * Quiz IRI.
	 *
	 * @param integer $course Course ID.
	 * @param integer $quiz   Quiz ID.
	 *
	 * @return string
	 */
	public static function quiz( int $course, int $quiz ): string {
		return self::course( $course ) . '/quizzes/' . $quiz;
	}

	/**
	 * `learning_ledger/direction2_iris`: object and parent IRIs of a LearnPress
	 * event whose course is not in a program.
	 *
	 * @param mixed         $iris Value from earlier callbacks.
	 * @param Neutral_Event $e    Event.
	 *
	 * @return mixed array{object:string,parent:string}|null, or $iris unchanged for other LMSs.
	 */
	public static function direction2_iris( mixed $iris, Neutral_Event $e ): mixed {
		if ( Learnpress_Adapter::ID !== $e->lms ) {
			return $iris;
		}
		if ( $e->course_id <= 0 || Learnpress_Adapter::COURSE !== get_post_type( $e->course_id ) ) {
			return null;
		}
		if ( null !== ( new Program_Repository() )->for_lms_course( $e->course_id ) ) {
			return null;
		}

		$parent = self::course( $e->course_id );
		$object = $parent;
		if ( null !== $e->quiz_id && in_array( $e->type, array( Neutral_Event::QUIZ_SUBMITTED, Neutral_Event::QUIZ_GRADED ), true ) ) {
			$object = self::quiz( $e->course_id, $e->quiz_id );
		} elseif ( null !== $e->lesson_id ) {
			$object = self::lesson( $e->course_id, $e->lesson_id );
		}
		return array(
			'object' => $object,
			'parent' => $parent,
		);
	}
}
