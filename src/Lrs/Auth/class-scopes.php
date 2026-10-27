<?php
/**
 * Client scopes.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

use LearningLedger\Util\Invalid_Input;

/**
 * The xAPI 1.0.3 OAuth scope names plus the plugin's own, and how they imply each other.
 */
final class Scopes {

	const STATEMENTS_WRITE     = 'statements/write';
	const STATEMENTS_READ      = 'statements/read';
	const STATEMENTS_READ_MINE = 'statements/read/mine';
	const STATE                = 'state';
	const PROFILE              = 'profile';
	const DEFINE               = 'define';
	const ALL_READ             = 'all/read';
	const ALL                  = 'all';

	const VOID               = 'void';
	const COURSES_WRITE      = 'courses/write';
	const BADGES_ISSUE       = 'badges/issue';
	const BADGES_CLAIM_LINKS = 'badges/claim-links';
	const RECORDS_VIEW_LINKS = 'records/view-links';
	const ACTORS_ERASE       = 'actors/erase';
	const TOKENS_MINT        = 'tokens/mint';

	/**
	 * The xAPI scopes (the ones `all` implies).
	 *
	 * @var string[]
	 */
	const XAPI = array(
		self::STATEMENTS_WRITE,
		self::STATEMENTS_READ,
		self::STATEMENTS_READ_MINE,
		self::STATE,
		self::PROFILE,
		self::DEFINE,
		self::ALL_READ,
		self::ALL,
	);

	/**
	 * The plugin's own scopes (never implied by anything else).
	 *
	 * @var string[]
	 */
	const OURS = array(
		self::VOID,
		self::COURSES_WRITE,
		self::BADGES_ISSUE,
		self::BADGES_CLAIM_LINKS,
		self::RECORDS_VIEW_LINKS,
		self::ACTORS_ERASE,
		self::TOKENS_MINT,
	);

	/**
	 * Whether holding one scope grants another.
	 *
	 * `all` implies every xAPI scope (not ours); `all/read` implies the statement read scopes;
	 * `statements/read` implies `statements/read/mine`. Read access to documents under `all/read`
	 * is checked by the document handlers against `all/read` itself, so it never grants the
	 * `state` or `profile` scopes, which also allow writes.
	 *
	 * @param string $held   Scope the caller holds.
	 * @param string $wanted Scope required.
	 *
	 * @return boolean
	 */
	public static function implies( string $held, string $wanted ): bool {
		if ( $held === $wanted ) {
			return true;
		}
		switch ( $held ) {
			case self::ALL:
				return in_array( $wanted, self::XAPI, true );
			case self::ALL_READ:
				return self::STATEMENTS_READ === $wanted || self::STATEMENTS_READ_MINE === $wanted;
			case self::STATEMENTS_READ:
				return self::STATEMENTS_READ_MINE === $wanted;
			default:
				return false;
		}
	}

	/**
	 * Reject unknown scope names.
	 *
	 * @param array $scopes Scope names.
	 *
	 * @return void
	 * @throws Invalid_Input Code `unknown_scope`.
	 */
	public static function validate( array $scopes ): void {
		foreach ( $scopes as $s ) {
			if ( ! is_string( $s ) || ( ! in_array( $s, self::XAPI, true ) && ! in_array( $s, self::OURS, true ) ) ) {
				throw new Invalid_Input( 'Unknown scope.', 'unknown_scope', 'scopes' );
			}
		}
	}
}
