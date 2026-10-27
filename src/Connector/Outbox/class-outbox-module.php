<?php
/**
 * Outbox module: direction-2 sink, sender schedule and CLI.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Outbox;

use LearningLedger\Connector\Lms\Event_Sink;
use LearningLedger\Kernel\Module;

/**
 * Supplies the outbox as the integrations' event sink and sends it every cron minute.
 */
final class Outbox_Module implements Module {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'learning_ledger/event_sink', array( $this, 'sink' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		add_action( 'learning_ledger/cron_minute', array( $this, 'flush' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'learning-ledger course', Direction2_Command::class );
		}
	}

	/**
	 * The outbox sink.
	 *
	 * @param mixed $sink Sink so far.
	 *
	 * @return Event_Sink
	 */
	public function sink( mixed $sink ): Event_Sink { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return new Outbox_Sink();
	}

	/**
	 * Cron catch-up for rows the shutdown run did not send.
	 *
	 * @return void
	 */
	public function flush(): void {
		( new Outbox_Sender() )->flush();
	}
}
