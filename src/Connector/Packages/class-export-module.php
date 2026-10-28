<?php
/**
 * Registers the package export endpoint, the content-changed ping and the WP-CLI export command.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

use LearningLedger\Kernel\Module;

/**
 * Package export module.
 */
final class Export_Module implements Module {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		( new Export_Controller() )->register();
		( new Content_Ping() )->register();
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'learning-ledger package export', array( new Export_Command(), 'export' ) );
		}
	}
}
