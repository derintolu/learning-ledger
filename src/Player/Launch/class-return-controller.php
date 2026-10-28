<?php
/**
 * The cmi5 return route.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Launch;

/**
 * `GET /learning-ledger/v1/cmi5/return/{session}`: the LaunchData returnURL. Fires
 * `learning_ledger/cmi5_return` with the session id, then redirects to the lesson.
 */
final class Return_Controller {

	const NS    = 'learning-ledger/v1';
	const ROUTE = '/cmi5/return/(?P<session>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})';

	/**
	 * Sessions.
	 *
	 * @var Session_Repository
	 */
	private Session_Repository $sessions;

	/**
	 * Constructor.
	 *
	 * @param Session_Repository|null $sessions Sessions.
	 */
	public function __construct( ?Session_Repository $sessions = null ) {
		$this->sessions = $sessions ?? new Session_Repository();
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				// The AU navigates here without a nonce; the unguessable session id is the only input.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Fire the return hook and redirect to the lesson.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$session = $this->sessions->find( (string) $request->get_param( 'session' ) );
		if ( null === $session ) {
			return new \WP_Error( 'lledger_not_found', __( 'Unknown session.', 'learning-ledger' ), array( 'status' => 404 ) );
		}
		do_action( 'learning_ledger/cmi5_return', $session['session_id'] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		$url = get_permalink( $session['lesson_id'] );
		$res = new \WP_REST_Response( null, 302 );
		$res->header( 'Location', is_string( $url ) && '' !== $url ? $url : home_url( '/' ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}
}
