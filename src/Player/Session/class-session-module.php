<?php
/**
 * The cmi5 session module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Session;

use LearningLedger\Kernel\Module;
use LearningLedger\Kernel\Roles;

/**
 * Abandonment every cron minute, the LMS's `satisfied` and recommended `waived` statements,
 * the return-route pull, the waive route, and, when the connector's LRS is this site, evaluation
 * as the LRS commits statements.
 */
final class Session_Module implements Module {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		$evaluator = new Player_Evaluator();
		$evaluator->hooks();
		add_action( 'learning_ledger/cron_minute', array( $this, 'abandon' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		if ( Roles::is_enabled( 'lrs' ) && Player_Evaluator::lrs_is_local() ) {
			// The connector's LRS is this site: evaluate as statements are committed, not on a later poll.
			add_action( 'learning_ledger/statements_committed', array( $evaluator, 'on_committed' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		}
		add_action(
			'rest_api_init',
			static function () use ( $evaluator ): void {
				( new Waive_Controller( $evaluator ) )->routes();
			}
		);
	}

	/**
	 * Cron minute: abandon expired sessions.
	 *
	 * @return void
	 */
	public function abandon(): void {
		( new Abandoner() )->run();
	}
}
