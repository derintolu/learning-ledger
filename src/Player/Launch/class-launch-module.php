<?php
/**
 * The cmi5 launch module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Launch;

use LearningLedger\Kernel\Module;

/**
 * Registers the cmi5 launch, fetch and return routes.
 */
final class Launch_Module implements Module {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		$fetch = new Fetch_Controller();
		$fetch->hooks();
		add_action(
			'rest_api_init',
			static function () use ( $fetch ): void {
				( new Launch_Controller() )->routes();
				$fetch->routes();
				( new Return_Controller() )->routes();
			}
		);
	}
}
