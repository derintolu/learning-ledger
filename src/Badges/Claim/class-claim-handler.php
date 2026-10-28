<?php
/**
 * Claim-time issuance of a pending badge.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Claim;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin-owned table, never cached: badge status must be read fresh.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed; Claim_Page maps the codes to its own text.

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Issuer_Key;
use LearningLedger\Badges\Issuer\Issuer_Profile;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Badges\Signing\Eddsa_Rdfc_2022;
use LearningLedger\Badges\Status\Status_List_Repository;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Http\Xapi_Error;
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
	 * @return array The stored credential.
	 *
	 * @throws Invalid_Input Code GONE, CONFLICT or INVALID_EMAIL.
	 * @throws \RuntimeException When no badge key is active, or signing or storage fails.
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

		$stored = is_array( $signed ) ? $signed : $credential;
		if ( ! $this->badges->store_issued( $badge['id'], $stored, $slot['list_id'], $slot['index'], $identity_hash, $salt ) ) {
			throw new Invalid_Input( 'This badge cannot be claimed.', self::CONFLICT, 'badge' );
		}
		self::finish_row( $badge['id'], $share, is_string( $signed ) ? $signed : null );

		$earned_id = $this->write_earned( $actor, $badge, $credential['id'] );

		/**
		 * Fires once when a badge is issued, after it is stored.
		 *
		 * @param string $credential_id Credential id (`urn:uuid:<badge id>`).
		 * @param array  $credential    The stored credential.
		 * @param string $registration  Registration UUID.
		 */
		do_action( 'learning_ledger/badge_issued', $credential['id'], $stored, $badge['registration'] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.

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
				'earned'       => $earned_id,
			),
			$link['created_by']
		);

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
	 * Record the share choice and, for a credential signed as a string, the string itself.
	 *
	 * @param string      $id    Badge id.
	 * @param boolean     $share Whether the public page is allowed.
	 * @param string|null $jwt   Credential string from the filter, or null.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException On a database error.
	 */
	private static function finish_row( string $id, bool $share, ?string $jwt ): void {
		if ( ! $share && null === $jwt ) {
			return;
		}
		global $wpdb;
		$table = Tables::name( 'badges' );
		if ( null === $jwt ) {
			$ok = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET shared = 1 WHERE id = %s AND status = %s', $table, $id, Badge_Repository::ISSUED ) );
		} else {
			$ok = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET shared = %d, credential = %s WHERE id = %s AND status = %s', $table, $share ? 1 : 0, $jwt, $id, Badge_Repository::ISSUED ) );
		}
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not store the issued badge.' );
		}
	}

	/**
	 * Store the `earned` statement as the LRS itself.
	 *
	 * The badge is already issued at this point, so a refused statement is recorded in the
	 * audit row (as null) rather than failing the claim.
	 *
	 * @param array  $actor         Learner.
	 * @param array  $badge         Badge row.
	 * @param string $credential_id Credential id.
	 *
	 * @return string|null The statement id, or null when it could not be stored.
	 */
	private function write_earned( array $actor, array $badge, string $credential_id ): ?string {
		$statement = Earned_Statement::build( $actor, $badge['registration'], (string) ( $badge['achievement']['id'] ?? '' ), $credential_id, $badge['trigger_statement_id'] );
		try {
			$ids = $this->store->store( array( $statement ), Principal::system(), self::XAPI_VERSION );
		} catch ( Xapi_Error $e ) {
			return null;
		}
		return $ids[0] ?? null;
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
