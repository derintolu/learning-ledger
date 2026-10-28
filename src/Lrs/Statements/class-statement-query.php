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
 *
 * Hashes and UUIDs are passed as hex to `UNHEX(%s)`, so no raw bytes reach the query text.
 */
final class Statement_Query {

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
	 * Index per driving filter, most selective first: registration, then actor, then object, then verb.
	 *
	 * @var array<string,string>
	 */
	private const INDEXES = array(
		'registration' => 'registration_seq',
		'actor'        => 'actor_seq',
		'agent'        => 'actor_seq, object_seq',
		'actor_home'   => 'actor_home_seq',
		'object'       => 'object_seq',
		'verb'         => 'verb_seq',
	);

	/**
	 * How far consistent-through lags behind now.
	 */
	private const CONSISTENCY_LAG = '-1 second';

	/**
	 * Run a list query.
	 *
	 * Returns non-voided statements (voiding statements included) within the caller's read
	 * filter, stored no later than the consistent-through time captured on the first page.
	 * A statement whose object is a StatementRef also matches the non-time filters when the
	 * statement it targets does (recursively); time and paging bounds apply to the statement itself.
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

		$read   = self::read_filter( $p );
		$match  = self::match_filter( $q, 's' );
		$bounds = self::bounds( $q, $upper, $c );

		$rows = $this->select(
			'',
			array( 's.voided = 0', ...$read['where'], ...$match['where'], ...$bounds['where'] ),
			array( ...$read['args'], ...$match['args'], ...$bounds['args'] ),
			self::index( array_merge( $read['drivers'], $match['drivers'] ) ),
			$q
		);

		if ( array() !== $match['where'] ) {
			$rows = self::merge( $rows, $this->referrers( $q, $read, $bounds ), $q );
		}

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
		$read  = self::read_filter( $p );
		$where = array( 's.statement_id = UNHEX(%s)', 's.voided = %d', ...$read['where'] );
		$args  = array( bin2hex( Uuid::to_bin( $id ) ), $voided ? 1 : 0, ...$read['args'] );

		global $wpdb;
		$table = Tables::name( 'statements' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . Statement_Repository::COLUMNS . " FROM {$table} s WHERE " . implode( ' AND ', $where ), $args ), ARRAY_A );
		return is_array( $row ) ? Statement_Repository::hydrate( $row ) : null;
	}

	/**
	 * Statements whose object is a StatementRef to a statement that matches the query's
	 * non-time filters, directly or through further StatementRefs.
	 *
	 * @param Query_Params $q      Query.
	 * @param array        $read   Read filter (read_filter()).
	 * @param array        $bounds Time and paging bounds (bounds()).
	 *
	 * @return Stored_Statement[]
	 */
	private function referrers( Query_Params $q, array $read, array $bounds ): array {
		$table  = Tables::name( 'statements' );
		$target = self::match_filter( $q, 't' );
		$hint   = self::hint( self::index( $target['drivers'] ) );
		$uuid   = "LOWER(CONCAT_WS('-', SUBSTR(HEX(m.sid), 1, 8), SUBSTR(HEX(m.sid), 9, 4), SUBSTR(HEX(m.sid), 13, 4), SUBSTR(HEX(m.sid), 17, 4), SUBSTR(HEX(m.sid), 21, 12)))";
		$cte    = "WITH RECURSIVE matched (sid) AS (SELECT t.statement_id FROM {$table} t{$hint} WHERE " . implode( ' AND ', $target['where'] )
			. " UNION SELECT r.statement_id FROM matched m INNER JOIN {$table} r ON r.object_kind = 'StatementRef' AND r.object_hash = UNHEX(SHA1(CONCAT('ref:', {$uuid})))) ";

		return $this->select(
			$cte,
			array( 's.voided = 0', "s.object_kind = 'StatementRef'", 's.statement_id IN (SELECT sid FROM matched)', ...$read['where'], ...$bounds['where'] ),
			array( ...$target['args'], ...$read['args'], ...$bounds['args'] ),
			null,
			$q
		);
	}

	/**
	 * Run one page query: up to limit + 1 rows in seq order.
	 *
	 * @param string       $prefix SQL before SELECT (a CTE), or ''.
	 * @param string[]     $where  Conditions.
	 * @param array        $args   Placeholder values, in order.
	 * @param string|null  $index  Index list to force, if any.
	 * @param Query_Params $q      Query.
	 *
	 * @return Stored_Statement[]
	 */
	private function select( string $prefix, array $where, array $args, ?string $index, Query_Params $q ): array {
		global $wpdb;
		$table  = Tables::name( 'statements' );
		$order  = $q->ascending ? 'ASC' : 'DESC';
		$sql    = $prefix . 'SELECT ' . Statement_Repository::COLUMNS . " FROM {$table} s" . self::hint( $index ) . ' WHERE ' . implode( ' AND ', $where ) . " ORDER BY s.seq {$order} LIMIT %d";
		$args[] = $q->limit + 1;
		$found  = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array_map( array( Statement_Repository::class, 'hydrate' ), is_array( $found ) ? $found : array() );
	}

	/**
	 * Merge two seq-ordered row lists without duplicates, keeping the first limit + 1.
	 *
	 * @param Stored_Statement[] $a Rows.
	 * @param Stored_Statement[] $b Rows.
	 * @param Query_Params       $q Query.
	 *
	 * @return Stored_Statement[]
	 */
	private static function merge( array $a, array $b, Query_Params $q ): array {
		$by_seq = array();
		foreach ( array_merge( $a, $b ) as $row ) {
			$by_seq[ $row->seq ] = $row;
		}
		if ( $q->ascending ) {
			ksort( $by_seq );
		} else {
			krsort( $by_seq );
		}
		return array_slice( array_values( $by_seq ), 0, $q->limit + 1 );
	}

	/**
	 * The caller's read filter on `s`: bindings, and `authority_client` without `statements/read`.
	 *
	 * @param Principal $p Caller.
	 *
	 * @return array{where:string[],args:array,drivers:string[]}
	 */
	private static function read_filter( Principal $p ): array {
		$f      = array(
			'where'   => array(),
			'args'    => array(),
			'drivers' => array(),
		);
		$filter = $p->bindings()->read_filter();
		if ( ! $p->can( Scopes::STATEMENTS_READ ) ) {
			$f['where'][] = 's.authority_client = %s';
			$f['args'][]  = $p->authority_key();
		}
		if ( isset( $filter['registration'] ) ) {
			$f['where'][]   = 's.registration = UNHEX(%s)';
			$f['args'][]    = bin2hex( Uuid::to_bin( $filter['registration'] ) );
			$f['drivers'][] = 'registration';
		}
		if ( isset( $filter['actor_hash'] ) ) {
			$f['where'][]   = 's.actor_hash = UNHEX(%s)';
			$f['args'][]    = bin2hex( $filter['actor_hash'] );
			$f['drivers'][] = 'actor';
		}
		if ( isset( $filter['actor_home_hash'] ) ) {
			$f['where'][]   = 's.actor_home_hash = UNHEX(%s)';
			$f['args'][]    = bin2hex( $filter['actor_home_hash'] );
			$f['drivers'][] = 'actor_home';
		}
		return $f;
	}

	/**
	 * The query's non-time filters (agent, verb, activity, registration) on a table alias.
	 *
	 * @param Query_Params $q     Query.
	 * @param string       $alias Table alias.
	 *
	 * @return array{where:string[],args:array,drivers:string[]}
	 */
	private static function match_filter( Query_Params $q, string $alias ): array {
		$f = array(
			'where'   => array(),
			'args'    => array(),
			'drivers' => array(),
		);
		if ( null !== $q->registration ) {
			$f['where'][]   = "{$alias}.registration = UNHEX(%s)";
			$f['args'][]    = bin2hex( Uuid::to_bin( $q->registration ) );
			$f['drivers'][] = 'registration';
		}
		if ( null !== $q->agent_hash ) {
			$hex = bin2hex( $q->agent_hash );
			if ( $q->related_agents ) {
				$f['where'][] = self::ref_match( $alias, self::AGENT_KINDS );
				array_push( $f['args'], $hex, ...self::AGENT_KINDS );
			} else {
				$f['where'][]   = "({$alias}.actor_hash = UNHEX(%s) OR ({$alias}.object_kind IN ('Agent', 'Group') AND {$alias}.object_hash = UNHEX(%s)))";
				$f['args'][]    = $hex;
				$f['args'][]    = $hex;
				$f['drivers'][] = 'agent';
			}
		}
		if ( null !== $q->activity ) {
			$hex = sha1( $q->activity );
			if ( $q->related_activities ) {
				$f['where'][] = self::ref_match( $alias, self::ACTIVITY_KINDS );
				array_push( $f['args'], $hex, ...self::ACTIVITY_KINDS );
			} else {
				$f['where'][]   = "{$alias}.object_kind = 'Activity' AND {$alias}.object_hash = UNHEX(%s)";
				$f['args'][]    = $hex;
				$f['drivers'][] = 'object';
			}
		}
		if ( null !== $q->verb ) {
			$f['where'][]   = "{$alias}.verb_hash = UNHEX(%s)";
			$f['args'][]    = sha1( $q->verb );
			$f['drivers'][] = 'verb';
		}
		return $f;
	}

	/**
	 * Time and paging bounds on `s`: the upper bound, since, until and the cursor's seq.
	 *
	 * @param Query_Params       $q     Query.
	 * @param \DateTimeImmutable $upper Upper bound on `stored`.
	 * @param More_Cursor|null   $c     Cursor.
	 *
	 * @return array{where:string[],args:array}
	 */
	private static function bounds( Query_Params $q, \DateTimeImmutable $upper, ?More_Cursor $c ): array {
		$where = array( 's.`stored` <= %s' );
		$args  = array( Timestamp::to_db( $upper ) );
		if ( null !== $q->since ) {
			$where[] = 's.`stored` > %s';
			$args[]  = $q->since;
		}
		if ( null !== $q->until ) {
			$where[] = 's.`stored` <= %s';
			$args[]  = $q->until;
		}
		if ( null !== $c ) {
			$where[] = $q->ascending ? 's.seq > %d' : 's.seq < %d';
			$args[]  = $c->last_seq();
		}
		return array(
			'where' => $where,
			'args'  => $args,
		);
	}

	/**
	 * The index for the most selective driving filter present.
	 *
	 * @param string[] $drivers Driving filters present.
	 *
	 * @return string|null
	 */
	private static function index( array $drivers ): ?string {
		foreach ( self::INDEXES as $driver => $index ) {
			if ( in_array( $driver, $drivers, true ) ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * FORCE INDEX clause.
	 *
	 * @param string|null $index Index list (from INDEXES), or null.
	 *
	 * @return string
	 */
	private static function hint( ?string $index ): string {
		return null === $index ? '' : ' FORCE INDEX (' . $index . ')';
	}

	/**
	 * Condition: the statement has a ref with the hash (first placeholder) and one of the kinds.
	 *
	 * @param string   $alias Table alias.
	 * @param string[] $kinds Ref kinds.
	 *
	 * @return string
	 */
	private static function ref_match( string $alias, array $kinds ): string {
		$refs = Tables::name( 'statement_refs' );
		return "{$alias}.seq IN (SELECT x.seq FROM {$refs} x WHERE x.ref_hash = UNHEX(%s) AND x.ref_kind IN (" . implode( ', ', array_fill( 0, count( $kinds ), '%s' ) ) . '))';
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
}
