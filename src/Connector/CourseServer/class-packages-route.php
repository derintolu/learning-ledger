<?php
/**
 * Course-server package routes.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\CourseServer;

use LearningLedger\Connector\Packages\Export_Controller;
use LearningLedger\Connector\Packages\Import_Controller;
use LearningLedger\Connector\Packages\Package_Builder;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Kernel\Service_Context;

/**
 * `learning-ledger/v1/course-server/packages…`: the full-flavour package of a program, and an import by URL.
 * Both authenticate only by a signed request (Signed_Request_Auth).
 */
final class Packages_Route {

	const NS = 'learning-ledger/v1';

	/**
	 * Request auth.
	 *
	 * @var Signed_Request_Auth
	 */
	private Signed_Request_Auth $auth;

	/**
	 * Constructor.
	 *
	 * @param Signed_Request_Auth|null $auth Request auth.
	 */
	public function __construct( ?Signed_Request_Auth $auth = null ) {
		$this->auth = $auth ?? new Signed_Request_Auth();
	}

	/**
	 * Register the routes. They authenticate themselves in the callback, so that a 429 can carry Retry-After.
	 *
	 * @return void
	 */
	public function register(): void {
		Export_Controller::hook_serving();
		register_rest_route(
			self::NS,
			'/course-server/packages/(?P<program>[^/]+)',
			array(
				'methods'             => 'GET',
				'callback'            => Service_Context::wrap( array( $this, 'get' ) ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/course-server/packages',
			array(
				'methods'             => 'POST',
				'callback'            => Service_Context::wrap( array( $this, 'import' ) ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'url'     => array(
						'type'     => 'string',
						'required' => false,
					),
					'adapter' => array(
						'type'              => 'string',
						'default'           => 'sensei',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * GET packages/{program}: the full flavour, with secrets. `{program}` is a post id or a stable key.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get( \WP_REST_Request $r ): \WP_REST_Response {
		$auth = $this->auth->authorize( $r, Trusted_Keys::READ_PACKAGES );
		if ( is_wp_error( $auth ) ) {
			return Signed_Request_Auth::to_response( $auth );
		}
		$id = Export_Controller::program_id( (string) $r['program'] );
		if ( null === $id ) {
			return Export_Controller::error_response( new Invalid_Input( 'No such program.', 'not_found' ) );
		}
		try {
			$built = ( new Package_Builder() )->build( $id, 'full' );
		} catch ( Invalid_Input $e ) {
			return Export_Controller::error_response( $e );
		}
		return Export_Controller::package_response( $built, $r, $id );
	}

	/**
	 * POST packages `{url, adapter?}`: import a package from an https URL and return the report.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function import( \WP_REST_Request $r ): \WP_REST_Response {
		$auth = $this->auth->authorize( $r, Trusted_Keys::IMPORT_PACKAGES );
		if ( is_wp_error( $auth ) ) {
			return Signed_Request_Auth::to_response( $auth );
		}
		// A signed course server holding import_packages is a trusted import path.
		return ( new Import_Controller() )->handle( $r, true );
	}
}
