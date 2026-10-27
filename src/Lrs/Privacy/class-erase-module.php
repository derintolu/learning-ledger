<?php
/**
 * Actor erasure module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Privacy;

use LearningLedger\Kernel\Module;

/**
 * Registers the `actors/erase` REST route and the LRS's WordPress privacy exporter and eraser.
 */
final class Erase_Module implements Module {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( new Erase_Controller(), 'routes' ) );
		( new Lrs_Privacy() )->register();
	}
}
