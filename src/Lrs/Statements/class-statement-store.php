<?php
/**
 * Append-only statement store.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Statements;

use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Auth\Token_Repository;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Resources\Activity_Repository;
use LearningLedger\Lrs\Resources\Agent_Repository;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;
use LearningLedger\Xapi\Statement_Normalizer;
use LearningLedger\Xapi\Statement_Validator;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Xapi_Error messages are served as JSON, never echoed into HTML.
// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.

/**
 * Validates, checks and stores statements, all or nothing, in one transaction.
 *
 * Statements may contain `\stdClass` values (an empty `{}` stays an object in the stored body);
 * everything else sees them as associative arrays.
 */
final class Statement_Store {

	/**
	 * Inserts slower than this many seconds are counted and logged.
	 */
	private const SLOW_INSERT_SECONDS = 0.5;

	/**
	 * Option counting slow inserts.
	 */
	public const SLOW_INSERTS_OPTION = 'lledger_slow_inserts';

	/**
	 * Attempts at the transaction when a concurrent writer takes a statement id.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Statement rows.
	 *
	 * @var Statement_Repository
	 */
	private Statement_Repository $repo;

	/**
	 * Activity definitions.
	 *
	 * @var Activity_Repository
	 */
	private Activity_Repository $activities;

	/**
	 * Agents seen.
	 *
	 * @var Agent_Repository
	 */
	private Agent_Repository $agents;

	/**
	 * Constructor.
	 *
	 * @param Statement_Repository|null $repo       Statement rows.
	 * @param Activity_Repository|null  $activities Activity definitions.
	 * @param Agent_Repository|null     $agents     Agents seen.
	 */
	public function __construct( ?Statement_Repository $repo = null, ?Activity_Repository $activities = null, ?Agent_Repository $agents = null ) {
		$this->repo       = $repo ?? new Statement_Repository();
		$this->activities = $activities ?? new Activity_Repository();
		$this->agents     = $agents ?? new Agent_Repository();
	}

	/**
	 * Store statements (POST).
	 *
	 * @param array     $statements Statements.
	 * @param Principal $p          Caller.
	 * @param string    $version    Negotiated xAPI version.
	 *
	 * @return string[] Statement ids, in order.
	 * @throws Xapi_Error 400 invalid, duplicate id in the batch, voiding a voiding statement;
	 *                    403 scope, binding or non-account actor; 409 existing id with different content.
	 */
	public function store( array $statements, Principal $p, string $version ): array {
		return $this->write( array_values( $statements ), $p, $version );
	}

	/**
	 * Store one statement under a given id (PUT).
	 *
	 * @param string    $id        Statement id.
	 * @param array     $statement Statement.
	 * @param Principal $p         Caller.
	 * @param string    $version   Negotiated xAPI version.
	 *
	 * @return void
	 * @throws Xapi_Error As store(); also 400 when the id is not a UUID or the body's id differs.
	 */
	public function put( string $id, array $statement, Principal $p, string $version ): void {
		if ( ! Uuid::is_valid( $id ) ) {
			throw new Xapi_Error( 400, 'statementId must be a UUID.' );
		}
		if ( array_key_exists( 'id', $statement ) && ( ! is_string( $statement['id'] ) || strtolower( $statement['id'] ) !== strtolower( $id ) ) ) {
			throw new Xapi_Error( 400, 'The statement id does not match statementId.' );
		}
		$statement['id'] = strtolower( $id );
		$this->write( array( $statement ), $p, $version );
	}

	/**
	 * Check, normalise and insert.
	 *
	 * @param array[]   $statements Statements (a list).
	 * @param Principal $p          Caller.
	 * @param string    $version    Negotiated xAPI version.
	 *
	 * @return string[]
	 * @throws Xapi_Error See store().
	 */
	private function write( array $statements, Principal $p, string $version ): array {
		if ( array() === $statements ) {
			throw new Xapi_Error( 400, 'The request contains no statements.' );
		}
		if ( ! $p->can( Scopes::STATEMENTS_WRITE ) ) {
			throw new Xapi_Error( 403, 'This client may not write statements.' );
		}

		$items = $this->check( $statements, $p, $version );

		$now       = Clock::now();
		$authority = $p->authority();
		foreach ( $items as $i => $item ) {
			$norm               = Statement_Normalizer::normalize( $item['plain'], $version, $authority, $now );
			$body               = Json::encode_exact( self::restore_objects( $norm, $item['input'] ) );
			$items[ $i ]['id']  = $norm['id'];
			$items[ $i ]['row'] = $this->row( (array) Json::decode_strict( $body, PHP_INT_MAX, 512 ), $body, $p, $version, $item['target'] );
		}

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$new = array();
			foreach ( $items as $item ) {
				if ( ! $item['given_id'] || ! $this->exists_identical( $item ) ) {
					$new[] = $item;
				}
			}
			if ( array() === $new || $this->insert_all( $new, $p ) ) {
				return array_column( $items, 'id' );
			}
		}
		throw new Xapi_Error( 500, 'The statements could not be stored; try again.' );
	}

	/**
	 * Everything that can be checked before the transaction: validation, guards, scopes, bindings.
	 *
	 * @param array[]   $statements Statements.
	 * @param Principal $p          Caller.
	 * @param string    $version    Negotiated xAPI version.
	 *
	 * @return array[] Per statement: input, plain, given_id, target.
	 * @throws Xapi_Error See store().
	 */
	private function check( array $statements, Principal $p, string $version ): array {
		$guards = array_filter(
			(array) apply_filters( 'learning_ledger/statement_guards', array(), $p ),
			static fn( $g ) => $g instanceof Statement_Guard
		);

		$items = array();
		$ids   = array();
		foreach ( $statements as $i => $input ) {
			$where = count( $statements ) > 1 ? '[' . $i . ']' : '';
			$plain = is_array( $input ) ? self::plain( $input ) : null;
			if ( ! is_array( $plain ) ) {
				throw new Xapi_Error( 400, 'Invalid statement' . ( '' === $where ? '' : ' at ' . $where ) . ': a statement must be a JSON object.' );
			}
			try {
				Statement_Validator::validate( $plain, $version );
			} catch ( Invalid_Input $e ) {
				$path = '' === $e->path() ? $where : ( '' === $where ? $e->path() : $where . '.' . $e->path() );
				throw new Xapi_Error( 400, 'Invalid statement' . ( '' === $path ? '' : ' at ' . $path ) . ': ' . $e->getMessage() );
			}
			foreach ( $guards as $guard ) {
				$guard->check( $plain, $p );
			}
			if ( $p->rejects_non_account() && ! self::is_account( $plain['actor'] ) ) {
				throw new Xapi_Error( 403, 'This client only accepts statements whose actor is identified by an account.' );
			}

			$target = null;
			if ( Statement_Validator::VOIDED_VERB === $plain['verb']['id'] ) {
				if ( ! $p->can( Scopes::VOID ) ) {
					throw new Xapi_Error( 403, 'Voiding statements requires the void scope.' );
				}
				$target = strtolower( $plain['object']['id'] );
			}
			if ( ! $p->bindings()->allows_statement( $plain ) ) {
				throw new Xapi_Error( 403, 'The statement is outside this client\'s bindings.' );
			}

			$given = isset( $plain['id'] );
			if ( $given ) {
				$id = strtolower( $plain['id'] );
				if ( isset( $ids[ $id ] ) ) {
					throw new Xapi_Error( 400, 'The statement id ' . $id . ' appears more than once in the request.' );
				}
				$ids[ $id ] = $plain;
			}
			$items[] = array(
				'input'    => $input,
				'plain'    => $plain,
				'given_id' => $given,
				'target'   => $target,
			);
		}

		foreach ( $items as $item ) {
			if ( null === $item['target'] ) {
				continue;
			}
			if ( isset( $ids[ $item['target'] ] ) ) {
				$target = $ids[ $item['target'] ];
			} else {
				$found  = $this->repo->find( $item['target'] );
				$target = null === $found ? null : $found->statement();
			}
			if ( null !== $target && Statement_Validator::VOIDED_VERB === ( $target['verb']['id'] ?? null ) ) {
				throw new Xapi_Error( 400, 'A voiding statement cannot be voided.' );
			}
			// A target that has not arrived yet is checked against the voider's bindings on arrival.
			if ( null !== $target && ! $p->bindings()->allows_statement( $target ) ) {
				throw new Xapi_Error( 403, 'The voided statement is outside this client\'s bindings.' );
			}
		}
		return $items;
	}

	/**
	 * Whether a statement with this id is already stored with the same content.
	 *
	 * @param array $item Checked statement.
	 *
	 * @return boolean False when absent.
	 * @throws Xapi_Error 409 when stored with different content.
	 */
	private function exists_identical( array $item ): bool {
		$existing = $this->repo->find( $item['id'] );
		if ( null === $existing ) {
			return false;
		}
		$incoming = $item['plain'];
		$stored   = $existing->statement();
		// A timestamp the LRS assigned is not part of the client's content.
		if ( ! isset( $incoming['timestamp'] ) ) {
			unset( $stored['timestamp'] );
		}
		if ( ! Json::equal( Statement_Normalizer::comparable( $incoming ), Statement_Normalizer::comparable( $stored ) ) ) {
			throw new Xapi_Error( 409, 'A different statement with id ' . $item['id'] . ' is already stored.' );
		}
		return true;
	}

	/**
	 * Insert statements in one transaction.
	 *
	 * @param array[]   $items Statements to insert.
	 * @param Principal $p     Caller.
	 *
	 * @return boolean False when a concurrent writer took one of the ids (nothing stored).
	 * @throws \Throwable Whatever failed inside the transaction, after rolling back.
	 */
	private function insert_all( array $items, Principal $p ): bool {
		global $wpdb;
		$started = microtime( true );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw new \RuntimeException( 'Could not start the statements transaction.' );
		}
		try {
			foreach ( $items as $i => $item ) {
				$seq = $this->repo->insert( $item['row'] );
				if ( 0 === $seq ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return false;
				}
				$items[ $i ]['seq'] = $seq;
				$this->repo->insert_refs( $seq, Ref_Indexer::refs( $item['row']['statement'] ) );
			}

			$voided = array();
			$voids  = array();
			foreach ( $items as $item ) {
				$pending = $this->repo->take_pending_voids( $item['id'] );
				if ( null !== $item['target'] ) {
					if ( $this->repo->mark_voided( $item['target'] ) ) {
						$voided[ $item['target'] ] = true;
						$voids[]                   = array( $item['target'], $item['seq'] );
					} elseif ( null === $this->repo->find( $item['target'] ) ) {
						$this->repo->add_pending_void( $item['target'], $item['seq'] );
					}
					foreach ( $pending as $voiding_seq ) {
						self::log_dropped_void( $item['id'], $voiding_seq, 'a voiding statement cannot be voided' );
					}
					continue;
				}
				foreach ( $pending as $voiding_seq ) {
					if ( isset( $voided[ $item['id'] ] ) ) {
						self::log_dropped_void( $item['id'], $voiding_seq, 'the statement was already voided' );
					} elseif ( $this->pending_void_allowed( $voiding_seq, $item ) && $this->repo->mark_voided( $item['id'] ) ) {
						$voided[ $item['id'] ] = true;
						$voids[]               = array( $item['id'], $voiding_seq );
					}
				}
			}

			$rows = array();
			$seqs = array();
			foreach ( $items as $item ) {
				$rows[]               = self::stored( $item, isset( $voided[ $item['id'] ] ) );
				$seqs[ $item['seq'] ] = end( $rows );
				$this->record_activities_and_agents( $item['row']['statement'], $p->can( Scopes::DEFINE ) );
			}
			foreach ( $voids as $k => [ $target, $voiding_seq ] ) {
				$voids[ $k ][1] = $seqs[ $voiding_seq ] ?? $this->repo->find_by_seq( $voiding_seq );
			}

			do_action_ref_array( 'learning_ledger/statements_stored', array( $rows, $p ) );

			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				throw new \RuntimeException( 'Could not commit the statements.' );
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}

		self::note_duration( microtime( true ) - $started, count( $rows ) );

		do_action_ref_array( 'learning_ledger/statements_committed', array( $rows ) );
		foreach ( $voids as [ $target, $voiding ] ) {
			if ( $voiding instanceof Stored_Statement ) {
				do_action( 'learning_ledger/statement_voided', $target, $voiding );
			}
		}
		return true;
	}

	/**
	 * Whether a pending void may be applied to an arriving statement: the principal that
	 * wrote the voiding statement must still exist and its bindings must allow the target.
	 * A refused void is dropped and logged.
	 *
	 * @param integer $voiding_seq Seq of the voiding statement.
	 * @param array   $item        Arriving statement.
	 *
	 * @return boolean
	 */
	private function pending_void_allowed( int $voiding_seq, array $item ): bool {
		$voiding = $this->repo->find_by_seq( $voiding_seq );
		$voider  = null === $voiding ? null : self::principal_for( $voiding->authority_client );
		if ( null !== $voider && $voider->can( Scopes::VOID ) && $voider->bindings()->allows_statement( $item['row']['statement'] ) ) {
			return true;
		}
		self::log_dropped_void( $item['id'], $voiding_seq, 'the voiding client is gone, inactive, lacks void or is not bound to the target' );
		return false;
	}

	/**
	 * Log a pending void that was not applied.
	 *
	 * @param string  $target_id   Target statement id.
	 * @param integer $voiding_seq Seq of the voiding statement.
	 * @param string  $reason      Why.
	 *
	 * @return void
	 */
	private static function log_dropped_void( string $target_id, int $voiding_seq, string $reason ): void {
		error_log( sprintf( 'Learning Ledger: pending void of %s by statement seq %d dropped: %s.', $target_id, $voiding_seq, $reason ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- dropped voids are logged for the admin.
	}

	/**
	 * The principal behind a stored `authority_client` (Principal::authority_key()).
	 *
	 * @param string $key Authority key.
	 *
	 * @return Principal|null Null when the client or token no longer exists, the client is
	 *                        revoked or expired, or the token is revoked (an expired token
	 *                        still counts while its parent client is active).
	 */
	private static function principal_for( string $key ): ?Principal {
		if ( 'sys:' . Principal::SYSTEM_NAME === $key ) {
			return Principal::system();
		}
		$now     = Clock::now();
		$clients = new Client_Repository();
		if ( str_starts_with( $key, 'tok:' ) ) {
			$token = ( new Token_Repository() )->find( substr( $key, 4 ) );
			if ( null === $token || null !== $token->revoked_at ) {
				return null;
			}
			$parent = $clients->find( $token->parent_client );
			return null === $parent || ! $parent->is_active( $now ) ? null : Principal::for_token( $parent, $token );
		}
		$client = $clients->find( $key );
		return null === $client || ! $client->is_active( $now ) ? null : Principal::for_client( $client );
	}

	/**
	 * Row fields of a normalised statement.
	 *
	 * @param array       $st      Normalised statement (decoded from $body).
	 * @param string      $body    Stored body.
	 * @param Principal   $p       Caller.
	 * @param string      $version Negotiated xAPI version.
	 * @param string|null $target  Target of a voiding statement.
	 *
	 * @return array
	 */
	private function row( array $st, string $body, Principal $p, string $version, ?string $target ): array {
		$home = null;
		try {
			$identity = Agent_Identity::from_array( $st['actor'] );
			$home     = null === $identity->home_page() ? null : Agent_Identity::home_hash( $identity->home_page() );
		} catch ( Invalid_Input $e ) {
			unset( $e );
		}

		$object = $st['object'];
		$kind   = $object['objectType'] ?? 'Activity';
		switch ( $kind ) {
			case 'Agent':
			case 'Group':
				$object_hash = Ref_Indexer::agent_hash( $object ) ?? sha1( 'anon:', true );
				break;
			case 'StatementRef':
				$object_hash = sha1( 'ref:' . strtolower( $object['id'] ), true );
				break;
			case 'SubStatement':
				$object_hash = sha1( 'sub:' . $st['id'], true );
				break;
			default:
				$object_hash = sha1( $object['id'], true );
		}

		$registration = $st['context']['registration'] ?? null;
		try {
			$timestamp = Timestamp::parse( $st['timestamp'], $version );
		} catch ( Invalid_Input $e ) {
			$timestamp = Timestamp::parse( $st['timestamp'], '1.0.3' );
		}

		return array(
			'statement'        => $st,
			'statement_id'     => $st['id'],
			'stored'           => Timestamp::to_db( Timestamp::parse( $st['stored'], '2.0.0' ) ),
			'timestamp'        => Timestamp::to_db( $timestamp ),
			'version'          => $st['version'],
			'verb_hash'        => sha1( $st['verb']['id'], true ),
			'actor_hash'       => Ref_Indexer::agent_hash( $st['actor'] ) ?? sha1( 'anon:', true ),
			'actor_home_hash'  => $home,
			'object_kind'      => $kind,
			'object_hash'      => $object_hash,
			'registration'     => is_string( $registration ) ? strtolower( $registration ) : null,
			'authority_client' => $p->authority_key(),
			'is_voiding'       => null !== $target,
			'voids_id'         => $target,
			'voided'           => false,
			'content_sha256'   => hash( 'sha256', $body, true ),
			'body'             => $body,
		);
	}

	/**
	 * Value object for an inserted statement.
	 *
	 * @param array   $item   Inserted statement.
	 * @param boolean $voided Whether it was voided in this transaction.
	 *
	 * @return Stored_Statement
	 */
	private static function stored( array $item, bool $voided ): Stored_Statement {
		$row = $item['row'];
		return new Stored_Statement(
			$item['seq'],
			$row['statement_id'],
			$row['statement']['stored'],
			$row['version'],
			$row['registration'],
			$row['actor_hash'],
			$row['authority_client'],
			$row['is_voiding'],
			$row['voids_id'],
			$voided,
			$row['body']
		);
	}

	/**
	 * Merge the activities and record the agents a statement mentions.
	 *
	 * @param array   $st         Normalised statement.
	 * @param boolean $may_define Whether the caller may define activities.
	 *
	 * @return void
	 */
	private function record_activities_and_agents( array $st, bool $may_define ): void {
		$parts = array( $st );
		if ( 'SubStatement' === ( $st['object']['objectType'] ?? null ) ) {
			$parts[] = $st['object'];
		}

		$agents = array();
		if ( isset( $st['authority'] ) && is_array( $st['authority'] ) ) {
			self::collect_agent( $st['authority'], $agents );
		}
		foreach ( $parts as $part ) {
			$object = $part['object'];
			$kind   = $object['objectType'] ?? 'Activity';
			if ( 'Activity' === $kind ) {
				$this->activities->merge( $object['id'], isset( $object['definition'] ) && is_array( $object['definition'] ) ? $object['definition'] : null, $may_define );
			} elseif ( 'Agent' === $kind || 'Group' === $kind ) {
				self::collect_agent( $object, $agents );
			}
			self::collect_agent( $part['actor'], $agents );

			$context = isset( $part['context'] ) && is_array( $part['context'] ) ? $part['context'] : array();
			foreach ( array( 'parent', 'grouping', 'category', 'other' ) as $key ) {
				foreach ( Ref_Indexer::activity_list( $context['contextActivities'][ $key ] ?? null ) as $activity ) {
					$this->activities->merge( $activity['id'], isset( $activity['definition'] ) && is_array( $activity['definition'] ) ? $activity['definition'] : null, $may_define );
				}
			}
			foreach ( array( 'instructor', 'team' ) as $key ) {
				if ( isset( $context[ $key ] ) && is_array( $context[ $key ] ) ) {
					self::collect_agent( $context[ $key ], $agents );
				}
			}
			foreach ( (array) ( $context['contextAgents'] ?? array() ) as $entry ) {
				if ( isset( $entry['agent'] ) && is_array( $entry['agent'] ) ) {
					self::collect_agent( $entry['agent'], $agents );
				}
			}
			foreach ( (array) ( $context['contextGroups'] ?? array() ) as $entry ) {
				if ( isset( $entry['group'] ) && is_array( $entry['group'] ) ) {
					self::collect_agent( $entry['group'], $agents );
				}
			}
		}
		foreach ( $agents as $identity ) {
			$this->agents->record( $identity );
		}
	}

	/**
	 * Add an identified agent or group, and the identified members of a group.
	 *
	 * @param array            $agent  Agent or Group.
	 * @param Agent_Identity[] $agents Agents so far, keyed by ifi_key.
	 *
	 * @return void
	 */
	private static function collect_agent( array $agent, array &$agents ): void {
		try {
			$identity                       = Agent_Identity::from_array( $agent );
			$agents[ $identity->ifi_key() ] = $identity;
		} catch ( Invalid_Input $e ) {
			unset( $e );
		}
		if ( 'Group' === ( $agent['objectType'] ?? null ) && isset( $agent['member'] ) && is_array( $agent['member'] ) ) {
			foreach ( $agent['member'] as $member ) {
				if ( is_array( $member ) ) {
					self::collect_agent( $member, $agents );
				}
			}
		}
	}

	/**
	 * Whether an actor is identified by an account.
	 *
	 * @param array $actor Actor.
	 *
	 * @return boolean
	 */
	private static function is_account( array $actor ): bool {
		try {
			return 'account' === Agent_Identity::from_array( $actor )->kind();
		} catch ( Invalid_Input $e ) {
			return false;
		}
	}

	/**
	 * Count and log a slow insert.
	 *
	 * @param float   $seconds Duration.
	 * @param integer $count   Statements inserted.
	 *
	 * @return void
	 */
	private static function note_duration( float $seconds, int $count ): void {
		if ( $seconds <= self::SLOW_INSERT_SECONDS ) {
			return;
		}
		update_option( self::SLOW_INSERTS_OPTION, (int) get_option( self::SLOW_INSERTS_OPTION, 0 ) + 1, false );
		error_log( sprintf( 'Learning Ledger: storing %d statement(s) took %d ms.', $count, (int) round( $seconds * 1000 ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- slow inserts are logged by design (spec 6.5).
	}

	/**
	 * A statement with every object as an associative array.
	 *
	 * @param array $st Statement, possibly holding \stdClass values.
	 *
	 * @return mixed
	 */
	private static function plain( array $st ): mixed {
		try {
			return json_decode( Json::encode_exact( $st ), true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException | Invalid_Input $e ) {
			return null;
		}
	}

	/**
	 * Put back the objects of the input that decoding as arrays lost: an empty `{}`, and
	 * an object whose keys look like list indexes.
	 *
	 * @param mixed $out Normalised value.
	 * @param mixed $in  The same value as given.
	 *
	 * @return mixed
	 */
	private static function restore_objects( mixed $out, mixed $in ): mixed {
		$was_object = $in instanceof \stdClass;
		if ( $was_object ) {
			$in = get_object_vars( $in );
		}
		if ( ! is_array( $out ) || ! is_array( $in ) ) {
			return $out;
		}
		if ( $was_object && array() === $out && array() === $in ) {
			return new \stdClass();
		}
		// A single contextActivities object the normaliser wrapped in a list.
		if ( array() !== $in && ! array_key_exists( 0, $in ) && ! Json::is_list( $in ) && 1 === count( $out ) && array_key_exists( 0, $out ) && is_array( $out[0] ) ) {
			return array( self::restore_objects( $out[0], $was_object ? (object) $in : $in ) );
		}
		foreach ( $out as $key => $value ) {
			if ( array_key_exists( $key, $in ) ) {
				$out[ $key ] = self::restore_objects( $value, $in[ $key ] );
			}
		}
		return $was_object && array() !== $out && Json::is_list( $out ) ? (object) $out : $out;
	}
}
