<?php
/**
 * Inbox processor tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Block;
use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Cmi5\Satisfaction_Result;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Inbox\Inbox_Processor;
use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Guarded_Adapter;
use LearningLedger\Connector\Lms\Integration_State;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Tests\Fake_Adapter;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

class Inbox_Processor_Test extends WP_UnitTestCase {

	const BASE = 'https://example.org/ledger-test/';

	private Fake_Adapter $fake;
	private Inbox_Repository $repo;
	private Inbox_Processor $processor;
	private int $program;
	private int $course;
	private int $l1;
	private int $l2;
	private int $user;
	private array $actor;
	private string $registration;
	private int $seq = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'conn_inbox', 'applied', 'identity_links', 'waivers', 'registrations' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		delete_option( 'lledger_integration_state' );
		delete_option( 'lledger_connector' );
		$module = new Program_Module();
		$module->register_post_type();
		$module->register_meta();

		$this->fake   = new Fake_Adapter();
		$this->course = $this->fake->upsert_course( new Course_Spec( 'c1', 'Course', '', 'publish', array() ) );
		$this->l1     = $this->fake->upsert_lesson( $this->course, new Lesson_Spec( 'l1', 'One', '', 1, 'publish', array() ) );
		$this->l2     = $this->fake->upsert_lesson( $this->course, new Lesson_Spec( 'l2', 'Two', '', 2, 'publish', array() ) );
		$guarded      = new Guarded_Adapter( $this->fake );

		$this->program = self::factory()->post->create(
			array(
				'post_type'   => Program_Repository::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->program, '_lledger_stable_key', 'prog' );
		update_post_meta( $this->program, '_lledger_iri_base', self::BASE );
		update_post_meta( $this->program, '_lledger_courses', array( $this->course ) );
		update_post_meta( $this->program, '_lledger_path_keys', array( $this->course => 'p1' ) );
		$structure = new Course_Structure(
			self::BASE . 'course',
			array( 'en' => 'Program' ),
			array(),
			array(
				new Block(
					self::BASE . 'paths/p1',
					'p1',
					array(),
					array(),
					null,
					array(
						new Au(
							array(
								'id'              => $this->au( $this->l1 ),
								'move_on'         => 'CompletedAndPassed',
								'parent_block_id' => self::BASE . 'paths/p1',
							)
						),
						new Au(
							array(
								'id'              => $this->au( $this->l2 ),
								'move_on'         => 'Completed',
								'parent_block_id' => self::BASE . 'paths/p1',
							)
						),
					)
				),
			),
			''
		);
		( new Program_Repository() )->set_structure( $this->program, $structure );

		$this->user         = self::factory()->user->create();
		$this->actor        = ( new Identity_Map() )->actor_for_user( $this->user );
		$this->registration = Uuid::v4();
		$this->repo         = new Inbox_Repository();
		$this->processor    = new Inbox_Processor( null, null, null, null, static fn() => $guarded );
	}

	private function au( int $lesson ): string {
		return Fake_Adapter::IRI_PREFIX . $lesson;
	}

	private function st( string $verb, int $lesson, array $result = array(), ?array $actor = null ): array {
		++$this->seq;
		$au = $this->au( $lesson );
		$st = array(
			'id'      => Uuid::v4(),
			'actor'   => $actor ?? $this->actor,
			'verb'    => array( 'id' => $verb ),
			'object'  => array(
				'objectType' => 'Activity',
				'id'         => Au::activity_id( $au ),
			),
			'context' => array(
				'registration'      => $this->registration,
				'contextActivities' => array(
					'category' => array( array( 'id' => Vocab::CAT_CMI5 ) ),
					'grouping' => array( array( 'id' => $au ) ),
				),
			),
			'stored'  => sprintf( '2026-10-27T10:00:%02d.000000Z', $this->seq ),
		);
		if ( $result ) {
			$st['result'] = $result;
		}
		return $st;
	}

	private function add( array ...$statements ): void {
		foreach ( $statements as $s ) {
			$this->assertTrue( $this->repo->add( $s, 'webhook' ) );
		}
	}

	private function applied_types(): array {
		return array_map( static fn( Result $r ) => $r->type . ':' . $r->object->kind . ':' . $r->object->id, $this->fake->applied );
	}

	private function status_of( string $id ): string {
		foreach ( $this->repo->for_registration( $this->registration ) as $row ) {
			if ( $row['statement_id'] === $id ) {
				return $row['status'];
			}
		}
		return '';
	}

	public function test_bare_completed_on_completed_and_passed_au_applies_nothing() {
		$c = $this->st( Vocab::COMPLETED, $this->l1, array( 'completion' => true ) );
		$this->add( $c );

		$this->assertSame( 1, $this->processor->process_pending() );
		$this->assertSame( array(), $this->fake->applied );
		$this->assertSame( 'processed', $this->status_of( $c['id'] ) );
		$this->assertSame( array(), ( new Apply_Ledger() )->for_user( $this->user ) );
	}

	public function test_completed_plus_passed_applies_completion_and_pass_once_each() {
		$c = $this->st( Vocab::COMPLETED, $this->l1, array( 'completion' => true ) );
		$p = $this->st(
			Vocab::PASSED,
			$this->l1,
			array(
				'success' => true,
				'score'   => array( 'scaled' => 0.9 ),
			)
		);
		$this->add( $c, $p );
		$this->processor->process_pending();

		$this->assertSame(
			array( 'lesson_passed:lesson:' . $this->l1, 'lesson_completed:lesson:' . $this->l1 ),
			$this->applied_types()
		);
		$this->assertSame( 0.9, $this->fake->applied[0]->score_scaled );
		$this->assertSame( $p['id'], $this->fake->applied[0]->statement_id );
		$this->assertSame( $p['id'], $this->fake->applied[1]->statement_id, 'the later of completed and passed satisfies the AU' );
		$this->assertSame( $this->registration, $this->fake->applied[1]->registration );
		$this->assertSame( $this->user, $this->fake->applied[1]->user_id );
		$this->assertContains( $this->l1, $this->fake->progress( $this->user, $this->course )->completed_lesson_ids );

		$ledger = new Apply_Ledger();
		$this->assertSame(
			array(
				'score_scaled' => 0.9,
				'success'      => true,
			),
			$ledger->score( $this->user, $this->l1 )
		);
		$this->assertSame( 'processed', $this->status_of( $c['id'] ) );
		$this->assertSame( 'processed', $this->status_of( $p['id'] ) );
		$this->assertSame( $this->user, $this->repo->for_user( $this->user )[0]['user_id'] );
	}

	public function test_waived_tested_out_applies_lesson_waived() {
		$w = $this->st( Vocab::WAIVED, $this->l2, array( 'extensions' => array( Vocab::EXT_REASON => 'Tested Out' ) ) );
		$this->add( $w );
		$this->processor->process_pending();

		$this->assertSame( array( 'lesson_waived:lesson:' . $this->l2 ), $this->applied_types() );
		$this->assertSame( 'Tested Out', $this->fake->applied[0]->reason );
		$this->assertFalse( $this->fake->applied[0]->carried_over );
		$this->assertSame( $w['id'], $this->fake->applied[0]->statement_id );
		$this->assertTrue( $this->fake->is_waived( $this->user, $this->l2 ) );
	}

	public function test_replaying_the_batch_applies_nothing_twice() {
		$c = $this->st( Vocab::COMPLETED, $this->l1, array( 'completion' => true ) );
		$p = $this->st( Vocab::PASSED, $this->l1, array( 'success' => true ) );
		$this->add( $c, $p );
		$this->processor->process_pending();
		$this->assertCount( 2, $this->fake->applied );

		$this->assertFalse( $this->repo->add( $c, 'poll' ), 'same statement id is deduplicated' );
		$this->assertFalse( $this->repo->add( $p, 'webhook' ) );
		$this->assertSame( 0, $this->processor->process_pending() );

		// The same facts re-sent under new ids still find everything in the ledger.
		$c2       = $c;
		$c2['id'] = Uuid::v4();
		$p2       = $p;
		$p2['id'] = Uuid::v4();
		$this->add( $c2, $p2 );
		$this->assertSame( 2, $this->processor->process_pending() );
		$this->assertCount( 2, $this->fake->applied, 'no second apply' );
	}

	public function test_void_of_the_passed_statement_flags_the_ledger_and_never_uncompletes() {
		$c = $this->st( Vocab::COMPLETED, $this->l1, array( 'completion' => true ) );
		$p = $this->st( Vocab::PASSED, $this->l1, array( 'success' => true ) );
		$this->add( $c, $p );
		$this->processor->process_pending();

		$void = array(
			'id'     => Uuid::v4(),
			'actor'  => array(
				'objectType' => 'Agent',
				'mbox'       => 'mailto:admin@example.org',
			),
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/voided' ),
			'object' => array(
				'objectType' => 'StatementRef',
				'id'         => $p['id'],
			),
			'stored' => '2026-10-27T11:00:00.000000Z',
		);
		$this->assertTrue( $this->repo->add( $void, 'webhook' ) );
		$this->assertSame( 1, $this->processor->process_pending() );

		$flagged = ( new Apply_Ledger() )->flagged();
		$this->assertNotEmpty( $flagged );
		$this->assertSame( array( $p['id'] ), array_values( array_unique( array_column( $flagged, 'statement_id' ) ) ) );
		$this->assertEqualsCanonicalizing( array( 'lesson_completed', 'lesson_passed' ), array_column( $flagged, 'status' ) );
		$this->assertCount( 2, $this->fake->applied, 'nothing applied or reverted' );
		$this->assertContains( $this->l1, $this->fake->progress( $this->user, $this->course )->completed_lesson_ids, 'never un-completed' );
		$this->assertSame( array( $p['id'] ), $this->repo->voided_ids( $this->registration ) );

		// A later statement in the registration evaluates without the voided one, still without reverting.
		$this->add( $this->st( Vocab::COMPLETED, $this->l2 ) );
		$this->processor->process_pending();
		$this->assertSame( 'lesson_completed:lesson:' . $this->l2, $this->applied_types()[2] );
		$this->assertCount( 3, $this->fake->applied, 'block not satisfied once the pass is voided' );
	}

	public function test_unknown_actor_is_unmatched_until_linked_then_applied() {
		$stranger = array(
			'objectType' => 'Agent',
			'mbox'       => 'mailto:stranger@example.org',
		);
		$c        = $this->st( Vocab::COMPLETED, $this->l2, array(), $stranger );
		$this->add( $c );
		$this->processor->process_pending();

		$this->assertSame( 'unmatched', $this->status_of( $c['id'] ) );
		$this->assertSame( array(), $this->fake->applied );
		$this->assertSame( 0, $this->processor->process_pending(), 'unmatched rows are not retried on their own' );

		$identity = Agent_Identity::from_array( $stranger );
		( new Identity_Map() )->link( $identity, $this->user );
		$this->assertSame( 1, $this->repo->rematch_actor( $identity->hash() ) );
		$this->assertSame( 'pending', $this->status_of( $c['id'] ) );
		$this->processor->process_pending();

		$this->assertSame( array( 'lesson_completed:lesson:' . $this->l2 ), $this->applied_types() );
		$this->assertSame( 'processed', $this->status_of( $c['id'] ) );
	}

	public function test_paused_adapter_defers_and_rows_stay_pending() {
		Integration_State::pause( 'fake', 'test' );
		$c = $this->st( Vocab::COMPLETED, $this->l2 );
		$this->add( $c );

		$this->assertSame( 0, $this->processor->process_pending() );
		$this->assertSame( 'pending', $this->status_of( $c['id'] ) );
		$this->assertSame( array(), $this->fake->applied );
		$this->assertSame( array(), ( new Apply_Ledger() )->for_user( $this->user ) );

		Integration_State::resume( 'fake' );
		$this->assertSame( 1, $this->processor->process_pending() );
		$this->assertSame( array( 'lesson_completed:lesson:' . $this->l2 ), $this->applied_types() );
		$this->assertSame( 'processed', $this->status_of( $c['id'] ) );
	}

	public function test_failed_apply_flags_the_rows() {
		$failing = new class() extends Fake_Adapter {
			public function apply( Result $r ): \LearningLedger\Connector\Lms\Apply_Outcome {
				$this->applied[] = $r;
				return new \LearningLedger\Connector\Lms\Apply_Outcome( \LearningLedger\Connector\Lms\Apply_Outcome::FAILED, 'nope' );
			}
		};
		$course  = $failing->upsert_course( new Course_Spec( 'c1', 'Course', '', 'publish', array() ) );
		$failing->upsert_lesson( $course, new Lesson_Spec( 'l1', 'One', '', 1, 'publish', array() ) );
		$this->assertSame( $this->l2, $failing->upsert_lesson( $course, new Lesson_Spec( 'l2', 'Two', '', 2, 'publish', array() ) ) );
		$processor = new Inbox_Processor( null, null, null, null, static fn() => $failing );
		$c         = $this->st( Vocab::COMPLETED, $this->l2 );
		$this->add( $c );
		$processor->process_pending();
		$this->assertSame( 'flagged', $this->status_of( $c['id'] ) );
		$this->assertSame( array(), ( new Apply_Ledger() )->for_user( $this->user ), 'failures are not recorded as applied' );
	}

	public function test_block_and_course_satisfied_and_evaluated_hook() {
		$seen = array();
		$hook = static function ( int $program_id, int $user, string $registration, Satisfaction_Result $e ) use ( &$seen ) {
			$seen[] = array( $program_id, $user, $registration, $e->course_satisfied() );
		};
		add_action( 'learning_ledger/connector_evaluated', $hook, 10, 4 ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		$this->add(
			$this->st( Vocab::COMPLETED, $this->l1 ),
			$this->st( Vocab::PASSED, $this->l1, array( 'success' => true ) ),
			$this->st( Vocab::COMPLETED, $this->l2 )
		);
		$this->processor->process_pending();
		remove_action( 'learning_ledger/connector_evaluated', $hook, 10 );

		$this->assertSame(
			array(
				'lesson_passed:lesson:' . $this->l1,
				'lesson_completed:lesson:' . $this->l1,
				'lesson_completed:lesson:' . $this->l2,
				'block_satisfied:course:' . $this->course,
				'course_satisfied:program:' . $this->program,
			),
			$this->applied_types()
		);
		$this->assertSame( array( array( $this->program, $this->user, $this->registration, true ) ), $seen );
	}

	public function test_preview_registration_is_evaluated_but_never_applied() {
		$seen = array();
		$hook = static function ( int $program_id, int $user, string $registration ) use ( &$seen ) {
			$seen[] = array( $program_id, $user, $registration );
		};
		add_action( 'learning_ledger/connector_evaluated', $hook, 10, 3 ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		$this->registration = ( new Registration_Map() )->get_or_create( $this->user, 'preview:' . $this->program . ':' . $this->user );
		$c                  = $this->st( Vocab::COMPLETED, $this->l2 );
		$this->add( $c );
		$this->assertSame( 1, $this->processor->process_pending() );
		remove_action( 'learning_ledger/connector_evaluated', $hook, 10 );

		$this->assertSame( array(), $this->fake->applied, 'a Browse registration never reaches the LMS' );
		$this->assertSame( array(), ( new Apply_Ledger() )->for_user( $this->user ) );
		$this->assertSame( 'processed', $this->status_of( $c['id'] ) );
		$this->assertSame( array( array( $this->program, $this->user, $this->registration ) ), $seen, 'listeners still see it (sessions close on terminated)' );
	}

	public function test_course_and_cmi5_scoped_registrations_are_applied() {
		foreach ( array( 'cmi5:' . $this->program, 'course:' . $this->course ) as $i => $scope ) {
			$this->registration = ( new Registration_Map() )->get_or_create( $this->user, $scope );
			$this->add( $this->st( Vocab::COMPLETED, 0 === $i ? $this->l2 : $this->l1 ), $this->st( Vocab::PASSED, $this->l1, array( 'success' => true ) ) );
			$this->processor->process_pending();
		}
		$this->assertContains( 'lesson_completed:lesson:' . $this->l2, $this->applied_types() );
		$this->assertContains( 'lesson_completed:lesson:' . $this->l1, $this->applied_types() );
	}

	public function test_a_run_skips_while_another_holds_the_lock() {
		$c = $this->st( Vocab::COMPLETED, $this->l2 );
		$this->add( $c );

		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', Inbox_Processor::lock_name() ) ) );
		try {
			$this->assertSame( 0, $this->processor->process_pending() );
			$this->assertSame( 'pending', $this->status_of( $c['id'] ) );
			$this->assertSame( array(), $this->fake->applied );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', Inbox_Processor::lock_name() ) );
			$other->close();
		}

		$this->assertSame( 1, $this->processor->process_pending() );
		$this->assertSame( 'processed', $this->status_of( $c['id'] ) );
		$after = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertSame( '1', (string) $after->get_var( $after->prepare( 'SELECT GET_LOCK(%s, 0)', Inbox_Processor::lock_name() ) ), 'the run released the lock' );
		$after->get_var( $after->prepare( 'SELECT RELEASE_LOCK(%s)', Inbox_Processor::lock_name() ) );
		$after->close();
	}

	public function test_unknown_actor_outside_any_program_is_processed_not_unmatched() {
		$other                 = $this->st(
			Vocab::COMPLETED,
			$this->l2,
			array(),
			array(
				'objectType' => 'Agent',
				'mbox'       => 'mailto:stranger@example.org',
			)
		);
		$other['object']['id'] = 'https://elsewhere.example/activity';
		$this->add( $other );
		$this->processor->process_pending();
		$this->assertSame( 'processed', $this->status_of( $other['id'] ) );
	}

	public function test_delete_user_removes_rows_about_the_users_actors() {
		$linked = array(
			'objectType' => 'Agent',
			'mbox'       => 'mailto:linked@example.org',
		);
		( new Identity_Map() )->link( Agent_Identity::from_array( $linked ), $this->user );
		$own      = $this->st( Vocab::COMPLETED, $this->l2 );
		$via_link = $this->st( Vocab::COMPLETED, $this->l2, array(), $linked );
		$stranger = $this->st(
			Vocab::COMPLETED,
			$this->l2,
			array(),
			array(
				'objectType' => 'Agent',
				'mbox'       => 'mailto:stranger@example.org',
			)
		);
		$this->add( $own, $via_link, $stranger );

		$this->assertSame( 2, $this->repo->delete_user( $this->user ), 'unmatched rows about the user go too' );
		$this->assertSame( array( $stranger['id'] ), array_column( $this->repo->for_registration( $this->registration ), 'statement_id' ) );
	}

	public function test_statement_outside_any_program_is_processed_and_ignored() {
		$other                 = $this->st( Vocab::COMPLETED, $this->l2 );
		$other['object']['id'] = 'https://elsewhere.example/activity';
		$this->add( $other );
		$this->assertSame( 1, $this->processor->process_pending() );
		$this->assertSame( 'processed', $this->status_of( $other['id'] ) );
		$this->assertSame( array(), $this->fake->applied );
	}
}
