<?php
/**
 * Secret-protected route that runs the minute tick (for an external cron).
 *
 * @package LearningLedger
 */

namespace LearningLedger\Queue;

/**
 * `GET|POST /learning-ledger/v1/cron`.
 *
 * The caller proves it knows the secret whose SHA-256 is stored in
 * `lledger_cron_secret_sha256`, by header `X-Learning-Ledger-Cron` or query `key`.
 */
final class Cron_Route {

	/**
	 * Option holding the hex SHA-256 of the cron secret.
	 */
	public const SECRET_OPTION = 'lledger_cron_secret_sha256';

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'learning-ledger/v1',
			'/cron',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	/**
	 * Allow only a caller holding the secret.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error
	 */
	public function authorize( \WP_REST_Request $request ) {
		$stored = get_option( self::SECRET_OPTION, '' );
		$given  = $request->get_header( 'X-Learning-Ledger-Cron' );
		if ( null === $given || '' === $given ) {
			$query = $request->get_query_params();
			$given = isset( $query['key'] ) && is_string( $query['key'] ) ? $query['key'] : '';
		}
		if ( is_string( $stored ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $stored ) && '' !== $given && hash_equals( $stored, hash( 'sha256', $given ) ) ) {
			return true;
		}
		return new \WP_Error( 'lledger_cron_forbidden', __( 'A valid cron secret is required.', 'learning-ledger' ), array( 'status' => 403 ) );
	}

	/**
	 * Run the minute tick, as WP-Cron would.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle(): \WP_REST_Response {
		$processed = Queue_Module::tick();
		$response  = new \WP_REST_Response(
			array(
				'processed' => $processed,
				'due'       => Job_Queue::due_count(),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
