<?php
/**
 * Outcome of validating a package.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Package;

/**
 * A list of errors; ok when empty.
 */
final class Validation_Result {

	/**
	 * Errors.
	 *
	 * @var array<array{code:string,path:string,message:string}>
	 */
	private array $errors;

	/**
	 * Constructor.
	 *
	 * @param array<array{code:string,path:string,message:string}> $errors Errors.
	 */
	public function __construct( array $errors = array() ) {
		$this->errors = array_values( $errors );
	}

	/**
	 * Whether the package is valid.
	 *
	 * @return boolean
	 */
	public function ok(): bool {
		return array() === $this->errors;
	}

	/**
	 * Errors.
	 *
	 * @return array<array{code:string,path:string,message:string}>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
