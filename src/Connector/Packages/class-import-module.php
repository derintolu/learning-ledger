<?php
/**
 * Registers the package import endpoint and WP-CLI command.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

use LearningLedger\Kernel\Module;

/**
 * Package import module.
 */
final class Import_Module implements Module {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		( new Import_Controller() )->register();
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'learning-ledger package import', array( new Package_Command(), 'import' ) );
		}
	}
}
