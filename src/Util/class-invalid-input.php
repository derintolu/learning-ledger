<?php
/**
 * Exception thrown by pure classes on invalid input.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Util;

/**
 * Carries a machine-readable error code and the path of the offending value.
 */
class Invalid_Input extends \InvalidArgumentException {

	/**
	 * Machine-readable code.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Path to the offending value.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Constructor.
	 *
	 * @param string $message    Message.
	 * @param string $error_code Error code.
	 * @param string $path       Path of the offending value.
	 */
	public function __construct( string $message, string $error_code = 'invalid', string $path = '' ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
		$this->path       = $path;
	}

	/**
	 * Error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Path of the offending value.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}
}
