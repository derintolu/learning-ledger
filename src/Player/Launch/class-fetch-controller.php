<?php
/**
 * The cmi5 fetch route.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Launch;

use LearningLedger\Cmi5\Au;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Pairing\Lrs_Client;
use LearningLedger\Crypto\Tokens;

/**
 * `POST /learning-ledger/v1/cmi5/fetch/{token}` (public; the token is the credential) [cmi5 8.2].
 *
 * The first use within 5 minutes of the launch mints a child token on the LRS for the session (one
 * registration and actor, the AU activity, 8 hours, the cmi5 guard, CORS for the AU's origin, which
 * is `null` for the sandboxed fallback) and answers `{"auth-token": base64(key:secret)}`. A reused,
 * expired or unknown token answers error-code 1; any other failure error-code 2. Other methods are
 * 405. CORS on this route allows only the session's AU origin, without credentials.
 */
final class Fetch_Controller {

	const NS        = 'learning-ledger/v1';
	const ROUTE     = '/cmi5/fetch/(?P<token>[A-Za-z0-9_-]{1,128})';
	const TOKEN_TTL = 28800;

	/**
	 * LRS client.
	 *
	 * @var Lrs_Client
	 */
	private Lrs_Client $lrs;

	/**
	 * Sessions.
	 *
	 * @var Session_Repository
	 */
	private Session_Repository $sessions;

	/**
	 * Constructor.
	 *
	 * @param Lrs_Client|null         $lrs      LRS client.
	 * @param Session_Repository|null $sessions Sessions.
	 */
	public function __construct( ?Lrs_Client $lrs = null, ?Session_Repository $sessions = null ) {
		$this->lrs      = $lrs ?? new Lrs_Client();
		$this->sessions = $sessions ?? new Session_Repository();
	}

	/**
	 * Hooks that answer CORS for this route instead of core.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'preflight' ), 10, 3 );
		// Before core's rest_send_cors_headers (priority 10), which would echo any origin with credentials.
		add_filter( 'rest_pre_serve_request', array( $this, 'own_cors' ), 9, 3 );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET, PUT, PATCH, DELETE',
					'callback'            => array( $this, 'not_allowed' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Use the fetch token.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$hash    = Tokens::hash( (string) $request->get_param( 'token' ) );
		$session = $this->sessions->use_fetch( $hash );
		if ( null === $session ) {
			return $this->respond(
				array(
					'error-code' => '1',
					'error-text' => 'Already in use or expired',
				),
				$request,
				self::origin_of( $this->sessions->find_by_fetch( $hash ) )
			);
		}

		$origin = self::origin_of( $session );
		$key    = null;
		$auth   = null;
		if ( '' !== $origin ) {
			try {
				$res  = $this->lrs->api(
					'POST',
					'tokens',
					array(
						'registration' => $session['registration'],
						'actor'        => ( new Identity_Map() )->actor_for_user( $session['user_id'] ),
						'activity_id'  => Au::activity_id( $session['au_id'] ),
						'session_id'   => $session['session_id'],
						'launch_mode'  => $session['launch_mode'],
						'ttl'          => self::TOKEN_TTL,
						'cors_origins' => array( $origin ),
						'guard'        => true,
					)
				);
				$body = $res->json();
				if ( 201 === $res->status && is_array( $body ) && is_string( $body['key'] ?? null ) && is_string( $body['secret'] ?? null ) ) {
					$key  = $body['key'];
					$auth = base64_encode( $key . ':' . $body['secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the cmi5 auth-token is HTTP Basic credentials.
				}
			} catch ( \RuntimeException $e ) {
				$auth = null;
			}
		}
		if ( null === $auth || null === $key ) {
			return $this->respond(
				array(
					'error-code' => '2',
					'error-text' => 'The LMS could not issue a token for this session',
				),
				$request,
				$origin
			);
		}

		$this->sessions->set_token( $session['session_id'], $key );
		return $this->respond( array( 'auth-token' => $auth ), $request, $origin );
	}

	/**
	 * 405 for anything but POST.
	 *
	 * @return \WP_REST_Response
	 */
	public function not_allowed(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => 'rest_method_not_allowed',
				'message' => 'Use POST.',
			),
			405,
			array( 'Allow' => 'POST' )
		);
	}

	/**
	 * Answer CORS preflight for this route.
	 *
	 * @param mixed            $result  Earlier result.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public function preflight( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		$token = self::token_of( $request->get_route() );
		if ( null !== $result || 'OPTIONS' !== $request->get_method() || null === $token ) {
			return $result;
		}
		$res    = new \WP_REST_Response( null, 204, array( 'Allow' => 'POST' ) );
		$origin = $request->get_header( 'Origin' );
		if ( null !== $origin && '' !== $origin && self::origin_of( $this->sessions->find_by_fetch( Tokens::hash( $token ) ) ) === $origin ) {
			$res->header( 'Access-Control-Allow-Origin', $origin );
			$res->header( 'Access-Control-Allow-Methods', 'POST, OPTIONS' );
			$res->header( 'Access-Control-Allow-Headers', 'Content-Type' );
			$res->header( 'Access-Control-Max-Age', '600' );
			$res->header( 'Vary', 'Origin' );
		}
		return $res;
	}

	/**
	 * Keep core's CORS headers off this route; the response carries its own.
	 *
	 * @param mixed            $served  Whether the request was served.
	 * @param mixed            $result  Result.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public function own_cors( mixed $served, mixed $result, \WP_REST_Request $request ): mixed {
		if ( null !== self::token_of( $request->get_route() ) ) {
			remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		}
		return $served;
	}

	/**
	 * A JSON answer, with CORS for the session's AU origin.
	 *
	 * @param array            $data    Body.
	 * @param \WP_REST_Request $request Request.
	 * @param string           $allowed The session's AU origin, '' when unknown.
	 *
	 * @return \WP_REST_Response
	 */
	private function respond( array $data, \WP_REST_Request $request, string $allowed ): \WP_REST_Response {
		$res = new \WP_REST_Response( $data, 200 );
		$res->header( 'Cache-Control', 'no-store' );
		$origin = $request->get_header( 'Origin' );
		if ( null !== $origin && '' !== $allowed && $origin === $allowed ) {
			$res->header( 'Access-Control-Allow-Origin', $origin );
			$res->header( 'Vary', 'Origin' );
		}
		return $res;
	}

	/**
	 * The origin a session's AU runs in (`null` for the sandboxed fallback), or '' when unknown.
	 *
	 * @param array|null $session Session row.
	 *
	 * @return string
	 */
	private static function origin_of( ?array $session ): string {
		$found = null === $session ? null : Launch_Controller::find_au( $session['au_id'] );
		return null === $found ? '' : Launch_Url::origin( $found['au']->url() );
	}

	/**
	 * The fetch token of a route on this endpoint, or null for another route.
	 *
	 * @param string $route REST route.
	 *
	 * @return string|null
	 */
	private static function token_of( string $route ): ?string {
		return 1 === preg_match( '#^/' . preg_quote( self::NS, '#' ) . '/cmi5/fetch/([A-Za-z0-9_-]{1,128})$#', $route, $m ) ? $m[1] : null;
	}
}
