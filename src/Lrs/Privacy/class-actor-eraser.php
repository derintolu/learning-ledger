<?php
/**
 * GDPR erasure of one actor from the LRS.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Privacy;

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Origin_Index;
use LearningLedger\Lrs\Documents\Document_Store;
use LearningLedger\Lrs\Resources\Agent_Repository;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Xapi\Agent_Identity;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables, never cached.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names are interpolated.

/**
 * Hard-deletes an actor's statements (with their ref rows), State and Agent Profile documents,
 * agent row, child tokens and view/claim links, in one transaction.
 *
 * Other actors' statements that name the erased actor (as instructor, object, context agent and
 * so on) are kept. Only their index is changed: the actor's hash in their ref rows, and in
 * `object_hash` where the object is that agent, becomes SHA-1('tombstone'). Their bodies stay
 * immutable, so the reference remains in the stored JSON: a known, documented limitation.
 * Badge revocation and badge rows are handled by listeners of `learning_ledger/actor_erased`.
 */
final class Actor_Eraser {

	/**
	 * Input of the SHA-1 that replaces an erased actor's hash in other statements' index rows.
	 */
	const TOMBSTONE = 'tombstone';

	/**
	 * Statement storage.
	 *
	 * @var Statement_Repository
	 */
	private Statement_Repository $statements;

	/**
	 * Document storage.
	 *
	 * @var Document_Store
	 */
	private Document_Store $documents;

	/**
	 * Agent storage.
	 *
	 * @var Agent_Repository
	 */
	private Agent_Repository $agents;

	/**
	 * Constructor.
	 *
	 * @param Statement_Repository|null $statements Statement storage.
	 * @param Document_Store|null       $documents  Document storage.
	 * @param Agent_Repository|null     $agents     Agent storage.
	 */
	public function __construct( ?Statement_Repository $statements = null, ?Document_Store $documents = null, ?Agent_Repository $agents = null ) {
		$this->statements = $statements ?? new Statement_Repository();
		$this->documents  = $documents ?? new Document_Store();
		$this->agents     = $agents ?? new Agent_Repository();
	}

	/**
	 * Erase an actor. Erasing an actor again finds nothing and returns zero counts.
	 *
	 * @param Agent_Identity $a  Actor.
	 * @param string         $by Who asked, for the audit row, e.g. `client:<id>` or `user:<id>`.
	 *
	 * @return array{statements:int,refs:int,documents:int,agents:int,tokens:int,links:int}
	 * @throws \Throwable Whatever failed, after rolling back.
	 */
	public function erase( Agent_Identity $a, string $by ): array {
		global $wpdb;
		$hash = $a->hash();

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Could not start the erasure transaction.' );
		}
		try {
			$seqs   = $this->lock_own_statements( $hash );
			$counts = array(
				'statements' => 0,
				'refs'       => $this->own_ref_count( $hash ),
			);

			$counts['statements'] = $this->statements->delete_for_actor( $hash );
			self::assert_no_db_error();
			$this->assert_own_rows_gone( $hash, $seqs, $counts['statements'] );
			$counts['refs']     += $this->tombstone( $hash );
			$counts['documents'] = $this->documents->delete_for_agent( $a );
			$this->agents->delete( $a );
			$counts['agents'] = (int) $wpdb->rows_affected;
			self::assert_no_db_error();
			$counts['tokens'] = self::delete_by_actor_hash( 'tokens', $hash );
			$counts['links']  = self::delete_by_actor_hash( 'links', $hash );

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Could not commit the erasure.' );
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		if ( $counts['tokens'] > 0 ) {
			Origin_Index::flush();
		}

		Audit_Log::record( 'actor_erased', $counts, $by );
		try {
			do_action( 'learning_ledger/actor_erased', $hash, $counts ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		} catch ( \Throwable $e ) {
			// The erasure is committed; a failing listener must not turn it into an error for the caller.
			error_log( 'Learning Ledger: actor_erased listener failed: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operators need the cause.
		}

		return $counts;
	}

	/**
	 * Lock the actor's statements for the transaction and return their seqs.
	 *
	 * @param string $hash Raw actor hash.
	 *
	 * @return int[]
	 * @throws \RuntimeException On a database error.
	 */
	private function lock_own_statements( string $hash ): array {
		global $wpdb;
		$statements = Tables::name( 'statements' );
		$seqs       = $wpdb->get_col( $wpdb->prepare( "SELECT seq FROM {$statements} WHERE actor_hash = UNHEX(%s) FOR UPDATE", bin2hex( $hash ) ) );
		self::assert_no_db_error();
		return array_map( 'intval', $seqs );
	}

	/**
	 * Check that the statements, their ref rows and their pending voids are all gone, so a
	 * failure in any of Statement_Repository::delete_for_actor()'s DELETEs rolls the erasure back.
	 *
	 * @param string  $hash    Raw actor hash.
	 * @param int[]   $seqs    Seqs locked before the delete.
	 * @param integer $deleted Statements the delete reported.
	 *
	 * @return void
	 * @throws \RuntimeException When anything is left.
	 */
	private function assert_own_rows_gone( string $hash, array $seqs, int $deleted ): void {
		global $wpdb;
		$fail = new \RuntimeException( 'Could not delete the actor\'s statements.' );
		if ( count( $seqs ) !== $deleted ) {
			throw $fail;
		}
		$statements = Tables::name( 'statements' );
		if ( 0 !== (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$statements} WHERE actor_hash = UNHEX(%s)", bin2hex( $hash ) ) ) ) {
			throw $fail;
		}
		$refs    = Tables::name( 'statement_refs' );
		$pending = Tables::name( 'pending_voids' );
		foreach ( array_chunk( $seqs, 1000 ) as $chunk ) {
			$in = implode( ',', $chunk );
			if ( 0 !== (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$refs} WHERE seq IN ({$in})" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer seqs only.
				|| 0 !== (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pending} WHERE voiding_seq IN ({$in})" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer seqs only.
				throw $fail;
			}
		}
		self::assert_no_db_error();
	}

	/**
	 * Throw when the last query failed.
	 *
	 * @return void
	 * @throws \RuntimeException On a database error.
	 */
	private static function assert_no_db_error(): void {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Could not erase the actor.' );
		}
	}

	/**
	 * Ref rows of the actor's own statements (deleted with them).
	 *
	 * @param string $hash Raw actor hash.
	 *
	 * @return integer
	 * @throws \RuntimeException On a database error.
	 */
	private function own_ref_count( string $hash ): int {
		global $wpdb;
		$refs       = Tables::name( 'statement_refs' );
		$statements = Tables::name( 'statements' );
		$count      = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$refs} r INNER JOIN {$statements} s ON s.seq = r.seq WHERE s.actor_hash = UNHEX(%s)", bin2hex( $hash ) )
		);
		self::assert_no_db_error();
		return $count;
	}

	/**
	 * Replace the actor's hash in the remaining (other actors') index rows by the tombstone hash.
	 *
	 * A ref row that would duplicate an existing tombstone row (same kind and statement) is deleted.
	 *
	 * @param string $hash Raw actor hash.
	 *
	 * @return integer Ref rows changed or removed.
	 * @throws \RuntimeException On a database error.
	 */
	private function tombstone( string $hash ): int {
		global $wpdb;
		$refs       = Tables::name( 'statement_refs' );
		$statements = Tables::name( 'statements' );
		$hex        = bin2hex( $hash );
		$tombstone  = sha1( self::TOMBSTONE );

		$moved = $wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$refs} SET ref_hash = UNHEX(%s) WHERE ref_hash = UNHEX(%s)", $tombstone, $hex ) );
		$left  = $wpdb->query( $wpdb->prepare( "DELETE FROM {$refs} WHERE ref_hash = UNHEX(%s)", $hex ) );
		$obj   = $wpdb->query( $wpdb->prepare( "UPDATE {$statements} SET object_hash = UNHEX(%s) WHERE object_hash = UNHEX(%s) AND object_kind IN ('Agent', 'Group')", $tombstone, $hex ) );
		if ( false === $moved || false === $left || false === $obj ) {
			throw new \RuntimeException( 'Could not replace the erased actor in the index.' );
		}
		return (int) $moved + (int) $left;
	}

	/**
	 * Delete the rows of a table whose `actor_hash` is the actor's.
	 *
	 * @param string $short Short table name.
	 * @param string $hash  Raw actor hash.
	 *
	 * @return integer Rows deleted.
	 * @throws \RuntimeException On a database error.
	 */
	private static function delete_by_actor_hash( string $short, string $hash ): int {
		global $wpdb;
		$table   = Tables::name( $short );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE actor_hash = UNHEX(%s)", bin2hex( $hash ) ) );
		if ( false === $deleted ) {
			throw new \RuntimeException( 'Could not erase the actor.' );
		}
		return (int) $deleted;
	}
}
