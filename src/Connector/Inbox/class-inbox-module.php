<?php
/**
 * Connector inbox module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Inbox;

use LearningLedger\Kernel\Module;

/**
 * Webhook route, catch-up poll, cron catch-up processing and daily pruning.
 */
final class Inbox_Module implements Module {

	const DAILY_EVENT = 'lledger_connector_inbox_daily';

	/**
	 * Unmatched rows are kept this many days.
	 */
	const UNMATCHED_DAYS = 30;

	/**
	 * Event ids are remembered this long: past the LRS's 72 h retry period, with a day to spare.
	 */
	const EVENT_TTL = 4 * DAY_IN_SECONDS;

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'learning_ledger/cron_quarter_hour', array( $this, 'poll' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		add_action( 'learning_ledger/cron_minute', array( $this, 'process' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::DAILY_EVENT, array( $this, 'daily' ) );
	}

	/**
	 * Register the webhook route.
	 *
	 * @return void
	 */
	public function register_route(): void {
		( new Inbox_Route() )->register();
	}

	/**
	 * Quarter-hour poll, then process what it brought.
	 *
	 * @return void
	 */
	public function poll(): void {
		if ( ( new Catch_Up_Poller() )->run() > 0 ) {
			( new Inbox_Processor() )->process_pending();
		}
	}

	/**
	 * Minute catch-up for processing that did not run at shutdown.
	 *
	 * @return void
	 */
	public function process(): void {
		( new Inbox_Processor() )->process_pending();
	}

	/**
	 * Schedule the daily prune.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( false === wp_next_scheduled( self::DAILY_EVENT ) ) {
			wp_schedule_event( time(), 'daily', self::DAILY_EVENT );
		}
	}

	/**
	 * Daily prune of old unmatched rows and remembered event ids.
	 *
	 * @return void
	 */
	public function daily(): void {
		( new Inbox_Repository() )->prune_unmatched( self::UNMATCHED_DAYS );
		Inbox_Route::prune_events( self::EVENT_TTL );
	}
}
