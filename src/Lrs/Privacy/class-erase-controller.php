<?php
/**
 * Actor erasure route.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Privacy;

use LearningLedger\Lrs\Auth\Client_Route_Auth;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Xapi\Agent_Identity;

/**
 * `POST /learning-ledger/v1/actors/erase` `{actor}`: Basic auth, scope `actors/erase`, the actor
 * within the client's actor bindings. Answers 200 with the erasure counts.
 */
final class Erase_Controller {

	const NAMESPACE = 'learning-ledger/v1';

	/**
	 * Eraser.
	 *
	 * @var Actor_Eraser
	 */
	private Actor_Eraser $eraser;

	/**
	 * Constructor.
	 *
	 * @param Actor_Eraser|null $eraser Eraser.
	 */
	public function __construct( ?Actor_Eraser $eraser = null ) {
		$this->eraser = $eraser ?? new Actor_Eraser();
	}

	/**
	 * Register the route.
	 *
	 * Authentication runs in the callback rather than in permission_callback, so the
	 * WWW-Authenticate and Retry-After headers of a 401 or 429 reach the response.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/actors/erase',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'erase' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Erase one actor.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function erase( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$p = Client_Route_Auth::principal( $r, Scopes::ACTORS_ERASE );
		if ( ! $p instanceof Principal ) {
			return self::auth_error( $p );
		}

		$body = $r->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['actor'] ) || ! is_array( $body['actor'] ) ) {
			return self::error( 'lledger_invalid', __( 'actor must be an Agent object.', 'learning-ledger' ), 400, 'actor' );
		}
		try {
			$actor = Agent_Identity::from_array( $body['actor'] );
		} catch ( Invalid_Input $e ) {
			return self::error( 'lledger_invalid', $e->getMessage(), 400, 'actor' );
		}
		if ( ! $p->bindings()->allows_actor( $actor ) ) {
			return self::error( 'lledger_forbidden', __( 'The actor is outside the client bindings.', 'learning-ledger' ), 403, 'actor' );
		}

		try {
			$counts = $this->eraser->erase( $actor, 'client:' . $p->client_id() );
		} catch ( \Throwable $e ) {
			return self::error( 'lledger_storage', __( 'The actor could not be erased.', 'learning-ledger' ), 500 );
		}

		$res = new \WP_REST_Response( $counts, 200 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/**
	 * Response for a Client_Route_Auth error, carrying its WWW-Authenticate / Retry-After headers.
	 *
	 * @param \WP_Error $err Error from Client_Route_Auth::principal().
	 *
	 * @return \WP_REST_Response
	 */
	private static function auth_error( \WP_Error $err ): \WP_REST_Response {
		$data = $err->get_error_data();
		$res  = rest_convert_error_to_response( $err );
		if ( is_array( $data ) && isset( $data['headers'] ) && is_array( $data['headers'] ) ) {
			foreach ( $data['headers'] as $name => $value ) {
				$res->header( (string) $name, (string) $value );
			}
		}
		return $res;
	}

	/**
	 * A REST error.
	 *
	 * @param string  $code    Error code.
	 * @param string  $message Message.
	 * @param integer $status  HTTP status.
	 * @param string  $field   Offending field, if any.
	 *
	 * @return \WP_Error
	 */
	private static function error( string $code, string $message, int $status, string $field = '' ): \WP_Error {
		$data = array( 'status' => $status );
		if ( '' !== $field ) {
			$data['field'] = $field;
		}
		return new \WP_Error( $code, $message, $data );
	}
}
