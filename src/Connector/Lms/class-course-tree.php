<?php
/**
 * Course structure.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * Course tree.
 */
final class Course_Tree {

	/**
	 * Constructor.
	 *
	 * @param integer       $course_id  Course ID.
	 * @param string        $title      Title.
	 * @param string|null   $stable_key Stable key.
	 * @param Lesson_Node[] $lessons    Lessons in order.
	 */
	public function __construct(
		public readonly int $course_id,
		public readonly string $title,
		public readonly ?string $stable_key,
		public readonly array $lessons
	) {}
}
