<?php
/**
 * The cmi5 guard for AU child tokens.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Cmi5;

use LearningLedger\Cmi5\Vocab;
use LearningLedger\Kernel\Module;
use LearningLedger\Lrs\Auth\Child_Token;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Token_Repository;
use LearningLedger\Lrs\Documents\Doc_Key;
use LearningLedger\Lrs\Documents\Document_Store;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Router;
use LearningLedger\Lrs\Statements\Ref_Indexer;
use LearningLedger\Lrs\Statements\Statement_Guard;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Stored_Statement;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;
use LearningLedger\Xapi\Agent_Identity;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Xapi_Error messages are served as JSON, never echoed into HTML.
// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.

/**
 * Rejects statements from an AU's child token that break the cmi5 rules (cmi5 §9.3, §9.6.3.1, §10.2.2),
 * instead of storing them for the LMS to void (cmi5 §6.3).
 *
 * The module instance hooks in; each request gets a fresh guard, which also remembers the
 * statements accepted earlier in the same batch. Order is stored order.
 */
final class Cmi5_Guard implements Statement_Guard, Module {

	/**
	 * Verbs only the LMS may use.
	 */
	const LMS_ONLY_VERBS = array( Vocab::LAUNCHED, Vocab::WAIVED, Vocab::SATISFIED, Vocab::ABANDONED );

	/**
	 * Launch modes in which only `initialized` and `terminated` defined statements may be sent.
	 */
	const NO_JUDGEMENT_MODES = array( 'Browse', 'Review' );

	/**
	 * Defined verbs that judge the AU, so carry the moveOn category [cmi5 9.6.2.2].
	 */
	const MOVEON_VERBS = array( Vocab::COMPLETED, Vocab::PASSED, Vocab::FAILED );

	/**
	 * The learner preferences agent profile [cmi5 11].
	 */
	const PREFERENCES = 'cmi5LearnerPreferences';

	/**
	 * Transient prefix marking that a token has read the learner preferences.
	 */
	const PREFERENCES_READ = 'lledger_cmi5_prefs_';

	/**
	 * Child tokens.
	 *
	 * @var Token_Repository
	 */
	private Token_Repository $tokens;

	/**
	 * Statement rows.
	 *
	 * @var Statement_Repository
	 */
	private Statement_Repository $statements;

	/**
	 * What the registration and session already hold, plus this batch; null until first needed.
	 *
	 * @var array{passed:bool,completed:bool,terminated:bool,session:array<string,true>}|null
	 */
	private ?array $state = null;

	/**
	 * The masteryScore of the token's LMS.LaunchData (false until loaded, null when it has none).
	 *
	 * @var float|null|false
	 */
	private float|null|false $mastery = false;

	/**
	 * Constructor.
	 *
	 * @param Token_Repository|null     $tokens     Child tokens.
	 * @param Statement_Repository|null $statements Statement rows.
	 */
	public function __construct( ?Token_Repository $tokens = null, ?Statement_Repository $statements = null ) {
		$this->tokens     = $tokens ?? new Token_Repository();
		$this->statements = $statements ?? new Statement_Repository();
	}

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'learning_ledger/statement_guards', array( $this, 'add_guard' ), 10, 2 );
		add_action( 'learning_ledger/statements_committed', array( $this, 'on_committed' ) );
		add_filter( 'rest_request_after_callbacks', array( $this, 'on_xapi_response' ), 10, 3 );
	}

	/**
	 * Note a child token's read of its learner preferences, which must come before its
	 * `initialized` [cmi5 11.0.0.0-3]. A 404 counts: the AU asked.
	 *
	 * @param mixed            $response Response.
	 * @param mixed            $handler  Matched handler.
	 * @param \WP_REST_Request $request  Request.
	 *
	 * @return mixed The response, unchanged.
	 */
	public function on_xapi_response( mixed $response, mixed $handler, \WP_REST_Request $request ): mixed {
		unset( $handler );
		if ( ! $response instanceof \WP_REST_Response || ! in_array( $response->get_status(), array( 200, 404 ), true )
			|| 'GET' !== $request->get_method() || self::PREFERENCES !== $request->get_param( 'profileId' )
			|| 1 !== preg_match( '#^/' . preg_quote( Xapi_Router::REST_NAMESPACE, '#' ) . '/+agents/profile/*$#', $request->get_route() ) ) {
			return $response;
		}
		$header = (string) $request->get_header( 'Authorization' );
		$pair   = 1 === preg_match( '/^Basic\s+(\S+)$/i', $header, $m ) ? base64_decode( $m[1], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- HTTP Basic credentials.
		if ( ! is_string( $pair ) || ! str_contains( $pair, ':' ) ) {
			return $response;
		}
		list( $key, $secret ) = explode( ':', $pair, 2 );
		$token                = $this->tokens->find( $key );
		if ( null !== $token && $token->is_active( Clock::now() ) && $token->secret_matches( $secret ) ) {
			set_transient( self::PREFERENCES_READ . hash( 'sha256', $token->key ), 1, max( 60, $token->expires_at->getTimestamp() - Clock::now()->getTimestamp() ) );
		}
		return $response;
	}

	/**
	 * Add a fresh guard for a child token whose guard is on.
	 *
	 * @param mixed $guards Guards so far.
	 * @param mixed $p      Caller.
	 *
	 * @return array
	 */
	public function add_guard( mixed $guards, mixed $p ): array {
		$guards = (array) $guards;
		if ( $p instanceof Principal && $p->is_child() && $p->guard_enabled() ) {
			$guards[] = new self( $this->tokens, $this->statements );
		}
		return $guards;
	}

	/**
	 * Check one statement from the token.
	 *
	 * @param array     $statement Validated statement.
	 * @param Principal $p         Caller.
	 *
	 * @return void
	 * @throws Xapi_Error 403 for any broken rule.
	 */
	public function check( array $statement, Principal $p ): void {
		$token = $p->token();
		if ( null === $token || ! $p->guard_enabled() ) {
			return;
		}
		// A statement already stored under this id is a resend: the store answers 204 when identical, 409 otherwise.
		// Once the session is terminated only its terminated may be resent [cmi5 9.3.0.0-5].
		$stored = isset( $statement['id'] ) && is_string( $statement['id'] ) ? $this->statements->find( $statement['id'] ) : null;
		if ( null !== $stored ) {
			if ( ! $this->state( $token )['terminated'] || Vocab::TERMINATED === ( $stored->statement()['verb']['id'] ?? null ) ) {
				return;
			}
			throw new Xapi_Error( 403, 'The AU session is terminated; no more statements are accepted for it.' );
		}

		$verb = $statement['verb']['id'];
		if ( in_array( $verb, self::LMS_ONLY_VERBS, true ) ) {
			throw new Xapi_Error( 403, 'Only the LMS may send statements with the verb ' . $verb . '.' );
		}
		self::check_envelope( $statement, $token );

		$category = self::has_category( $statement, Vocab::CAT_CMI5 );
		$defined  = $category && in_array( $verb, Vocab::VERBS, true );
		$moveon   = in_array( $verb, self::MOVEON_VERBS, true );
		// The moveOn category marks exactly the defined completed, passed and failed statements [cmi5 9.6.2.2].
		if ( self::has_category( $statement, Vocab::CAT_MOVEON ) !== ( $defined && $moveon ) ) {
			throw new Xapi_Error( 403, 'The moveOn category belongs on cmi5 defined completed, passed and failed statements, and only there.' );
		}

		$state = $this->state( $token );
		// Initialized comes first and terminated last, in cmi5 allowed statements too [cmi5 9.3.0.0-4/5].
		if ( $state['terminated'] ) {
			throw new Xapi_Error( 403, 'The AU session is terminated; no more statements are accepted for it.' );
		}
		if ( Vocab::INITIALIZED !== $verb && ! isset( $state['session'][ Vocab::INITIALIZED ] ) ) {
			throw new Xapi_Error( 403, 'The AU session has not been initialized; initialized comes first.' );
		}
		if ( ! $defined ) {
			return;
		}
		if ( in_array( $token->launch_mode, self::NO_JUDGEMENT_MODES, true ) && Vocab::INITIALIZED !== $verb && Vocab::TERMINATED !== $verb ) {
			throw new Xapi_Error( 403, 'In ' . $token->launch_mode . ' mode the AU may send only initialized and terminated cmi5 defined statements.' );
		}
		if ( ( $statement['object']['id'] ?? null ) !== $token->activity_id ) {
			throw new Xapi_Error( 403, 'A cmi5 defined statement must have the launched AU as its object.' );
		}
		if ( Vocab::INITIALIZED === $verb && ! self::preferences_read( $token ) ) {
			throw new Xapi_Error( 403, 'The AU must read the cmi5LearnerPreferences agent profile before initialized.' );
		}
		$this->check_result( $statement, $verb, $token );
		if ( Vocab::PASSED === $verb && $state['passed'] ) {
			throw new Xapi_Error( 403, 'The AU has already been passed in this registration.' );
		}
		if ( Vocab::FAILED === $verb && $state['passed'] ) {
			throw new Xapi_Error( 403, 'A failed statement cannot follow a passed statement in this registration.' );
		}
		if ( Vocab::COMPLETED === $verb && $state['completed'] ) {
			throw new Xapi_Error( 403, 'The AU has already been completed in this registration.' );
		}
		// In one session no cmi5 defined verb repeats [cmi5 9.3.0.0-2] and only one of passed or failed is sent [9.3.0.0-3].
		if ( isset( $state['session'][ $verb ] ) ) {
			throw new Xapi_Error( 403, 'The AU session already has a ' . $verb . ' statement.' );
		}
		if ( ( Vocab::PASSED === $verb || Vocab::FAILED === $verb ) && ( isset( $state['session'][ Vocab::PASSED ] ) || isset( $state['session'][ Vocab::FAILED ] ) ) ) {
			throw new Xapi_Error( 403, 'The AU session already has a passed or failed statement.' );
		}

		$this->state['session'][ $verb ] = true;
		if ( Vocab::PASSED === $verb ) {
			$this->state['passed'] = true;
		} elseif ( Vocab::COMPLETED === $verb ) {
			$this->state['completed'] = true;
		} elseif ( Vocab::TERMINATED === $verb ) {
			$this->state['terminated'] = true;
		}
	}

	/**
	 * Rules for every statement from the token: an id, a UTC timestamp, the launched actor, the
	 * launch registration and session [cmi5 9.1, 9.2, 9.6.1, 9.6.3.1, 9.7].
	 *
	 * @param array       $statement Statement.
	 * @param Child_Token $token     Token.
	 *
	 * @return void
	 * @throws Xapi_Error 403.
	 */
	private static function check_envelope( array $statement, Child_Token $token ): void {
		if ( ! isset( $statement['id'] ) ) {
			throw new Xapi_Error( 403, 'The AU must give each statement an id.' );
		}
		$timestamp = $statement['timestamp'] ?? null;
		if ( ! is_string( $timestamp ) || 1 !== preg_match( '/(?:Z|[+-]00:?00)$/i', $timestamp ) ) {
			throw new Xapi_Error( 403, 'The AU must give each statement a timestamp in UTC.' );
		}
		$actor = is_array( $statement['actor'] ?? null ) ? $statement['actor'] : array();
		if ( 'Agent' !== ( $actor['objectType'] ?? 'Agent' ) || ! isset( $actor['account'] ) || Ref_Indexer::agent_hash( $actor ) !== Ref_Indexer::agent_hash( $token->actor ) ) {
			throw new Xapi_Error( 403, 'The actor must be the launched learner\'s account Agent.' );
		}
		$registration = $statement['context']['registration'] ?? null;
		if ( ! is_string( $registration ) || strtolower( $registration ) !== strtolower( $token->registration ) ) {
			throw new Xapi_Error( 403, 'The statement must carry the launch registration in its context.' );
		}
		$session = self::session_of( $statement );
		if ( ! is_string( $session ) ) {
			throw new Xapi_Error( 403, 'The statement must carry the sessionid context extension as a string.' );
		}
		if ( strtolower( $session ) !== strtolower( $token->session_id ) ) {
			throw new Xapi_Error( 403, 'The statement belongs to another AU session.' );
		}
	}

	/**
	 * Result rules of the defined verbs [cmi5 9.3.4, 9.3.5, 9.5, 9.6.3.2].
	 *
	 * @param array       $statement Statement.
	 * @param string      $verb      Verb id.
	 * @param Child_Token $token     Token.
	 *
	 * @return void
	 * @throws Xapi_Error 403.
	 */
	private function check_result( array $statement, string $verb, Child_Token $token ): void {
		$result = is_array( $statement['result'] ?? null ) ? $statement['result'] : array();
		if ( in_array( $verb, array( Vocab::COMPLETED, Vocab::PASSED, Vocab::FAILED, Vocab::TERMINATED ), true ) && ! isset( $result['duration'] ) ) {
			throw new Xapi_Error( 403, 'A ' . $verb . ' statement must carry result.duration.' );
		}
		if ( Vocab::COMPLETED === $verb ) {
			if ( true !== ( $result['completion'] ?? null ) || array_key_exists( 'success', $result ) || array_key_exists( 'score', $result ) ) {
				throw new Xapi_Error( 403, 'A completed statement has result.completion true and no success or score.' );
			}
			return;
		}
		if ( Vocab::PASSED !== $verb && Vocab::FAILED !== $verb ) {
			return;
		}
		if ( ( Vocab::PASSED === $verb ) !== ( $result['success'] ?? null ) || array_key_exists( 'completion', $result ) ) {
			throw new Xapi_Error( 403, 'A passed statement has result.success true, a failed one false, and neither has completion.' );
		}
		$score = is_array( $result['score'] ?? null ) ? $result['score'] : array();
		if ( isset( $score['raw'] ) && ( ! isset( $score['min'] ) || ! isset( $score['max'] ) ) ) {
			throw new Xapi_Error( 403, 'A raw score needs min and max.' );
		}
		$mastery = $this->mastery_score( $token );
		if ( null === $mastery ) {
			return;
		}
		$given = $statement['context']['extensions'][ Vocab::EXT_MASTERYSCORE ] ?? null;
		if ( ( ! is_int( $given ) && ! is_float( $given ) ) || abs( (float) $given - $mastery ) > 1e-9 ) {
			throw new Xapi_Error( 403, 'A passed or failed statement must carry the masteryScore from LMS.LaunchData.' );
		}
		$scaled = $score['scaled'] ?? null;
		if ( ( is_int( $scaled ) || is_float( $scaled ) ) && ( Vocab::PASSED === $verb ) !== ( (float) $scaled >= $mastery ) ) {
			throw new Xapi_Error( 403, 'A passed scaled score is at least the masteryScore and a failed one below it.' );
		}
	}

	/**
	 * The masteryScore of the token's LMS.LaunchData, loaded once.
	 *
	 * @param Child_Token $token Token.
	 *
	 * @return float|null
	 */
	private function mastery_score( Child_Token $token ): ?float {
		if ( false !== $this->mastery ) {
			return $this->mastery;
		}
		$this->mastery = null;
		try {
			$doc = ( new Document_Store() )->get( new Doc_Key( 'state', $token->activity_id, Agent_Identity::from_array( $token->actor ), $token->registration, 'LMS.LaunchData' ) );
		} catch ( Invalid_Input $e ) {
			return null;
		}
		$data  = null === $doc ? null : json_decode( $doc->content, true );
		$value = is_array( $data ) ? ( $data['masteryScore'] ?? null ) : null;
		if ( is_int( $value ) || is_float( $value ) ) {
			$this->mastery = (float) $value;
		}
		return $this->mastery;
	}

	/**
	 * Whether the token has read its learner preferences.
	 *
	 * @param Child_Token $token Token.
	 *
	 * @return boolean
	 */
	private static function preferences_read( Child_Token $token ): bool {
		return false !== get_transient( self::PREFERENCES_READ . hash( 'sha256', $token->key ) );
	}

	/**
	 * Record the session's termination on the token when its `terminated` statement is committed.
	 *
	 * @param mixed $rows Stored_Statement[] just committed.
	 *
	 * @return void
	 */
	public function on_committed( mixed $rows ): void {
		foreach ( (array) $rows as $row ) {
			if ( ! $row instanceof Stored_Statement || ! str_starts_with( $row->authority_client, 'tok:' ) ) {
				continue;
			}
			$st = $row->statement();
			if ( Vocab::TERMINATED !== ( $st['verb']['id'] ?? null ) || ! self::has_category( $st, Vocab::CAT_CMI5 ) ) {
				continue;
			}
			$token   = $this->tokens->find( substr( $row->authority_client, 4 ) );
			$session = self::session_of( $st );
			if ( null === $token || ! is_string( $session ) || strtolower( $session ) !== strtolower( $token->session_id ) ) {
				continue;
			}
			$this->tokens->mark_terminated( $token->key, Timestamp::parse( $row->stored, '2.0.0' ) );
		}
	}

	/**
	 * What the token's registration and session already hold (loaded once per request, when first needed).
	 *
	 * Passed and completed count the learner's unvoided cmi5 defined statements about the AU;
	 * the session counts as terminated once a cmi5 defined `terminated` with its session id is stored;
	 * `session` holds the cmi5 defined verbs the learner's statements in this session already used.
	 *
	 * @param Child_Token $token Token.
	 *
	 * @return array{passed:bool,completed:bool,terminated:bool,session:array<string,true>}
	 */
	private function state( Child_Token $token ): array {
		if ( null !== $this->state ) {
			return $this->state;
		}
		$state = array(
			'passed'     => false,
			'completed'  => false,
			'terminated' => null !== $token->terminated_at,
			'session'    => array(),
		);
		$actor = Ref_Indexer::agent_hash( $token->actor );
		foreach ( $this->statements->for_registration( $token->registration ) as $row ) {
			$st   = $row->statement();
			$verb = $st['verb']['id'] ?? null;
			if ( ! in_array( $verb, Vocab::VERBS, true ) || ! self::has_category( $st, Vocab::CAT_CMI5 ) ) {
				continue;
			}
			$session = self::session_of( $st );
			if ( Vocab::TERMINATED === $verb && is_string( $session ) && strtolower( $session ) === strtolower( $token->session_id ) ) {
				$state['terminated'] = true;
			}
			if ( $row->actor_hash !== $actor || ( $st['object']['id'] ?? null ) !== $token->activity_id ) {
				continue;
			}
			if ( is_string( $session ) && strtolower( $session ) === strtolower( $token->session_id ) ) {
				$state['session'][ $verb ] = true;
			}
			if ( Vocab::PASSED === $verb ) {
				$state['passed'] = true;
			} elseif ( Vocab::COMPLETED === $verb ) {
				$state['completed'] = true;
			}
		}
		$this->state = $state;
		return $state;
	}

	/**
	 * Whether the statement has a category activity (the cmi5 one marks defined statements, cmi5 §9.6.2.1).
	 *
	 * @param array  $statement Statement.
	 * @param string $id        Category activity id.
	 *
	 * @return boolean
	 */
	private static function has_category( array $statement, string $id ): bool {
		$category = $statement['context']['contextActivities']['category'] ?? null;
		foreach ( Ref_Indexer::activity_list( $category ) as $activity ) {
			if ( $id === $activity['id'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The sessionid context extension, as given.
	 *
	 * @param array $statement Statement.
	 *
	 * @return mixed Null when absent.
	 */
	private static function session_of( array $statement ): mixed {
		$extensions = $statement['context']['extensions'] ?? null;
		return is_array( $extensions ) ? ( $extensions[ Vocab::EXT_SESSIONID ] ?? null ) : null;
	}
}
