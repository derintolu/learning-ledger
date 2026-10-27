<?php
/**
 * Sensei integration and adapter read side.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Activity_Ref;
use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Integration_Descriptor;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Db\Installer;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
use LearningLedger\Integrations\Sensei\Sensei_Integration;
use LearningLedger\Integrations\Sensei\Sensei_Iris;
use LearningLedger\Util\Uuid;

/**
 * Sensei adapter tests.
 *
 * @group sensei-contract
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Sensei_Adapter_Test extends WP_UnitTestCase {

	const BOOTSTRAP = LEARNING_LEDGER_DIR . 'integrations/sensei/bootstrap.php';

	private Sensei_Adapter $adapter;

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		$module = new Program_Module();
		$module->register_post_type();
		$this->adapter = new Sensei_Adapter();
	}

	private function course( string $title = 'Course' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'course',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	private function lesson( int $course, int $order, string $title = 'Lesson' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'lesson',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		update_post_meta( $id, '_lesson_course', $course );
		update_post_meta( $id, '_order_' . $course, $order );
		return $id;
	}

	private function modified( int $id ): string {
		clean_post_cache( $id );
		return get_post( $id )->post_modified_gmt;
	}

	private function backdate( int $id ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->posts,
			array(
				'post_modified'     => '2001-01-01 00:00:00',
				'post_modified_gmt' => '2001-01-01 00:00:00',
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
	}

	public function test_descriptor_available_with_sensei() {
		$d = require self::BOOTSTRAP;
		$this->assertInstanceOf( Integration_Descriptor::class, $d );
		$this->assertSame( 'sensei', $d->id );
		$this->assertSame( '4.20.1', $d->min_version );
		$this->assertSame( '4.26', $d->tested_up_to );
		$this->assertTrue( call_user_func( $d->is_available ) );
		$this->assertSame( Sensei()->version, call_user_func( $d->version ) );
		$adapter = call_user_func( $d->factory );
		$this->assertInstanceOf( Sensei_Adapter::class, $adapter );
		$this->assertSame( 'sensei', $adapter->id() );
		$this->assertSame( Sensei()->version, $adapter->lms_version() );
		$this->assertEqualsCanonicalizing(
			array( 'emit_events', 'apply_completion', 'apply_score', 'mark_waived', 'enrol', 'export_course', 'import_course' ),
			$adapter->capabilities()
		);
		$this->assertNull( $adapter->certificate_pdf( 1, 1 ) );
		if ( ! class_exists( 'LearningLedger\Integrations\Sensei\Sensei_Applier' ) ) {
			$r = new Result( Result::LESSON_COMPLETED, 1, new Lms_Object( 'lesson', 1 ), Uuid::v4(), Uuid::v4() );
			$this->assertSame( Apply_Outcome::DEFERRED, $adapter->apply( $r )->status );
		}
	}

	public function test_boot_registers_post_types_and_status() {
		Sensei_Integration::boot();
		// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Slash-namespaced hooks.
		$this->assertEqualsCanonicalizing( array( 'page', 'course', 'lesson' ), apply_filters( 'learning_ledger/stable_key_post_types', array( 'page' ) ) );
		$this->assertSame( array( 'lesson' ), apply_filters( 'learning_ledger/lesson_post_types', array() ) );
		$this->assertSame( array( 'course' ), apply_filters( 'learning_ledger/course_post_types', array() ) );
		$this->assertSame(
			array(
				'fake'   => array( 'x' ),
				'sensei' => array( 'course', 'lesson', 'quiz' ),
			),
			apply_filters( 'learning_ledger/adapter_post_types', array( 'fake' => array( 'x' ) ) )
		);
		$status = apply_filters( 'learning_ledger/status', array( 'connector' => array( 'keep' => 1 ) ), false );
		// phpcs:enable
		$this->assertSame( 1, $status['connector']['keep'] );
		$this->assertSame( Sensei()->version, $status['connector']['lms']['sensei'] );
		$expected = defined( 'SENSEI_CERTIFICATES_VERSION' ) ? SENSEI_CERTIFICATES_VERSION : null;
		$this->assertSame( $expected, $status['connector']['lms']['sensei_certificates'] );

		Sensei_Integration::boot();
		$this->assertSame( array( 'lesson' ), apply_filters( 'learning_ledger/lesson_post_types', array() ), 'boot is idempotent' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	public function test_course_tree_order() {
		$course = $this->course( 'Ordered' );
		update_post_meta( $course, '_lledger_stable_key', 'ordered' );
		// Created in one order, ordered by Sensei's lesson order meta in another.
		$third  = $this->lesson( $course, 3, 'Third' );
		$first  = $this->lesson( $course, 1, 'First' );
		$second = $this->lesson( $course, 2, 'Second' );
		update_post_meta( $first, '_lledger_stable_key', 'first' );
		$other = $this->course( 'Other' );
		$this->lesson( $other, 1, 'Elsewhere' );

		$tree = $this->adapter->course_tree( $course );
		$this->assertSame( $course, $tree->course_id );
		$this->assertSame( 'Ordered', $tree->title );
		$this->assertSame( 'ordered', $tree->stable_key );
		$this->assertSame( array( $first, $second, $third ), array_map( static fn( $n ) => $n->id, $tree->lessons ) );
		$this->assertSame( array( 1, 2, 3 ), array_map( static fn( $n ) => $n->order, $tree->lessons ) );
		$this->assertSame( array( 'First', 'Second', 'Third' ), array_map( static fn( $n ) => $n->title, $tree->lessons ) );
		$this->assertSame( 'first', $tree->lessons[0]->stable_key );
		$this->assertNull( $tree->lessons[1]->stable_key );
		$this->assertFalse( $tree->lessons[0]->has_pass_required_quiz );
	}

	public function test_upsert_idempotent_by_stable_key() {
		$spec   = new Course_Spec( 'block-theme', 'Block themes', '<p>Intro</p>', 'publish', array( '_lledger_preview_kind' => 'theme' ) );
		$course = $this->adapter->upsert_course( $spec );
		$this->assertGreaterThan( 0, $course );
		$this->assertSame( 'course', get_post_type( $course ) );
		$this->assertSame( 'block-theme', get_post_meta( $course, '_lledger_stable_key', true ) );
		$this->assertSame( 'theme', get_post_meta( $course, '_lledger_preview_kind', true ) );

		$l2 = $this->adapter->upsert_lesson( $course, new Lesson_Spec( 'bt-two', 'Two', '<p>2</p>', 2, 'publish', array() ) );
		$l1 = $this->adapter->upsert_lesson( $course, new Lesson_Spec( 'bt-one', 'One', '<p>1</p>', 1, 'publish', array( '_lledger_starter' => 'a' ) ) );
		$this->assertSame( (string) $course, (string) get_post_meta( $l1, '_lesson_course', true ) );
		$this->assertSame( '1', (string) get_post_meta( $l1, '_order_' . $course, true ) );
		$this->assertSame( $l1 . ',' . $l2, get_post_meta( $course, '_lesson_order', true ) );
		$this->assertSame( array( $l1, $l2 ), array_map( 'intval', Sensei()->course->course_lessons( $course, 'any', 'ids' ) ) );

		$this->backdate( $course );
		$this->backdate( $l1 );
		$before_course = $this->modified( $course );
		$before_lesson = $this->modified( $l1 );
		$this->assertSame( $course, $this->adapter->upsert_course( $spec ) );
		$this->assertSame( $l1, $this->adapter->upsert_lesson( $course, new Lesson_Spec( 'bt-one', 'One', '<p>1</p>', 1, 'publish', array( '_lledger_starter' => 'a' ) ) ) );
		$this->assertSame( $before_course, $this->modified( $course ), 'unchanged course is not rewritten' );
		$this->assertSame( $before_lesson, $this->modified( $l1 ), 'unchanged lesson is not rewritten' );
		$this->assertCount( 1, get_posts( array( 'post_type' => 'course', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertCount( 2, get_posts( array( 'post_type' => 'lesson', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// A change is written, and a new order moves the lesson.
		$this->assertSame( $course, $this->adapter->upsert_course( new Course_Spec( 'block-theme', 'Block themes, renamed', '<p>Intro</p>', 'publish', array( '_lledger_preview_kind' => 'theme' ) ) ) );
		$this->assertSame( 'Block themes, renamed', get_post( $course )->post_title );
		$this->assertNotSame( $before_course, $this->modified( $course ) );
		$this->assertSame( $l1, $this->adapter->upsert_lesson( $course, new Lesson_Spec( 'bt-one', 'One', '<p>1</p>', 3, 'publish', array( '_lledger_starter' => 'b' ) ) ) );
		$this->assertSame( 'b', get_post_meta( $l1, '_lledger_starter', true ) );
		$this->assertSame( $l2 . ',' . $l1, get_post_meta( $course, '_lesson_order', true ) );
		$this->assertSame( array( $l2, $l1 ), array_map( static fn( $n ) => $n->id, $this->adapter->course_tree( $course )->lessons ) );
	}

	public function test_resolve_program_and_direction2_iris() {
		$course = $this->course();
		$lesson = $this->lesson( $course, 1 );
		update_post_meta( $course, '_lledger_stable_key', 'block-theme' );
		update_post_meta( $lesson, '_lledger_stable_key', 'bt-one' );
		$program = self::factory()->post->create(
			array(
				'post_type'   => 'lledger_program',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $program, '_lledger_stable_key', 'learn-wp' );
		update_post_meta( $program, '_lledger_iri_base', 'https://example.test/activities/' );
		update_post_meta( $program, '_lledger_courses', array( $course ) );

		// Program IRIs.
		$this->assertEquals( new Lms_Object( 'lesson', $lesson ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/lessons/bt-one' ) ) );
		$this->assertEquals( new Lms_Object( 'lesson', $lesson ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/lessons/bt-one/au' ) ) );
		$this->assertEquals( new Lms_Object( 'course', $course ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/paths/block-theme' ) ) );
		$this->assertEquals( new Lms_Object( 'program', $program ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities' ) ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/lessons/unknown' ) ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/placement' ) ) );

		// Direction-2 IRIs.
		$solo   = $this->course();
		$solo_l = $this->lesson( $solo, 1 );
		$quiz   = self::factory()->post->create(
			array(
				'post_type'   => 'quiz',
				'post_status' => 'publish',
				'post_parent' => $solo_l,
			)
		);
		update_post_meta( $solo_l, '_lesson_quiz', $quiz );
		$this->assertSame( home_url( '/activities/courses/' . $solo ), Sensei_Iris::course( $solo ) );
		$this->assertSame( Sensei_Iris::course( $solo ) . '/lessons/' . $solo_l, Sensei_Iris::lesson( $solo, $solo_l ) );
		$this->assertSame( Sensei_Iris::lesson( $solo, $solo_l ) . '/quiz', Sensei_Iris::quiz( $solo, $solo_l ) );
		$this->assertEquals( new Lms_Object( 'course', $solo ), $this->adapter->resolve( new Activity_Ref( Sensei_Iris::course( $solo ) ) ) );
		$this->assertEquals( new Lms_Object( 'lesson', $solo_l ), $this->adapter->resolve( new Activity_Ref( Sensei_Iris::lesson( $solo, $solo_l ) ) ) );
		$this->assertEquals( new Lms_Object( 'quiz', $quiz ), $this->adapter->resolve( new Activity_Ref( Sensei_Iris::quiz( $solo, $solo_l ) ) ) );
		// A lesson IRI under the wrong course base is not ours.
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( Sensei_Iris::course( $solo ) . '/lessons/' . $lesson ) ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( 'https://example.org/not-ours/1' ) ) );
	}

	public function test_direction2_iri_filter() {
		Sensei_Integration::boot();
		$course = $this->course();
		$lesson = $this->lesson( $course, 1 );
		$user   = self::factory()->user->create();
		// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Slash-namespaced hook.
		$this->assertSame(
			array(
				'object' => Sensei_Iris::lesson( $course, $lesson ),
				'parent' => Sensei_Iris::course( $course ),
			),
			apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::LESSON_COMPLETED, 'sensei', $user, $course, $lesson ) )
		);
		$this->assertSame(
			array(
				'object' => Sensei_Iris::quiz( $course, $lesson ),
				'parent' => Sensei_Iris::course( $course ),
			),
			apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::QUIZ_GRADED, 'sensei', $user, $course, $lesson, 99, 0.8, true ) )
		);
		$this->assertSame(
			array(
				'object' => Sensei_Iris::course( $course ),
				'parent' => Sensei_Iris::course( $course ),
			),
			apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::COURSE_COMPLETED, 'sensei', $user, $course ) )
		);
		$this->assertSame( 'kept', apply_filters( 'learning_ledger/direction2_iris', 'kept', new Neutral_Event( Neutral_Event::COURSE_COMPLETED, 'tutor', $user, $course ) ), 'other LMS events pass through' );

		// Courses in a program use Program_Iris instead.
		$program = self::factory()->post->create(
			array(
				'post_type'   => 'lledger_program',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $program, '_lledger_iri_base', 'https://example.test/activities/' );
		update_post_meta( $program, '_lledger_courses', array( $course ) );
		$this->assertNull( apply_filters( 'learning_ledger/direction2_iris', null, new Neutral_Event( Neutral_Event::LESSON_COMPLETED, 'sensei', $user, $course, $lesson ) ) );
		// phpcs:enable
	}

	public function test_activity_base_fixed_on_first_use() {
		$course = $this->course();
		$lesson = $this->lesson( $course, 1 );
		$first  = Sensei_Iris::course( $course );
		$this->assertSame( home_url( '/activities/courses/' . $course ), $first );
		$this->assertSame( $first, get_post_meta( $course, '_lledger_activity_base', true ) );

		$moved = static fn() => 'https://moved.example';
		add_filter( 'home_url', $moved );
		$this->assertSame( $first, Sensei_Iris::course( $course ), 'base unchanged after home_url changes' );
		$this->assertSame( $first . '/lessons/' . $lesson, Sensei_Iris::lesson( $course, $lesson ) );
		$this->assertEquals( new Lms_Object( 'lesson', $lesson ), $this->adapter->resolve( new Activity_Ref( Sensei_Iris::lesson( $course, $lesson ) ) ) );
		$later = $this->course();
		$this->assertSame( 'https://moved.example', Sensei_Iris::course( $later ) );
		remove_filter( 'home_url', $moved );
	}

	public function test_enrol_and_progress() {
		$course = $this->course();
		$l1     = $this->lesson( $course, 1 );
		$l2     = $this->lesson( $course, 2 );
		$user   = self::factory()->user->create();

		$this->assertFalse( Sensei_Course_Enrolment::get_course_instance( $course )->is_enrolled( $user, false ) );
		$this->assertTrue( $this->adapter->enrol( $user, $course ) );
		$this->assertTrue( Sensei_Course_Enrolment::get_course_instance( $course )->is_enrolled( $user, false ) );
		$this->assertTrue( $this->adapter->enrol( $user, $course ), 'enrolling twice is fine' );

		$empty = $this->adapter->progress( $user, $course );
		$this->assertSame( array(), $empty->completed_lesson_ids );
		$this->assertSame( array(), $empty->tested_out_lesson_ids );
		$this->assertFalse( $empty->course_completed );

		Sensei_Utils::sensei_start_lesson( $l1, $user, true );
		( new Waiver_Ledger() )->record( $user, $l2, Uuid::v4(), Uuid::v4(), 'Tested Out', false );
		$progress = $this->adapter->progress( $user, $course );
		$this->assertSame( array( $l1 ), $progress->completed_lesson_ids );
		$this->assertSame( array( $l2 ), $progress->tested_out_lesson_ids );
		$this->assertFalse( $progress->course_completed );
		$this->assertTrue( $this->adapter->is_waived( $user, $l2 ) );
		$this->assertFalse( $this->adapter->is_waived( $user, $l1 ) );

		Sensei_Utils::sensei_start_lesson( $l2, $user, true );
		$done = $this->adapter->progress( $user, $course );
		$this->assertSame( array( $l1, $l2 ), $done->completed_lesson_ids );
		$this->assertTrue( $done->course_completed );
	}
}
