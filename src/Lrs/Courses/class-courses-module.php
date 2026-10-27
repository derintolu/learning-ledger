<?php
/**
 * Course import module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Courses;

use LearningLedger\Kernel\Module;

/**
 * Registers the course import routes.
 */
final class Courses_Module implements Module {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( new Courses_Controller(), 'register_routes' ) );
	}
}
