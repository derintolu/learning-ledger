<?php
/**
 * Runs the adapter contract against LifterLMS, plus the LifterLMS-specific behaviour.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Ledger\Echo_Guard;
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
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Integrations\Lifterlms\Lifterlms_Adapter;
use LearningLedger\Integrations\Lifterlms\Lifterlms_Applier;
use LearningLedger\Integrations\Lifterlms\Lifterlms_Iris;
use LearningLedger\Queue\Shutdown_Runner;
use LearningLedger\Tests\Adapter_Contract_Test_Case;
use LearningLedger\Tests\Recording_Sink;

/**
 * LifterLMS contract.
 *
 * @group lifterlms-contract
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Lifterlms_Contract_Test extends Adapter_Contract_Test_Case {

	const BOOTSTRAP = LEARNING_LEDGER_DIR . 'integrations/lifterlms/bootstrap.php';

	// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Slash-namespaced hooks are the plugin convention.

	public function set_up(): void {
		Installer::install();
		( new Program_Module() )->register_post_type();
		parent::set_up();
	}

	protected function make_adapter(): LMS_Adapter {
		return new Lifterlms_Adapter();
	}

	protected function make_course( int $lessons ): array {
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

	protected function lesson_iri( int $course_id, int $lesson_id ): string {
		return Lifterlms_Iris::lesson( $course_id, $lesson_id );
	}

	private function program( array $courses, string $key = 'learn-wp' ): int {
		$program = self::factory()->post->create(
			array(
				'post_type'   => 'lledger_program',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $program, '_lledger_stable_key', $key );
		update_post_meta( $program, '_lledger_iri_base', 'https://example.test/activities/' );
		update_post_meta( $program, '_lledger_courses', $courses );
		return $program;
	}

	private function quiz_for( int $lesson ): int {
		$quiz = new LLMS_Quiz( 'new', array( 'post_title' => 'Quiz' ) );
		$quiz->set( 'lesson_id', $lesson );
		$model = llms_get_post( $lesson );
		$model->set( 'quiz', $quiz->get( 'id' ) );
		$model->set( 'quiz_enabled', 'yes' );
		return (int) $quiz->get( 'id' );
	}

	private function attempt( int $lesson, string $status, ?float $grade ): LLMS_Quiz_Attempt {
		$attempt = new LLMS_Quiz_Attempt();
		$attempt->set( 'lesson_id', $lesson );
		$attempt->set( 'grade', $grade );
		$attempt->set_status( $status );
		return $attempt;
	}

	public function test_descriptor_available_with_lifterlms() {
		$d = require self::BOOTSTRAP;
		$this->assertInstanceOf( Integration_Descriptor::class, $d );
		$this->assertSame( 'lifterlms', $d->id );
		$this->assertSame( '10.0', $d->min_version );
		$this->assertSame( '10.3', $d->tested_up_to );
		$this->assertTrue( call_user_func( $d->is_available ) );
		$this->assertSame( llms()->version, call_user_func( $d->version ) );
		$adapter = call_user_func( $d->factory );
		$this->assertInstanceOf( Lifterlms_Adapter::class, $adapter );
		$this->assertSame( 'lifterlms', $adapter->id() );
		$this->assertSame( llms()->version, $adapter->lms_version() );
		$this->assertEqualsCanonicalizing(
			array( 'emit_events', 'apply_completion', 'apply_score', 'mark_waived', 'enrol', 'export_course', 'import_course' ),
			$adapter->capabilities()
		);
	}

	public function test_boot_registers_post_types_status_and_iri_filter() {
		Lifterlms_Adapter::boot();
		$this->assertEqualsCanonicalizing( array( 'page', 'course', 'lesson' ), apply_filters( 'learning_ledger/stable_key_post_types', array( 'page' ) ) );
		$this->assertSame( array( 'lesson' ), apply_filters( 'learning_ledger/lesson_post_types', array() ) );
		$this->assertSame( array( 'course' ), apply_filters( 'learning_ledger/course_post_types', array() ) );
		$this->assertSame(
			array(
				'fake'      => array( 'x' ),
				'lifterlms' => array( 'course', 'lesson', 'llms_quiz' ),
			),
			apply_filters( 'learning_ledger/adapter_post_types', array( 'fake' => array( 'x' ) ) )
		);
		$status = apply_filters( 'learning_ledger/status', array( 'connector' => array( 'keep' => 1 ) ), false );
		$this->assertSame( 1, $status['connector']['keep'] );
		$this->assertSame( llms()->version, $status['connector']['lms']['lifterlms'] );
		$this->assertNotFalse( has_filter( 'learning_ledger/direction2_iris', array( Lifterlms_Iris::class, 'direction2_iris' ) ) );

		Lifterlms_Adapter::boot();
		$this->assertSame( array( 'lesson' ), apply_filters( 'learning_ledger/lesson_post_types', array() ), 'boot is idempotent' );
	}

	public function test_upsert_uses_lifterlms_models_with_a_section() {
		$made    = $this->make_course( 2 );
		$course  = llms_get_post( $made['course'] );
		$lessons = array_map( 'llms_get_post', $made['lessons'] );
		$this->assertInstanceOf( LLMS_Course::class, $course );
		$this->assertContainsOnlyInstancesOf( LLMS_Lesson::class, $lessons );
		$sections = $course->get_sections( 'ids' );
		$this->assertCount( 1, $sections );
		foreach ( $lessons as $i => $lesson ) {
			$this->assertSame( $made['course'], $lesson->get( 'parent_course' ) );
			$this->assertSame( $sections[0], $lesson->get( 'parent_section' ) );
			$this->assertSame( $i + 1, $lesson->get( 'order' ) );
		}
		$this->assertSame( $made['lessons'], array_map( 'intval', $course->get_lessons( 'ids' ) ) );
		$this->assertSame( $made['course'], (int) llms_get_post( $sections[0] )->get( 'parent_course' ) );

		// A changed title and order go through; a second section is not created.
		$key = get_post_meta( $made['lessons'][0], '_lledger_stable_key', true );
		$id  = $this->adapter->upsert_lesson( $made['course'], new Lesson_Spec( $key, 'Renamed', '', 3, 'publish', array() ) );
		$this->assertSame( $made['lessons'][0], $id );
		$this->assertSame( 'Renamed', get_the_title( $id ) );
		$this->assertSame( array( $made['lessons'][1], $made['lessons'][0] ), array_map( 'intval', ( new LLMS_Course( $made['course'] ) )->get_lessons( 'ids' ) ) );
		$this->assertCount( 1, ( new LLMS_Course( $made['course'] ) )->get_sections( 'ids' ) );
	}

	public function test_same_lesson_key_in_two_courses_keeps_separate_lessons() {
		$a  = $this->adapter->upsert_course( new Course_Spec( 'course-a', 'A', '', 'publish', array() ) );
		$b  = $this->adapter->upsert_course( new Course_Spec( 'course-b', 'B', '', 'publish', array() ) );
		$la = $this->adapter->upsert_lesson( $a, new Lesson_Spec( 'intro', 'Intro A', '', 1, 'publish', array() ) );
		$lb = $this->adapter->upsert_lesson( $b, new Lesson_Spec( 'intro', 'Intro B', '', 1, 'publish', array() ) );
		$this->assertGreaterThan( 0, $lb );
		$this->assertNotSame( $la, $lb );
		$this->assertSame( $a, llms_get_post( $la )->get( 'parent_course' ) );
		$this->assertSame( $b, llms_get_post( $lb )->get( 'parent_course' ) );
		$this->assertSame( 'Intro A', get_post( $la )->post_title );
		$this->assertSame( $lb, $this->adapter->upsert_lesson( $b, new Lesson_Spec( 'intro', 'Intro B', '', 1, 'publish', array() ) ) );
		$this->assertSame( 0, $this->adapter->upsert_lesson( $la, new Lesson_Spec( 'x', 'X', '', 1, 'publish', array() ) ), 'parent must be a course' );
	}

	public function test_course_tree_reports_pass_required_quiz() {
		$made   = $this->make_course( 2 );
		$lesson = llms_get_post( $made['lessons'][1] );
		$this->quiz_for( $made['lessons'][1] );
		$lesson->set( 'require_passing_grade', 'yes' );
		$tree = $this->adapter->course_tree( $made['course'] );
		$this->assertFalse( $tree->lessons[0]->has_pass_required_quiz );
		$this->assertTrue( $tree->lessons[1]->has_pass_required_quiz );
		$this->assertSame( array( 1, 2 ), array_map( static fn( $n ) => $n->order, $tree->lessons ) );
		$this->assertSame( array(), $this->adapter->course_tree( $made['lessons'][0] )->lessons, 'not a course' );
	}

	public function test_resolve_program_iris() {
		$made = $this->make_course( 1 );
		update_post_meta( $made['course'], '_lledger_stable_key', 'block-theme' );
		update_post_meta( $made['lessons'][0], '_lledger_stable_key', 'bt-one' );
		$program = $this->program( array( $made['course'] ) );

		$this->assertEquals( new Lms_Object( 'lesson', $made['lessons'][0] ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/lessons/bt-one' ) ) );
		$this->assertEquals( new Lms_Object( 'lesson', $made['lessons'][0] ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/lessons/bt-one/au' ) ) );
		$this->assertEquals( new Lms_Object( 'course', $made['course'] ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/paths/block-theme' ) ) );
		$this->assertEquals( new Lms_Object( 'program', $program ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities' ) ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/lessons/unknown' ) ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/placement' ) ) );
	}

	public function test_resolve_program_lesson_key_matching_two_lessons_is_null() {
		$a = $this->adapter->upsert_course( new Course_Spec( 'path-a', 'A', '', 'publish', array() ) );
		$b = $this->adapter->upsert_course( new Course_Spec( 'path-b', 'B', '', 'publish', array() ) );
		$this->adapter->upsert_lesson( $a, new Lesson_Spec( 'intro', 'Intro A', '', 1, 'publish', array() ) );
		$this->adapter->upsert_lesson( $b, new Lesson_Spec( 'intro', 'Intro B', '', 1, 'publish', array() ) );
		$only = $this->adapter->upsert_lesson( $b, new Lesson_Spec( 'only-b', 'Only B', '', 2, 'publish', array() ) );
		$this->program( array( $a, $b ) );

		$this->assertNull( $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/lessons/intro' ) ) );
		$this->assertEquals( new Lms_Object( 'lesson', $only ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/lessons/only-b' ) ) );
	}

	public function test_resolve_program_path_key_within_the_program_courses() {
		$outside = $this->adapter->upsert_course( new Course_Spec( 'outside', 'Outside', '', 'publish', array() ) );
		$inside  = $this->adapter->upsert_course( new Course_Spec( 'inside', 'Inside', '', 'publish', array() ) );
		update_post_meta( $outside, '_lledger_stable_key', 'shared' );
		update_post_meta( $inside, '_lledger_stable_key', 'shared' );
		$this->program( array( $inside ) );

		$this->assertEquals( new Lms_Object( 'course', $inside ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/paths/shared' ) ) );
	}

	public function test_resolve_direction2_iris_writes_nothing() {
		$made   = $this->make_course( 2 );
		$course = $made['course'];
		$lesson = $made['lessons'][0];
		$quiz   = $this->quiz_for( $lesson );
		$base   = home_url( '/activities/courses/' . $course );

		$this->assertEquals( new Lms_Object( 'course', $course ), $this->adapter->resolve( new Activity_Ref( $base ) ) );
		$this->assertEquals( new Lms_Object( 'lesson', $lesson ), $this->adapter->resolve( new Activity_Ref( $base . '/lessons/' . $lesson ) ) );
		$this->assertEquals( new Lms_Object( 'quiz', $quiz ), $this->adapter->resolve( new Activity_Ref( $base . '/lessons/' . $lesson . '/quiz' ) ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( $base . '/lessons/' . $made['lessons'][1] . '/quiz' ) ), 'lesson without a quiz' );
		$this->assertFalse( metadata_exists( 'post', $course, '_lledger_activity_base' ), 'resolve() writes nothing' );

		// Fixed bases resolve; a lesson under another course's base is not ours.
		$this->assertSame( $base, Lifterlms_Iris::course( $course ) );
		$this->assertSame( $base, get_post_meta( $course, '_lledger_activity_base', true ) );
		$other = $this->make_course( 1 );
		$this->assertEquals( new Lms_Object( 'course', $course ), $this->adapter->resolve( new Activity_Ref( Lifterlms_Iris::course( $course ) ) ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( Lifterlms_Iris::course( $course ) . '/lessons/' . $other['lessons'][0] ) ) );
	}

	public function test_activity_base_fixed_on_first_use() {
		$made  = $this->make_course( 1 );
		$first = Lifterlms_Iris::course( $made['course'] );
		$moved = static fn() => 'https://moved.example';
		add_filter( 'home_url', $moved );
		$this->assertSame( $first, Lifterlms_Iris::course( $made['course'] ), 'base unchanged after home_url changes' );
		$this->assertSame( $first . '/lessons/' . $made['lessons'][0], Lifterlms_Iris::lesson( $made['course'], $made['lessons'][0] ) );
		$this->assertEquals( new Lms_Object( 'lesson', $made['lessons'][0] ), $this->adapter->resolve( new Activity_Ref( Lifterlms_Iris::lesson( $made['course'], $made['lessons'][0] ) ) ) );
		remove_filter( 'home_url', $moved );
	}

	public function test_direction2_iri_filter() {
		Lifterlms_Adapter::boot();
		$made   = $this->make_course( 1 );
		$course = $made['course'];
		$lesson = $made['lessons'][0];
		$quiz   = $this->quiz_for( $lesson );
		$user   = self::factory()->user->create();
		$base   = Lifterlms_Iris::course( $course );

		$this->assertSame(
			array(
				'object' => $base . '/lessons/' . $lesson,
				'parent' => $base,
			),
			apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::LESSON_COMPLETED, 'lifterlms', $user, $course, $lesson ) )
		);
		$this->assertSame(
			array(
				'object' => $base . '/lessons/' . $lesson . '/quiz',
				'parent' => $base,
			),
			apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::QUIZ_GRADED, 'lifterlms', $user, $course, null, $quiz, 0.8, true ) ),
			'the quiz lesson comes from the quiz'
		);
		$this->assertSame(
			array(
				'object' => $base,
				'parent' => $base,
			),
			apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::COURSE_COMPLETED, 'lifterlms', $user, $course ) )
		);
		$this->assertSame( 'kept', apply_filters( 'learning_ledger/direction2_iris', 'kept', new Neutral_Event( Neutral_Event::COURSE_COMPLETED, 'sensei', $user, $course ) ), 'other LMS events pass through' );

		// Courses in a program use Program_Iris instead.
		$this->program( array( $course ) );
		$this->assertNull( apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::LESSON_COMPLETED, 'lifterlms', $user, $course, $lesson ) ) );
	}

	public function test_lesson_completion_cascade_emits_section_course_and_track() {
		Lifterlms_Adapter::boot();
		$sink = new Recording_Sink();
		$this->adapter->register_event_listeners( $sink );
		$made    = $this->make_course( 1 );
		$course  = $made['course'];
		$lesson  = $made['lessons'][0];
		$section = (int) llms_get_post( $lesson )->get( 'parent_section' );
		$track   = wp_insert_term( 'Track ' . wp_generate_uuid4(), 'course_track' )['term_id'];
		wp_set_object_terms( $course, array( $track ), 'course_track' );
		$user = self::factory()->user->create();

		llms_mark_complete( $user, $lesson, 'lesson', 'lesson_' . $lesson );

		$this->assertSame(
			array( Neutral_Event::LESSON_COMPLETED, Neutral_Event::COURSE_COMPLETED, Neutral_Event::COURSE_COMPLETED, Neutral_Event::COURSE_COMPLETED ),
			array_map( static fn( $e ) => $e->type, $sink->events )
		);
		foreach ( $sink->events as $e ) {
			$this->assertSame( 'lifterlms', $e->lms );
			$this->assertSame( $user, $e->user_id );
			$this->assertSame( $course, $e->course_id );
		}
		$this->assertSame( $lesson, $sink->events[0]->lesson_id );
		$base = Lifterlms_Iris::course( $course );
		$iris = array_map( static fn( $e ) => apply_filters( 'learning_ledger/direction2_iris', null, $e ), $sink->events );
		$this->assertSame(
			array(
				array(
					'object' => $base . '/lessons/' . $lesson,
					'parent' => $base,
				),
				array(
					'object' => $base . '/sections/' . $section,
					'parent' => $base,
				),
				array(
					'object' => $base,
					'parent' => $base,
				),
				array(
					'object' => Lifterlms_Iris::track( $track ),
					'parent' => '',
				),
			),
			$iris
		);
		$this->assertSame( home_url( '/activities/tracks/' . $track ), Lifterlms_Iris::track( $track ) );
		$this->assertSame( Lifterlms_Iris::track( $track ), get_term_meta( $track, '_lledger_activity_base', true ), 'track base fixed on first use' );
	}

	public function test_outbox_queues_on_site_completions_but_not_applied_ones() {
		Lifterlms_Adapter::boot();
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_not_allowed', 'No HTTP during an LMS action.' ) );
		remove_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) );
		$this->adapter->register_event_listeners( new Outbox_Sink() );
		$made  = $this->make_course( 2 );
		$track = wp_insert_term( 'Track ' . wp_generate_uuid4(), 'course_track' )['term_id'];
		wp_set_object_terms( $made['course'], array( $track ), 'course_track' );
		$user = self::factory()->user->create();
		$this->adapter->enrol( $user, $made['course'] );
		$objects = static function (): array {
			global $wpdb;
			$bodies = $wpdb->get_col( 'SELECT body FROM ' . Tables::name( 'conn_outbox' ) . ' ORDER BY id' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			return array_map( static fn( $b ) => json_decode( $b, true )['object']['id'], $bodies );
		};
		$before  = $objects();

		// The connector's own write is not echoed back to the LRS.
		$this->adapter->apply( $this->result( Result::LESSON_COMPLETED, $user, $made['lessons'][0] ) );
		$this->assertSame( $before, $objects() );

		// The learner's completion of the last lesson completes the section, course and track.
		llms_mark_complete( $user, $made['lessons'][1], 'lesson', 'lesson_' . $made['lessons'][1] );
		$base    = Lifterlms_Iris::course( $made['course'] );
		$section = (int) llms_get_post( $made['lessons'][1] )->get( 'parent_section' );
		$this->assertSame(
			array( $base . '/lessons/' . $made['lessons'][1], $base . '/sections/' . $section, $base, Lifterlms_Iris::track( $track ) ),
			array_slice( $objects(), count( $before ) )
		);
	}

	public function test_section_and_track_completions_in_a_program_course_emit_nothing() {
		$sink = new Recording_Sink();
		$this->adapter->register_event_listeners( $sink );
		$made  = $this->make_course( 1 );
		$track = wp_insert_term( 'Track ' . wp_generate_uuid4(), 'course_track' )['term_id'];
		wp_set_object_terms( $made['course'], array( $track ), 'course_track' );
		$this->program( array( $made['course'] ) );
		$user = self::factory()->user->create();

		llms_mark_complete( $user, $made['lessons'][0], 'lesson', 'test' );

		$this->assertSame( array( Neutral_Event::LESSON_COMPLETED, Neutral_Event::COURSE_COMPLETED ), array_map( static fn( $e ) => $e->type, $sink->events ) );
		$this->assertNull( $sink->events[1]->lesson_id );
	}

	public function test_quiz_completed_events() {
		$sink = new Recording_Sink();
		$this->adapter->register_event_listeners( $sink );
		$made   = $this->make_course( 1 );
		$lesson = $made['lessons'][0];
		$quiz   = $this->quiz_for( $lesson );
		$user   = self::factory()->user->create();

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fires LifterLMS's own hook.
		do_action( 'lifterlms_quiz_completed', $user, $quiz, $this->attempt( $lesson, 'pass', 80.0 ) );
		do_action( 'lifterlms_quiz_completed', $user, $quiz, $this->attempt( $lesson, 'fail', 25.5 ) );
		do_action( 'lifterlms_quiz_completed', $user, $quiz, $this->attempt( $lesson, 'pending', null ) );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// LifterLMS itself completes the lesson on a finished quiz; those events are not quiz events.
		$quizzes = array_values( array_filter( $sink->events, static fn( $e ) => in_array( $e->type, array( Neutral_Event::QUIZ_GRADED, Neutral_Event::QUIZ_SUBMITTED ), true ) ) );
		$this->assertCount( 3, $quizzes );
		list( $pass, $fail, $pending ) = $quizzes;
		$this->assertSame( array( Neutral_Event::QUIZ_GRADED, $made['course'], $lesson, $quiz, 0.8, true ), array( $pass->type, $pass->course_id, $pass->lesson_id, $pass->quiz_id, $pass->score_scaled, $pass->success ) );
		$this->assertSame( array( Neutral_Event::QUIZ_GRADED, 0.255, false ), array( $fail->type, $fail->score_scaled, $fail->success ) );
		$this->assertSame( array( Neutral_Event::QUIZ_SUBMITTED, $lesson, null, null ), array( $pending->type, $pending->lesson_id, $pending->score_scaled, $pending->success ) );
	}

	public function test_enrolment_events() {
		$sink = new Recording_Sink();
		$this->adapter->register_event_listeners( $sink );
		$made = $this->make_course( 1 );
		$user = self::factory()->user->create();

		$this->assertTrue( $this->adapter->enrol( $user, $made['course'] ) );
		$this->assertTrue( $this->adapter->enrol( $user, $made['course'] ), 'already enrolled' );
		$this->assertSame( 'learning-ledger', ( new LLMS_Student( $user ) )->get_enrollment_trigger( $made['course'] ) );
		llms_unenroll_student( $user, $made['course'] );
		$this->assertFalse( $this->adapter->enrol( $user, $made['lessons'][0] ), 'not a course' );

		$this->assertSame( array( Neutral_Event::ENROLLED, Neutral_Event::UNENROLLED ), array_map( static fn( $e ) => $e->type, $sink->events ) );
		$this->assertSame( $made['course'], $sink->events[1]->course_id );
	}

	public function test_apply_runs_inside_the_echo_guard_with_our_trigger() {
		$made     = $this->make_course( 1 );
		$user     = self::factory()->user->create();
		$active   = array();
		$triggers = array();
		$this->adapter->enrol( $user, $made['course'] );
		add_action(
			'llms_mark_complete',
			static function ( $student, $object_id, $type, $trigger ) use ( &$active, &$triggers ) {
				$active[]   = Echo_Guard::active();
				$triggers[] = $type . ':' . $trigger;
			},
			10,
			4
		);

		$outcome = $this->adapter->apply( $this->result( Result::LESSON_COMPLETED, $user, $made['lessons'][0] ) );

		$this->assertSame( Apply_Outcome::APPLIED, $outcome->status );
		$this->assertSame( array( 'lesson:learning-ledger', 'section:learning-ledger', 'course:learning-ledger' ), $triggers );
		$this->assertSame( array( true, true, true ), $active );
		$this->assertSame( 'learning-ledger', Lifterlms_Applier::TRIGGER );
		$this->assertTrue( $this->adapter->progress( $user, $made['course'] )->course_completed );
	}

	public function test_apply_waived_then_completed_clears_the_waiver() {
		$made = $this->make_course( 1 );
		$user = self::factory()->user->create();
		$this->adapter->enrol( $user, $made['course'] );
		$this->adapter->apply( $this->result( Result::LESSON_WAIVED, $user, $made['lessons'][0] ) );
		$this->assertTrue( $this->adapter->is_waived( $user, $made['lessons'][0] ) );
		$this->assertSame( Apply_Outcome::SKIPPED, $this->adapter->apply( $this->result( Result::LESSON_WAIVED, $user, $made['lessons'][0] ) )->status, 'same waiver twice' );
		$this->assertSame( Apply_Outcome::APPLIED, $this->adapter->apply( $this->result( Result::LESSON_COMPLETED, $user, $made['lessons'][0] ) )->status );
		$this->assertFalse( $this->adapter->is_waived( $user, $made['lessons'][0] ) );
		$this->assertTrue( llms_is_complete( $user, $made['lessons'][0], 'lesson' ) );
	}

	public function test_apply_needs_enrolment_and_a_lifterlms_object() {
		$made = $this->make_course( 2 );
		$user = self::factory()->user->create();
		$this->assertSame( Apply_Outcome::DEFERRED, $this->adapter->apply( $this->result( Result::LESSON_COMPLETED, $user, $made['lessons'][0] ) )->status );
		$this->assertFalse( llms_is_complete( $user, $made['lessons'][0], 'lesson' ) );

		$this->adapter->enrol( $user, $made['course'] );
		$this->assertSame( Apply_Outcome::FAILED, $this->adapter->apply( $this->result( Result::LESSON_COMPLETED, $user, $made['course'] ) )->status );
		$this->assertSame( Apply_Outcome::FAILED, $this->adapter->apply( $this->result( Result::LESSON_COMPLETED, 0, $made['lessons'][0] ) )->status );

		// A block is satisfied only once every lesson is complete.
		$block = new Result( Result::BLOCK_SATISFIED, $user, new Lms_Object( 'course', $made['course'] ), self::REGISTRATION, self::STATEMENT );
		$this->assertSame( Apply_Outcome::SKIPPED, $this->adapter->apply( $block )->status );
		$this->assertFalse( $this->adapter->progress( $user, $made['course'] )->course_completed );
		foreach ( $made['lessons'] as $lesson ) {
			$this->adapter->apply( $this->result( Result::LESSON_COMPLETED, $user, $lesson ) );
		}
		$this->assertTrue( $this->adapter->progress( $user, $made['course'] )->course_completed );
		$this->assertSame( Apply_Outcome::SKIPPED, $this->adapter->apply( $block )->status );
	}

	public function test_apply_course_satisfied_marks_the_program_once() {
		$user = self::factory()->user->create();
		$r    = new Result( Result::COURSE_SATISFIED, $user, new Lms_Object( 'program', 42 ), self::REGISTRATION, self::STATEMENT );
		$this->assertSame( Apply_Outcome::APPLIED, $this->adapter->apply( $r )->status );
		$this->assertNotSame( '', get_user_meta( $user, '_lledger_program_satisfied_42', true ) );
		$this->assertSame( Apply_Outcome::SKIPPED, $this->adapter->apply( $r )->status );
	}

	// phpcs:enable
}
