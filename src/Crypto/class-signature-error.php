<?php
/**
 * Signed request verification failure.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Crypto;

/**
 * Carries one of `missing_header`, `unknown_key`, `stale`, `replay`, `bad_signature`.
 */
class Signature_Error extends \RuntimeException {

	/**
	 * Reason code.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Constructor.
	 *
	 * @param string $reason Reason code; also the message, so nothing sensitive is carried.
	 */
	public function __construct( string $reason ) {
		parent::__construct( $reason );
		$this->reason = $reason;
	}

	/**
	 * Reason code.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
}
