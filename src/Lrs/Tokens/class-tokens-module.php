<?php
/**
 * Child token minting module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Tokens;

use LearningLedger\Kernel\Module;

/**
 * Registers the `tokens` REST routes.
 */
final class Tokens_Module implements Module {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( new Tokens_Controller(), 'routes' ) );
	}
}
