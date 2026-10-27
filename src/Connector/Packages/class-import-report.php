<?php
/**
 * Outcome of a package import.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

/**
 * What an import created, updated and drafted, or why it was refused.
 */
final class Import_Report {

	/**
	 * Constructor.
	 *
	 * @param integer                                              $program_id Program post id, 0 when nothing was written.
	 * @param string[]                                             $created    Created items as course:<key> or lesson:<key>.
	 * @param string[]                                             $updated    Rewritten items, same form.
	 * @param string[]                                             $drafted    Lessons set to draft, as lesson:<key>.
	 * @param array<array{code:string,path:string,message:string}> $errors     Validator-shaped errors.
	 */
	public function __construct(
		public readonly int $program_id,
		public readonly array $created = array(),
		public readonly array $updated = array(),
		public readonly array $drafted = array(),
		public readonly array $errors = array()
	) {}

	/**
	 * A report for a refused or failed import.
	 *
	 * @param array<array{code:string,path:string,message:string}> $errors     Errors.
	 * @param integer                                              $program_id Program id, if one was reached.
	 * @param string[]                                             $created    Items created before the failure.
	 * @param string[]                                             $updated    Items updated before the failure.
	 *
	 * @return self
	 */
	public static function failed( array $errors, int $program_id = 0, array $created = array(), array $updated = array() ): self {
		return new self( $program_id, $created, $updated, array(), $errors );
	}

	/**
	 * Whether the import succeeded.
	 *
	 * @return boolean
	 */
	public function ok(): bool {
		return array() === $this->errors;
	}

	/**
	 * Array form for JSON responses.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'program_id' => $this->program_id,
			'created'    => $this->created,
			'updated'    => $this->updated,
			'drafted'    => $this->drafted,
			'errors'     => $this->errors,
		);
	}
}
