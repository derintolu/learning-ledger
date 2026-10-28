<?php
/**
 * View links module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Links;

use LearningLedger\Kernel\Module;

/**
 * Registers the `view-links` REST route, the `/records/<token>` page and the hourly prune of
 * expired links.
 */
final class View_Links_Module implements Module {

	/**
	 * Cron event that deletes expired links.
	 */
	const PRUNE_EVENT = 'lledger_prune_links';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		$page = new Record_Page();
		add_action( 'rest_api_init', array( new View_Links_Controller(), 'routes' ) );
		add_action( 'init', array( $page, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $page, 'query_vars' ) );
		add_action( 'template_redirect', array( $page, 'maybe_serve' ), 0 );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::PRUNE_EVENT, array( $this, 'prune' ) );
	}

	/**
	 * Schedule the prune once.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( false === wp_next_scheduled( self::PRUNE_EVENT ) ) {
			wp_schedule_event( time(), 'hourly', self::PRUNE_EVENT );
		}
	}

	/**
	 * Delete expired links.
	 *
	 * @return void
	 */
	public function prune(): void {
		( new Link_Repository() )->prune();
	}
}
