<?php
/**
 * Contract for statement guards.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Statements;

use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Http\Xapi_Error;

/**
 * Extra checks a statement must pass before it is stored, registered through
 * the `learning_ledger/statement_guards` filter.
 */
interface Statement_Guard {

	/**
	 * Check one validated statement (before LRS-owned properties are set).
	 *
	 * @param array     $statement Decoded statement.
	 * @param Principal $p         Caller.
	 *
	 * @return void
	 * @throws Xapi_Error To reject the statement (and so the whole request).
	 */
	public function check( array $statement, Principal $p ): void;
}
