<?php
/**
 * Per-course direction-2 setting.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Outbox;

use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Util\Invalid_Input;

/**
 * Whether the connector sends a course's on-site learning to the LRS.
 *
 * Post meta `_lledger_direction2` (`on`/`off`). Without it: on, except off for a
 * course in a program driven by a course server (that server is the course's one
 * writer). Always off for a course in a cmi5 player program.
 */
final class Direction2 {

	const META = '_lledger_direction2';

	/**
	 * Whether direction 2 is on for a course.
	 *
	 * @param integer $course_id LMS course id.
	 *
	 * @return boolean
	 */
	public static function is_on( int $course_id ): bool {
		$program = ( new Program_Repository() )->for_lms_course( $course_id );
		if ( null !== $program && self::is_player( $program ) ) {
			return false;
		}
		$value = (string) get_post_meta( $course_id, self::META, true );
		if ( 'on' === $value || 'off' === $value ) {
			return 'on' === $value;
		}
		return null === $program || '' === (string) get_post_meta( $program, '_lledger_course_server_url', true );
	}

	/**
	 * Whether direction 2 is forced off (player course), whatever the setting says.
	 *
	 * @param integer $course_id LMS course id.
	 *
	 * @return boolean
	 */
	public static function is_forced_off( int $course_id ): bool {
		$program = ( new Program_Repository() )->for_lms_course( $course_id );
		return null !== $program && self::is_player( $program );
	}

	/**
	 * Store the setting.
	 *
	 * @param integer $course_id LMS course id.
	 * @param string  $value     Either `on` or `off`.
	 *
	 * @return void
	 *
	 * @throws Invalid_Input When the value is neither.
	 */
	public static function set( int $course_id, string $value ): void {
		if ( 'on' !== $value && 'off' !== $value ) {
			throw new Invalid_Input( 'Direction 2 must be "on" or "off".', 'invalid_direction2' );
		}
		update_post_meta( $course_id, self::META, $value );
	}

	/**
	 * Whether a program is played by the cmi5 player.
	 *
	 * @param integer $program_id Program post id.
	 *
	 * @return boolean
	 */
	private static function is_player( int $program_id ): bool {
		return (bool) (int) get_post_meta( $program_id, '_lledger_player', true );
	}
}
