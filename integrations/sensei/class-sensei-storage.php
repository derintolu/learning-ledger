<?php
/**
 * Sensei's lesson progress storage: comments or its own tables (High-Performance Progress Storage).
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Sensei;

/**
 * Sensei 4.26 keeps lesson and course progress in comments unless High-Performance Progress
 * Storage reads from its tables. Some hosts empty comment queries, so progress read from comments
 * looks empty there; progress read from Sensei's tables does not go through WP_Comment_Query.
 *
 * Sensei reads from tables only with all three of its settings on: `experimental_progress_storage`,
 * `experimental_progress_storage_synchronization` (it then writes both tables and comments) and
 * `experimental_progress_storage_repository` = `custom_tables`. Its admin screen gets there in
 * steps: the feature on, then synchronisation on (which drops and recreates the tables and
 * schedules the migration of comment progress), then the repository switch once the migration is
 * complete. switch_to_tables() takes the same steps without the screen; switch_to_comments() is
 * the way back.
 */
final class Sensei_Storage {

	/**
	 * Sensei's settings option.
	 */
	public const SETTINGS = 'sensei-settings';

	/**
	 * Sensei's progress tables, without the table prefix.
	 */
	public const TABLES = array( 'sensei_lms_progress', 'sensei_lms_quiz_submissions', 'sensei_lms_quiz_answers', 'sensei_lms_quiz_grades' );

	/**
	 * Comment types Sensei's comment-based progress uses.
	 */
	public const COMMENT_TYPES = array( 'sensei_lesson_status', 'sensei_course_status' );

	/**
	 * Sensei's migration options (Migration_Job_Scheduler::*_OPTION_NAME) and statuses.
	 */
	public const MIGRATION_STATUS    = 'sensei_lms_migration_job_status';
	public const MIGRATION_STARTED   = 'sensei_lms_migration_job_started';
	public const MIGRATION_COMPLETED = 'sensei_lms_migration_job_completed';
	public const MIGRATION_COMPLETE  = 'complete';

	/**
	 * Where Sensei reads progress from and whether its tables exist; nulls without Sensei.
	 *
	 * @return array{storage:?string,tables:?bool,migration:?string}
	 */
	public static function status(): array {
		$none = array(
			'storage'   => null,
			'tables'    => null,
			'migration' => null,
		);
		try {
			if ( ! function_exists( 'Sensei' ) ) {
				return $none;
			}
			$config = Sensei()->progress_storage_configuration ?? null;
			// A Sensei without the storage configuration only has comment storage.
			$tables = is_object( $config ) && method_exists( $config, 'is_table_reading_enabled' ) && $config->is_table_reading_enabled();
			return array(
				'storage'   => $tables ? 'tables' : 'comments',
				'tables'    => self::tables_exist(),
				'migration' => (string) get_option( self::MIGRATION_STATUS, 'not_started' ),
			);
		} catch ( \Throwable $e ) {
			return $none;
		}
	}

	/**
	 * Whether all of Sensei's progress tables exist.
	 *
	 * @return boolean
	 */
	public static function tables_exist(): bool {
		global $wpdb;
		foreach ( self::TABLES as $table ) {
			$name = $wpdb->prefix . $table;
			if ( $name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				return false;
			}
		}
		return true;
	}

	/**
	 * Lesson and course progress rows Sensei holds in comments.
	 *
	 * @return integer
	 */
	public static function comment_progress_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type IN (%s, %s)", self::COMMENT_TYPES[0], self::COMMENT_TYPES[1] )
		);
	}

	/**
	 * Move Sensei to reading progress from its tables, in the steps its admin screen takes. Takes
	 * effect from the next request, when Sensei reads its settings.
	 *
	 * - Migration complete, with HPPS and synchronisation still on: switch the repository to the tables.
	 * - No comment progress: create the tables, record the (empty) migration as complete the way
	 *   Sensei records it, and switch.
	 * - Comment progress and $force: synchronisation on, the tables dropped and recreated and
	 *   Sensei's migration scheduled, as its screen does; the repository stays on comments until
	 *   the migration is complete, then a second call switches it.
	 * - Comment progress without $force, or a migration still running: refused.
	 *
	 * @param boolean $force Start Sensei's migration of existing comment progress.
	 *
	 * @return string|\WP_Error `tables` (Sensei reads its tables) or `migrating`.
	 */
	public static function switch_to_tables( bool $force = false ): string|\WP_Error {
		if ( ! function_exists( 'Sensei' ) || ! class_exists( '\Sensei\Internal\Installer\Schema' ) ) {
			return new \WP_Error( 'sensei_unavailable', 'Sensei LMS is not active.' );
		}
		$migration = (string) get_option( self::MIGRATION_STATUS, 'not_started' );
		// The tables are current only while Sensei has kept writing them: HPPS and synchronisation
		// on since the migration. Turning HPPS off on Sensei's screen leaves the status complete but
		// stops the writes, so that case is handled like a fresh switch below.
		$settings = get_option( self::SETTINGS, array() );
		$writing  = is_array( $settings ) && true === ( $settings['experimental_progress_storage'] ?? null ) && true === ( $settings['experimental_progress_storage_synchronization'] ?? null );
		if ( self::MIGRATION_COMPLETE === $migration && $writing && self::tables_exist() ) {
			self::settings( 'custom_tables' );
			return 'tables';
		}
		if ( 'in_progress' === $migration ) {
			return new \WP_Error( 'migration_in_progress', 'Sensei is still migrating progress into its tables. Run this again when `wp learning-ledger sensei storage status` shows migration: complete.' );
		}

		$rows = self::comment_progress_rows();
		if ( $rows > 0 && ! $force ) {
			return new \WP_Error( 'comment_progress_exists', sprintf( 'Sensei holds %d progress rows in comments. Run again with --force to migrate them into its tables first, as its settings screen does.', $rows ) );
		}

		if ( 0 === $rows ) {
			if ( ! self::create_tables() ) {
				return new \WP_Error( 'tables_not_created', 'Sensei progress tables could not be created.' );
			}
			// Nothing to migrate: record the migration as Migration_Job_Scheduler does when it completes.
			$now = microtime( true );
			update_option( self::MIGRATION_STARTED, $now );
			update_option( self::MIGRATION_COMPLETED, $now );
			update_option( self::MIGRATION_STATUS, self::MIGRATION_COMPLETE );
			self::settings( 'custom_tables' );
			return 'tables';
		}

		return self::start_migration();
	}

	/**
	 * The way back: Sensei reads progress from comments again. Synchronisation stays on, so both
	 * stores keep being written, as Sensei's settings screen leaves it.
	 *
	 * @return string|\WP_Error `comments`.
	 */
	public static function switch_to_comments(): string|\WP_Error {
		if ( ! function_exists( 'Sensei' ) ) {
			return new \WP_Error( 'sensei_unavailable', 'Sensei LMS is not active.' );
		}
		self::settings( 'comments' );
		return 'comments';
	}

	/**
	 * Sensei's own synchronisation start: drop and recreate the tables, clear the migration state
	 * and schedule the migration (Sensei_Settings::experimental_features_saved()).
	 *
	 * @return string|\WP_Error `migrating`.
	 */
	private static function start_migration(): string|\WP_Error {
		self::settings( 'comments' );
		Sensei()->init_migration_scheduler();
		$scheduler = Sensei()->migration_scheduler ?? null;
		if ( ! is_object( $scheduler ) || ! class_exists( '\Sensei\Internal\Installer\Eraser' ) ) {
			return new \WP_Error( 'migration_unavailable', 'Sensei cannot migrate progress here (Action Scheduler is not available).' );
		}
		( new \Sensei\Internal\Installer\Eraser() )->drop_tables();
		$scheduler->clear_state();
		if ( ! self::create_tables() ) {
			return new \WP_Error( 'tables_not_created', 'Sensei progress tables could not be created.' );
		}
		$scheduler->schedule();
		return 'migrating';
	}

	/**
	 * Create Sensei's tables with its own schema; the feature flag it needs is on only for this call.
	 *
	 * @return boolean Whether the tables exist afterwards.
	 */
	private static function create_tables(): bool {
		add_filter( 'sensei_feature_flag_tables_based_progress', '__return_true' );
		try {
			( new \Sensei\Internal\Installer\Schema( Sensei()->feature_flags ) )->create_tables();
		} finally {
			remove_filter( 'sensei_feature_flag_tables_based_progress', '__return_true' );
		}
		return self::tables_exist();
	}

	/**
	 * The feature and synchronisation on, with the given repository, in Sensei's option.
	 *
	 * @param string $repository Either `custom_tables` or `comments`.
	 *
	 * @return void
	 */
	private static function settings( string $repository ): void {
		$settings                                  = get_option( self::SETTINGS, array() );
		$settings                                  = is_array( $settings ) ? $settings : array();
		$settings['experimental_progress_storage'] = true;
		$settings['experimental_progress_storage_synchronization'] = true;
		$settings['experimental_progress_storage_repository']      = $repository;
		update_option( self::SETTINGS, $settings );
	}
}
