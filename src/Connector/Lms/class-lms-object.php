<?php
/**
 * An object inside an LMS.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * LMS object: Course, lesson, quiz or program.
 */
final class Lms_Object {

	/**
	 * Constructor.
	 *
	 * @param string  $kind Course, lesson, quiz or program.
	 * @param integer $id   Post ID.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly int $id
	) {}
}
