<?php
/**
 * A program: path courses grouped into one cmi5 course.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Programs;

/**
 * Immutable program values read from post meta.
 */
final class Program {

	/**
	 * Constructor.
	 *
	 * @param integer             $id                Post id.
	 * @param string              $stable_key        Stable key.
	 * @param string              $iri_base          Absolute IRI base ending in a slash.
	 * @param string              $course_iri        Course IRI.
	 * @param int[]               $course_ids        Ordered LMS course ids, one per path.
	 * @param array<int,string>   $path_keys         Course id to path key.
	 * @param array<string,mixed> $placement         Placement settings (au_key, guide).
	 * @param string              $rules             Rules text.
	 * @param array<int,mixed>    $badges            Badge declarations.
	 * @param array<string,mixed> $badge_issuer      Badge issuer settings (signer, claimPage).
	 * @param string              $custody           Lms-launched, author-lrs, server-kept or practice.
	 * @param string              $course_server_url Course server URL.
	 * @param string              $au_url_template   AU URL template containing {key}.
	 * @param boolean             $waive_recommended Whether recommended AUs may be waived.
	 * @param integer             $version           Structure version.
	 * @param string              $etag              Structure etag.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $stable_key,
		public readonly string $iri_base,
		public readonly string $course_iri = '',
		public readonly array $course_ids = array(),
		public readonly array $path_keys = array(),
		public readonly array $placement = array(),
		public readonly string $rules = '',
		public readonly array $badges = array(),
		public readonly array $badge_issuer = array(),
		public readonly string $custody = 'lms-launched',
		public readonly string $course_server_url = '',
		public readonly string $au_url_template = '',
		public readonly bool $waive_recommended = true,
		public readonly int $version = 0,
		public readonly string $etag = ''
	) {}
}
