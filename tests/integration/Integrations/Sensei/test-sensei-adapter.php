<?php
/**
 * Sensei integration and adapter read side.
 *
 * @package LearningLedger
 */

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Inbox\Inbox_Processor;
use LearningLedger\Connector\Inbox\Inbox_Repository;
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
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
use LearningLedger\Integrations\Sensei\Sensei_Integration;
use LearningLedger\Integrations\Sensei\Sensei_Iris;
use LearningLedger\Player\Import\Player_Importer;
use LearningLedger\Util\Uuid;

/**
 * Sensei adapter tests.
 *
 * @group sensei-contract
 * @requires function Sensei
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

	public function test_same_lesson_key_in_two_courses_keeps_separate_lessons() {
		$a  = $this->adapter->upsert_course( new Course_Spec( 'course-a', 'A', '', 'publish', array() ) );
		$b  = $this->adapter->upsert_course( new Course_Spec( 'course-b', 'B', '', 'publish', array() ) );
		$la = $this->adapter->upsert_lesson( $a, new Lesson_Spec( 'intro', 'Intro A', '', 1, 'publish', array() ) );
		$this->backdate( $la );
		$order_a  = get_post_meta( $a, '_lesson_order', true );
		$before_a = $this->modified( $la );

		$lb = $this->adapter->upsert_lesson( $b, new Lesson_Spec( 'intro', 'Intro B', '', 1, 'publish', array() ) );
		$this->assertGreaterThan( 0, $lb );
		$this->assertNotSame( $la, $lb );
		$this->assertSame( (string) $a, (string) get_post_meta( $la, '_lesson_course', true ) );
		$this->assertSame( (string) $b, (string) get_post_meta( $lb, '_lesson_course', true ) );
		$this->assertSame( 'Intro A', get_post( $la )->post_title );
		$this->assertSame( $before_a, $this->modified( $la ) );
		$this->assertSame( (string) $la, $order_a );
		$this->assertSame( $order_a, get_post_meta( $a, '_lesson_order', true ), 'first course order untouched' );
		$this->assertSame( (string) $lb, get_post_meta( $b, '_lesson_order', true ) );
		$this->assertSame( $lb, $this->adapter->upsert_lesson( $b, new Lesson_Spec( 'intro', 'Intro B', '', 1, 'publish', array() ) ) );
	}

	public function test_resolve_does_not_fix_the_activity_base() {
		$course = $this->course();
		$lesson = $this->lesson( $course, 1 );
		$this->assertEquals( new Lms_Object( 'course', $course ), $this->adapter->resolve( new Activity_Ref( home_url( '/activities/courses/' . $course ) ) ) );
		$this->assertEquals( new Lms_Object( 'lesson', $lesson ), $this->adapter->resolve( new Activity_Ref( home_url( '/activities/courses/' . $course . '/lessons/' . $lesson ) ) ) );
		$this->assertFalse( metadata_exists( 'post', $course, '_lledger_activity_base' ), 'resolve() writes nothing' );
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
				'post_status' => 'draft',
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

	public function test_program_lesson_key_on_two_courses_resolves_to_neither() {
		$one = $this->adapter->upsert_course( new Course_Spec( 'one', 'One', '', 'publish', array() ) );
		$two = $this->adapter->upsert_course( new Course_Spec( 'two', 'Two', '', 'publish', array() ) );
		$this->adapter->upsert_lesson( $one, new Lesson_Spec( 'intro', 'Intro', '', 1, 'publish', array() ) );
		$this->adapter->upsert_lesson( $two, new Lesson_Spec( 'intro', 'Intro', '', 1, 'publish', array() ) );
		$only = $this->adapter->upsert_lesson( $two, new Lesson_Spec( 'only-two', 'Only two', '', 2, 'publish', array() ) );
		$this->program( array( $one, $two ) );

		$this->assertNull( $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/lessons/intro' ) ) );
		$this->assertEquals( new Lms_Object( 'lesson', $only ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/lessons/only-two' ) ) );
	}

	public function test_program_path_key_resolves_within_the_program_courses() {
		$outside = $this->course( 'Outside' );
		$inside  = $this->course( 'Inside' );
		update_post_meta( $outside, '_lledger_stable_key', 'shared' );
		update_post_meta( $inside, '_lledger_stable_key', 'shared' );
		$this->program( array( $inside ) );

		$this->assertEquals( new Lms_Object( 'course', $inside ), $this->adapter->resolve( new Activity_Ref( 'https://example.test/activities/paths/shared' ) ) );
	}

	/**
	 * Import tests/fixtures/cmi5/nested-blocks.xml through the player.
	 *
	 * @return array{program:int,a:int,b:int,a1:int,b1:int,b2:int}
	 */
	private function import_nested(): array {
		( new Program_Module() )->register_meta();
		$xml  = tempnam( sys_get_temp_dir(), 'llxml' );
		$copy = copy( LEARNING_LEDGER_DIR . 'tests/fixtures/cmi5/nested-blocks.xml', $xml );
		$this->assertTrue( $copy );
		$report = ( new Player_Importer( $this->adapter ) )->import( $xml );
		wp_delete_file( $xml );
		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );
		$courses = ( new Program_Repository() )->get( $report->program_id )->course_ids;
		$lessons = array();
		foreach ( $courses as $course ) {
			foreach ( $this->adapter->course_tree( $course )->lessons as $node ) {
				$lessons[ (string) get_post_meta( $node->id, Player_Importer::AU_META, true ) ] = $node->id;
			}
		}
		return array(
			'program' => $report->program_id,
			'a'       => $courses[0],
			'b'       => $courses[1],
			'a1'      => $lessons['https://example.org/courses/nested/aus/a1'],
			'b1'      => $lessons['https://example.org/courses/nested/aus/b1'],
			'b2'      => $lessons['https://example.org/courses/nested/aus/b2'],
		);
	}

	public function test_resolve_player_au_ids_to_lessons_and_top_level_blocks_to_courses() {
		$ids = $this->import_nested();
		$au  = 'https://example.org/courses/nested/aus/';

		$this->assertEquals( new Lms_Object( 'lesson', $ids['a1'] ), $this->adapter->resolve( new Activity_Ref( $au . 'a1' ) ) );
		$this->assertEquals( new Lms_Object( 'lesson', $ids['a1'] ), $this->adapter->resolve( new Activity_Ref( Au::activity_id( $au . 'a1' ) ) ) );
		$this->assertEquals( new Lms_Object( 'lesson', $ids['b1'] ), $this->adapter->resolve( new Activity_Ref( $au . 'b1' ) ) );
		$this->assertEquals( new Lms_Object( 'lesson', $ids['b2'] ), $this->adapter->resolve( new Activity_Ref( Au::activity_id( $au . 'b2' ) ) ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( $au . 'a1/other' ) ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( $au . 'a' ) ) );

		$this->assertEquals( new Lms_Object( 'course', $ids['a'] ), $this->adapter->resolve( new Activity_Ref( 'https://example.org/courses/nested/blocks/a' ) ) );
		$this->assertEquals( new Lms_Object( 'course', $ids['b'] ), $this->adapter->resolve( new Activity_Ref( 'https://example.org/courses/nested/blocks/b' ) ) );
		// A nested block is flattened into its top-level course; it is not that course.
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( 'https://example.org/courses/nested/blocks/b1' ) ) );

		// A lesson that left the program's courses no longer resolves.
		update_post_meta( $ids['program'], '_lledger_courses', array( $ids['b'] ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( $au . 'a1' ) ) );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( 'https://example.org/courses/nested/blocks/a' ) ) );
	}

	public function test_inbox_completes_a_plain_player_lesson_and_its_block_course() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Tables::name( 'conn_inbox' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids  = $this->import_nested();
		$user = self::factory()->user->create();
		$this->assertTrue( $this->adapter->enrol( $user, $ids['a'] ) );
		$au = 'https://example.org/courses/nested/aus/a1';
		$st = array(
			'id'      => Uuid::v4(),
			'actor'   => ( new Identity_Map() )->actor_for_user( $user ),
			'verb'    => array( 'id' => Vocab::COMPLETED ),
			'object'  => array(
				'objectType' => 'Activity',
				'id'         => Au::activity_id( $au ),
			),
			'result'  => array( 'completion' => true ),
			'context' => array(
				'registration'      => Uuid::v4(),
				'contextActivities' => array(
					'category' => array( array( 'id' => Vocab::CAT_CMI5 ) ),
					'grouping' => array( array( 'id' => $au ) ),
				),
			),
			'stored'  => '2026-10-27T10:00:00.000000Z',
		);
		$this->assertTrue( ( new Inbox_Repository() )->add( $st, 'webhook' ) );

		$adapter = $this->adapter;
		$this->assertSame( 1, ( new Inbox_Processor( null, null, null, null, static fn() => $adapter ) )->process_pending() );

		$this->assertTrue( (bool) \Sensei_Utils::user_completed_lesson( $ids['a1'], $user ), 'the moveOn Completed AU completes its Sensei lesson' );
		$this->assertTrue( (bool) \Sensei_Utils::user_completed_course( $ids['a'], $user ), 'the satisfied block completes its course' );
		$this->assertFalse( (bool) \Sensei_Utils::user_completed_course( $ids['b'], $user ) );
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
