<?php
/**
 * Status list module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Status;

use LearningLedger\Kernel\Module;

/**
 * Registers the public Bitstring Status List route.
 */
class Status_List_Module implements Module {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$controller = new Status_List_Controller();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		// After core's rest_send_cors_headers (priority 10).
		add_filter( 'rest_pre_serve_request', array( $controller, 'public_cors' ), 11, 3 );
	}
}
