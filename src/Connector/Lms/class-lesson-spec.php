<?php
/**
 * Lesson to create or update.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * Lesson spec.
 */
final class Lesson_Spec {

	/**
	 * Constructor.
	 *
	 * @param string  $stable_key Stable key.
	 * @param string  $title      Title.
	 * @param string  $content    Content.
	 * @param integer $order      Position.
	 * @param string  $status     Post status.
	 * @param array   $meta       Extra meta.
	 */
	public function __construct(
		public readonly string $stable_key,
		public readonly string $title,
		public readonly string $content,
		public readonly int $order,
		public readonly string $status,
		public readonly array $meta
	) {}
}
