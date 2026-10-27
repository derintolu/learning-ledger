<?php
/**
 * WP-CLI role commands.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Kernel;

/**
 * Enable or disable a Learning Ledger role.
 */
final class Role_Command {

	/**
	 * Enable a role.
	 *
	 * ## OPTIONS
	 *
	 * <role>
	 * : Role to enable.
	 * ---
	 * options:
	 *   - lrs
	 *   - connector
	 * ---
	 *
	 * @param string[] $args Positional arguments.
	 *
	 * @return void
	 */
	public function enable( array $args ): void {
		$result = Roles::enable( (string) ( $args[0] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result );
		}
	}

	/**
	 * Disable a role.
	 *
	 * ## OPTIONS
	 *
	 * <role>
	 * : Role to disable.
	 * ---
	 * options:
	 *   - lrs
	 *   - connector
	 * ---
	 *
	 * @param string[] $args Positional arguments.
	 *
	 * @return void
	 */
	public function disable( array $args ): void {
		Roles::disable( (string) ( $args[0] ?? '' ) );
	}
}
