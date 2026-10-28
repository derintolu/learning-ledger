<?php
/**
 * Revocation module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Revocation;

use LearningLedger\Kernel\Module;

/**
 * Revokes badges when their trigger statement is voided or their learner is erased, and adds
 * the `badges revoke` and `badges reissue` commands.
 */
final class Revocation_Module implements Module {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$revoker = new Revoker();
		add_action( 'learning_ledger/statement_voided', array( $revoker, 'on_statement_voided' ) );
		add_action( 'learning_ledger/actor_erased', array( $revoker, 'on_actor_erased' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			$command = new Revoke_Command( $revoker );
			\WP_CLI::add_command( 'learning-ledger badges revoke', array( $command, 'revoke' ) );
			\WP_CLI::add_command( 'learning-ledger badges reissue', array( $command, 'reissue' ) );
			\WP_CLI::add_hook( 'before_invoke:learning-ledger badges remove-key', array( $command, 'before_remove_key' ) );
			\WP_CLI::add_hook( 'after_invoke:learning-ledger badges remove-key', array( $command, 'after_remove_key' ) );
			\WP_CLI::add_hook( 'after_invoke:learning-ledger badges rotate', array( $command, 'after_rotate' ) );
		}
	}
}
