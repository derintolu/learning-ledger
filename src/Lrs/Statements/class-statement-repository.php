<?php
/**
 * Statement rows.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Statements;

use LearningLedger\Db\Tables;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables, never cached: statements are read back immediately.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names and column lists are interpolated.

/**
 * Reads and writes `lledger_statements`, `lledger_statement_refs` and `lledger_pending_voids`.
 *
 * Bodies are never updated: the only change to a stored row is the `voided` flag.
 */
final class Statement_Repository {

	/**
	 * Columns read into a Stored_Statement.
	 */
	private const COLUMNS = 'seq, statement_id, `stored`, version, registration, actor_hash, authority_client, is_voiding, voids_id, voided, body';

	/**
	 * A statement by id.
	 *
	 * @param string $id Statement id (any case).
	 *
	 * @return Stored_Statement|null
	 */
	public function find( string $id ): ?Stored_Statement {
		if ( ! Uuid::is_valid( $id ) ) {
			return null;
		}
		global $wpdb;
		$table = Tables::name( 'statements' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE statement_id = UNHEX(%s)", self::hex_uuid( $id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * A statement by seq.
	 *
	 * @param integer $seq Seq.
	 *
	 * @return Stored_Statement|null
	 */
	public function find_by_seq( int $seq ): ?Stored_Statement {
		global $wpdb;
		$table = Tables::name( 'statements' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE seq = %d", $seq ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Statements of a registration, in stored order.
	 *
	 * @param string  $registration   Registration UUID.
	 * @param boolean $include_voided Whether voided statements are included.
	 *
	 * @return Stored_Statement[]
	 */
	public function for_registration( string $registration, bool $include_voided = false ): array {
		if ( ! Uuid::is_valid( $registration ) ) {
			return array();
		}
		global $wpdb;
		$table  = Tables::name( 'statements' );
		$voided = $include_voided ? '' : ' AND voided = 0';
		$rows   = $wpdb->get_results(
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE registration = UNHEX(%s){$voided} ORDER BY seq", self::hex_uuid( $registration ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Approximate number of statements (the highest seq).
	 *
	 * @return integer
	 */
	public function approx_count(): int {
		global $wpdb;
		$table = Tables::name( 'statements' );
		return (int) $wpdb->get_var( "SELECT MAX(seq) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Delete every statement whose actor has this hash, with its index rows (erasure only, Task 44).
	 *
	 * @param string $actor_hash Raw SHA-1 actor hash.
	 *
	 * @return integer Statements deleted.
	 */
	public function delete_for_actor( string $actor_hash ): int {
		global $wpdb;
		$statements = Tables::name( 'statements' );
		$refs       = Tables::name( 'statement_refs' );
		$hex        = bin2hex( $actor_hash );
		$wpdb->query( $wpdb->prepare( "DELETE r FROM {$refs} r INNER JOIN {$statements} s ON s.seq = r.seq WHERE s.actor_hash = UNHEX(%s)", $hex ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$statements} WHERE actor_hash = UNHEX(%s)", $hex ) );
	}

	/**
	 * Insert a statement row.
	 *
	 * @param array $row Keys: statement_id, stored, timestamp (DB format), version, verb_hash, actor_hash,
	 *                   actor_home_hash (or null), object_kind, object_hash, registration (or null),
	 *                   authority_client, is_voiding, voids_id (or null), voided, content_sha256, body.
	 *                   Hashes raw bytes, UUIDs as text.
	 *
	 * @return integer The new seq, or 0 when the statement id already exists.
	 * @throws \RuntimeException On any other database error.
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$table        = Tables::name( 'statements' );
		$values       = array(
			'statement_id'     => array( 'UNHEX(%s)', self::hex_uuid( $row['statement_id'] ) ),
			'`stored`'         => array( '%s', $row['stored'] ),
			'timestamp'        => array( '%s', $row['timestamp'] ),
			'version'          => array( '%s', $row['version'] ),
			'verb_hash'        => array( 'UNHEX(%s)', bin2hex( $row['verb_hash'] ) ),
			'actor_hash'       => array( 'UNHEX(%s)', bin2hex( $row['actor_hash'] ) ),
			'actor_home_hash'  => null === $row['actor_home_hash'] ? null : array( 'UNHEX(%s)', bin2hex( $row['actor_home_hash'] ) ),
			'object_kind'      => array( '%s', $row['object_kind'] ),
			'object_hash'      => array( 'UNHEX(%s)', bin2hex( $row['object_hash'] ) ),
			'registration'     => null === $row['registration'] ? null : array( 'UNHEX(%s)', self::hex_uuid( $row['registration'] ) ),
			'authority_client' => array( '%s', $row['authority_client'] ),
			'is_voiding'       => array( '%d', $row['is_voiding'] ? 1 : 0 ),
			'voids_id'         => null === $row['voids_id'] ? null : array( 'UNHEX(%s)', self::hex_uuid( $row['voids_id'] ) ),
			'voided'           => array( '%d', $row['voided'] ? 1 : 0 ),
			'content_sha256'   => array( 'UNHEX(%s)', bin2hex( $row['content_sha256'] ) ),
			'body'             => array( '%s', $row['body'] ),
		);
		$placeholders = array();
		$args         = array();
		foreach ( $values as $value ) {
			if ( null === $value ) {
				$placeholders[] = 'NULL';
				continue;
			}
			$placeholders[] = $value[0];
			$args[]         = $value[1];
		}
		$sql = "INSERT INTO {$table} (" . implode( ', ', array_keys( $values ) ) . ') VALUES (' . implode( ', ', $placeholders ) . ')';

		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed placeholders above.
		$wpdb->suppress_errors( $suppress );
		if ( false === $ok ) {
			if ( str_contains( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
				return 0;
			}
			throw new \RuntimeException( 'Could not store the statement.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert the index rows of a statement.
	 *
	 * @param integer                               $seq  Statement seq.
	 * @param array<array{hash:string,kind:string}> $refs Refs (Ref_Indexer::refs()).
	 *
	 * @return void
	 * @throws \RuntimeException On a database error.
	 */
	public function insert_refs( int $seq, array $refs ): void {
		if ( array() === $refs ) {
			return;
		}
		global $wpdb;
		$table = Tables::name( 'statement_refs' );
		$rows  = array();
		$args  = array();
		foreach ( $refs as $ref ) {
			$rows[] = '(UNHEX(%s), %s, %d)';
			array_push( $args, bin2hex( $ref['hash'] ), $ref['kind'], $seq );
		}
		$sql = "INSERT IGNORE INTO {$table} (ref_hash, ref_kind, seq) VALUES " . implode( ', ', $rows );
		if ( false === $wpdb->query( $wpdb->prepare( $sql, $args ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed placeholders above.
			throw new \RuntimeException( 'Could not index the statement.' );
		}
	}

	/**
	 * Set the voided flag of a statement that is not itself a voiding statement.
	 *
	 * @param string $target_id Target statement id.
	 *
	 * @return boolean Whether a stored target was found.
	 * @throws \RuntimeException On a database error.
	 */
	public function mark_voided( string $target_id ): bool {
		global $wpdb;
		$table = Tables::name( 'statements' );
		$hex   = self::hex_uuid( $target_id );
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET voided = 1 WHERE statement_id = UNHEX(%s) AND is_voiding = 0", $hex ) ) ) {
			throw new \RuntimeException( 'Could not void the statement.' );
		}
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT seq FROM {$table} WHERE statement_id = UNHEX(%s) AND is_voiding = 0", $hex ) );
	}

	/**
	 * Remember a voiding statement whose target has not arrived. The first one wins.
	 *
	 * @param string  $target_id   Target statement id.
	 * @param integer $voiding_seq Seq of the voiding statement.
	 *
	 * @return void
	 * @throws \RuntimeException On a database error.
	 */
	public function add_pending_void( string $target_id, int $voiding_seq ): void {
		global $wpdb;
		$table = Tables::name( 'pending_voids' );
		if ( false === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (target_id, voiding_seq) VALUES (UNHEX(%s), %d)", self::hex_uuid( $target_id ), $voiding_seq ) ) ) {
			throw new \RuntimeException( 'Could not record the pending void.' );
		}
	}

	/**
	 * Remove and return the pending void of a statement, if any.
	 *
	 * @param string $target_id Statement id.
	 *
	 * @return integer|null Seq of the voiding statement.
	 * @throws \RuntimeException On a database error.
	 */
	public function take_pending_void( string $target_id ): ?int {
		global $wpdb;
		$table = Tables::name( 'pending_voids' );
		$hex   = self::hex_uuid( $target_id );
		$seq   = $wpdb->get_var( $wpdb->prepare( "SELECT voiding_seq FROM {$table} WHERE target_id = UNHEX(%s) FOR UPDATE", $hex ) );
		if ( null === $seq ) {
			return null;
		}
		if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE target_id = UNHEX(%s)", $hex ) ) ) {
			throw new \RuntimeException( 'Could not apply the pending void.' );
		}
		return (int) $seq;
	}

	/**
	 * UUID as 32 hex digits.
	 *
	 * @param string $id UUID.
	 *
	 * @return string
	 */
	private static function hex_uuid( string $id ): string {
		return str_replace( '-', '', strtolower( $id ) );
	}

	/**
	 * Row to value object.
	 *
	 * @param array $row Row.
	 *
	 * @return Stored_Statement
	 */
	private static function hydrate( array $row ): Stored_Statement {
		return new Stored_Statement(
			(int) $row['seq'],
			Uuid::from_bin( $row['statement_id'] ),
			Timestamp::to_xapi( Timestamp::from_db( $row['stored'] ) ),
			$row['version'],
			null === $row['registration'] ? null : Uuid::from_bin( $row['registration'] ),
			$row['actor_hash'],
			$row['authority_client'],
			1 === (int) $row['is_voiding'],
			null === $row['voids_id'] ? null : Uuid::from_bin( $row['voids_id'] ),
			1 === (int) $row['voided'],
			$row['body']
		);
	}
}
