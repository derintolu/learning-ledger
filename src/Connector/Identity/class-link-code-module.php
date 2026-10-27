<?php
/**
 * Record linking module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Identity;

use LearningLedger\Kernel\Module;

/**
 * Link route, backfill job handler and the learner WP-CLI command.
 */
final class Link_Code_Module implements Module {

	const JOB = 'identity_backfill';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'learning_ledger/jobs/' . self::JOB, array( $this, 'run_job' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'learning-ledger learner', Learner_Command::class );
		}
	}

	/**
	 * Register the link route.
	 *
	 * @return void
	 */
	public function register_route(): void {
		( new Link_Code_Controller() )->register();
	}

	/**
	 * Job handler.
	 *
	 * @param array $payload Payload with the user id and the actor..
	 *
	 * @return void
	 */
	public function run_job( array $payload ): void {
		$user  = (int) ( $payload['user'] ?? 0 );
		$actor = $payload['actor'] ?? null;
		if ( $user < 1 || ! is_array( $actor ) ) {
			return;
		}
		( new Backfill() )->run( $user, $actor );
	}
}
