<?php
/**
 * `wp learning-ledger sensei storage`.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Sensei;

/**
 * Shows and switches Sensei's lesson progress storage.
 */
final class Sensei_Storage_Command {

	/**
	 * Show where Sensei reads lesson progress from and whether its progress tables exist.
	 *
	 * @return void
	 */
	public function status(): void {
		$status = Sensei_Storage::status();
		\WP_CLI::log( 'storage: ' . ( $status['storage'] ?? 'unknown' ) );
		\WP_CLI::log( 'tables: ' . ( null === $status['tables'] ? 'unknown' : ( $status['tables'] ? 'yes' : 'no' ) ) );
		\WP_CLI::log( 'comment progress rows: ' . Sensei_Storage::comment_progress_rows() );
	}

	/**
	 * Create Sensei's progress tables and make Sensei read progress from them (from the next request).
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Switch even though Sensei holds progress in comments, which the tables will not show.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function tables( array $args, array $assoc_args ): void {
		unset( $args );
		$result = Sensei_Storage::switch_to_tables( isset( $assoc_args['force'] ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( 'Sensei progress tables exist and Sensei reads progress from them from the next request.' );
	}
}
