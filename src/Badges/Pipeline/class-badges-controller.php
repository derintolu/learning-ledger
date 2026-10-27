<?php
/**
 * Badge listing route.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Pipeline;

use LearningLedger\Lrs\Auth\Client_Route_Auth;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

/**
 * `GET /learning-ledger/v1/badges?registration=<uuid>`, for clients holding `badges/claim-links`
 * or `badges/issue`. It tells the course server or connector a badge exists: metadata only,
 * never a link.
 */
final class Badges_Controller {

	const NAMESPACE = 'learning-ledger/v1';

	const ROUTE = '/badges';

	/**
	 * Constructor.
	 *
	 * @param Badge_Repository     $badges     Badges.
	 * @param Statement_Repository $statements Statements (for the learner behind each badge).
	 */
	public function __construct(
		private readonly Badge_Repository $badges = new Badge_Repository(),
		private readonly Statement_Repository $statements = new Statement_Repository()
	) {}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list' ),
				// Authenticated in the callback by Client_Route_Auth, so its 401/429 headers reach the client.
				'permission_callback' => '__return_true',
				'args'                => array(
					'registration' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The badges of a registration.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function list( \WP_REST_Request $request ): \WP_REST_Response {
		$p = Client_Route_Auth::principal( $request, Scopes::BADGES_CLAIM_LINKS );
		if ( $p instanceof \WP_Error && 'lledger_forbidden' === $p->get_error_code() ) {
			// Either badge scope will do; this second check authenticates the request again.
			$p = Client_Route_Auth::principal( $request, Scopes::BADGES_ISSUE );
		}
		if ( ! $p instanceof Principal ) {
			return self::error_response( $p );
		}

		$registration = (string) $request->get_param( 'registration' );
		if ( ! Uuid::is_valid( $registration ) ) {
			return self::error_response(
				new \WP_Error( 'lledger_invalid_registration', __( 'The registration must be a UUID.', 'learning-ledger' ), array( 'status' => 400 ) )
			);
		}
		$registration = strtolower( $registration );

		$filter = $p->bindings()->read_filter();
		$rows   = $this->badges->for_registration( $registration );
		if ( ( isset( $filter['registration'] ) && $filter['registration'] !== $registration ) || ! $this->actors_allowed( $p, $rows ) ) {
			return self::error_response(
				new \WP_Error( 'lledger_forbidden', __( 'This registration is outside the client\'s bindings.', 'learning-ledger' ), array( 'status' => 403 ) )
			);
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'          => $row['id'],
				'achievement' => array(
					'id'    => $row['achievement']['id'] ?? null,
					'name'  => $row['achievement']['name'] ?? null,
					'image' => $row['achievement']['image'] ?? null,
				),
				'status'      => $row['status'],
				'created_at'  => $row['created_at'],
			);
		}
		return self::no_store( new \WP_REST_Response( $out, 200 ) );
	}

	/**
	 * Whether the learner of every badge is within the client's actor bindings.
	 *
	 * The learner is the actor of the badge's trigger statement; a badge whose trigger is gone
	 * is not shown to a bound client.
	 *
	 * @param Principal $p    Caller.
	 * @param array[]   $rows Badges.
	 *
	 * @return boolean
	 */
	private function actors_allowed( Principal $p, array $rows ): bool {
		$bindings = $p->bindings();
		$filter   = $bindings->read_filter();
		if ( ! isset( $filter['actor_hash'] ) && ! isset( $filter['actor_home_hash'] ) ) {
			return true;
		}
		foreach ( $rows as $row ) {
			$trigger = $this->statements->find( $row['trigger_statement_id'] );
			$actor   = null === $trigger ? null : ( $trigger->statement()['actor'] ?? null );
			if ( ! is_array( $actor ) ) {
				return false;
			}
			try {
				if ( ! $bindings->allows_actor( Agent_Identity::from_array( $actor ) ) ) {
					return false;
				}
			} catch ( Invalid_Input $e ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * An error as a response, sending any headers in its data (WWW-Authenticate, Retry-After).
	 *
	 * @param \WP_Error $error Error.
	 *
	 * @return \WP_REST_Response
	 */
	private static function error_response( \WP_Error $error ): \WP_REST_Response {
		$data    = $error->get_error_data();
		$headers = is_array( $data ) && is_array( $data['headers'] ?? null ) ? $data['headers'] : array();
		if ( is_array( $data ) && isset( $data['headers'] ) ) {
			unset( $data['headers'] );
			$error = new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
		}
		$response = rest_convert_error_to_response( $error );
		foreach ( $headers as $name => $value ) {
			$response->header( (string) $name, (string) $value );
		}
		return self::no_store( $response );
	}

	/**
	 * Mark a response uncacheable.
	 *
	 * @param \WP_REST_Response $response Response.
	 *
	 * @return \WP_REST_Response
	 */
	private static function no_store( \WP_REST_Response $response ): \WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
