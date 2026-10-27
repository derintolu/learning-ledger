<?php
/**
 * Child token minting and revocation routes.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Tokens;

use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Client_Route_Auth;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Auth\Token_Repository;
use LearningLedger\Lrs\Auth\Token_Spec;
use LearningLedger\Util\Invalid_Input;

/**
 * `POST /learning-ledger/v1/tokens` mints a child token; `DELETE /learning-ledger/v1/tokens/{key}` revokes one.
 * Both authenticate the calling client with Basic auth and require `tokens/mint`.
 * The secret is returned once in the 201 body; only its SHA-256 is stored, and it is never logged.
 */
final class Tokens_Controller {

	const NAMESPACE = 'learning-ledger/v1';

	/**
	 * Token storage.
	 *
	 * @var Token_Repository
	 */
	private Token_Repository $tokens;

	/**
	 * Client storage.
	 *
	 * @var Client_Repository
	 */
	private Client_Repository $clients;

	/**
	 * Constructor.
	 *
	 * @param Token_Repository|null  $tokens  Token storage.
	 * @param Client_Repository|null $clients Client storage.
	 */
	public function __construct( ?Token_Repository $tokens = null, ?Client_Repository $clients = null ) {
		$this->tokens  = $tokens ?? new Token_Repository();
		$this->clients = $clients ?? new Client_Repository();
	}

	/**
	 * Register the routes.
	 *
	 * Authentication runs in the callbacks rather than in permission_callback, so the
	 * WWW-Authenticate and Retry-After headers of a 401 or 429 reach the response.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/tokens',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mint' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/tokens/(?P<key>[A-Za-z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'revoke' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Mint a child token.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function mint( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$p = Client_Route_Auth::principal( $r, Scopes::TOKENS_MINT );
		if ( ! $p instanceof Principal ) {
			return self::auth_error( $p );
		}
		$parent_client = $p->is_child() ? null : $this->clients->find( $p->client_id() );
		if ( null === $parent_client ) {
			return self::error( 'lledger_forbidden', __( 'This client may not mint tokens.', 'learning-ledger' ), 403 );
		}

		try {
			$spec = self::spec( $r->get_json_params() );
		} catch ( Invalid_Input $e ) {
			return self::error( 'lledger_invalid', $e->getMessage(), 400, $e->path() );
		}
		if ( ! $spec->guard && $parent_client->guard_enabled ) {
			return self::error( 'lledger_forbidden', __( 'The cmi5 guard can be turned off only for a client whose guard is off.', 'learning-ledger' ), 403, 'guard' );
		}

		try {
			$minted = $this->tokens->mint( $parent_client, $spec );
		} catch ( Invalid_Input $e ) {
			$status = 'forbidden' === $e->error_code() ? 403 : 400;
			return self::error( 403 === $status ? 'lledger_forbidden' : 'lledger_invalid', $e->getMessage(), $status, $e->path() );
		} catch ( \RuntimeException $e ) {
			return self::error( 'lledger_storage', __( 'The token could not be stored.', 'learning-ledger' ), 500 );
		}

		$res = new \WP_REST_Response( $minted, 201 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/**
	 * Revoke a child token of the calling client.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response|\WP_Error 404 for unknown tokens and for other clients' tokens alike.
	 */
	public function revoke( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$p = Client_Route_Auth::principal( $r, Scopes::TOKENS_MINT );
		if ( ! $p instanceof Principal ) {
			return self::auth_error( $p );
		}

		$key   = (string) $r->get_param( 'key' );
		$token = $p->is_child() ? null : $this->tokens->find( $key );
		if ( null === $token || ! hash_equals( $token->parent_client, $p->client_id() ) ) {
			return self::error( 'lledger_not_found', __( 'No such token.', 'learning-ledger' ), 404 );
		}

		$this->tokens->revoke( $token->key );
		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Build a Token_Spec from the JSON body, checking types. Token_Repository::mint checks the values.
	 *
	 * @param mixed $body Decoded JSON body.
	 *
	 * @return Token_Spec
	 * @throws Invalid_Input On a missing field or a wrong type.
	 */
	private static function spec( mixed $body ): Token_Spec {
		if ( ! is_array( $body ) ) {
			throw new Invalid_Input( 'Expected a JSON object.', 'type', '' );
		}
		foreach ( array( 'registration', 'activity_id', 'session_id', 'launch_mode' ) as $field ) {
			if ( ! isset( $body[ $field ] ) || ! is_string( $body[ $field ] ) ) {
				throw new Invalid_Input( $field . ' must be a string.', 'type', $field ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never echoed; the REST layer JSON-encodes it.
			}
		}
		if ( ! isset( $body['actor'] ) || ! is_array( $body['actor'] ) ) {
			throw new Invalid_Input( 'actor must be an Agent object.', 'type', 'actor' );
		}
		$ttl = $body['ttl'] ?? Token_Repository::MAX_TTL;
		if ( ! is_int( $ttl ) ) {
			throw new Invalid_Input( 'ttl must be an integer.', 'type', 'ttl' );
		}
		$origins = $body['cors_origins'] ?? array();
		if ( ! is_array( $origins ) || ! array_is_list( $origins ) ) {
			throw new Invalid_Input( 'Expected a list of origins.', 'type', 'cors_origins' );
		}
		$guard = $body['guard'] ?? true;
		if ( ! is_bool( $guard ) ) {
			throw new Invalid_Input( 'guard must be a boolean.', 'type', 'guard' );
		}

		return new Token_Spec(
			$body['registration'],
			$body['actor'],
			$body['activity_id'],
			$body['session_id'],
			$body['launch_mode'],
			$ttl,
			$origins,
			$guard
		);
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
