<?php
/**
 * `wp learning-ledger sensei storage`.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Sensei;

/**
 * Shows and switches where Sensei reads lesson progress from: comments, or its own tables
 * (High-Performance Progress Storage). `tables` takes the steps Sensei's settings screen takes;
 * `comments` is the way back.
 */
final class Sensei_Storage_Command {

	/**
	 * Show where Sensei reads lesson progress from, whether its tables exist and the migration status.
	 *
	 * @return void
	 */
	public function status(): void {
		$status = Sensei_Storage::status();
		\WP_CLI::log( 'storage: ' . ( $status['storage'] ?? 'unknown' ) );
		\WP_CLI::log( 'tables: ' . ( null === $status['tables'] ? 'unknown' : ( $status['tables'] ? 'yes' : 'no' ) ) );
		\WP_CLI::log( 'migration: ' . ( $status['migration'] ?? 'unknown' ) );
		\WP_CLI::log( 'comment progress rows: ' . Sensei_Storage::comment_progress_rows() );
	}

	/**
	 * Make Sensei read progress from its tables (from the next request).
	 *
	 * With no progress in comments yet, this creates the tables and switches at once. With progress
	 * in comments it refuses unless --force, which starts Sensei's own migration (as its settings
	 * screen does): run this again once `status` shows migration: complete. The way back is
	 * `wp learning-ledger sensei storage comments`.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Migrate the progress Sensei holds in comments into its tables first.
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
		if ( 'migrating' === $result ) {
			\WP_CLI::success( 'Sensei is migrating comment progress into its tables. Run this command again when `wp learning-ledger sensei storage status` shows migration: complete.' );
			return;
		}
		\WP_CLI::success( 'Sensei reads progress from its tables from the next request.' );
	}

	/**
	 * The way back: make Sensei read progress from comments again (from the next request).
	 * Synchronisation stays on, so Sensei keeps writing both its tables and comments.
	 *
	 * @return void
	 */
	public function comments(): void {
		$result = Sensei_Storage::switch_to_comments();
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( 'Sensei reads progress from comments from the next request.' );
	}
}
