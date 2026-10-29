<?php
/**
 * Public status list route.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Status;

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Kernel\No_Page_Cache;

/**
 * Serves `GET /learning-ledger/v1/status-lists/{id}`: a list's signed
 * BitstringStatusListCredential, public and cacheable for its ttl.
 */
final class Status_List_Controller {

	/**
	 * Headers sent with a list credential.
	 */
	const HEADERS = array(
		'Cache-Control'               => No_Page_Cache::SHORT_CACHE_CONTROL,
		'Access-Control-Allow-Origin' => '*',
	);

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'learning-ledger/v1',
			'/status-lists/(?P<id>[1-9][0-9]{0,18})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Return a list credential.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$credential = ( new Status_List_Repository() )->credential( (int) $request['id'] );
		} catch ( Invalid_Input $e ) {
			return new \WP_Error( 'lledger_not_found', __( 'No such status list.', 'learning-ledger' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $credential, 200, self::HEADERS );
	}

	/**
	 * Keep `Access-Control-Allow-Origin: *` on list responses. Core's
	 * rest_send_cors_headers() runs after the response headers are sent and
	 * would echo the request's Origin and allow credentials.
	 *
	 * @param mixed $served  Whether the request has been served.
	 * @param mixed $result  Response.
	 * @param mixed $request Request.
	 *
	 * @return mixed $served, unchanged.
	 */
	public function public_cors( mixed $served, mixed $result, mixed $request ): mixed {
		if ( $request instanceof \WP_REST_Request
			&& 1 === preg_match( '#^/learning-ledger/v1/status-lists/#', $request->get_route() )
			&& ! headers_sent() ) {
			header( 'Access-Control-Allow-Origin: *' );
			header_remove( 'Access-Control-Allow-Credentials' );
		}
		return $served;
	}
}
