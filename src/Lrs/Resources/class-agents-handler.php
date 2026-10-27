<?php
/**
 * The xAPI Agents resource.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Resources;

use LearningLedger\Lrs\Http\Resource_Handler;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Http\Xapi_Response;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Xapi\Agent_Identity;

/**
 * Returns the Person for an Agent; a principal bound to one actor may read only that actor.
 */
final class Agents_Handler implements Resource_Handler {

	/**
	 * Handle GET agents.
	 *
	 * @param Xapi_Request $r Request.
	 *
	 * @return Xapi_Response
	 * @throws Xapi_Error 400 for a missing or invalid agent, 403 outside the principal's bindings.
	 */
	public function handle( Xapi_Request $r ): Xapi_Response {
		$raw = $r->param( 'agent' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			throw new Xapi_Error( 400, 'agent is required.' );
		}
		try {
			$decoded = Json::decode_strict( $raw, 65536, 8 );
			if ( ! is_array( $decoded ) || array() === $decoded || Json::is_list( $decoded ) ) {
				throw new Invalid_Input( 'agent must be a JSON object.', 'type', 'agent' );
			}
			$agent = Agent_Identity::from_array( $decoded );
		} catch ( Invalid_Input $e ) {
			throw new Xapi_Error( 400, 'agent is not a valid Agent: ' . $e->getMessage() );
		}

		$principal = $r->principal();
		if ( null === $principal || ! $principal->bindings()->allows_actor( $agent ) ) {
			throw new Xapi_Error( 403, 'This credential may not read that agent.' );
		}

		$person = ( new Agent_Repository() )->person_for( $agent );
		// An agent never seen in a statement still identifies itself.
		$own = $agent->to_array();
		foreach ( Agent_Identity::IFI_KEYS as $key ) {
			if ( isset( $own[ $key ] ) && ! in_array( $own[ $key ], $person[ $key ], true ) ) {
				$person[ $key ][] = $own[ $key ];
			}
		}
		return Xapi_Response::json( 200, $person );
	}
}
