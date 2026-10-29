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
 * `experimental_progress_storage_repository` = `custom_tables`. Sensei creates the tables only
 * when the setting is saved on its admin screen; switch_to_tables() does that step without it.
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
	 * Where Sensei reads progress from and whether its tables exist; nulls without Sensei.
	 *
	 * @return array{storage:?string,tables:?bool}
	 */
	public static function status(): array {
		try {
			$config = function_exists( 'Sensei' ) ? ( Sensei()->progress_storage_configuration ?? null ) : null;
			if ( ! is_object( $config ) || ! method_exists( $config, 'is_table_reading_enabled' ) ) {
				return array(
					'storage' => null,
					'tables'  => null,
				);
			}
			return array(
				'storage' => $config->is_table_reading_enabled() ? 'tables' : 'comments',
				'tables'  => self::tables_exist(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'storage' => null,
				'tables'  => null,
			);
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
	 * Switch Sensei to read progress from its tables: create the tables, then turn on the three
	 * settings. Refuses while comment-based progress exists, unless forced: Sensei only reads the
	 * tables afterwards, and it copies older progress across only when the switch is made on its
	 * admin screen.
	 *
	 * Takes effect from the next request, when Sensei reads its settings.
	 *
	 * @param boolean $force Switch even though comment-based progress exists.
	 *
	 * @return array{tables:bool,comment_rows:int}|\WP_Error
	 */
	public static function switch_to_tables( bool $force = false ): array|\WP_Error {
		if ( ! function_exists( 'Sensei' ) || ! class_exists( '\Sensei\Internal\Installer\Schema' ) ) {
			return new \WP_Error( 'sensei_unavailable', 'Sensei LMS is not active.' );
		}
		$rows = self::comment_progress_rows();
		if ( $rows > 0 && ! $force ) {
			return new \WP_Error( 'comment_progress_exists', sprintf( 'Sensei holds %d progress rows in comments that its tables would not show. Run again with --force to switch anyway.', $rows ) );
		}

		// Sensei's schema includes the progress tables only while this feature flag is on.
		add_filter( 'sensei_feature_flag_tables_based_progress', '__return_true' );
		( new \Sensei\Internal\Installer\Schema( Sensei()->feature_flags ) )->create_tables();
		if ( ! self::tables_exist() ) {
			return new \WP_Error( 'tables_not_created', 'Sensei progress tables could not be created.' );
		}

		$settings                                  = get_option( self::SETTINGS, array() );
		$settings                                  = is_array( $settings ) ? $settings : array();
		$settings['experimental_progress_storage'] = true;
		$settings['experimental_progress_storage_synchronization'] = true;
		$settings['experimental_progress_storage_repository']      = 'custom_tables';
		update_option( self::SETTINGS, $settings );

		return array(
			'tables'       => true,
			'comment_rows' => $rows,
		);
	}
}
