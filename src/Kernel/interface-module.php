<?php
/**
 * Module contract.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Kernel;

/**
 * A unit of plugin functionality that hooks itself into WordPress.
 */
interface Module {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void;
}
