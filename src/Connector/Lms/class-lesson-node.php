<?php
/**
 * A lesson in a course tree.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * Lesson node.
 */
final class Lesson_Node {

	/**
	 * Constructor.
	 *
	 * @param integer     $id                     Lesson ID.
	 * @param string      $title                  Title.
	 * @param string|null $stable_key             Stable key.
	 * @param integer     $order                  Position.
	 * @param boolean     $has_pass_required_quiz Whether a quiz must be passed.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly ?string $stable_key,
		public readonly int $order,
		public readonly bool $has_pass_required_quiz
	) {}
}
