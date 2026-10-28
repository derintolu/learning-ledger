<?php
/**
 * Agent Profile resource.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Documents;

use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Util\Invalid_Input;

/**
 * `agents/profile`: keyed by agent and profileId.
 */
final class Agent_Profile_Handler extends Document_Handler_Base {

	/**
	 * Id parameter.
	 *
	 * @return string
	 */
	protected function id_param(): string {
		return 'profileId';
	}

	/**
	 * Scope.
	 *
	 * @return string
	 */
	protected function scope(): string {
		return Scopes::PROFILE;
	}

	/**
	 * Both versions apply the 409 rule to profiles.
	 *
	 * @param string $version Version.
	 *
	 * @return boolean
	 */
	protected function conflict_without_precondition( string $version ): bool {
		return true;
	}

	/**
	 * Under 1.0.3 a PUT that creates a profile must carry a precondition (XAPI-00273).
	 *
	 * @param string $version Negotiated version.
	 *
	 * @return boolean
	 */
	protected function precondition_required_to_create( string $version ): bool {
		return '1.0.3' === $version;
	}

	/**
	 * Key.
	 *
	 * @param Xapi_Request $r      Request.
	 * @param string|null  $doc_id The profileId.
	 *
	 * @return Doc_Key
	 * @throws Invalid_Input On missing or malformed parameters.
	 */
	protected function key( Xapi_Request $r, ?string $doc_id ): Doc_Key {
		return new Doc_Key( 'agent_profile', null, self::agent_param( $r ), null, $doc_id );
	}

	/**
	 * Writes need a non-child caller holding `profile` (child tokens read only, which cmi5 allows).
	 *
	 * @param Principal $p     Caller.
	 * @param Doc_Key   $k     Key.
	 * @param boolean   $write Whether the request writes.
	 *
	 * @return void
	 * @throws \LearningLedger\Lrs\Http\Xapi_Error 403.
	 */
	protected function authorize_more( Principal $p, Doc_Key $k, bool $write ): void {
		if ( $write && ! $p->can_write_profile() ) {
			throw self::forbidden( 'Agent profiles are read-only for this token.' );
		}
	}
}
