<?php
/**
 * Migration import of an NDJSON export.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Export;

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Statements\Ref_Indexer;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- transaction control.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed raw.
// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming a file the administrator names, not a WordPress upload.

/**
 * Reads an export line by line and stores each statement with its original `stored` time and authority.
 *
 * Nothing is validated beyond structure and the content hash: the statements were accepted by the LRS
 * they came from. No `learning_ledger/statements_stored` hook fires, so nothing downstream reacts.
 */
final class Migration_Importer {

	/**
	 * Stored as `authority_client` of every imported statement. It names no client or token, so a void
	 * left pending by an import is dropped (and logged) if a later write would apply it.
	 */
	public const AUTHORITY_KEY = 'import';

	/**
	 * Longest line accepted, newline excluded (the largest request the LRS takes).
	 */
	public const MAX_LINE_BYTES = 2097152;

	/**
	 * Most problems kept for the caller.
	 */
	private const MAX_PROBLEMS = 100;

	/**
	 * Matches the line shape the exporter writes, capturing the statement bytes untouched.
	 */
	private const LINE = '/\A\{"statement":(\{.*\}),"stored":"([^"\\\\]+)","content_sha256":"([0-9a-fA-F]{64})"\}\z/s';

	/**
	 * Statement rows.
	 *
	 * @var Statement_Repository
	 */
	private Statement_Repository $repo;

	/**
	 * Statement store, for the index fields, the resource records and the pending-void check.
	 *
	 * @var Statement_Store
	 */
	private Statement_Store $store;

	/**
	 * Targets of voiding statements imported in this run before the target itself.
	 *
	 * @var array<string,int>
	 */
	private array $awaiting_target = array();

	/**
	 * Why lines were rejected.
	 *
	 * @var array<array{line:int,reason:string}>
	 */
	private array $problems = array();

	/**
	 * Constructor.
	 *
	 * @param Statement_Repository|null $repo Statement rows.
	 */
	public function __construct( ?Statement_Repository $repo = null ) {
		$this->repo  = $repo ?? new Statement_Repository();
		$this->store = new Statement_Store( $this->repo );
	}

	/**
	 * Import a file.
	 *
	 * @param string $path  NDJSON file.
	 * @param string $actor Audit actor.
	 *
	 * @return array{imported:int,skipped:int,rejected:int}
	 * @throws Invalid_Input Code `unreadable_file`.
	 */
	public function import( string $path, string $actor = 'cli' ): array {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new Invalid_Input( 'The file cannot be read.', 'unreadable_file' );
		}
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new Invalid_Input( 'The file cannot be read.', 'unreadable_file' );
		}

		$this->problems        = array();
		$this->awaiting_target = array();
		$result                = array(
			'imported' => 0,
			'skipped'  => 0,
			'rejected' => 0,
		);
		$number                = 0;
		while ( false !== ( $line = fgets( $handle, self::MAX_LINE_BYTES + 2 ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- reading line by line.
			++$number;
			$too_long = ! str_ends_with( $line, "\n" ) && ! feof( $handle );
			if ( $too_long ) {
				// Skip the rest of the line without keeping it.
				while ( false !== ( $rest = fgets( $handle, 65536 ) ) && ! str_ends_with( $rest, "\n" ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- draining one line.
					unset( $rest );
				}
			}
			$line = rtrim( $line, "\r\n" );
			if ( ! $too_long && '' === trim( $line ) ) {
				continue;
			}
			try {
				if ( $too_long ) {
					throw new Invalid_Input( 'The line is longer than ' . self::MAX_LINE_BYTES . ' bytes.', 'line_too_long' );
				}
				$outcome = $this->import_line( $line );
			} catch ( Invalid_Input $e ) {
				$outcome = $e->getMessage();
			} catch ( \Throwable $e ) {
				$outcome = 'The statement could not be stored.';
			}
			if ( 'imported' === $outcome || 'skipped' === $outcome ) {
				++$result[ $outcome ];
				continue;
			}
			++$result['rejected'];
			if ( count( $this->problems ) < self::MAX_PROBLEMS ) {
				$this->problems[] = array(
					'line'   => $number,
					'reason' => $outcome,
				);
			}
		}
		fclose( $handle );

		Audit_Log::record(
			'statements.import',
			array_merge(
				$result,
				array(
					'file'        => basename( $path ),
					'file_sha256' => (string) hash_file( 'sha256', $path ),
				)
			),
			$actor
		);
		return $result;
	}

	/**
	 * Why lines of the last import were rejected (at most 100).
	 *
	 * @return array<array{line:int,reason:string}>
	 */
	public function problems(): array {
		return $this->problems;
	}

	/**
	 * Check and store one line.
	 *
	 * @param string $line The line without its newline.
	 *
	 * @return string `imported` or `skipped`.
	 * @throws Invalid_Input With the reason the line is rejected.
	 */
	private function import_line( string $line ): string {
		[ $raw, $stored_text, $sha ] = self::split( $line );
		if ( ! hash_equals( $sha, hash( 'sha256', $raw ) ) ) {
			throw new Invalid_Input( 'content_sha256 does not match the statement.', 'hash_mismatch' );
		}
		$st = Json::decode_strict( $raw, PHP_INT_MAX, 512 );
		if ( ! is_array( $st ) || Json::is_list( $st ) ) {
			throw new Invalid_Input( 'The statement must be a JSON object.', 'invalid_statement' );
		}
		$row = self::row( $st, $raw, $stored_text, $sha );

		$existing = $this->repo->find( $row['statement_id'] );
		if ( null !== $existing ) {
			if ( hash_equals( $sha, hash( 'sha256', $existing->body ) ) ) {
				return 'skipped';
			}
			throw new Invalid_Input( 'A different statement with this id is already stored.', 'conflict' );
		}
		$this->store( $st, $row );
		return 'imported';
	}

	/**
	 * Split a line into statement bytes, stored time and hash.
	 *
	 * @param string $line Line.
	 *
	 * @return array{0:string,1:string,2:string}
	 * @throws Invalid_Input When the line is not an export line.
	 */
	private static function split( string $line ): array {
		if ( 1 === preg_match( self::LINE, $line, $m ) ) {
			return array( $m[1], $m[2], strtolower( $m[3] ) );
		}
		// Re-formatted by another tool: re-encode the statement exactly as the store would.
		$outer = json_decode( $line, false, 512 );
		if ( ! $outer instanceof \stdClass || ! isset( $outer->statement, $outer->stored, $outer->content_sha256 ) || ! $outer->statement instanceof \stdClass || ! is_string( $outer->stored ) || ! is_string( $outer->content_sha256 ) || 1 !== preg_match( '/\A[0-9a-fA-F]{64}\z/', $outer->content_sha256 ) ) {
			throw new Invalid_Input( 'Not an export line: expected statement, stored and content_sha256.', 'malformed_line' );
		}
		return array( Json::encode_exact( $outer->statement ), $outer->stored, strtolower( $outer->content_sha256 ) );
	}

	/**
	 * The database row of a statement, with the stored time and authority of the export.
	 *
	 * @param array  $st          Decoded statement.
	 * @param string $raw         Statement bytes.
	 * @param string $stored_text Stored time of the line.
	 * @param string $sha         Hex SHA-256 of the bytes.
	 *
	 * @return array Row for Statement_Repository::insert().
	 * @throws Invalid_Input When a required part is missing or invalid.
	 */
	private static function row( array $st, string $raw, string $stored_text, string $sha ): array {
		$id = $st['id'] ?? null;
		if ( ! is_string( $id ) || ! Uuid::is_valid( $id ) ) {
			throw new Invalid_Input( 'The statement has no valid id.', 'invalid_statement' );
		}
		foreach ( array( 'actor', 'object', 'authority' ) as $key ) {
			if ( ! isset( $st[ $key ] ) || ! is_array( $st[ $key ] ) ) {
				throw new Invalid_Input( 'The statement has no ' . $key . '.', 'invalid_statement' );
			}
		}
		if ( ! isset( $st['verb']['id'] ) || ! is_string( $st['verb']['id'] ) || '' === $st['verb']['id'] ) {
			throw new Invalid_Input( 'The statement has no verb.', 'invalid_statement' );
		}
		$version = $st['version'] ?? '1.0.0';
		if ( ! is_string( $version ) || '' === $version || strlen( $version ) > 8 ) {
			throw new Invalid_Input( 'The statement version is invalid.', 'invalid_statement' );
		}
		try {
			$stored = Timestamp::parse( $stored_text, '2.0.0' );
			if ( isset( $st['stored'] ) && ( ! is_string( $st['stored'] ) || Timestamp::parse( $st['stored'], '2.0.0' ) != $stored ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- DateTime value comparison.
				throw new Invalid_Input( 'The stored time of the line differs from the statement.', 'invalid_statement' );
			}
			$timestamp = $stored;
			if ( isset( $st['timestamp'] ) ) {
				try {
					$timestamp = Timestamp::parse( (string) $st['timestamp'], '2.0.0' );
				} catch ( Invalid_Input $e ) {
					$timestamp = Timestamp::parse( (string) $st['timestamp'], '1.0.3' );
				}
			}
		} catch ( Invalid_Input $e ) {
			throw new Invalid_Input( 'A time of the statement is invalid.', 'invalid_statement' );
		}

		$object = $st['object'];
		$kind   = $object['objectType'] ?? 'Activity';
		if ( ! is_string( $kind ) || ( ! in_array( $kind, array( 'Agent', 'Group', 'SubStatement' ), true ) && ! is_string( $object['id'] ?? null ) ) ) {
			throw new Invalid_Input( 'The statement object is invalid.', 'invalid_statement' );
		}
		if ( isset( $st['context'] ) && ! is_array( $st['context'] ) ) {
			throw new Invalid_Input( 'The statement context is invalid.', 'invalid_statement' );
		}

		$voids = null;
		if ( 'http://adlnet.gov/expapi/verbs/voided' === $st['verb']['id'] && 'StatementRef' === $kind && is_string( $object['id'] ?? null ) && Uuid::is_valid( $object['id'] ) ) {
			$voids = strtolower( $object['id'] );
		}

		$st['id'] = strtolower( $id );
		$index    = Statement_Store::index_fields( $st );
		if ( null !== $index['registration'] && ! Uuid::is_valid( $index['registration'] ) ) {
			$index['registration'] = null;
		}

		return array_merge(
			array(
				'statement_id' => strtolower( $id ),
				'stored'       => Timestamp::to_db( $stored ),
				'timestamp'    => Timestamp::to_db( $timestamp ),
				'version'      => $version,
			),
			$index,
			array(
				'authority_client' => self::AUTHORITY_KEY,
				'is_voiding'       => null !== $voids,
				'voids_id'         => $voids,
				'voided'           => false,
				'content_sha256'   => hex2bin( $sha ),
				'body'             => $raw,
			)
		);
	}

	/**
	 * Insert a statement, its index rows and its voiding effects in one transaction.
	 *
	 * @param array $st  Decoded statement.
	 * @param array $row Row.
	 *
	 * @return void
	 * @throws \Throwable Whatever failed, after rolling back.
	 */
	private function store( array $st, array $row ): void {
		global $wpdb;
		$awaiting = array();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Could not start the import transaction.' );
		}
		try {
			$seq = $this->repo->insert( $row );
			if ( 0 === $seq ) {
				throw new Invalid_Input( 'The statement id was taken by a concurrent write.', 'conflict' );
			}
			$this->repo->insert_refs( $seq, Ref_Indexer::refs( $st ) );

			if ( null !== $row['voids_id'] ) {
				if ( ! $this->repo->mark_voided( $row['voids_id'] ) && null === $this->repo->find( $row['voids_id'] ) ) {
					// The target comes later in this file (kept in memory, the import has no authority to leave a pending void behind).
					$awaiting[ $row['voids_id'] ] = true;
				}
			} else {
				$voided = isset( $this->awaiting_target[ $row['statement_id'] ] );
				// Voids left pending by live writes apply only if their writer may still void this statement.
				foreach ( $this->repo->take_pending_voids( $row['statement_id'] ) as $voiding_seq ) {
					$voided = $voided || $this->store->pending_void_allowed(
						$voiding_seq,
						array(
							'row' => array( 'statement' => $st ),
							'id'  => $row['statement_id'],
						)
					);
				}
				if ( $voided ) {
					$this->repo->mark_voided( $row['statement_id'] );
				}
			}

			$this->store->record_activities_and_agents( $st, false );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Could not commit the imported statement.' );
			}
			$this->awaiting_target = array_merge( array_diff_key( $this->awaiting_target, array( $row['statement_id'] => true ) ), $awaiting );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}
}
