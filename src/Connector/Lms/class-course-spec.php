<?php
/**
 * Course to create or update.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * Course spec.
 */
final class Course_Spec {

	/**
	 * Constructor.
	 *
	 * @param string $stable_key Stable key.
	 * @param string $title      Title.
	 * @param string $content    Content.
	 * @param string $status     Post status.
	 * @param array  $meta       Extra meta.
	 */
	public function __construct(
		public readonly string $stable_key,
		public readonly string $title,
		public readonly string $content,
		public readonly string $status,
		public readonly array $meta
	) {}
}
