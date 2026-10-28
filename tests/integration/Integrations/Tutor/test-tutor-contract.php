<?php
/**
 * Runs the adapter contract against Tutor LMS.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Lms\Activity_Ref;
use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Integration_Descriptor;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\LMS_Adapter;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Db\Installer;
use LearningLedger\Integrations\Tutor\Tutor_Adapter;
use LearningLedger\Integrations\Tutor\Tutor_Iris;
use LearningLedger\Tests\Adapter_Contract_Test_Case;
use LearningLedger\Tests\Recording_Sink;

// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The tests fire Tutor's hooks and read the plugin's filters.

/**
 * Tutor LMS contract.
 *
 * @group tutor-contract
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Tutor_Contract_Test extends Adapter_Contract_Test_Case {

	/**
	 * Load Tutor LMS as WordPress would for an active plugin. The integration test
	 * bootstrap loads only Sensei, and `init` has already run, so Tutor's post types
	 * are registered by hand. Its quiz tables are created once.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		if ( ! function_exists( 'tutor' ) ) {
			require_once WP_PLUGIN_DIR . '/tutor/tutor.php';
		}
		$types = new \TUTOR\Post_types();
		$types->register_course_post_types();
		$types->register_lesson_post_types();
		$types->register_quiz_post_types();
		$types->register_topic_post_types();
		$types->register_tutor_enrolled_post_types();
		global $wpdb;
		$attempts = $wpdb->prefix . 'tutor_quiz_attempts';
		// Tutor's dbDelta run fails on tables it already created, so it runs once per database.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $attempts ) ) !== $attempts ) {
			\TUTOR\Tutor::create_database();
		}
	}

	public function set_up(): void {
		Installer::install();
		parent::set_up();
		Tutor_Adapter::boot();
	}

	protected function make_adapter(): LMS_Adapter {
		return new Tutor_Adapter();
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
		return Tutor_Iris::lesson( $course_id, $lesson_id );
	}

	public function test_descriptor_is_available_for_the_installed_tutor() {
		$d = require LEARNING_LEDGER_DIR . 'integrations/tutor/bootstrap.php'; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant
		$this->assertInstanceOf( Integration_Descriptor::class, $d );
		$this->assertSame( 'tutor', $d->id );
		$this->assertSame( '3.0', $d->min_version );
		$this->assertSame( '3.9', $d->tested_up_to );
		$this->assertTrue( call_user_func( $d->is_available ) );
		$this->assertSame( TUTOR_VERSION, call_user_func( $d->version ) );
		$this->assertSame( 'tutor', $this->adapter->id() );
		$this->assertSame( TUTOR_VERSION, $this->adapter->lms_version() );
	}

	public function test_post_types_are_registered_through_the_connector_filters() {
		$this->assertContains( 'courses', apply_filters( 'learning_ledger/course_post_types', array() ) );
		$this->assertContains( 'lesson', apply_filters( 'learning_ledger/lesson_post_types', array() ) );
		$this->assertSame( array( 'courses', 'lesson' ), array_values( array_intersect( array( 'courses', 'lesson' ), apply_filters( 'learning_ledger/stable_key_post_types', array() ) ) ) );
		$map = apply_filters( 'learning_ledger/adapter_post_types', array() );
		$this->assertSame( array( 'courses', 'lesson', 'tutor_quiz' ), $map['tutor'] );
		$status = apply_filters( 'learning_ledger/status', array(), false );
		$this->assertSame( TUTOR_VERSION, $status['connector']['lms']['tutor'] );
	}

	public function test_upserted_lessons_sit_in_a_topic_of_their_course() {
		$made  = $this->make_course( 2 );
		$topic = (int) wp_get_post_parent_id( $made['lessons'][0] );
		$this->assertSame( 'courses', get_post_type( $made['course'] ) );
		$this->assertSame( 'lesson', get_post_type( $made['lessons'][0] ) );
		$this->assertSame( 'topics', get_post_type( $topic ) );
		$this->assertSame( $made['course'], (int) wp_get_post_parent_id( $topic ) );
		$this->assertSame( $topic, (int) wp_get_post_parent_id( $made['lessons'][1] ) );
		$this->assertSame( (int) $made['course'], (int) tutor_utils()->get_course_id_by_lesson( $made['lessons'][1] ) );
	}

	public function test_lessons_in_tutor_topics_follow_topic_then_lesson_order() {
		$course = self::factory()->post->create( array( 'post_type' => 'courses' ) );
		$second = self::factory()->post->create(
			array(
				'post_type'   => 'topics',
				'post_parent' => $course,
				'menu_order'  => 2,
			)
		);
		$first  = self::factory()->post->create(
			array(
				'post_type'   => 'topics',
				'post_parent' => $course,
				'menu_order'  => 1,
			)
		);
		$c      = self::factory()->post->create(
			array(
				'post_type'   => 'lesson',
				'post_parent' => $second,
				'menu_order'  => 1,
			)
		);
		$b      = self::factory()->post->create(
			array(
				'post_type'   => 'lesson',
				'post_parent' => $first,
				'menu_order'  => 2,
			)
		);
		$a      = self::factory()->post->create(
			array(
				'post_type'   => 'lesson',
				'post_parent' => $first,
				'menu_order'  => 1,
			)
		);
		$tree   = $this->adapter->course_tree( $course );
		$this->assertSame( array( $a, $b, $c ), array_map( static fn( $n ) => $n->id, $tree->lessons ) );
		$this->assertSame( array( 1, 2, 3 ), array_map( static fn( $n ) => $n->order, $tree->lessons ) );
	}

	public function test_block_satisfied_completes_the_course_and_emits() {
		$sink = new Recording_Sink();
		$this->adapter->register_event_listeners( $sink );
		$made = $this->make_course( 1 );
		$user = self::factory()->user->create();
		$this->adapter->enrol( $user, $made['course'] );
		$r     = new Result( Result::BLOCK_SATISFIED, $user, new Lms_Object( 'course', $made['course'] ), self::REGISTRATION, self::STATEMENT );
		$first = $this->adapter->apply( $r );
		$this->assertSame( Apply_Outcome::APPLIED, $first->status );
		$this->assertTrue( $this->adapter->progress( $user, $made['course'] )->course_completed );
		$this->assertSame( Apply_Outcome::SKIPPED, $this->adapter->apply( $r )->status );
		$types = array_map( static fn( $e ) => $e->type, $sink->events );
		$this->assertContains( Neutral_Event::ENROLLED, $types );
		$this->assertSame( 1, count( array_keys( $types, Neutral_Event::COURSE_COMPLETED, true ) ) );
	}

	public function test_apply_defers_for_a_learner_not_enrolled() {
		$made    = $this->make_course( 1 );
		$user    = self::factory()->user->create();
		$outcome = $this->adapter->apply( $this->result( Result::LESSON_COMPLETED, $user, $made['lessons'][0] ) );
		$this->assertSame( Apply_Outcome::DEFERRED, $outcome->status );
		$this->assertSame( array(), $this->adapter->progress( $user, $made['course'] )->completed_lesson_ids );
	}

	public function test_frontend_lesson_completion_emits_one_event() {
		// Tutor's own callback redirects to the next lesson and exits.
		remove_all_actions( 'tutor_lesson_completed_after', 999 );
		$sink = new Recording_Sink();
		$this->adapter->register_event_listeners( $sink );
		$made = $this->make_course( 1 );
		$user = self::factory()->user->create();
		// Tutor's "Mark as complete" form fires the model's hook, then its own.
		do_action( 'tutor_mark_lesson_complete_after', $made['lessons'][0], $user );
		do_action( 'tutor_lesson_completed_after', $made['lessons'][0], $user );
		$this->assertCount( 1, $sink->events );
		$this->assertSame( Neutral_Event::LESSON_COMPLETED, $sink->events[0]->type );
		$this->assertSame( $made['course'], $sink->events[0]->course_id );
		$this->assertSame( 'tutor', $sink->events[0]->lms );
	}

	public function test_quiz_attempt_ended_emits_submitted_and_graded() {
		global $wpdb;
		$sink = new Recording_Sink();
		$this->adapter->register_event_listeners( $sink );
		$made  = $this->make_course( 1 );
		$topic = (int) wp_get_post_parent_id( $made['lessons'][0] );
		$quiz  = self::factory()->post->create(
			array(
				'post_type'   => 'tutor_quiz',
				'post_parent' => $topic,
			)
		);
		$user  = self::factory()->user->create();
		$wpdb->insert(
			$wpdb->prefix . 'tutor_quiz_attempts',
			array(
				'course_id'      => $made['course'],
				'quiz_id'        => $quiz,
				'user_id'        => $user,
				'total_marks'    => 4,
				'earned_marks'   => 3,
				'attempt_status' => 'attempt_ended',
				'result'         => 'pass',
			)
		);
		do_action( 'tutor_quiz/attempt_ended', $wpdb->insert_id, $made['course'], $user );

		$this->assertSame( array( Neutral_Event::QUIZ_SUBMITTED, Neutral_Event::QUIZ_GRADED ), array_map( static fn( $e ) => $e->type, $sink->events ) );
		$graded = $sink->events[1];
		$this->assertSame( $quiz, $graded->quiz_id );
		$this->assertNull( $graded->lesson_id );
		$this->assertSame( $made['course'], $graded->course_id );
		$this->assertSame( 0.75, $graded->score_scaled );
		$this->assertTrue( $graded->success );
	}

	public function test_quiz_waiting_for_review_emits_submitted_only() {
		global $wpdb;
		$sink = new Recording_Sink();
		$this->adapter->register_event_listeners( $sink );
		$made = $this->make_course( 1 );
		$quiz = self::factory()->post->create(
			array(
				'post_type'   => 'tutor_quiz',
				'post_parent' => (int) wp_get_post_parent_id( $made['lessons'][0] ),
			)
		);
		$user = self::factory()->user->create();
		$wpdb->insert(
			$wpdb->prefix . 'tutor_quiz_attempts',
			array(
				'course_id'      => $made['course'],
				'quiz_id'        => $quiz,
				'user_id'        => $user,
				'total_marks'    => 4,
				'earned_marks'   => 0,
				'attempt_status' => 'review_required',
				'result'         => 'pending',
			)
		);
		do_action( 'tutor_quiz/attempt_ended', $wpdb->insert_id, $made['course'], $user );

		$this->assertCount( 1, $sink->events );
		$this->assertSame( Neutral_Event::QUIZ_SUBMITTED, $sink->events[0]->type );
		$this->assertNull( $sink->events[0]->score_scaled );
		$this->assertNull( $sink->events[0]->success );
	}

	public function test_direction2_iris_for_courses_lessons_and_quizzes() {
		$made   = $this->make_course( 1 );
		$course = $made['course'];
		$lesson = $made['lessons'][0];
		$user   = self::factory()->user->create();
		$base   = home_url( '/activities/courses/' . $course );

		$iris = apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::LESSON_COMPLETED, 'tutor', $user, $course, $lesson ) );
		$this->assertSame(
			array(
				'object' => $base . '/lessons/' . $lesson,
				'parent' => $base,
			),
			$iris
		);
		$iris = apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::QUIZ_GRADED, 'tutor', $user, $course, null, 99 ) );
		$this->assertSame( $base . '/quizzes/99', $iris['object'] );
		$iris = apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::COURSE_COMPLETED, 'tutor', $user, $course ) );
		$this->assertSame( $base, $iris['object'] );
		$this->assertSame( 'kept', apply_filters( 'learning_ledger/direction2_iris', 'kept', new Neutral_Event( Neutral_Event::COURSE_COMPLETED, 'sensei', $user, $course ) ) );
	}

	public function test_resolve_course_and_quiz_iris() {
		$made  = $this->make_course( 1 );
		$quiz  = self::factory()->post->create(
			array(
				'post_type'   => 'tutor_quiz',
				'post_parent' => (int) wp_get_post_parent_id( $made['lessons'][0] ),
			)
		);
		$found = $this->adapter->resolve( new Activity_Ref( Tutor_Iris::course( $made['course'] ) ) );
		$this->assertSame( array( 'course', $made['course'] ), array( $found->kind, $found->id ) );
		$found = $this->adapter->resolve( new Activity_Ref( Tutor_Iris::quiz( $made['course'], $quiz ) ) );
		$this->assertSame( array( 'quiz', $quiz ), array( $found->kind, $found->id ) );
		$other = $this->make_course( 1 );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( Tutor_Iris::lesson( $other['course'], $made['lessons'][0] ) ) ), 'a lesson under another course IRI' );
	}
}
