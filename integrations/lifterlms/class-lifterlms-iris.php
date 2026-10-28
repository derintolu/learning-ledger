<?php
/**
 * Direction-2 activity IRIs for LifterLMS courses outside a program.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Lifterlms;

use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Programs\Program_Repository;

/**
 * Courses in a program use Program_Iris instead.
 */
final class Lifterlms_Iris {

	const META = '_lledger_activity_base';

	/**
	 * Section and track events by event: [kind, id]. Neutral events have no
	 * level between course and lesson or above the course, so the listeners
	 * mark these events and the IRI filter reads the mark.
	 *
	 * @var \WeakMap<Neutral_Event, array{0:string,1:int}>|null
	 */
	private static ?\WeakMap $marks = null;

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
	 * Quiz IRI: a LifterLMS lesson has at most one quiz.
	 *
	 * @param integer $course Course ID.
	 * @param integer $lesson Lesson ID.
	 *
	 * @return string
	 */
	public static function quiz( int $course, int $lesson ): string {
		return self::lesson( $course, $lesson ) . '/quiz';
	}

	/**
	 * Section IRI.
	 *
	 * @param integer $course  Course ID.
	 * @param integer $section Section ID.
	 *
	 * @return string
	 */
	public static function section( int $course, int $section ): string {
		return self::course( $course ) . '/sections/' . $section;
	}

	/**
	 * Course track IRI, fixed in term meta on first use like a course base.
	 *
	 * @param integer $term_id Track term ID.
	 *
	 * @return string
	 */
	public static function track( int $term_id ): string {
		$base = (string) get_term_meta( $term_id, self::META, true );
		if ( '' === $base ) {
			$base = home_url( '/activities/tracks/' . $term_id );
			// First-use write, an allowed exception to the one-INSERT rule (F22).
			update_term_meta( $term_id, self::META, $base );
		}
		return $base;
	}

	/**
	 * Mark an event as a section or track completion.
	 *
	 * @param Neutral_Event $e    Event.
	 * @param string        $kind Kind, `section` or `track`.
	 * @param integer       $id   Section post ID or track term ID.
	 *
	 * @return Neutral_Event The same event.
	 */
	public static function mark( Neutral_Event $e, string $kind, int $id ): Neutral_Event {
		self::$marks ??= new \WeakMap();

		self::$marks[ $e ] = array( $kind, $id );
		return $e;
	}

	/**
	 * Object and parent IRIs of a LifterLMS event whose course is not in a
	 * program (program courses use Program_Iris). Hooked on
	 * `learning_ledger/direction2_iris`.
	 *
	 * @param mixed         $iris Value from earlier callbacks.
	 * @param Neutral_Event $e    Event.
	 *
	 * @return mixed array{object:string,parent:string}|null, or $iris unchanged for other LMSs.
	 */
	public static function direction2_iris( mixed $iris, Neutral_Event $e ): mixed {
		if ( Lifterlms_Adapter::ID !== $e->lms ) {
			return $iris;
		}
		if ( $e->course_id <= 0 || 'course' !== get_post_type( $e->course_id ) ) {
			return null;
		}
		if ( null !== ( new Program_Repository() )->for_lms_course( $e->course_id ) ) {
			return null;
		}

		$course = self::course( $e->course_id );
		$mark   = null === self::$marks ? null : ( self::$marks[ $e ] ?? null );
		if ( null !== $mark ) {
			return 'track' === $mark[0]
				? array(
					'object' => self::track( $mark[1] ),
					'parent' => '',
				)
				: array(
					'object' => self::section( $e->course_id, $mark[1] ),
					'parent' => $course,
				);
		}

		$quiz   = in_array( $e->type, array( Neutral_Event::QUIZ_SUBMITTED, Neutral_Event::QUIZ_GRADED ), true );
		$lesson = (int) $e->lesson_id;
		if ( $quiz && $lesson <= 0 && null !== $e->quiz_id ) {
			$model  = llms_get_post( $e->quiz_id );
			$lesson = $model instanceof \LLMS_Quiz ? (int) $model->get( 'lesson_id' ) : 0;
		}

		$object = $course;
		if ( $lesson > 0 ) {
			$object = $quiz ? self::quiz( $e->course_id, $lesson ) : self::lesson( $e->course_id, $lesson );
		}
		return array(
			'object' => $object,
			'parent' => $course,
		);
	}
}
