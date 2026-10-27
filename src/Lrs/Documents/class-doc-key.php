<?php
/**
 * Document key.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Documents;

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed; HTTP layers JSON-encode them.

/**
 * Identifies a State, Agent Profile or Activity Profile document (or, without an id, a set of them).
 *
 * Absent parts are stored as zero bytes, never NULL, so the UNIQUE key holds for documents
 * without a registration, agent or activity (MySQL treats NULLs as distinct).
 */
final class Doc_Key {

	const KINDS = array( 'state', 'agent_profile', 'activity_profile' );

	/**
	 * Lowercase registration UUID, if any.
	 *
	 * @var string|null
	 */
	private ?string $registration;

	/**
	 * Constructor.
	 *
	 * @param string              $kind         One of `state`, `agent_profile`, `activity_profile`.
	 * @param string|null         $activity_iri Activity IRI (state, activity profile).
	 * @param Agent_Identity|null $agent        Agent (state, agent profile).
	 * @param string|null         $registration Registration UUID (state only).
	 * @param string|null         $doc_id       The stateId or profileId; null for a set of documents.
	 *
	 * @throws Invalid_Input When a part is missing, not allowed for the kind, or malformed.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly ?string $activity_iri,
		public readonly ?Agent_Identity $agent,
		?string $registration,
		public readonly ?string $doc_id
	) {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			throw new Invalid_Input( 'Unknown document kind.', 'invalid', 'kind' );
		}
		$needs_activity = 'agent_profile' !== $kind;
		$needs_agent    = 'activity_profile' !== $kind;
		if ( ( null !== $activity_iri ) !== $needs_activity ) {
			throw new Invalid_Input( $needs_activity ? 'activityId is required.' : 'activityId is not allowed here.', 'invalid', 'activityId' );
		}
		if ( ( null !== $agent ) !== $needs_agent ) {
			throw new Invalid_Input( $needs_agent ? 'agent is required.' : 'agent is not allowed here.', 'invalid', 'agent' );
		}
		if ( null !== $registration && 'state' !== $kind ) {
			throw new Invalid_Input( 'registration is only allowed for state.', 'invalid', 'registration' );
		}
		if ( null !== $registration && ! Uuid::is_valid( $registration ) ) {
			throw new Invalid_Input( 'registration must be a UUID.', 'invalid_uuid', 'registration' );
		}
		foreach ( array( $activity_iri, $doc_id ) as $text ) {
			if ( null !== $text && ! mb_check_encoding( $text, 'UTF-8' ) ) {
				throw new Invalid_Input( 'Parameters must be UTF-8.', 'invalid', '' );
			}
		}
		$this->registration = null === $registration ? null : strtolower( $registration );
	}

	/**
	 * Lowercase registration UUID, if any.
	 *
	 * @return string|null
	 */
	public function registration(): ?string {
		return $this->registration;
	}

	/**
	 * Copy without the document id (the set of documents in this context).
	 *
	 * @return self
	 */
	public function without_id(): self {
		return new self( $this->kind, $this->activity_iri, $this->agent, $this->registration, null );
	}

	/**
	 * Raw SHA-1 of the activity IRI, or 20 zero bytes.
	 *
	 * @return string
	 */
	public function activity_hash(): string {
		return null === $this->activity_iri ? str_repeat( "\0", 20 ) : sha1( $this->activity_iri, true );
	}

	/**
	 * Raw agent IFI hash, or 20 zero bytes.
	 *
	 * @return string
	 */
	public function agent_hash(): string {
		return null === $this->agent ? str_repeat( "\0", 20 ) : $this->agent->hash();
	}

	/**
	 * Registration as 16 raw bytes, or 16 zero bytes.
	 *
	 * @return string
	 */
	public function registration_bin(): string {
		return null === $this->registration ? Uuid::NIL_BIN : Uuid::to_bin( $this->registration );
	}

	/**
	 * Raw SHA-1 of the document id, or 20 zero bytes when there is none.
	 *
	 * @return string
	 */
	public function doc_id_hash(): string {
		return null === $this->doc_id ? str_repeat( "\0", 20 ) : sha1( $this->doc_id, true );
	}
}
