<?php
/**
 * Optional certificate capability of an adapter.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * An adapter that can render a course certificate.
 */
interface Certificate_Provider {

	/**
	 * Certificate PDF bytes, or null when none exists.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return string|null
	 */
	public function certificate_pdf( int $user_id, int $course_id ): ?string;
}
