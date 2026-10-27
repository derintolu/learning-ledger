<?php
/**
 * Admin REST routes for LRS clients.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Clients;

use LearningLedger\Crypto\Tokens;
use LearningLedger\Kernel\Capabilities;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Util\Invalid_Input;

/**
 * `learning-ledger/v1/clients`: for administrators holding `lledger_manage`, by cookie with a REST nonce or by Application Password.
 */
final class Clients_Controller {

	const NAMESPACE = 'learning-ledger/v1';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/clients',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_clients' ),
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
			'/clients/(?P<id>[A-Za-z0-9._-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => array( $this, 'permit' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'permit' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke' ),
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
			return new \WP_Error( 'lledger_forbidden', __( 'You may not manage LRS clients.', 'learning-ledger' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /clients.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_clients(): \WP_REST_Response {
		return new \WP_REST_Response( array_map( array( Clients_Module::class, 'view' ), ( new Client_Repository() )->all() ), 200 );
	}

	/**
	 * GET /clients/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$client = ( new Client_Repository() )->find( (string) $request['id'] );
		if ( null === $client ) {
			return self::error( new Invalid_Input( 'No such client.', 'not_found', 'id' ) );
		}
		return new \WP_REST_Response( Clients_Module::view( $client ), 200 );
	}

	/**
	 * POST /clients: the server generates the secret, stores its hash and returns the secret once.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $this->body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$secret = Tokens::random( 32 );
		try {
			if ( array_key_exists( 'secret_sha256', $body ) || array_key_exists( 'secret', $body ) ) {
				throw new Invalid_Input( 'The server generates the secret.', 'unknown_property', 'secret' );
			}
			$body['secret_sha256'] = bin2hex( Tokens::hash( $secret ) );
			$spec                  = Client_Input::from_array( $body );
			Clients_Module::create( $spec, 'user:' . get_current_user_id() );
		} catch ( Invalid_Input $e ) {
			return self::error( $e );
		}
		$view           = Clients_Module::view( ( new Client_Repository() )->find( $spec->id ) );
		$view['secret'] = $secret;
		$response       = new \WP_REST_Response( $view, 201 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * PATCH /clients/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $this->body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		try {
			if ( array_key_exists( 'secret_sha256', $body ) || array_key_exists( 'id', $body ) ) {
				throw new Invalid_Input( 'The id and the secret cannot be changed here.', 'unknown_property', array_key_exists( 'id', $body ) ? 'id' : 'secret_sha256' );
			}
			$client = Clients_Module::update( (string) $request['id'], $body, 'user:' . get_current_user_id() );
		} catch ( Invalid_Input $e ) {
			return self::error( $e );
		}
		return new \WP_REST_Response( Clients_Module::view( $client ), 200 );
	}

	/**
	 * DELETE /clients/{id}: revokes the client.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$client = Clients_Module::revoke( (string) $request['id'], 'user:' . get_current_user_id() );
		} catch ( Invalid_Input $e ) {
			return self::error( $e );
		}
		return new \WP_REST_Response( Clients_Module::view( $client ), 200 );
	}

	/**
	 * The JSON body as an array.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array|\WP_Error
	 */
	private function body( \WP_REST_Request $request ): array|\WP_Error {
		$body = $request->get_json_params();
		if ( null === $body ) {
			$body = $request->get_body_params();
		}
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'lledger_invalid', __( 'Expected a JSON object.', 'learning-ledger' ), array( 'status' => 400 ) );
		}
		return $body;
	}

	/**
	 * Invalid_Input as a WP_Error: 404 for not_found, 409 for a duplicate, 400 otherwise.
	 *
	 * @param Invalid_Input $e Error.
	 *
	 * @return \WP_Error
	 */
	private static function error( Invalid_Input $e ): \WP_Error {
		$statuses = array(
			'not_found'    => 404,
			'duplicate_id' => 409,
		);
		return new \WP_Error(
			'lledger_' . $e->error_code(),
			$e->getMessage(),
			array(
				'status' => $statuses[ $e->error_code() ] ?? 400,
				'path'   => $e->path(),
			)
		);
	}
}
