<?php
/**
 * Reference to an activity by IRI.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * Activity reference.
 */
final class Activity_Ref {

	/**
	 * Constructor.
	 *
	 * @param string $iri Activity IRI.
	 */
	public function __construct( public readonly string $iri ) {}
}
