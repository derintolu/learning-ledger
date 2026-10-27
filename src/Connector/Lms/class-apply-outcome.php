<?php
/**
 * Outcome of applying a result.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * Apply outcome.
 */
final class Apply_Outcome {

	const APPLIED  = 'applied';
	const SKIPPED  = 'skipped';
	const DEFERRED = 'deferred';
	const FAILED   = 'failed';

	/**
	 * Constructor.
	 *
	 * @param string $status  One of the status constants.
	 * @param string $message Detail.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $message = ''
	) {}
}
