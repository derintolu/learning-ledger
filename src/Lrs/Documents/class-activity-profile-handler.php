<?php
/**
 * Activity Profile resource.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Documents;

use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Util\Invalid_Input;

/**
 * `activities/profile`: keyed by activityId and profileId.
 */
final class Activity_Profile_Handler extends Document_Handler_Base {

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
	 * Key.
	 *
	 * @param Xapi_Request $r      Request.
	 * @param string|null  $doc_id The profileId.
	 *
	 * @return Doc_Key
	 * @throws Invalid_Input On missing or malformed parameters.
	 */
	protected function key( Xapi_Request $r, ?string $doc_id ): Doc_Key {
		return new Doc_Key( 'activity_profile', self::activity_param( $r ), null, null, $doc_id );
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
			throw self::forbidden( 'Activity profiles are read-only for this token.' );
		}
	}
}
