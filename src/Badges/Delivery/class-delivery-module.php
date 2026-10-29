<?php
/**
 * Badge delivery module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Delivery;

use LearningLedger\Kernel\Module;
use LearningLedger\Kernel\Page_Route;

/**
 * Registers the public badge page and downloads (`/badges/<uuid>`, `.json`, `.png`) and the
 * `achievements/{hash}` REST route.
 */
final class Delivery_Module implements Module {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		$page = new Badge_Page();
		add_action( 'rest_api_init', array( new Achievement_Controller(), 'register_routes' ) );
		add_action( 'rest_api_init', array( $page, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( Page_Route::class, 'serve_raw' ), 10, 3 );
		add_action( 'init', array( $page, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $page, 'query_vars' ) );
		add_action( 'template_redirect', array( $page, 'maybe_serve' ), 0 );
	}
}
