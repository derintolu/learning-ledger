<?php
/**
 * Claim module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Claim;

use LearningLedger\Kernel\Module;

/**
 * Registers the `badges/{id}/claim-links` REST route and the `/badges/claim/<token>` page.
 */
final class Claim_Module implements Module {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		$page = new Claim_Page();
		add_action( 'rest_api_init', array( new Claim_Links_Controller(), 'routes' ) );
		add_action( 'init', array( $page, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $page, 'query_vars' ) );
		add_action( 'template_redirect', array( $page, 'maybe_serve' ), 0 );
	}
}
