<?php
/**
 * Direction-2 activity IRIs for Sensei courses outside a program.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Sensei;

/**
 * Courses in a program use Program_Iris instead.
 */
final class Sensei_Iris {

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
	 * @param integer $lesson Lesson ID.
	 *
	 * @return string
	 */
	public static function quiz( int $course, int $lesson ): string {
		return self::lesson( $course, $lesson ) . '/quiz';
	}
}
