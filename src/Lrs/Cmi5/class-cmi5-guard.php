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
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Statements\Ref_Indexer;
use LearningLedger\Lrs\Statements\Statement_Guard;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Stored_Statement;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;

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
	 * Seconds after `terminated` during which cmi5 allowed statements are still accepted.
	 */
	const GRACE_SECONDS = 600;

	/**
	 * Verbs only the LMS may use.
	 */
	const LMS_ONLY_VERBS = array( Vocab::LAUNCHED, Vocab::WAIVED, Vocab::SATISFIED, Vocab::ABANDONED );

	/**
	 * Launch modes in which only `initialized` and `terminated` defined statements may be sent.
	 */
	const NO_JUDGEMENT_MODES = array( 'Browse', 'Review' );

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
	 * @var array{passed:bool,completed:bool,terminated:bool,terminated_at:?\DateTimeImmutable,session:array<string,true>}|null
	 */
	private ?array $state = null;

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
	 * @throws Xapi_Error 400 for a missing or malformed session id; 403 for any other broken rule.
	 */
	public function check( array $statement, Principal $p ): void {
		$token = $p->token();
		if ( null === $token || ! $p->guard_enabled() ) {
			return;
		}
		// A statement already stored under this id is a resend: the store answers 204 when identical, 409 otherwise.
		if ( isset( $statement['id'] ) && is_string( $statement['id'] ) && null !== $this->statements->find( $statement['id'] ) ) {
			return;
		}

		$terminated_at = $this->terminated_at( $token );
		if ( null !== $terminated_at && Clock::now()->getTimestamp() - $terminated_at->getTimestamp() > self::GRACE_SECONDS ) {
			throw new Xapi_Error( 403, 'The AU session was terminated more than 10 minutes ago; no more statements are accepted for it.' );
		}

		$verb = $statement['verb']['id'];
		if ( in_array( $verb, self::LMS_ONLY_VERBS, true ) ) {
			throw new Xapi_Error( 403, 'Only the LMS may send statements with the verb ' . $verb . '.' );
		}

		$category = self::has_cmi5_category( $statement );
		$session  = self::session_of( $statement );
		if ( $category && null === $session ) {
			throw new Xapi_Error( 400, 'A cmi5 statement must carry the sessionid context extension.' );
		}
		if ( null !== $session && ! is_string( $session ) ) {
			throw new Xapi_Error( 400, 'The sessionid context extension must be a string.' );
		}
		if ( null !== $session && strtolower( $session ) !== strtolower( $token->session_id ) ) {
			throw new Xapi_Error( 403, 'The statement belongs to another AU session.' );
		}

		if ( ! $category || ! in_array( $verb, Vocab::VERBS, true ) ) {
			return;
		}
		$state = $this->state( $token );
		if ( $state['terminated'] ) {
			throw new Xapi_Error( 403, 'The AU session is terminated; no more cmi5 defined statements are accepted for it.' );
		}
		if ( in_array( $token->launch_mode, self::NO_JUDGEMENT_MODES, true ) && Vocab::INITIALIZED !== $verb && Vocab::TERMINATED !== $verb ) {
			throw new Xapi_Error( 403, 'In ' . $token->launch_mode . ' mode the AU may send only initialized and terminated cmi5 defined statements.' );
		}
		if ( ( $statement['object']['id'] ?? null ) !== $token->activity_id ) {
			throw new Xapi_Error( 403, 'A cmi5 defined statement must have the launched AU as its object.' );
		}
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
			if ( Vocab::TERMINATED !== ( $st['verb']['id'] ?? null ) || ! self::has_cmi5_category( $st ) ) {
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
	 * When the token's session was terminated: the token's own record, or the registration's
	 * statements when the token has none (another token of the session may have terminated it).
	 *
	 * @param Child_Token $token Token.
	 *
	 * @return \DateTimeImmutable|null
	 */
	private function terminated_at( Child_Token $token ): ?\DateTimeImmutable {
		if ( null === $this->state && null !== $token->terminated_at ) {
			return $token->terminated_at;
		}
		return $this->state( $token )['terminated_at'];
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
	 * @return array{passed:bool,completed:bool,terminated:bool,terminated_at:?\DateTimeImmutable,session:array<string,true>}
	 */
	private function state( Child_Token $token ): array {
		if ( null !== $this->state ) {
			return $this->state;
		}
		$state = array(
			'passed'        => false,
			'completed'     => false,
			'terminated'    => null !== $token->terminated_at,
			'terminated_at' => $token->terminated_at,
			'session'       => array(),
		);
		$actor = Ref_Indexer::agent_hash( $token->actor );
		foreach ( $this->statements->for_registration( $token->registration ) as $row ) {
			$st   = $row->statement();
			$verb = $st['verb']['id'] ?? null;
			if ( ! in_array( $verb, Vocab::VERBS, true ) || ! self::has_cmi5_category( $st ) ) {
				continue;
			}
			$session = self::session_of( $st );
			if ( Vocab::TERMINATED === $verb && is_string( $session ) && strtolower( $session ) === strtolower( $token->session_id ) ) {
				$at                     = Timestamp::parse( $row->stored, '2.0.0' );
				$state['terminated']    = true;
				$state['terminated_at'] = null === $state['terminated_at'] || $at < $state['terminated_at'] ? $at : $state['terminated_at'];
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
	 * Whether the statement has the cmi5 category activity (cmi5 §9.6.2.1).
	 *
	 * @param array $statement Statement.
	 *
	 * @return boolean
	 */
	private static function has_cmi5_category( array $statement ): bool {
		$category = $statement['context']['contextActivities']['category'] ?? null;
		foreach ( Ref_Indexer::activity_list( $category ) as $activity ) {
			if ( Vocab::CAT_CMI5 === $activity['id'] ) {
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
