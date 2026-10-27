<?php
/**
 * Stored document.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Documents;

/**
 * A State, Agent Profile or Activity Profile document as stored.
 */
final class Document {

	/**
	 * Constructor.
	 *
	 * @param string             $content      Bytes exactly as stored.
	 * @param string             $content_type Content-Type as sent.
	 * @param string             $etag         SHA-1 hex of the content (unquoted).
	 * @param \DateTimeImmutable $updated      Last change, UTC.
	 */
	public function __construct(
		public readonly string $content,
		public readonly string $content_type,
		public readonly string $etag,
		public readonly \DateTimeImmutable $updated
	) {}
}
