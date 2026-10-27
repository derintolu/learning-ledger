<?php
/**
 * Admin REST routes for webhook subscriptions.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Notify;

use LearningLedger\Kernel\Capabilities;
use LearningLedger\Util\Invalid_Input;

/**
 * `learning-ledger/v1/subscriptions`: for administrators holding `lledger_manage`, by cookie with
 * a REST nonce or by Application Password.
 */
final class Subscriptions_Controller {

	const NAMESPACE = 'learning-ledger/v1';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/subscriptions',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_subscriptions' ),
					'permission_callback' => array( $this, 'permit' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'permit' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/subscriptions/(?P<id>[0-9]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'permit' ),
				),
			)
		);
	}

	/**
	 * Permission: a signed-in user with `lledger_manage`. Under cookie authentication the request
	 * must carry a valid `wp_rest` nonce; an Application Password needs none.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error 401 when signed out, 403 otherwise.
	 */
	public function permit( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'lledger_unauthenticated', __( 'Authentication is required.', 'learning-ledger' ), array( 'status' => 401 ) );
		}
		if ( null === rest_get_authenticated_app_password() ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! is_string( $nonce ) || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new \WP_Error( 'lledger_bad_nonce', __( 'The REST nonce is missing or invalid.', 'learning-ledger' ), array( 'status' => 403 ) );
			}
		}
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return new \WP_Error( 'lledger_forbidden', __( 'You may not manage webhook subscriptions.', 'learning-ledger' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /subscriptions.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_subscriptions(): \WP_REST_Response {
		return new \WP_REST_Response( array_map( array( Notify_Module::class, 'view' ), ( new Subscription_Repository() )->all() ), 200 );
	}

	/**
	 * POST /subscriptions with `{"url": "...", "filters": {...}}`.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( null === $body ) {
			$body = $request->get_body_params();
		}
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'lledger_invalid', __( 'Expected a JSON object.', 'learning-ledger' ), array( 'status' => 400 ) );
		}
		try {
			$unknown = array_diff( array_keys( $body ), array( 'url', 'filters' ) );
			if ( array() !== $unknown ) {
				throw new Invalid_Input( 'Unknown property.', 'unknown_property', (string) reset( $unknown ) );
			}
			if ( ! is_string( $body['url'] ?? null ) ) {
				throw new Invalid_Input( 'url is required.', 'invalid', 'url' );
			}
			$filters = $body['filters'] ?? array();
			if ( ! is_array( $filters ) ) {
				throw new Invalid_Input( 'filters must be an object.', 'invalid', 'filters' );
			}
			$id = Notify_Module::create( $body['url'], $filters, 'user:' . get_current_user_id() );
		} catch ( Invalid_Input $e ) {
			return self::error( $e );
		}
		return new \WP_REST_Response( Notify_Module::view( ( new Subscription_Repository() )->find( $id ) ), 201 );
	}

	/**
	 * DELETE /subscriptions/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request['id'];
		try {
			Notify_Module::delete( $id, 'user:' . get_current_user_id() );
		} catch ( Invalid_Input $e ) {
			return self::error( $e );
		}
		return new \WP_REST_Response(
			array(
				'id'      => $id,
				'deleted' => true,
			),
			200
		);
	}

	/**
	 * Invalid_Input as a WP_Error: 404 for not_found, 400 otherwise.
	 *
	 * @param Invalid_Input $e Error.
	 *
	 * @return \WP_Error
	 */
	private static function error( Invalid_Input $e ): \WP_Error {
		return new \WP_Error(
			'lledger_' . $e->error_code(),
			$e->getMessage(),
			array(
				'status' => 'not_found' === $e->error_code() ? 404 : 400,
				'path'   => $e->path(),
			)
		);
	}
}
