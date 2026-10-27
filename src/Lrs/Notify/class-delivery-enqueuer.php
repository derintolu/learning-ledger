<?php
/**
 * Queues webhook deliveries for stored statements.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Notify;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the outbox is plugin-owned.

use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Statements\Stored_Statement;
use LearningLedger\Queue\Shutdown_Runner;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;

/**
 * On `learning_ledger/statements_stored` (inside the storing transaction): one outbox INSERT per
 * matching subscription per statement, so the rows commit or roll back with the statements.
 * Sending is left to the shutdown runner, after the response has gone.
 */
final class Delivery_Enqueuer {

	/**
	 * Queue deliveries.
	 *
	 * @param Stored_Statement[]|Stored_Statement $rows Statements just inserted. do_action() passes a
	 *                                                  one-element list of objects as the object itself.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When an outbox row cannot be written, so the whole store rolls back.
	 */
	public function on_stored( array|Stored_Statement $rows ): void {
		$rows  = is_array( $rows ) ? $rows : array( $rows );
		$subs  = new Subscription_Repository();
		$now   = Timestamp::to_db( Clock::now() );
		$table = Tables::name( 'outbox' );
		$added = 0;
		global $wpdb;
		foreach ( $rows as $row ) {
			if ( ! $row instanceof Stored_Statement ) {
				continue;
			}
			foreach ( $subs->matching( $row ) as $sub_id ) {
				$ok = $wpdb->insert(
					$table,
					array(
						'subscription_id' => $sub_id,
						'seq'             => $row->seq,
						'status'          => Webhook_Sender::PENDING,
						'attempts'        => 0,
						'next_attempt_at' => $now,
					),
					array( '%d', '%d', '%s', '%d', '%s' )
				);
				if ( 1 !== $ok ) {
					throw new \RuntimeException( 'Could not queue the webhook delivery.' );
				}
				++$added;
			}
		}
		if ( $added > 0 ) {
			Shutdown_Runner::add( array( self::class, 'deliver' ) );
		}
	}

	/**
	 * Shutdown callback: send what is due.
	 *
	 * @return void
	 */
	public static function deliver(): void {
		( new Webhook_Sender() )->run();
	}
}
