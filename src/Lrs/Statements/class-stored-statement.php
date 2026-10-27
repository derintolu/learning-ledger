<?php
/**
 * A stored statement row.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Statements;

use LearningLedger\Util\Json;

/**
 * One row of `lledger_statements`.
 */
final class Stored_Statement {

	/**
	 * Constructor.
	 *
	 * @param integer     $seq              Stored order.
	 * @param string      $id               Statement id (lowercase UUID).
	 * @param string      $stored           Stored time, xAPI format.
	 * @param string      $version          Statement version.
	 * @param string|null $registration     Registration (lowercase UUID), if any.
	 * @param string      $actor_hash       Raw SHA-1 actor hash.
	 * @param string      $authority_client Principal::authority_key() of the writer.
	 * @param boolean     $is_voiding       Whether this statement voids another.
	 * @param string|null $voids_id         Target of a voiding statement.
	 * @param boolean     $voided           Whether this statement has been voided.
	 * @param string      $body             The statement JSON exactly as stored.
	 */
	public function __construct(
		public readonly int $seq,
		public readonly string $id,
		public readonly string $stored,
		public readonly string $version,
		public readonly ?string $registration,
		public readonly string $actor_hash,
		public readonly string $authority_client,
		public readonly bool $is_voiding,
		public readonly ?string $voids_id,
		public readonly bool $voided,
		public readonly string $body
	) {}

	/**
	 * The statement, decoded (objects as associative arrays).
	 *
	 * @return array
	 */
	public function statement(): array {
		return (array) Json::decode_strict( $this->body, PHP_INT_MAX, 512 );
	}
}
