<?php
/**
 * The xAPI Agents resource.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Resources;

use LearningLedger\Lrs\Auth\Bindings;
use LearningLedger\Lrs\Http\Resource_Handler;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Http\Xapi_Response;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Xapi\Agent_Identity;

/**
 * Returns the Person for an Agent, limited to the identities inside the principal's actor
 * bindings; a principal bound to one actor may read only that actor.
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
			throw new Xapi_Error( 400, 'agent is not a valid Agent: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages become a JSON error body, never raw HTML.
		}

		$principal = $r->principal();
		if ( null === $principal || ! $principal->bindings()->allows_actor( $agent ) ) {
			throw new Xapi_Error( 403, 'This credential may not read that agent.' );
		}

		$person   = ( new Agent_Repository() )->person_for( $agent );
		$bindings = $principal->bindings();
		// A linked identity outside the principal's actor bindings belongs to another system.
		foreach ( Agent_Identity::IFI_KEYS as $key ) {
			$person[ $key ] = array_values( array_filter( $person[ $key ], static fn( $value ) => self::within( $bindings, $key, $value ) ) );
		}
		// An agent never seen in a statement still identifies itself.
		$own = $agent->to_array();
		foreach ( Agent_Identity::IFI_KEYS as $key ) {
			if ( isset( $own[ $key ] ) && ! self::listed( $own[ $key ], $person[ $key ] ) ) {
				$person[ $key ][] = $own[ $key ];
			}
		}
		return Xapi_Response::json( 200, $person );
	}

	/**
	 * Whether an IFI value is already in a list, whatever the key order of an account object
	 * (MySQL 8 JSON columns return the keys in their own order).
	 *
	 * @param mixed $value IFI value.
	 * @param array $items Listed IFI values.
	 *
	 * @return boolean
	 */
	private static function listed( mixed $value, array $items ): bool {
		if ( ! is_array( $value ) ) {
			return in_array( $value, $items, true );
		}
		ksort( $value );
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				ksort( $item );
			}
			if ( $item === $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether one IFI of a Person is inside the actor bindings.
	 *
	 * @param Bindings $bindings Bindings.
	 * @param string   $key      IFI kind.
	 * @param mixed    $value    IFI value.
	 *
	 * @return boolean
	 */
	private static function within( Bindings $bindings, string $key, mixed $value ): bool {
		try {
			return $bindings->allows_actor( Agent_Identity::from_array( array( $key => $value ) ) );
		} catch ( Invalid_Input $e ) {
			return false;
		}
	}
}
