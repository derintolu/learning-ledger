<?php
/**
 * Queue module: cron schedule, catch-up event, cron route and CLI.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Queue;

use LearningLedger\Kernel\Module;
use LearningLedger\Util\Clock;

/**
 * Runs the job queue every minute from WP-Cron as catch-up for the shutdown runner.
 */
final class Queue_Module implements Module {

	/**
	 * Cron schedule name.
	 */
	public const SCHEDULE = 'lledger_minute';

	/**
	 * Cron event name.
	 */
	public const EVENT = 'lledger_cron_minute';

	/**
	 * Option holding the Unix time `learning_ledger/cron_quarter_hour` last fired.
	 */
	private const QUARTER_OPTION = 'lledger_cron_quarter_hour_last';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::EVENT, array( $this, 'on_cron_minute' ) );
		( new Cron_Route() )->register();
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'learning-ledger cron', Cron_Command::class );
		}
	}

	/**
	 * Add the one-minute schedule.
	 *
	 * @param array $schedules Schedules.
	 *
	 * @return array
	 */
	public function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (Learning Ledger)', 'learning-ledger' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the minute event if it is not scheduled.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( false === wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::EVENT );
		}
	}

	/**
	 * Minute tick: run the queue, then the minute and quarter-hour hooks.
	 *
	 * @return void
	 */
	public function on_cron_minute(): void {
		Job_Queue::run();
		do_action( 'learning_ledger/cron_minute' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		$now  = Clock::now()->getTimestamp();
		$last = (int) get_option( self::QUARTER_OPTION, 0 );
		if ( $now - $last >= 900 ) {
			update_option( self::QUARTER_OPTION, $now, false );
			do_action( 'learning_ledger/cron_quarter_hour' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		}
	}
}
