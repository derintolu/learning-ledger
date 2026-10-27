<?php
/**
 * Registers every block built under build/blocks/.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Blocks;

use LearningLedger\Kernel\Module;

/**
 * Block registration.
 */
final class Blocks_Module implements Module {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register each directory containing a block.json under build/blocks/.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		$dirs     = glob( LEARNING_LEDGER_DIR . 'build/blocks/*', GLOB_ONLYDIR );
		foreach ( $dirs ? $dirs : array() as $dir ) {
			$name = 'learning-ledger/' . basename( $dir );
			if ( ! $registry->is_registered( $name ) && is_readable( $dir . '/block.json' ) ) {
				register_block_type( $dir );
			}
		}
	}
}
