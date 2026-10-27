<?php
/**
 * Contract tests every LMS adapter must pass.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Tests;

use LearningLedger\Connector\Lms\Activity_Ref;
use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\LMS_Adapter;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Lms\Result;

/**
 * Subclass per adapter; implement make_adapter() and make_course().
 */
abstract class Adapter_Contract_Test_Case extends \WP_UnitTestCase {

	const REGISTRATION = '11111111-1111-4111-8111-111111111111';
	const STATEMENT    = '22222222-2222-4222-8222-222222222222';

	/**
	 * Adapter under test.
	 *
	 * @var LMS_Adapter
	 */
	protected LMS_Adapter $adapter;

	/**
	 * Build the adapter under test.
	 *
	 * @return LMS_Adapter
	 */
	abstract protected function make_adapter(): LMS_Adapter;

	/**
	 * Create a course with the given number of lessons, in order.
	 *
	 * @param int $lessons Lesson count.
	 *
	 * @return array{course:int,lessons:int[]}
	 */
	abstract protected function make_course( int $lessons ): array;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->adapter = $this->make_adapter();
	}

	/**
	 * IRI of a lesson as this adapter's resolve() understands it. Adapters
	 * whose IRIs come from Program_Iris override this.
	 *
	 * @param int $course_id Course ID.
	 * @param int $lesson_id Lesson ID.
	 *
	 * @return string
	 */
	protected function lesson_iri( int $course_id, int $lesson_id ): string {
		unset( $course_id );
		return Fake_Adapter::IRI_PREFIX . $lesson_id;
	}

	/**
	 * Build a result for a lesson.
	 *
	 * @param string $type    Result type.
	 * @param int    $user_id User ID.
	 * @param int    $lesson  Lesson ID.
	 *
	 * @return Result
	 */
	protected function result( string $type, int $user_id, int $lesson ): Result {
		return new Result( $type, $user_id, new Lms_Object( 'lesson', $lesson ), self::REGISTRATION, self::STATEMENT, null, Result::LESSON_WAIVED === $type ? 'prior learning' : null );
	}

	public function test_course_tree_lists_lessons_in_order() {
		$made = $this->make_course( 3 );
		$tree = $this->adapter->course_tree( $made['course'] );
		$this->assertSame( $made['course'], $tree->course_id );
		$this->assertSame( $made['lessons'], array_map( static fn( $n ) => $n->id, $tree->lessons ) );
		$orders = array_map( static fn( $n ) => $n->order, $tree->lessons );
		$sorted = $orders;
		sort( $sorted );
		$this->assertSame( $sorted, $orders );
	}

	public function test_apply_lesson_completed_shows_in_progress() {
		$made = $this->make_course( 2 );
		$user = self::factory()->user->create();
		$this->adapter->enrol( $user, $made['course'] );
		$outcome = $this->adapter->apply( $this->result( Result::LESSON_COMPLETED, $user, $made['lessons'][0] ) );
		$this->assertSame( Apply_Outcome::APPLIED, $outcome->status );
		$progress = $this->adapter->progress( $user, $made['course'] );
		$this->assertSame( array( $made['lessons'][0] ), $progress->completed_lesson_ids );
		$this->assertFalse( $progress->course_completed );
	}

	public function test_apply_waived_completes_and_is_waived() {
		$made = $this->make_course( 2 );
		$user = self::factory()->user->create();
		$this->adapter->enrol( $user, $made['course'] );
		$this->adapter->apply( $this->result( Result::LESSON_WAIVED, $user, $made['lessons'][1] ) );
		$progress = $this->adapter->progress( $user, $made['course'] );
		$this->assertContains( $made['lessons'][1], $progress->completed_lesson_ids );
		$this->assertContains( $made['lessons'][1], $progress->tested_out_lesson_ids );
		$this->assertTrue( $this->adapter->is_waived( $user, $made['lessons'][1] ) );
		$this->assertFalse( $this->adapter->is_waived( $user, $made['lessons'][0] ) );
	}

	public function test_apply_is_idempotent() {
		$made = $this->make_course( 1 );
		$user = self::factory()->user->create();
		$this->adapter->enrol( $user, $made['course'] );
		$r = $this->result( Result::LESSON_COMPLETED, $user, $made['lessons'][0] );
		$this->adapter->apply( $r );
		$second = $this->adapter->apply( $r );
		$this->assertContains( $second->status, array( Apply_Outcome::APPLIED, Apply_Outcome::SKIPPED ) );
		$progress = $this->adapter->progress( $user, $made['course'] );
		$this->assertSame( array( $made['lessons'][0] ), $progress->completed_lesson_ids );
	}

	public function test_enrol_then_progress_empty() {
		$made = $this->make_course( 2 );
		$user = self::factory()->user->create();
		$this->assertTrue( $this->adapter->enrol( $user, $made['course'] ) );
		$progress = $this->adapter->progress( $user, $made['course'] );
		$this->assertSame( array(), $progress->completed_lesson_ids );
		$this->assertSame( array(), $progress->tested_out_lesson_ids );
		$this->assertFalse( $progress->course_completed );
	}

	public function test_upsert_course_and_lesson_idempotent_by_stable_key() {
		$course_spec = new Course_Spec( 'course-key', 'Title', '', 'publish', array() );
		$course      = $this->adapter->upsert_course( $course_spec );
		$this->assertSame( $course, $this->adapter->upsert_course( $course_spec ) );
		$lesson_spec = new Lesson_Spec( 'lesson-key', 'Lesson', '', 1, 'publish', array() );
		$lesson      = $this->adapter->upsert_lesson( $course, $lesson_spec );
		$this->assertSame( $lesson, $this->adapter->upsert_lesson( $course, $lesson_spec ) );
		$tree = $this->adapter->course_tree( $course );
		$this->assertSame( 'course-key', $tree->stable_key );
		$this->assertCount( 1, $tree->lessons );
		$this->assertSame( 'lesson-key', $tree->lessons[0]->stable_key );
	}

	public function test_resolve_lesson_iri_round_trip() {
		$made = $this->make_course( 2 );
		$obj  = $this->adapter->resolve( new Activity_Ref( $this->lesson_iri( $made['course'], $made['lessons'][1] ) ) );
		$this->assertNotNull( $obj );
		$this->assertSame( 'lesson', $obj->kind );
		$this->assertSame( $made['lessons'][1], $obj->id );
		$this->assertNull( $this->adapter->resolve( new Activity_Ref( 'https://example.org/not-ours/1' ) ) );
	}

	public function test_events_emitted_for_lesson_completion() {
		$sink = new Recording_Sink();
		$this->adapter->register_event_listeners( $sink );
		$made = $this->make_course( 1 );
		$user = self::factory()->user->create();
		$this->adapter->enrol( $user, $made['course'] );
		$this->adapter->apply( $this->result( Result::LESSON_COMPLETED, $user, $made['lessons'][0] ) );
		$types = array_map( static fn( $e ) => $e->type, $sink->events );
		$this->assertContains( Neutral_Event::LESSON_COMPLETED, $types );
		$event = $sink->events[ array_search( Neutral_Event::LESSON_COMPLETED, $types, true ) ];
		$this->assertSame( $user, $event->user_id );
		$this->assertSame( $made['course'], $event->course_id );
		$this->assertSame( $made['lessons'][0], $event->lesson_id );
		$this->assertSame( $this->adapter->id(), $event->lms );
	}
}
