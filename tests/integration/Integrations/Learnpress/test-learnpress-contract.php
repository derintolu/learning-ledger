<?php
/**
 * Runs the adapter contract against LearnPress, plus the LearnPress-specific
 * parts of the integration.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Ledger\Echo_Guard;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Activity_Ref;
use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Integration_Descriptor;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\LMS_Adapter;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Outbox\Outbox_Sink;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Integrations\Learnpress\Learnpress_Adapter;
use LearningLedger\Integrations\Learnpress\Learnpress_Iris;
use LearningLedger\Tests\Adapter_Contract_Test_Case;
use LearningLedger\Queue\Shutdown_Runner;
use LearningLedger\Tests\Recording_Sink;
use LearningLedger\Util\Clock;

/**
 * LearnPress contract.
 *
 * @group learnpress-contract
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Learnpress_Contract_Test extends Adapter_Contract_Test_Case {

	const BOOTSTRAP = LEARNING_LEDGER_DIR . 'integrations/learnpress/bootstrap.php';

	public function set_up(): void {
		Installer::install();
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Tables::name( 'conn_outbox' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// The WordPress test install recreates the core tables only, so post IDs restart while
		// LearnPress's tables keep rows from earlier runs that would point at the new posts.
		foreach ( array( 'sections', 'section_items', 'user_items', 'user_itemmeta', 'user_item_results', 'courses' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}learnpress_{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		$this->http_calls = 0;
		add_filter( 'pre_http_request', array( $this, 'no_http' ) );
		remove_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) );
	}

	public function tear_down(): void {
		Clock::freeze( null );
		remove_filter( 'pre_http_request', array( $this, 'no_http' ) );
		remove_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) );
		parent::tear_down();
	}

	/**
	 * HTTP calls attempted outside imports (all are refused).
	 *
	 * @var int
	 */
	private int $http_calls = 0;

	/**
	 * Whether a course import is running (LearnPress then starts its own background save).
	 *
	 * @var bool
	 */
	private bool $importing = false;

	public function no_http(): WP_Error {
		if ( ! $this->importing ) {
			++$this->http_calls;
		}
		return new WP_Error( 'http_not_allowed', 'No HTTP in these tests.' );
	}

	/**
	 * Run a course import as a user who may edit LearnPress courses (the
	 * import route's capability), then switch back to no user, as learners'
	 * results arrive from cron.
	 *
	 * @param callable $fn Import.
	 *
	 * @return mixed
	 */
	private function as_importer( callable $fn ): mixed { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.fnFound
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->importing = true;
		try {
			return $fn();
		} finally {
			$this->importing = false;
			wp_set_current_user( 0 );
		}
	}

	private function outbox_rows(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . Tables::name( 'conn_outbox' ) . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * A program over the given courses, with an IRI base.
	 *
	 * @param int[] $courses Course IDs.
	 *
	 * @return int Program ID.
	 */
	private function program( array $courses ): int {
		$program = self::factory()->post->create(
			array(
				'post_type'   => 'lledger_program',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $program, '_lledger_stable_key', 'learn-wp' );
		update_post_meta( $program, '_lledger_iri_base', 'https://example.test/activities/' );
		update_post_meta( $program, '_lledger_courses', $courses );
		return $program;
	}

	protected function make_adapter(): LMS_Adapter {
		return new Learnpress_Adapter();
	}

	protected function make_course( int $lessons ): array {
		return $this->as_importer(
			function () use ( $lessons ): array {
				$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
				$course = $this->adapter->upsert_course( new Course_Spec( 'c-' . $suffix, 'Course', '', 'publish', array() ) );
				$ids    = array();
				for ( $i = 1; $i <= $lessons; $i++ ) {
					$ids[] = $this->adapter->upsert_lesson( $course, new Lesson_Spec( 'l-' . $suffix . '-' . $i, 'Lesson ' . $i, '', $i, 'publish', array() ) );
				}
				return array(
					'course'  => $course,
					'lessons' => $ids,
				);
			}
		);
	}

	public function test_upsert_course_and_lesson_idempotent_by_stable_key() {
		$this->as_importer( fn() => parent::test_upsert_course_and_lesson_idempotent_by_stable_key() );
	}

	protected function lesson_iri( int $course_id, int $lesson_id ): string {
		return Learnpress_Iris::lesson( $course_id, $lesson_id );
	}

	private function enrolled_user( int $course ): int {
		$user = self::factory()->user->create();
		$this->assertTrue( $this->adapter->enrol( $user, $course ) );
		return $user;
	}

	private function apply_to( string $type, int $user, string $kind, int $id ): Apply_Outcome {
		return $this->adapter->apply( new Result( $type, $user, new Lms_Object( $kind, $id ), self::REGISTRATION, self::STATEMENT, null, Result::LESSON_WAIVED === $type ? 'prior learning' : null ) );
	}

	/**
	 * A quiz placed in the course's curriculum after its lessons.
	 *
	 * @param int $course Course ID.
	 *
	 * @return int Quiz ID.
	 */
	private function quiz_in( int $course ): int {
		$quiz     = self::factory()->post->create(
			array(
				'post_type'   => 'lp_quiz',
				'post_status' => 'publish',
			)
		);
		$sections = \LearnPress\Models\CourseModel::find( $course, false )->get_section_items();
		$section  = (int) reset( $sections )->section_id;
		( new LP_Section_CURD( $course ) )->assign_item_section(
			$section,
			array(
				'item_id'    => $quiz,
				'item_type'  => 'lp_quiz',
				'item_order' => 99,
			)
		);
		return $quiz;
	}

	public function test_descriptor_available_with_learnpress() {
		$d = require self::BOOTSTRAP;
		$this->assertInstanceOf( Integration_Descriptor::class, $d );
		$this->assertSame( 'learnpress', $d->id );
		$this->assertSame( '4.2', $d->min_version );
		$this->assertSame( '4.4', $d->tested_up_to );
		$this->assertTrue( call_user_func( $d->is_available ) );
		$this->assertSame( LEARNPRESS_VERSION, call_user_func( $d->version ) );
		$adapter = call_user_func( $d->factory );
		$this->assertInstanceOf( Learnpress_Adapter::class, $adapter );
		$this->assertSame( 'learnpress', $adapter->id() );
		$this->assertSame( LEARNPRESS_VERSION, $adapter->lms_version() );
		$this->assertEqualsCanonicalizing(
			array( 'emit_events', 'apply_completion', 'apply_score', 'mark_waived', 'enrol', 'export_course', 'import_course' ),
			$adapter->capabilities()
		);
	}

	public function test_boot_registers_post_types_and_status() {
		Learnpress_Adapter::boot();
		Learnpress_Adapter::boot();
		// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Slash-namespaced hooks.
		$this->assertContains( 'lp_course', apply_filters( 'learning_ledger/stable_key_post_types', array() ) );
		$this->assertContains( 'lp_lesson', apply_filters( 'learning_ledger/stable_key_post_types', array() ) );
		$this->assertSame( array( 'lp_lesson' ), apply_filters( 'learning_ledger/lesson_post_types', array() ), 'boot is idempotent' );
		$this->assertSame( array( 'lp_course' ), apply_filters( 'learning_ledger/course_post_types', array() ) );
		$this->assertSame( array( 'lp_course', 'lp_lesson', 'lp_quiz' ), apply_filters( 'learning_ledger/adapter_post_types', array() )['learnpress'] );
		$status = apply_filters( 'learning_ledger/status', array( 'connector' => array( 'keep' => 1 ) ), false );
		// phpcs:enable
		$this->assertSame( 1, $status['connector']['keep'] );
		$this->assertSame( LEARNPRESS_VERSION, $status['connector']['lms']['learnpress'] );
	}

	/**
	 * Item IDs of a course's curriculum as LearnPress reads it.
	 *
	 * @param int  $course Course ID.
	 * @param bool $cached Through LearnPress's cache.
	 *
	 * @return int[]
	 */
	private function curriculum( int $course, bool $cached ): array {
		$items = array();
		foreach ( \LearnPress\Models\CourseModel::find( $course, $cached )->get_section_items() as $section ) {
			$items = array_merge( $items, array_map( static fn( $i ) => (int) $i->item_id, $section->items ) );
		}
		return $items;
	}

	public function test_upserted_lessons_are_in_the_learnpress_curriculum_in_order() {
		$made = $this->make_course( 2 );
		$this->assertSame( $made['lessons'], $this->curriculum( $made['course'], false ) );

		// LearnPress keeps the section list in its course record and its cache, as its background save does.
		$record = \LearnPress\Models\CourseModel::find( $made['course'], true );
		$record->get_section_items();
		$record->save( true );
		$this->assertSame( $made['lessons'], $this->curriculum( $made['course'], true ) );

		$key   = get_post_meta( $made['lessons'][0], '_lledger_stable_key', true );
		$third = $this->as_importer( fn() => $this->adapter->upsert_lesson( $made['course'], new Lesson_Spec( str_replace( '-1', '-3', $key ), 'Lesson 3', '', 3, 'publish', array() ) ) );
		$all   = array_merge( $made['lessons'], array( $third ) );
		$this->assertSame( $all, $this->curriculum( $made['course'], false ), 'the stored record sees the new lesson' );
		$this->assertSame( $all, $this->curriculum( $made['course'], true ), 'the cache sees the new lesson' );

		// A lesson moved to the front is first after the next import.
		$this->as_importer( fn() => $this->adapter->upsert_lesson( $made['course'], new Lesson_Spec( get_post_meta( $third, '_lledger_stable_key', true ), 'Lesson 3', '', 0, 'publish', array() ) ) );
		$tree = $this->adapter->course_tree( $made['course'] );
		$this->assertSame( array( $third, $made['lessons'][0], $made['lessons'][1] ), array_map( static fn( $n ) => $n->id, $tree->lessons ) );
		$this->assertSame( array( 1, 2, 3 ), array_map( static fn( $n ) => $n->order, $tree->lessons ) );
	}

	/**
	 * Two courses (stable keys one, two) that each have a lesson with key intro.
	 *
	 * @return array{0:int,1:int,2:int,3:int} Course one, course two, lesson in one, lesson in two.
	 */
	private function two_courses_sharing_a_lesson_key(): array {
		return $this->as_importer(
			function (): array {
				$one = $this->adapter->upsert_course( new Course_Spec( 'one', 'One', '', 'publish', array() ) );
				$two = $this->adapter->upsert_course( new Course_Spec( 'two', 'Two', '', 'publish', array() ) );
				$a   = $this->adapter->upsert_lesson( $one, new Lesson_Spec( 'intro', 'Intro', '', 1, 'publish', array() ) );
				$b   = $this->adapter->upsert_lesson( $two, new Lesson_Spec( 'intro', 'Intro', '', 1, 'publish', array() ) );
				return array( $one, $two, $a, $b );
			}
		);
	}

	public function test_same_lesson_key_in_two_courses_keeps_separate_lessons() {
		list( $one, $two, $a, $b ) = $this->two_courses_sharing_a_lesson_key();
		$this->assertNotSame( $a, $b );
		$this->assertSame( array( $a ), array_map( static fn( $n ) => $n->id, $this->adapter->course_tree( $one )->lessons ) );
		$this->assertSame( array( $b ), array_map( static fn( $n ) => $n->id, $this->adapter->course_tree( $two )->lessons ) );
	}

	public function test_resolve_program_iris() {
		list( $one, $two, $a ) = $this->two_courses_sharing_a_lesson_key();
		$program               = $this->program( array( $one ) );
		$this->assertEquals( new Lms_Object( 'lesson', $a ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/lessons/intro' ) ) );
		$this->assertEquals( new Lms_Object( 'course', $one ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/paths/one' ) ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/paths/two' ) ), 'a course outside the program' );
		$this->assertEquals( new Lms_Object( 'program', $program ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities' ) ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/lessons/unknown' ) ) );
	}

	public function test_program_lesson_key_on_two_courses_resolves_to_neither() {
		list( $one, $two ) = $this->two_courses_sharing_a_lesson_key();
		$this->program( array( $one, $two ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/lessons/intro' ) ) );
	}

	public function test_resolve_program_path_key_within_the_program_courses() {
		list( $outside, $inside ) = $this->two_courses_sharing_a_lesson_key();
		update_post_meta( $outside, '_lledger_stable_key', 'shared' );
		update_post_meta( $inside, '_lledger_stable_key', 'shared' );
		$this->program( array( $inside ) );

		$this->assertEquals( new Lms_Object( 'course', $inside ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/paths/shared' ) ) );
	}

	public function test_import_without_a_current_user_writes_the_learnpress_course_record() {
		// LearnPress's own save hook refuses without a user who may edit the course and logs why; keep that log out of the test output.
		$log             = wp_tempnam( 'lledger-lp-log' );
		$previous        = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$this->importing = true;
		try {
			$course = $this->adapter->upsert_course( new Course_Spec( 'cli-import', 'From the CLI', '', 'publish', array() ) );
			$lesson = $this->adapter->upsert_lesson( $course, new Lesson_Spec( 'cli-lesson', 'Lesson', '', 1, 'publish', array() ) );
			$this->adapter->upsert_course( new Course_Spec( 'cli-import', 'Renamed', '', 'publish', array() ) );
		} finally {
			$this->importing = false;
			ini_set( 'error_log', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		$this->assertStringContainsString( 'You do not have permission', (string) file_get_contents( $log ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $log );

		$filter     = new LP_Course_JSON_Filter();
		$filter->ID = $course;
		$record     = \LearnPress\Models\CourseModel::get_item_model_from_db( $filter );
		$this->assertInstanceOf( \LearnPress\Models\CourseModel::class, $record );
		$this->assertSame( 'Renamed', $record->post_title );
		$this->assertSame( 'cli-import', $record->get_meta_value_by_key( '_lledger_stable_key' ) );
		$this->assertSame( array( $lesson ), $this->curriculum( $course, true ) );
	}

	public function test_upsert_rejects_a_lesson_for_a_non_course() {
		$post = self::factory()->post->create();
		$this->assertSame( 0, $this->adapter->upsert_lesson( $post, new Lesson_Spec( 'x', 'X', '', 1, 'publish', array() ) ) );
		$this->assertSame( 0, $this->adapter->upsert_course( new Course_Spec( '', 'No key', '', 'publish', array() ) ) );
	}

	public function test_block_satisfied_finishes_the_course_once() {
		$sink = new Recording_Sink();
		$this->adapter->register_event_listeners( $sink );
		$made = $this->make_course( 1 );
		$user = $this->enrolled_user( $made['course'] );
		$this->apply_to( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][0] );

		$this->assertSame( Apply_Outcome::APPLIED, $this->apply_to( Result::BLOCK_SATISFIED, $user, 'course', $made['course'] )->status );
		$this->assertTrue( $this->adapter->progress( $user, $made['course'] )->course_completed );
		$this->assertSame( Apply_Outcome::SKIPPED, $this->apply_to( Result::BLOCK_SATISFIED, $user, 'course', $made['course'] )->status );

		$finished = array_values( array_filter( $sink->events, static fn( $e ) => Neutral_Event::COURSE_COMPLETED === $e->type ) );
		$this->assertCount( 1, $finished );
		$this->assertSame( $made['course'], $finished[0]->course_id );
		$this->assertNull( $finished[0]->lesson_id );
		$this->assertSame( 'learnpress', $finished[0]->lms );
	}

	public function test_results_apply_without_a_current_user() {
		$made = $this->make_course( 1 );
		wp_set_current_user( 0 );
		$user = $this->enrolled_user( $made['course'] );

		$this->assertSame( Apply_Outcome::APPLIED, $this->apply_to( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][0] )->status );
		$this->assertSame( Apply_Outcome::APPLIED, $this->apply_to( Result::BLOCK_SATISFIED, $user, 'course', $made['course'] )->status );
		$progress = $this->adapter->progress( $user, $made['course'] );
		$this->assertSame( array( $made['lessons'][0] ), $progress->completed_lesson_ids );
		$this->assertTrue( $progress->course_completed );
	}

	public function test_block_satisfied_is_skipped_until_learnpress_passes_the_course() {
		$made = $this->make_course( 2 );
		$user = $this->enrolled_user( $made['course'] );
		$this->apply_to( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][0] );

		$this->assertSame( Apply_Outcome::SKIPPED, $this->apply_to( Result::BLOCK_SATISFIED, $user, 'course', $made['course'] )->status );
		$this->assertFalse( $this->adapter->progress( $user, $made['course'] )->course_completed );

		$this->apply_to( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][1] );
		$this->assertSame( Apply_Outcome::APPLIED, $this->apply_to( Result::BLOCK_SATISFIED, $user, 'course', $made['course'] )->status );
		$this->assertTrue( $this->adapter->progress( $user, $made['course'] )->course_completed );
	}

	public function test_completed_result_clears_an_earlier_waiver() {
		$made = $this->make_course( 1 );
		$user = $this->enrolled_user( $made['course'] );
		$this->apply_to( Result::LESSON_WAIVED, $user, 'lesson', $made['lessons'][0] );
		$this->assertTrue( $this->adapter->is_waived( $user, $made['lessons'][0] ) );

		$this->assertSame( Apply_Outcome::APPLIED, $this->apply_to( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][0] )->status );
		$this->assertFalse( $this->adapter->is_waived( $user, $made['lessons'][0] ) );
		$this->assertSame( array( $made['lessons'][0] ), $this->adapter->progress( $user, $made['course'] )->completed_lesson_ids );
	}

	public function test_passed_and_failed_leave_learnpress_untouched() {
		$made = $this->make_course( 1 );
		$user = $this->enrolled_user( $made['course'] );
		$this->assertSame( Apply_Outcome::APPLIED, $this->apply_to( Result::LESSON_FAILED, $user, 'lesson', $made['lessons'][0] )->status );
		$this->assertSame( Apply_Outcome::APPLIED, $this->apply_to( Result::LESSON_PASSED, $user, 'lesson', $made['lessons'][0] )->status );
		$this->assertSame( array(), $this->adapter->progress( $user, $made['course'] )->completed_lesson_ids );
	}

	public function test_not_enrolled_is_deferred_unless_auto_enrol_is_on() {
		$made = $this->make_course( 1 );
		$user = self::factory()->user->create();

		Connector_Settings::update( array( 'allow_auto_enrol' => false ) );
		$this->assertSame( Apply_Outcome::DEFERRED, $this->apply_to( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][0] )->status );
		$this->assertFalse( learn_press_get_user( $user )->has_enrolled_or_finished( $made['course'] ) );

		Connector_Settings::update( array( 'allow_auto_enrol' => true ) );
		$this->assertSame( Apply_Outcome::APPLIED, $this->apply_to( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][0] )->status );
		$this->assertTrue( learn_press_get_user( $user )->has_enrolled_or_finished( $made['course'] ) );
		$this->assertSame( array( $made['lessons'][0] ), $this->adapter->progress( $user, $made['course'] )->completed_lesson_ids );
	}

	public function test_results_for_objects_that_are_not_learnpress_fail() {
		$user = self::factory()->user->create();
		$post = self::factory()->post->create();
		$this->assertSame( Apply_Outcome::FAILED, $this->apply_to( Result::LESSON_COMPLETED, $user, 'lesson', $post )->status );
		$this->assertSame( Apply_Outcome::FAILED, $this->apply_to( Result::BLOCK_SATISFIED, $user, 'course', $post )->status );
		$this->assertSame( Apply_Outcome::FAILED, $this->apply_to( Result::LESSON_COMPLETED, 0, 'lesson', $post )->status );
	}

	public function test_course_satisfied_sets_program_user_meta() {
		Clock::freeze( new DateTimeImmutable( '2026-10-27 12:00:00', new DateTimeZone( 'UTC' ) ) );
		$user = self::factory()->user->create();
		$this->assertSame( Apply_Outcome::APPLIED, $this->apply_to( Result::COURSE_SATISFIED, $user, 'program', 42 )->status );
		$this->assertSame( (string) Clock::now()->getTimestamp(), get_user_meta( $user, '_lledger_program_satisfied_42', true ) );
		$this->assertSame( Apply_Outcome::SKIPPED, $this->apply_to( Result::COURSE_SATISFIED, $user, 'program', 42 )->status );
	}

	public function test_quiz_finished_emits_quiz_graded_with_score_and_success() {
		$sink = new Recording_Sink();
		$this->adapter->register_event_listeners( $sink );
		$made = $this->make_course( 1 );
		$quiz = $this->quiz_in( $made['course'] );
		$user = $this->enrolled_user( $made['course'] );

		$this->finish_quiz( $made['course'], $quiz, $user );

		$graded = array_values( array_filter( $sink->events, static fn( $e ) => Neutral_Event::QUIZ_GRADED === $e->type ) );
		$this->assertCount( 1, $graded );
		$this->assertSame( $user, $graded[0]->user_id );
		$this->assertSame( $made['course'], $graded[0]->course_id );
		$this->assertNull( $graded[0]->lesson_id );
		$this->assertSame( $quiz, $graded[0]->quiz_id );
		$this->assertSame( 0.8, $graded[0]->score_scaled );
		$this->assertTrue( $graded[0]->success );

		// Without the model argument (LP_User::finish_quiz) the attempt is looked up.
		do_action( 'learn-press/user/quiz-finished', $quiz, $made['course'], $user ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LearnPress hook.
		$last = end( $sink->events );
		$this->assertSame( Neutral_Event::QUIZ_GRADED, $last->type );
		$this->assertSame( 0.8, $last->score_scaled );
	}

	/**
	 * Fire LearnPress's quiz-finished hook for a finished attempt.
	 *
	 * @param int $course Course ID.
	 * @param int $quiz   Quiz ID.
	 * @param int $user   User ID.
	 *
	 * @return void
	 */
	private function finish_quiz( int $course, int $quiz, int $user ): void {
		$user_quiz            = new \LearnPress\Models\UserItems\UserQuizModel();
		$user_quiz->user_id   = $user;
		$user_quiz->item_id   = $quiz;
		$user_quiz->ref_id    = $course;
		$user_quiz->parent_id = \LearnPress\Models\UserItems\UserCourseModel::find( $user, $course )->get_user_item_id();
		$user_quiz->status    = 'completed';
		$user_quiz->save();
		LP_User_Items_Result_DB::instance()->update(
			$user_quiz->get_user_item_id(),
			wp_json_encode(
				array(
					'result' => 80,
					'pass'   => 1,
				)
			)
		);
		do_action( 'learn-press/user/quiz-finished', $quiz, $course, $user, $user_quiz ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LearnPress hook.
	}

	public function test_quiz_grades_reach_the_outbox_only_outside_programs() {
		Learnpress_Adapter::boot();
		$this->adapter->register_event_listeners( new Outbox_Sink() );
		$solo = $this->make_course( 1 );
		$quiz = $this->quiz_in( $solo['course'] );
		$user = $this->enrolled_user( $solo['course'] );
		$this->finish_quiz( $solo['course'], $quiz, $user );
		$rows = $this->outbox_rows();
		$this->assertCount( 1, $rows );
		$body = json_decode( $rows[0]['body'], true );
		$this->assertSame( 'http://adlnet.gov/expapi/verbs/passed', $body['verb']['id'] );
		$this->assertSame( Learnpress_Iris::quiz( $solo['course'], $quiz ), $body['object']['id'] );

		// In a program the quiz is no activity of its own; it must not be reported against the block.
		$path    = $this->make_course( 1 );
		$program = $this->program( array( $path['course'] ) );
		update_post_meta( $program, '_lledger_path_keys', array( $path['course'] => 'path-one' ) );
		$quiz = $this->quiz_in( $path['course'] );
		$user = $this->enrolled_user( $path['course'] );
		$this->finish_quiz( $path['course'], $quiz, $user );
		$this->assertCount( 1, $this->outbox_rows() );

		// Direction 2 is on for that course: the learner's lesson completion is sent, on the program's lesson IRI.
		$this->learner_completes( $user, $path['lessons'][0], $path['course'] );
		$rows = $this->outbox_rows();
		$this->assertCount( 2, $rows );
		$key = get_post_meta( $path['lessons'][0], '_lledger_stable_key', true );
		$this->assertStringStartsWith( 'https://example.test/activities/', json_decode( $rows[1]['body'], true )['object']['id'] );
		$this->assertStringContainsString( $key, json_decode( $rows[1]['body'], true )['object']['id'] );
		$this->assertSame( 0, $this->http_calls, 'Listeners make no HTTP call during the learner\'s action.' );
	}

	/**
	 * Complete a lesson the way LearnPress does for the learner: open it, then complete it.
	 *
	 * @param int $user   User ID.
	 * @param int $lesson Lesson ID.
	 * @param int $course Course ID.
	 *
	 * @return void
	 */
	private function learner_completes( int $user, int $lesson, int $course ): void {
		$item            = new \LearnPress\Models\UserItems\UserLessonModel();
		$item->user_id   = $user;
		$item->item_id   = $lesson;
		$item->ref_id    = $course;
		$item->parent_id = \LearnPress\Models\UserItems\UserCourseModel::find( $user, $course )->get_user_item_id();
		$item->status    = 'started';
		$item->save();
		$this->assertTrue( learn_press_get_user( $user )->complete_lesson( $lesson, $course ) );
	}

	public function test_learner_completion_reaches_the_outbox_but_applied_results_do_not() {
		Learnpress_Adapter::boot();
		$this->adapter->register_event_listeners( new Outbox_Sink() );
		$made = $this->make_course( 3 );
		$user = $this->enrolled_user( $made['course'] );

		$this->assertSame( Apply_Outcome::APPLIED, $this->apply_to( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][0] )->status );
		$this->assertSame( Apply_Outcome::APPLIED, $this->apply_to( Result::LESSON_WAIVED, $user, 'lesson', $made['lessons'][1] )->status );
		Echo_Guard::while_applying( fn() => $this->learner_completes( $user, $made['lessons'][2], $made['course'] ) );
		$this->assertSame( array(), $this->outbox_rows(), 'nothing the connector applied is sent back' );

		$learner = $this->enrolled_user( $made['course'] );
		$this->learner_completes( $learner, $made['lessons'][0], $made['course'] );
		$rows = $this->outbox_rows();
		$this->assertCount( 1, $rows );
		$body = json_decode( $rows[0]['body'], true );
		$this->assertSame( 'http://adlnet.gov/expapi/verbs/completed', $body['verb']['id'] );
		$this->assertSame( Learnpress_Iris::lesson( $made['course'], $made['lessons'][0] ), $body['object']['id'] );
		$this->assertSame( 0, $this->http_calls, 'Listeners make no HTTP call during the learner\'s action.' );
	}

	public function test_events_without_a_user_or_course_are_dropped() {
		$sink = new Recording_Sink();
		$this->adapter->register_event_listeners( $sink );
		// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LearnPress hook names.
		do_action( 'learn-press/user-completed-lesson', 5, 0, 1 );
		do_action( 'learn-press/user-course-finished', 7, 0, 1 );
		do_action( 'learn-press/user/quiz-finished', 9, 7, 0 );
		// phpcs:enable
		$this->assertSame( array(), $sink->events );
	}

	public function test_direction2_iri_filter() {
		Learnpress_Adapter::boot();
		$made   = $this->make_course( 1 );
		$course = $made['course'];
		$lesson = $made['lessons'][0];
		$user   = self::factory()->user->create();
		// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Slash-namespaced hook.
		$this->assertSame(
			array(
				'object' => Learnpress_Iris::lesson( $course, $lesson ),
				'parent' => Learnpress_Iris::course( $course ),
			),
			apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::LESSON_COMPLETED, 'learnpress', $user, $course, $lesson ) )
		);
		$this->assertSame(
			array(
				'object' => Learnpress_Iris::quiz( $course, 99 ),
				'parent' => Learnpress_Iris::course( $course ),
			),
			apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::QUIZ_GRADED, 'learnpress', $user, $course, null, 99, 0.8, true ) )
		);
		$this->assertSame(
			array(
				'object' => Learnpress_Iris::course( $course ),
				'parent' => Learnpress_Iris::course( $course ),
			),
			apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::COURSE_COMPLETED, 'learnpress', $user, $course ) )
		);
		$this->assertSame( 'kept', apply_filters( 'learning_ledger/direction2_iris', 'kept', new Neutral_Event( Neutral_Event::COURSE_COMPLETED, 'sensei', $user, $course ) ), 'other LMS events pass through' );

		// Courses in a program use Program_Iris instead.
		$this->program( array( $course ) );
		$this->assertNull( apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::LESSON_COMPLETED, 'learnpress', $user, $course, $lesson ) ) );
		// phpcs:enable
	}

	public function test_resolve_course_and_quiz_iris_without_fixing_the_base() {
		$made   = $this->make_course( 1 );
		$course = $made['course'];
		$quiz   = $this->quiz_in( $course );
		$this->assertEquals( new Lms_Object( 'course', $course ), $this->adapter->resolve( new Activity_Ref( Learnpress_Iris::peek( $course ) ) ) );
		$this->assertSame( '', get_post_meta( $course, '_lledger_activity_base', true ), 'resolve does not write' );
		$this->assertEquals( new Lms_Object( 'quiz', $quiz ), $this->adapter->resolve( new Activity_Ref( Learnpress_Iris::quiz( $course, $quiz ) ) ) );
		$this->assertEquals( new Lms_Object( 'course', $course ), $this->adapter->resolve( new Activity_Ref( Learnpress_Iris::course( $course ) ) ) );

		// A lesson under another course's base is not ours.
		$other = $this->make_course( 1 );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( Learnpress_Iris::course( $other['course'] ) . '/lessons/' . $made['lessons'][0] ) ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( Learnpress_Iris::course( $course ) . '/quizzes/' . $made['lessons'][0] ) ) );
	}

	public function test_activity_base_fixed_on_first_use() {
		$made  = $this->make_course( 1 );
		$first = Learnpress_Iris::course( $made['course'] );
		$this->assertSame( home_url( '/activities/courses/' . $made['course'] ), $first );
		$moved = static fn() => 'https://moved.example';
		add_filter( 'home_url', $moved );
		$this->assertSame( $first, Learnpress_Iris::course( $made['course'] ), 'base unchanged after home_url changes' );
		$this->assertEquals( new Lms_Object( 'lesson', $made['lessons'][0] ), $this->adapter->resolve( new Activity_Ref( Learnpress_Iris::lesson( $made['course'], $made['lessons'][0] ) ) ) );
		remove_filter( 'home_url', $moved );
	}

	public function test_waivers_are_kept_in_the_shared_ledger() {
		$made = $this->make_course( 1 );
		$user = $this->enrolled_user( $made['course'] );
		$this->apply_to( Result::LESSON_WAIVED, $user, 'lesson', $made['lessons'][0] );
		$waiver = ( new Waiver_Ledger() )->get( $user, $made['lessons'][0] );
		$this->assertSame( 'prior learning', $waiver['reason'] );
		$this->assertSame( self::STATEMENT, $waiver['statement_id'] );
		$this->assertSame( Apply_Outcome::SKIPPED, $this->apply_to( Result::LESSON_WAIVED, $user, 'lesson', $made['lessons'][0] )->status );
	}
}
