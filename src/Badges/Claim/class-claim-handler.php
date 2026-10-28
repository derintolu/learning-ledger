<?php
/**
 * Claim-time issuance of a pending badge.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Claim;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed; Claim_Page maps the codes to its own text.

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Issuer_Key;
use LearningLedger\Badges\Issuer\Issuer_Profile;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Badges\Signing\Eddsa_Rdfc_2022;
use LearningLedger\Badges\Status\Status_List_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Links\Link_Repository;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;

/**
 * The only place a badge credential is signed (spec §8.2): for a pending badge the job created,
 * when its learner redeems a claim link with an email address.
 *
 * The email is used only to compute the salted identity hash and is never stored. The link is
 * spent with one atomic UPDATE before anything is signed, so of two concurrent claims only one
 * goes on, and Badge_Repository::store_issued() only changes a pending row.
 */
final class Claim_Handler {

	/**
	 * Error code: the link is unknown, used, expired, or not for this badge's learner (HTTP 410).
	 */
	const GONE = 'claim_gone';

	/**
	 * Error code: the badge is no longer pending, or its trigger statement is gone (HTTP 409).
	 */
	const CONFLICT = 'claim_conflict';

	/**
	 * Error code: the email address is not valid (HTTP 400).
	 */
	const INVALID_EMAIL = 'claim_invalid_email';

	/**
	 * Longest email address accepted (RFC 5321 path limit).
	 */
	const MAX_EMAIL = 254;

	/**
	 * The xAPI version the `earned` statement is written in.
	 */
	const XAPI_VERSION = '2.0.0';

	/**
	 * Constructor.
	 *
	 * @param Badge_Repository       $badges     Badges.
	 * @param Link_Repository        $links      Claim links.
	 * @param Status_List_Repository $status     Status lists.
	 * @param Statement_Repository   $statements Statement reads (the trigger).
	 * @param Statement_Store        $store      Statement writes (the `earned` statement).
	 */
	public function __construct(
		private readonly Badge_Repository $badges = new Badge_Repository(),
		private readonly Link_Repository $links = new Link_Repository(),
		private readonly Status_List_Repository $status = new Status_List_Repository(),
		private readonly Statement_Repository $statements = new Statement_Repository(),
		private readonly Statement_Store $store = new Statement_Store()
	) {}

	/**
	 * Redeem a claim link: sign the badge for the email's salted hash and issue it.
	 *
	 * @param string  $token Claim link token.
	 * @param string  $email Recipient email address (trimmed and lowercased before hashing).
	 * @param boolean $share Whether the learner allows the public badge page.
	 *
	 * @return array The stored credential (the unsigned one when the `badge_sign` filter returned a VC-JWT).
	 *
	 * @throws Invalid_Input Code GONE, CONFLICT or INVALID_EMAIL.
	 * @throws \RuntimeException When no badge key is active, or signing or storage fails (before the badge is issued; nothing after issuance throws).
	 */
	public function claim( string $token, #[\SensitiveParameter] string $email, bool $share ): array {
		$link = 1 === preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $token ) ? $this->links->resolve( $token, 'claim' ) : null;
		if ( null === $link ) {
			throw new Invalid_Input( 'This claim link has expired or was already used.', self::GONE, 'token' );
		}
		$badge = $this->badges->get( $link['subject'] );
		if ( null === $badge || ! hash_equals( $badge['actor_hash'], $link['actor_hash'] ) ) {
			throw new Invalid_Input( 'This claim link has expired or was already used.', self::GONE, 'token' );
		}
		if ( Badge_Repository::PENDING !== $badge['status'] ) {
			throw new Invalid_Input( 'This badge cannot be claimed.', self::CONFLICT, 'badge' );
		}
		$email   = self::normalized_email( $email );
		$trigger = $this->statements->find( $badge['trigger_statement_id'] );
		$actor   = null === $trigger || $trigger->voided ? null : ( $trigger->statement()['actor'] ?? null );
		if ( ! is_array( $actor ) ) {
			throw new Invalid_Input( 'This badge cannot be claimed.', self::CONFLICT, 'badge' );
		}
		$key = Key_Store::active();
		if ( null === $key ) {
			throw new \RuntimeException( 'No badge key is active.' );
		}

		if ( ! $this->links->consume( $token ) ) {
			throw new Invalid_Input( 'This claim link has expired or was already used.', self::GONE, 'token' );
		}
		// Look again now the link is spent, so a badge revoked meanwhile does not take a status index.
		$fresh = $this->badges->get( $badge['id'] );
		if ( null === $fresh || Badge_Repository::PENDING !== $fresh['status'] ) {
			throw new Invalid_Input( 'This badge cannot be claimed.', self::CONFLICT, 'badge' );
		}

		$salt          = bin2hex( random_bytes( 16 ) );
		$identity_hash = 'sha256$' . hash( 'sha256', $email . $salt );
		unset( $email );
		$slot       = $this->status->allocate();
		$now        = Clock::now();
		$credential = Credential_Builder::build(
			$badge,
			$badge['achievement'],
			Did_Document::did(),
			(string) Issuer_Profile::document()['name'],
			$identity_hash,
			$salt,
			$this->status->entry( $slot['list_id'], $slot['index'] ),
			$badge['narrative'],
			$now
		);
		$signed     = self::sign( $credential, $key, $now );

		// One UPDATE of a pending row: the credential (JSON-LD or VC-JWT), status slot, hash, share choice.
		if ( ! $this->badges->store_issued( $badge['id'], $signed, $slot['list_id'], $slot['index'], $identity_hash, $salt, $share ) ) {
			throw new Invalid_Input( 'This badge cannot be claimed.', self::CONFLICT, 'badge' );
		}
		$stored = is_array( $signed ) ? $signed : $credential;

		// The badge is issued. Nothing below may fail the claim: the learner must get the result page.
		try {
			Audit_Log::record(
				'badge_issued',
				array(
					'badge'        => $badge['id'],
					'credential'   => $credential['id'],
					'achievement'  => (string) ( $badge['achievement']['id'] ?? '' ),
					'registration' => $badge['registration'],
					'kid'          => $key->kid,
					'status_list'  => $slot['list_id'],
					'status_index' => $slot['index'],
					'shared'       => $share,
				),
				$link['created_by']
			);
		} catch ( \Throwable $e ) {
			self::log( 'audit row', $badge['id'], $e );
		}

		$this->write_earned( $actor, $badge, $credential['id'] );

		try {
			/**
			 * Fires once when a badge is issued, after it is stored.
			 *
			 * @param string $credential_id Credential id (`urn:uuid:<badge id>`).
			 * @param array  $credential    The stored credential (the unsigned one when it was stored as a VC-JWT).
			 * @param string $registration  Registration UUID.
			 */
			do_action( 'learning_ledger/badge_issued', $credential['id'], $stored, $badge['registration'] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		} catch ( \Throwable $e ) {
			self::log( 'badge_issued listener', $badge['id'], $e );
		}

		return $stored;
	}

	/**
	 * Sign with the eddsa-rdfc-2022 Data Integrity proof, then let `learning_ledger/badge_sign`
	 * replace the result (Task 65 returns a VC-JWT string).
	 *
	 * @param array              $credential Unsigned credential.
	 * @param Issuer_Key         $key        Active key.
	 * @param \DateTimeImmutable $now        Proof creation time.
	 *
	 * @return array|string
	 *
	 * @throws \RuntimeException When the filter returns neither a secured credential with the same id nor a non-empty string.
	 */
	private static function sign( array $credential, Issuer_Key $key, \DateTimeImmutable $now ): array|string {
		$signed = Eddsa_Rdfc_2022::sign( $credential, Did_Document::verification_method_for( $key ), $key->secret(), $now );

		/**
		 * Filters the signed credential.
		 *
		 * @param array|string $signed     The credential with its Data Integrity proof, or a replacement (e.g. a VC-JWT).
		 * @param array        $credential The unsigned credential.
		 * @param Issuer_Key   $key        The active badge key.
		 */
		$signed = apply_filters( 'learning_ledger/badge_sign', $signed, $credential, $key ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.

		if ( is_string( $signed ) && '' !== $signed ) {
			return $signed;
		}
		if ( is_array( $signed ) && ( $signed['id'] ?? null ) === $credential['id'] && is_array( $signed['proof'] ?? null ) ) {
			return $signed;
		}
		throw new \RuntimeException( 'The badge could not be signed.' );
	}

	/**
	 * Store the `earned` statement as the LRS itself.
	 *
	 * The badge is already issued at this point, so a failure is logged rather than failing the claim.
	 *
	 * @param array  $actor         Learner.
	 * @param array  $badge         Badge row.
	 * @param string $credential_id Credential id.
	 *
	 * @return void
	 */
	private function write_earned( array $actor, array $badge, string $credential_id ): void {
		$statement = Earned_Statement::build( $actor, $badge['registration'], (string) ( $badge['achievement']['id'] ?? '' ), $credential_id, $badge['trigger_statement_id'] );
		try {
			$this->store->store( array( $statement ), Principal::system(), self::XAPI_VERSION );
		} catch ( \Throwable $e ) {
			self::log( 'earned statement', $badge['id'], $e );
		}
	}

	/**
	 * Log a failure that followed a successful issuance.
	 *
	 * @param string     $what     What failed.
	 * @param string     $badge_id Badge id.
	 * @param \Throwable $e        Failure.
	 *
	 * @return void
	 */
	private static function log( string $what, string $badge_id, \Throwable $e ): void {
		error_log( sprintf( 'Learning Ledger: badge %s was issued, but its %s failed: %s', $badge_id, $what, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics after an issuance that already succeeded.
	}

	/**
	 * Trim, lowercase and validate an email address.
	 *
	 * @param string $email Email address.
	 *
	 * @return string
	 *
	 * @throws Invalid_Input Code INVALID_EMAIL.
	 */
	private static function normalized_email( #[\SensitiveParameter] string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || strlen( $email ) > self::MAX_EMAIL || false === is_email( $email ) ) {
			throw new Invalid_Input( 'Enter a valid email address.', self::INVALID_EMAIL, 'email' );
		}
		return $email;
	}
}
