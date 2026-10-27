<?php
/**
 * Statement queries.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Statements;

use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables, never cached: results must reflect the latest writes.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- SQL is built from fixed fragments; every value goes through a placeholder.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Xapi_Error messages are served as JSON, never echoed into HTML.

/**
 * Runs statement queries within the caller's read filter, paged by `seq` under a fixed upper bound.
 */
final class Statement_Query {

	/**
	 * Columns read into a Stored_Statement.
	 */
	private const COLUMNS = 's.seq, s.statement_id, s.`stored`, s.version, s.registration, s.actor_hash, s.authority_client, s.is_voiding, s.voids_id, s.voided, s.body';

	/**
	 * Ref kinds `related_agents` matches.
	 *
	 * @var string[]
	 */
	private const AGENT_KINDS = array( 'actor', 'object-agent', 'authority', 'instructor', 'team', 'context-agent', 'context-group', 'substatement-actor', 'substatement-object-agent', 'substatement-instructor', 'substatement-team', 'substatement-context-agent', 'substatement-context-group' );

	/**
	 * Ref kinds `related_activities` matches.
	 *
	 * @var string[]
	 */
	private const ACTIVITY_KINDS = array( 'object-activity', 'context-parent', 'context-grouping', 'context-category', 'context-other', 'substatement-object-activity', 'substatement-context-parent', 'substatement-context-grouping', 'substatement-context-category', 'substatement-context-other' );

	/**
	 * How far consistent-through lags behind now.
	 */
	private const CONSISTENCY_LAG = '-1 second';

	/**
	 * Run a list query.
	 *
	 * Returns non-voided statements (voiding statements included) within the caller's read
	 * filter, stored no later than the consistent-through time captured on the first page.
	 *
	 * @param Query_Params     $q Query.
	 * @param Principal        $p Caller.
	 * @param More_Cursor|null $c Cursor of the previous page, if any.
	 *
	 * @return array{rows:Stored_Statement[],more:?string,consistent_through:string}
	 * @throws Xapi_Error 400 when the cursor belongs to another caller.
	 */
	public function run( Query_Params $q, Principal $p, ?More_Cursor $c = null ): array {
		if ( null !== $c ) {
			if ( ( $c->params()['caller'] ?? null ) !== self::caller( $p ) ) {
				throw new Xapi_Error( 400, 'The more token is invalid.' );
			}
			try {
				$upper = Timestamp::parse( $c->upper_bound(), '2.0.0' );
			} catch ( Invalid_Input $e ) {
				throw new Xapi_Error( 400, 'The more token is invalid.' );
			}
		} else {
			$upper = Timestamp::parse( self::consistent_through(), '2.0.0' );
		}
		$consistent = Timestamp::to_xapi( $upper );

		[ $index, $where, $args ] = self::filters( $q, $p );
		array_unshift( $where, 's.voided = 0' );
		$where[] = 's.`stored` <= %s';
		$args[]  = Timestamp::to_db( $upper );
		if ( null !== $c ) {
			$where[] = $q->ascending ? 's.seq > %d' : 's.seq < %d';
			$args[]  = $c->last_seq();
		}
		$args[] = $q->limit + 1;

		global $wpdb;
		$table = Tables::name( 'statements' );
		$hint  = null === $index ? '' : ' FORCE INDEX (' . $index . ')';
		$order = $q->ascending ? 'ASC' : 'DESC';
		$sql   = 'SELECT ' . self::COLUMNS . " FROM {$table} s{$hint} WHERE " . implode( ' AND ', $where ) . " ORDER BY s.seq {$order} LIMIT %d";
		$found = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$rows  = array_map( array( self::class, 'hydrate' ), is_array( $found ) ? $found : array() );

		$more = null;
		if ( count( $rows ) > $q->limit ) {
			$rows = array_slice( $rows, 0, $q->limit );
			$more = More_Cursor::encode(
				array(
					'query'  => $q->to_array(),
					'caller' => self::caller( $p ),
				),
				end( $rows )->seq,
				$consistent
			);
		}
		return array(
			'rows'               => $rows,
			'more'               => $more,
			'consistent_through' => $consistent,
		);
	}

	/**
	 * Consistent-through time for a new query: now minus one second, xAPI format.
	 *
	 * @return string
	 */
	public static function consistent_through(): string {
		return Timestamp::to_xapi( Clock::now()->modify( self::CONSISTENCY_LAG ) );
	}

	/**
	 * One statement by id, within the caller's read filter.
	 *
	 * @param string    $id     Statement id.
	 * @param boolean   $voided True for `voidedStatementId` (only a voided statement), false for `statementId` (only one that is not).
	 * @param Principal $p      Caller.
	 *
	 * @return Stored_Statement|null
	 */
	public function find( string $id, bool $voided, Principal $p ): ?Stored_Statement {
		if ( ! Uuid::is_valid( $id ) ) {
			return null;
		}
		[ , $where, $args ] = self::filters( null, $p );
		array_unshift( $where, 's.statement_id = UNHEX(%s)', 's.voided = %d' );
		array_unshift( $args, self::hex_uuid( $id ), $voided ? 1 : 0 );

		global $wpdb;
		$table = Tables::name( 'statements' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} s WHERE " . implode( ' AND ', $where ), $args ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * WHERE fragments and arguments for the caller's read filter and the query's filters,
	 * and the index to drive the scan: registration, then actor, then object, then verb.
	 * Hashes and UUIDs are passed as hex to `UNHEX(%s)`, so no raw bytes reach the query text.
	 *
	 * @param Query_Params|null $q Query (null: read filter only).
	 * @param Principal         $p Caller.
	 *
	 * @return array{0:?string,1:string[],2:array}
	 */
	private static function filters( ?Query_Params $q, Principal $p ): array {
		$where   = array();
		$args    = array();
		$indexes = array();

		$filter = $p->bindings()->read_filter();
		if ( ! $p->can( Scopes::STATEMENTS_READ ) ) {
			$where[] = 's.authority_client = %s';
			$args[]  = $p->authority_key();
		}
		foreach ( array_filter( array( $filter['registration'] ?? null, $q?->registration ) ) as $registration ) {
			$where[]   = 's.registration = UNHEX(%s)';
			$args[]    = self::hex_uuid( $registration );
			$indexes[] = 'registration_seq';
		}
		if ( isset( $filter['actor_hash'] ) ) {
			$where[]   = 's.actor_hash = UNHEX(%s)';
			$args[]    = bin2hex( $filter['actor_hash'] );
			$indexes[] = 'actor_seq';
		}
		if ( isset( $filter['actor_home_hash'] ) ) {
			$where[]   = 's.actor_home_hash = UNHEX(%s)';
			$args[]    = bin2hex( $filter['actor_home_hash'] );
			$indexes[] = 'actor_home_seq';
		}
		if ( null === $q ) {
			return array( null, $where, $args );
		}

		if ( null !== $q->agent_hash ) {
			$hex = bin2hex( $q->agent_hash );
			if ( $q->related_agents ) {
				$where[] = self::ref_match( self::AGENT_KINDS );
				$args    = array_merge( $args, array( $hex ), self::AGENT_KINDS );
			} else {
				$where[]   = "(s.actor_hash = UNHEX(%s) OR (s.object_kind IN ('Agent', 'Group') AND s.object_hash = UNHEX(%s)))";
				$args[]    = $hex;
				$args[]    = $hex;
				$indexes[] = 'actor_seq, object_seq';
			}
		}
		if ( null !== $q->activity ) {
			$hex = sha1( $q->activity );
			if ( $q->related_activities ) {
				$where[] = self::ref_match( self::ACTIVITY_KINDS );
				$args    = array_merge( $args, array( $hex ), self::ACTIVITY_KINDS );
			} else {
				$where[]   = "s.object_kind = 'Activity' AND s.object_hash = UNHEX(%s)";
				$args[]    = $hex;
				$indexes[] = 'object_seq';
			}
		}
		if ( null !== $q->verb ) {
			$where[]   = 's.verb_hash = UNHEX(%s)';
			$args[]    = sha1( $q->verb );
			$indexes[] = 'verb_seq';
		}
		if ( null !== $q->since ) {
			$where[] = 's.`stored` > %s';
			$args[]  = $q->since;
		}
		if ( null !== $q->until ) {
			$where[] = 's.`stored` <= %s';
			$args[]  = $q->until;
		}
		return array( $indexes[0] ?? null, $where, $args );
	}

	/**
	 * Condition: the statement has a ref with the hash (first placeholder) and one of the kinds.
	 *
	 * @param string[] $kinds Ref kinds.
	 *
	 * @return string
	 */
	private static function ref_match( array $kinds ): string {
		$refs = Tables::name( 'statement_refs' );
		return "s.seq IN (SELECT r.seq FROM {$refs} r WHERE r.ref_hash = UNHEX(%s) AND r.ref_kind IN (" . implode( ', ', array_fill( 0, count( $kinds ), '%s' ) ) . '))';
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
	 * Who a cursor belongs to: a digest of the caller's authority key.
	 *
	 * @param Principal $p Caller.
	 *
	 * @return string
	 */
	private static function caller( Principal $p ): string {
		return substr( hash( 'sha256', 'more|' . $p->authority_key() ), 0, 32 );
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
