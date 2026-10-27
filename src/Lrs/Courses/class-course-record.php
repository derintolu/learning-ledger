<?php
/**
 * An imported course.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Courses;

use LearningLedger\Cmi5\Course_Structure;

/**
 * A course as the LRS holds it: the AU and block tree, badge declarations and who owns it.
 */
final class Course_Record {

	/**
	 * Constructor.
	 *
	 * @param string     $course_iri   Course IRI (course@id).
	 * @param integer    $version      Import version, 1 for the first import.
	 * @param string     $etag         ETag of the imported bytes.
	 * @param array      $tree         Course_Structure::to_array().
	 * @param array      $badges       Badge declarations (`course.json#/badges`), empty for plain cmi5.
	 * @param array|null $badge_issuer Value of `course.json#/badgeIssuer`, null when absent.
	 * @param boolean    $custom_rules Whether any declaration's awardOn is not a standard rule.
	 * @param string     $owner_client Client id that imported the course.
	 */
	public function __construct(
		public readonly string $course_iri,
		public readonly int $version,
		public readonly string $etag,
		private readonly array $tree,
		public readonly array $badges,
		public readonly ?array $badge_issuer,
		public readonly bool $custom_rules,
		public readonly string $owner_client
	) {}

	/**
	 * The parsed AU and block tree.
	 *
	 * @return Course_Structure
	 */
	public function structure(): Course_Structure {
		return Course_Structure::from_array( $this->tree );
	}
}
