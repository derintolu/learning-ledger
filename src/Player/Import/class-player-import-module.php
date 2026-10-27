<?php
/**
 * Registers the cmi5 player import route, WP-CLI command and extraction job.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Import;

use LearningLedger\Kernel\Module;
use LearningLedger\Player\Store\Au_Store;
use LearningLedger\Player\Store\Extract_Job;

/**
 * Player import module: the cmi5 import route, its WP-CLI command, the extraction job and the superseded-package sweep.
 */
final class Player_Import_Module implements Module {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		( new Player_Import_Controller() )->register();
		add_action( 'learning_ledger/jobs/' . Au_Store::JOB, array( Extract_Job::class, 'run' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		add_action( 'learning_ledger/jobs/' . Player_Importer::SWEEP_JOB, array( Player_Importer::class, 'sweep' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'learning-ledger cmi5 import', array( new Player_Import_Command(), 'import' ) );
		}
	}
}
