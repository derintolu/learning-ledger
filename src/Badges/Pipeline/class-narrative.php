<?php
/**
 * Badge evidence narrative.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Pipeline;

use LearningLedger\Util\Invalid_Input;

/**
 * The narrative a badge's evidence carries: how many lessons were completed and tested out.
 */
final class Narrative {

	/**
	 * E.g. `4 of 6 lessons completed, 2 tested out by placement`, or `6 of 6 lessons completed`.
	 *
	 * The text goes into the signed credential, so it does not follow the admin's locale.
	 *
	 * @param integer $total      Lessons the badge covers.
	 * @param integer $completed  Lessons whose moveOn was met.
	 * @param integer $tested_out Lessons that counted through a waiver.
	 *
	 * @return string
	 *
	 * @throws Invalid_Input Code `invalid_counts` for negative counts or more lessons than the total.
	 */
	public static function for( int $total, int $completed, int $tested_out ): string {
		if ( $total < 0 || $completed < 0 || $tested_out < 0 || $completed + $tested_out > $total ) {
			throw new Invalid_Input( 'Narrative counts must be non-negative and add up to at most the total.', 'invalid_counts' );
		}
		$text = sprintf( '%d of %d lessons completed', $completed, $total );
		if ( $tested_out > 0 ) {
			$text .= sprintf( ', %d tested out by placement', $tested_out );
		}
		return $text;
	}
}
