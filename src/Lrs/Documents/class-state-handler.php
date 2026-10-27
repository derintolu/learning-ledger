<?php
/**
 * State resource.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Documents;

use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Util\Invalid_Input;

/**
 * `activities/state`: keyed by activityId, agent, optional registration and stateId.
 */
final class State_Handler extends Document_Handler_Base {

	/**
	 * The cmi5 launch document, which only the LMS writes.
	 */
	const LAUNCH_DATA = 'LMS.LaunchData';

	/**
	 * Id parameter.
	 *
	 * @return string
	 */
	protected function id_param(): string {
		return 'stateId';
	}

	/**
	 * Scope.
	 *
	 * @return string
	 */
	protected function scope(): string {
		return Scopes::STATE;
	}

	/**
	 * DELETE without stateId deletes the set.
	 *
	 * @return boolean
	 */
	protected function supports_delete_all(): bool {
		return true;
	}

	/**
	 * 2.0 applies the 409 rule to State; 1.0.3 lets State PUT overwrite.
	 *
	 * @param string $version Version.
	 *
	 * @return boolean
	 */
	protected function conflict_without_precondition( string $version ): bool {
		return '1.0.3' !== $version;
	}

	/**
	 * Key.
	 *
	 * @param Xapi_Request $r      Request.
	 * @param string|null  $doc_id The stateId.
	 *
	 * @return Doc_Key
	 * @throws Invalid_Input On missing or malformed parameters.
	 */
	protected function key( Xapi_Request $r, ?string $doc_id ): Doc_Key {
		$activity     = self::activity_param( $r );
		$agent        = self::agent_param( $r );
		$registration = self::string_param( $r, 'registration' );
		return new Doc_Key( 'state', $activity, $agent, $registration, $doc_id );
	}

	/**
	 * A registration binding (always set for a child token) pins the registration;
	 * a child token is also pinned to its AU activity, never writes LMS.LaunchData and never deletes the set.
	 *
	 * @param Principal $p     Caller.
	 * @param Doc_Key   $k     Key.
	 * @param boolean   $write Whether the request writes.
	 *
	 * @return void
	 * @throws \LearningLedger\Lrs\Http\Xapi_Error 403.
	 */
	protected function authorize_more( Principal $p, Doc_Key $k, bool $write ): void {
		$bound = $p->bindings()->read_filter()['registration'] ?? null;
		if ( null !== $bound && $k->registration() !== $bound ) {
			throw self::forbidden( 'This client may only use state for its own registration.' );
		}
		$token = $p->token();
		if ( null === $token ) {
			return;
		}
		if ( $k->activity_iri !== $token->activity_id ) {
			throw self::forbidden( 'This token may only use state for its own activity.' );
		}
		if ( $write && ( null === $k->doc_id || self::LAUNCH_DATA === $k->doc_id ) ) {
			throw self::forbidden( 'LMS.LaunchData is read-only for this token.' );
		}
	}
}
