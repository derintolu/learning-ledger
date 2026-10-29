<?php
/**
 * Switching Sensei's progress storage the way its settings screen does.
 *
 * @package LearningLedger
 */

use LearningLedger\Integrations\Sensei\Sensei_Storage;

/**
 * Sensei drops and creates its tables while switching, and table DDL commits the test's open
 * transaction, so what a test writes before it is cleaned up by hand in tear_down().
 *
 * @group sensei-contract
 * @requires function Sensei
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Sensei_Storage_Test extends WP_UnitTestCase {

	const OPTIONS = array( Sensei_Storage::SETTINGS, Sensei_Storage::MIGRATION_STATUS, Sensei_Storage::MIGRATION_STARTED, Sensei_Storage::MIGRATION_COMPLETED, 'sensei_lms_migration_job_errors' );

	private array $saved = array();

	private array $posts = array();

	private array $users = array();

	public function set_up(): void {
		parent::set_up();
		foreach ( self::OPTIONS as $option ) {
			$this->saved[ $option ] = get_option( $option, null );
		}
	}

	public function tear_down(): void {
		parent::tear_down();
		// After the rollback: undo what the DDL committed, then let Sensei read its settings again.
		foreach ( $this->saved as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->users as $user ) {
			wp_delete_user( $user );
		}
		foreach ( $this->posts as $post ) {
			wp_delete_post( $post, true );
		}
		self::reload();
	}

	/**
	 * What Sensei does at the start of each request: read the settings, build the repositories.
	 */
	private static function reload(): void {
		Sensei()->settings->get_settings();
		$config                                  = \Sensei\Internal\Services\Progress_Storage_Configuration::resolve( Sensei()->settings->settings );
		Sensei()->progress_storage_configuration = $config;
		Sensei()->course_progress_repository     = ( new \Sensei\Internal\Student_Progress\Course_Progress\Repositories\Course_Progress_Repository_Factory( $config ) )->create();
		Sensei()->lesson_progress_repository     = ( new \Sensei\Internal\Student_Progress\Lesson_Progress\Repositories\Lesson_Progress_Repository_Factory( $config ) )->create();
		Sensei()->quiz_progress_repository       = ( new \Sensei\Internal\Student_Progress\Quiz_Progress\Repositories\Quiz_Progress_Repository_Factory( $config ) )->create();
		\Sensei\Internal\Services\Progress_Storage_Settings::reset_cache_enabled();
	}

	/**
	 * A course and lesson the learner completes with comment storage.
	 *
	 * @return array{user:int,lesson:int}
	 */
	private function comment_progress(): array {
		$course = wp_insert_post(
			array(
				'post_type'   => 'course',
				'post_status' => 'publish',
				'post_title'  => 'Course',
			)
		);
		$lesson = wp_insert_post(
			array(
				'post_type'   => 'lesson',
				'post_status' => 'publish',
				'post_title'  => 'Lesson',
				'meta_input'  => array( '_lesson_course' => $course ),
			)
		);
		$user   = wp_insert_user(
			array(
				'user_login' => 'storage-' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
			)
		);
		array_push( $this->posts, $course, $lesson );
		$this->users[] = $user;
		\Sensei_Utils::sensei_start_lesson( $lesson, $user, true );
		return array(
			'user'   => $user,
			'lesson' => $lesson,
		);
	}

	private static function settings(): array {
		$s = get_option( Sensei_Storage::SETTINGS, array() );
		return array( $s['experimental_progress_storage'] ?? null, $s['experimental_progress_storage_synchronization'] ?? null, $s['experimental_progress_storage_repository'] ?? null );
	}

	public function test_with_no_comment_progress_it_switches_and_records_the_migration_as_complete() {
		$this->assertSame( 0, Sensei_Storage::comment_progress_rows(), 'a clean site' );
		$this->assertSame( 'tables', Sensei_Storage::switch_to_tables() );
		$this->assertSame( array( true, true, 'custom_tables' ), self::settings() );
		$this->assertSame( 'complete', get_option( Sensei_Storage::MIGRATION_STATUS ) );
		$this->assertGreaterThan( 0, (float) get_option( Sensei_Storage::MIGRATION_COMPLETED ) );
		$this->assertFalse( has_filter( 'sensei_feature_flag_tables_based_progress', '__return_true' ), 'the feature flag filter does not leak' );

		self::reload();
		$this->assertSame( 'tables', Sensei_Storage::status()['storage'] );
		$this->assertTrue( Sensei_Storage::status()['tables'] );
		$this->assertSame( 'complete', Sensei_Storage::status()['migration'] );
	}

	public function test_comment_progress_is_refused_then_migrated_with_force_before_the_switch() {
		$run = $this->comment_progress();
		$this->assertGreaterThan( 0, Sensei_Storage::comment_progress_rows() );

		$refused = Sensei_Storage::switch_to_tables();
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'comment_progress_exists', $refused->get_error_code() );

		// --force: synchronisation on, the tables recreated and Sensei's migration scheduled; reads stay on comments.
		$this->assertSame( 'migrating', Sensei_Storage::switch_to_tables( true ) );
		$this->assertSame( array( true, true, 'comments' ), self::settings() );
		self::reload();
		$this->assertSame( 'comments', Sensei_Storage::status()['storage'] );

		// Sensei's own migration jobs, as Action Scheduler would run them.
		Sensei()->init_migration_scheduler();
		Sensei()->migration_scheduler->run_job( 'student_progress_migration' );
		Sensei()->migration_scheduler->run_job( 'quiz_migration' );
		$this->assertSame( 'complete', get_option( Sensei_Storage::MIGRATION_STATUS ) );

		$this->assertSame( 'tables', Sensei_Storage::switch_to_tables() );
		$this->assertSame( array( true, true, 'custom_tables' ), self::settings() );
		self::reload();
		$this->assertSame( 'tables', Sensei_Storage::status()['storage'] );
		$this->assertTrue( (bool) \Sensei_Utils::user_completed_lesson( $run['lesson'], $run['user'] ), 'the old progress was migrated into the tables' );
	}

	public function test_comments_is_the_way_back_with_synchronisation_left_on() {
		$this->assertSame( 'tables', Sensei_Storage::switch_to_tables() );
		self::reload();
		$this->assertSame( 'comments', Sensei_Storage::switch_to_comments() );
		$this->assertSame( array( true, true, 'comments' ), self::settings() );
		self::reload();
		$this->assertSame( 'comments', Sensei_Storage::status()['storage'] );
		$this->assertTrue( Sensei()->progress_storage_configuration->is_dual_write_enabled(), 'both stores keep being written' );
	}

	public function test_tables_left_behind_when_hpps_was_turned_off_are_not_switched_to() {
		$this->assertSame( 'tables', Sensei_Storage::switch_to_tables() );
		$this->assertSame( 'complete', get_option( Sensei_Storage::MIGRATION_STATUS ) );

		// An admin turns HPPS off on Sensei's screen: sync and the repository go, the status stays complete.
		$settings                                  = get_option( Sensei_Storage::SETTINGS );
		$settings['experimental_progress_storage'] = false;
		unset( $settings['experimental_progress_storage_synchronization'], $settings['experimental_progress_storage_repository'] );
		update_option( Sensei_Storage::SETTINGS, $settings );
		self::reload();
		$this->assertSame( 'comments', Sensei_Storage::status()['storage'] );

		// Learners progress in comments only; the tables go stale.
		$run = $this->comment_progress();
		$this->assertGreaterThan( 0, Sensei_Storage::comment_progress_rows() );

		$refused = Sensei_Storage::switch_to_tables();
		$this->assertInstanceOf( WP_Error::class, $refused, 'no switch to stale tables' );
		$this->assertSame( 'comment_progress_exists', $refused->get_error_code() );
		$this->assertFalse( self::settings()[0], 'nothing changed' );

		$this->assertSame( 'migrating', Sensei_Storage::switch_to_tables( true ), '--force migrates first' );
		Sensei()->init_migration_scheduler();
		self::reload();
		Sensei()->migration_scheduler->run_job( 'student_progress_migration' );
		Sensei()->migration_scheduler->run_job( 'quiz_migration' );
		$this->assertSame( 'tables', Sensei_Storage::switch_to_tables() );
		self::reload();
		$this->assertTrue( (bool) \Sensei_Utils::user_completed_lesson( $run['lesson'], $run['user'] ), 'the comment progress made meanwhile is in the tables' );
	}
}
