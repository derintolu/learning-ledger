<?php
/**
 * Statement export REST route.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Export;

use LearningLedger\Kernel\Capabilities;
use LearningLedger\Util\Invalid_Input;

/**
 * `GET learning-ledger/v1/export`: for users holding `lledger_export`, by cookie with a REST nonce or by Application Password.
 * The route checks access and the filters; Export_Module::serve() then streams the body.
 */
final class Export_Controller {

	const NAMESPACE = 'learning-ledger/v1';

	const ROUTE = '/learning-ledger/v1/export';

	/**
	 * Filter names, taken from the query string.
	 */
	const FILTERS = array( 'since', 'until', 'registration', 'agent' );

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'permit' ),
			)
		);
	}

	/**
	 * Permission: a signed-in user with `lledger_export`. Under cookie authentication the request
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
		if ( ! current_user_can( Capabilities::EXPORT ) ) {
			return new \WP_Error( 'lledger_forbidden', __( 'You may not export statements.', 'learning-ledger' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /export: check the filters and set the download headers; the body is streamed afterwards.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			Ndjson_Exporter::normalize_filters( self::filters( $request ) );
		} catch ( Invalid_Input $e ) {
			return new \WP_Error( 'lledger_invalid_' . $e->error_code(), $e->getMessage(), array( 'status' => 400 ) );
		}
		$response = new \WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', 'application/x-ndjson; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="learning-ledger-statements-' . gmdate( 'Ymd\THis\Z' ) . '.ndjson"' );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}

	/**
	 * The filters of a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array
	 */
	public static function filters( \WP_REST_Request $request ): array {
		$out = array();
		foreach ( self::FILTERS as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}
