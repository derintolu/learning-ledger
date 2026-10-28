<?php
/**
 * Badge_Evaluator and badge pipeline tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Pipeline\Badge_Evaluator;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Badges\Pipeline\Pipeline_Module;
use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Auth\Token_Repository;
use LearningLedger\Lrs\Auth\Token_Spec;
use LearningLedger\Lrs\Courses\Course_Record;
use LearningLedger\Lrs\Courses\Course_Repository;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Uuid;

class Badge_Evaluator_Test extends WP_UnitTestCase {

	const COURSE = 'https://example.org/courses/badges';
	const PATH_A = self::COURSE . '/paths/a';
	const PATH_B = self::COURSE . '/paths/b';
	const A1     = self::COURSE . '/lessons/a1';
	const A2     = self::COURSE . '/lessons/a2';
	const B1     = self::COURSE . '/lessons/b1';
	const BADGE  = 'https://example.org/badges/';

	private string $registration;

	private Principal $trusted;

	private Principal $untrusted;

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'courses', 'course_aus', 'badges', 'jobs', 'audit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		delete_option( Pipeline_Module::HELD_OPTION );
		$this->registration = Uuid::v4();
		$this->trusted      = self::principal( true );
		$this->untrusted    = self::principal( false );
	}

	public function tear_down() {
		remove_all_actions( 'learning_ledger/statements_committed' );
		remove_all_actions( 'learning_ledger/jobs/badge_evaluate' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private static function principal( bool $trusted ): Principal {
		$c = Client_Factory::create( array( Scopes::STATEMENTS_WRITE, Scopes::VOID ), array( 'trusted' => $trusted ) );
		return Principal::for_client( ( new Client_Repository() )->find( $c['id'] ) );
	}

	private static function au( string $id, string $key, string $block ): array {
		return array(
			'type'            => 'au',
			'id'              => $id,
			'key'             => $key,
			'title'           => array( 'en-US' => $key ),
			'move_on'         => 'Completed',
			'url'             => $key . '.html',
			'parent_block_id' => $block,
		);
	}

	private static function structure(): Course_Structure {
		return Course_Structure::from_array(
			array(
				'course_id' => self::COURSE,
				'title'     => array( 'en-US' => 'Badges' ),
				'children'  => array(
					array(
						'type'     => 'block',
						'id'       => self::PATH_A,
						'key'      => 'path-a',
						'title'    => array( 'en-US' => 'Path A' ),
						'children' => array( self::au( self::A1, 'a1', self::PATH_A ), self::au( self::A2, 'a2', self::PATH_A ) ),
					),
					array(
						'type'     => 'block',
						'id'       => self::PATH_B,
						'key'      => 'path-b',
						'title'    => array( 'en-US' => 'Path B' ),
						'children' => array( self::au( self::B1, 'b1', self::PATH_B ) ),
					),
				),
			)
		);
	}

	private static function declaration( string $award_on, ?string $target_key, bool $count_waived = false, string $slug = 'one' ): array {
		$d = array(
			'id'              => self::BADGE . $slug,
			'name'            => 'Badge ' . $slug,
			'description'     => 'Earned ' . $slug . '.',
			'criteria'        => array( 'narrative' => 'Do ' . $slug . '.' ),
			'image'           => 'https://example.org/img/' . $slug . '.png',
			'achievementType' => 'Badge',
			'awardOn'         => $award_on,
			'countWaived'     => $count_waived,
		);
		if ( null !== $target_key ) {
			$d['for'] = $target_key;
		}
		return $d;
	}

	private function import( array ...$declarations ): Course_Record {
		$repo = new Course_Repository();
		$repo->import( self::structure(), array( 'badges' => $declarations ), '"e1"', 'owner' );
		return $repo->get( self::COURSE );
	}

	private static function actor(): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => 'https://site.example',
				'name'     => 'learner-1',
			),
		);
	}

	private function defined_statement( string $verb, string $au, array $result = array() ): array {
		$st = array(
			'actor'   => self::actor(),
			'verb'    => array( 'id' => $verb ),
			'object'  => array(
				'objectType' => 'Activity',
				'id'         => $au . '/au',
			),
			'context' => array(
				'registration'      => $this->registration,
				'contextActivities' => array(
					'category' => array( array( 'id' => Vocab::CAT_CMI5 ) ),
					'grouping' => array( array( 'id' => $au ) ),
				),
			),
		);
		if ( array() !== $result ) {
			$st['result'] = $result;
		}
		return $st;
	}

	private function completed( string $au ): array {
		return $this->defined_statement( Vocab::COMPLETED, $au, array( 'completion' => true ) );
	}

	private function waived( string $au, string $reason = 'Tested Out', bool $carried_over = false ): array {
		$extensions = array( Vocab::EXT_REASON => $reason );
		if ( $carried_over ) {
			$extensions[ Vocab::CXM_EXT_CARRIED_OVER ] = true;
		}
		return $this->defined_statement(
			Vocab::WAIVED,
			$au,
			array(
				'completion' => true,
				'success'    => true,
				'extensions' => $extensions,
			)
		);
	}

	private function satisfied( string $object_id ): array {
		return array(
			'actor'   => self::actor(),
			'verb'    => array( 'id' => Vocab::SATISFIED ),
			'object'  => array(
				'objectType' => 'Activity',
				'id'         => $object_id,
			),
			'context' => array(
				'registration'      => $this->registration,
				'contextActivities' => array( 'category' => array( array( 'id' => Vocab::CAT_CMI5 ) ) ),
			),
		);
	}

	/**
	 * A cmi5 LMS's `satisfied`: about its own typed block or course activity, with the publisher
	 * id in grouping (cmi5 9.3.9, 9.6.2.3), as the Sensei player writes it.
	 */
	private function lms_satisfied( string $publisher_id, string $kind ): array {
		$st                         = $this->satisfied( $publisher_id . '/' . $kind );
		$st['object']['definition'] = array( 'type' => 'https://w3id.org/xapi/cmi5/activitytype/' . $kind );
		$st['context']['contextActivities']['grouping'] = array( array( 'id' => $publisher_id ) );
		return $st;
	}

	private function store( Principal $p, array ...$statements ): array {
		return ( new Statement_Store() )->store( $statements, $p, '1.0.3' );
	}

	private function evaluate( Course_Record $c ): array {
		return ( new Badge_Evaluator() )->evaluate( $this->registration, $c );
	}

	private function rows(): array {
		return ( new Badge_Repository() )->for_registration( $this->registration );
	}

	private function audit_events( string $event ): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT data FROM ' . Tables::name( 'audit' ) . ' WHERE event = %s', $event ) ); // phpcs:ignore WordPress.DB
	}

	public function test_block_badge_counting_waivers_is_created_with_tested_out_narrative() {
		$c = $this->import( self::declaration( 'block-satisfied', 'path-a', true ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->waived( self::A2 ) );
		[ $sat ] = $this->store( $this->trusted, $this->satisfied( self::PATH_A ) );

		$ids = $this->evaluate( $c );

		$this->assertCount( 1, $ids );
		$badge = ( new Badge_Repository() )->get( $ids[0] );
		$this->assertSame( Badge_Repository::PENDING, $badge['status'] );
		$this->assertSame( '1 of 2 lessons completed, 1 tested out by placement', $badge['narrative'] );
		$this->assertSame( 1, $badge['waived_count'] );
		$this->assertSame( $sat, $badge['trigger_statement_id'] );
		$this->assertSame( $this->registration, $badge['registration'] );
		$this->assertSame( ( new Statement_Repository() )->find( $sat )->actor_hash, $badge['actor_hash'] );
		$this->assertSame( sha1( self::COURSE, true ), $badge['course_iri_hash'] );
		$this->assertSame(
			array(
				'id'              => self::BADGE . 'one',
				'name'            => 'Badge one',
				'description'     => 'Earned one.',
				'criteria'        => array( 'narrative' => 'Do one.' ),
				'image'           => 'https://example.org/img/one.png',
				'achievementType' => 'Badge',
			),
			$badge['achievement']
		);
		$this->assertNull( $badge['credential'] );
	}

	public function test_block_badge_without_count_waived_is_not_created() {
		$c = $this->import( self::declaration( 'block-satisfied', 'path-a', false ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->waived( self::A2 ), $this->satisfied( self::PATH_A ) );

		$this->assertSame( array(), $this->evaluate( $c ) );
		$this->assertSame( array(), $this->rows() );
		$this->assertSame( array(), $this->audit_events( 'badge_held' ), 'A waiver the badge does not count is not a disagreement.' );
	}

	public function test_carried_over_waiver_never_counts() {
		$c = $this->import( self::declaration( 'block-satisfied', 'path-a', true ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->waived( self::A2, 'Administrative', true ), $this->satisfied( self::PATH_A ) );

		$this->assertSame( array(), $this->evaluate( $c ) );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_any_path_fires_on_the_first_block_only_once() {
		$c = $this->import( self::declaration( 'any-path', null ) );
		$this->store( $this->trusted, $this->completed( self::B1 ) );
		[ $first ] = $this->store( $this->trusted, $this->satisfied( self::PATH_B ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->completed( self::A2 ), $this->satisfied( self::PATH_A ) );

		$ids = $this->evaluate( $c );
		$this->assertCount( 1, $ids );
		$badge = ( new Badge_Repository() )->get( $ids[0] );
		$this->assertSame( $first, $badge['trigger_statement_id'] );
		$this->assertSame( '1 of 1 lessons completed', $badge['narrative'] );

		$this->assertSame( array(), $this->evaluate( $c ) );
		$this->assertCount( 1, $this->rows() );
	}

	public function test_au_moveon_badge_uses_the_statement_that_met_move_on() {
		$c        = $this->import( self::declaration( 'au-moveon', 'a1' ) );
		[ $done ] = $this->store( $this->trusted, $this->completed( self::A1 ) );
		$this->store( $this->trusted, $this->completed( self::A1 ) );

		$ids = $this->evaluate( $c );
		$this->assertCount( 1, $ids );
		$badge = ( new Badge_Repository() )->get( $ids[0] );
		$this->assertSame( $done, $badge['trigger_statement_id'] );
		$this->assertSame( '1 of 1 lessons completed', $badge['narrative'] );
	}

	public function test_course_badge_needs_every_block() {
		$c = $this->import( self::declaration( 'course-satisfied', null, true ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->waived( self::A2 ), $this->completed( self::B1 ) );
		[ $sat ] = $this->store( $this->trusted, $this->satisfied( self::COURSE ) );

		$ids = $this->evaluate( $c );
		$this->assertCount( 1, $ids );
		$badge = ( new Badge_Repository() )->get( $ids[0] );
		$this->assertSame( $sat, $badge['trigger_statement_id'] );
		$this->assertSame( '2 of 3 lessons completed, 1 tested out by placement', $badge['narrative'] );
	}

	/**
	 * T64 regression: the player's typed `{id}/block` and `{id}/course` satisfied statements
	 * trigger block and course badges through their grouping publisher id.
	 */
	public function test_typed_lms_block_and_course_satisfied_trigger_badges() {
		$c = $this->import( self::declaration( 'block-satisfied', 'path-a', false, 'block' ), self::declaration( 'course-satisfied', null, false, 'course' ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->completed( self::A2 ), $this->completed( self::B1 ) );
		[ $block ]  = $this->store( $this->trusted, $this->lms_satisfied( self::PATH_A, 'block' ) );
		[ $course ] = $this->store( $this->trusted, $this->lms_satisfied( self::COURSE, 'course' ) );

		$ids = $this->evaluate( $c );
		$this->assertCount( 2, $ids );
		$triggers = array_map( static fn( $id ) => ( new Badge_Repository() )->get( $id )['trigger_statement_id'], $ids );
		sort( $triggers );
		$expected = array( $block, $course );
		sort( $expected );
		$this->assertSame( $expected, $triggers );
	}

	public function test_typed_satisfied_needs_its_kind_and_a_matching_grouping() {
		$c = $this->import( self::declaration( 'block-satisfied', 'path-a', false, 'block' ), self::declaration( 'course-satisfied', null, false, 'course' ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->completed( self::A2 ), $this->completed( self::B1 ) );
		$untyped = $this->lms_satisfied( self::PATH_A, 'block' );
		unset( $untyped['object']['definition'] );
		$wrong_kind = $this->lms_satisfied( self::COURSE, 'block' );
		$elsewhere  = $this->lms_satisfied( 'https://example.org/courses/other/paths/a', 'block' );
		$this->store( $this->trusted, $untyped, $wrong_kind, $elsewhere );

		$this->assertSame( array(), $this->evaluate( $c ) );
	}

	public function test_a_batch_holding_only_a_typed_course_satisfied_queues_its_course() {
		( new Pipeline_Module() )->register();
		$this->import( self::declaration( 'course-satisfied', null ) );
		$this->store( $this->trusted, $this->lms_satisfied( self::COURSE, 'course' ) );
		global $wpdb;
		$payload = json_decode( (string) $wpdb->get_var( 'SELECT payload FROM ' . Tables::name( 'jobs' ) ), true ); // phpcs:ignore WordPress.DB
		$this->assertSame( self::COURSE, $payload['course'] );
	}

	public function test_satisfied_written_by_an_untrusted_client_creates_nothing() {
		$c = $this->import( self::declaration( 'block-satisfied', 'path-a', false, 'block' ), self::declaration( 'au-moveon', 'a1', false, 'lesson' ) );
		$this->store( $this->untrusted, $this->completed( self::A1 ), $this->completed( self::A2 ), $this->satisfied( self::PATH_A ) );

		$this->assertSame( array(), $this->evaluate( $c ) );
		$this->assertSame( array(), $this->rows() );
		$this->assertSame( array(), $this->audit_events( 'badge_held' ) );
	}

	public function test_trusted_satisfied_with_unmet_recomputation_is_held_and_audited() {
		$c = $this->import( self::declaration( 'block-satisfied', 'path-a' ) );
		$this->store( $this->trusted, $this->completed( self::A1 ) );
		[ $sat ] = $this->store( $this->trusted, $this->satisfied( self::PATH_A ) );

		$ids = $this->evaluate( $c );

		$this->assertCount( 1, $ids );
		$badge = ( new Badge_Repository() )->get( $ids[0] );
		$this->assertSame( Badge_Repository::HELD, $badge['status'] );
		$this->assertSame( $sat, $badge['trigger_statement_id'] );
		$audit = $this->audit_events( 'badge_held' );
		$this->assertCount( 1, $audit );
		$data = json_decode( $audit[0], true );
		$this->assertSame( $ids[0], $data['badge'] );
		$this->assertSame( $sat, $data['trigger'] );
		$this->assertSame( 'block-satisfied', $data['award_on'] );
		$this->assertSame( 1, (int) get_option( Pipeline_Module::HELD_OPTION ) );

		$this->assertSame( array(), $this->evaluate( $c ), 'A held badge is not created again.' );
		$this->assertCount( 1, $this->audit_events( 'badge_held' ) );
	}

	public function test_custom_rules_accept_a_trusted_satisfied_without_recomputation() {
		$c = $this->import( self::declaration( 'block-satisfied', 'path-a' ), self::declaration( 'author-rule', 'path-b', false, 'custom' ) );
		$this->assertTrue( $c->custom_rules );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->satisfied( self::PATH_A ), $this->satisfied( self::PATH_B ) );
		$this->store( $this->untrusted, $this->satisfied( self::PATH_B ) );

		$ids = $this->evaluate( $c );
		$this->assertCount( 2, $ids );
		foreach ( $ids as $id ) {
			$this->assertSame( Badge_Repository::PENDING, ( new Badge_Repository() )->get( $id )['status'] );
		}
		$this->assertSame( array(), $this->audit_events( 'badge_held' ) );
	}

	public function test_voided_trigger_creates_nothing() {
		$c = $this->import( self::declaration( 'block-satisfied', 'path-a' ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->completed( self::A2 ) );
		[ $sat ] = $this->store( $this->trusted, $this->satisfied( self::PATH_A ) );
		$this->store(
			$this->trusted,
			array(
				'actor'  => self::actor(),
				'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/voided' ),
				'object' => array(
					'objectType' => 'StatementRef',
					'id'         => $sat,
				),
			)
		);
		$this->assertTrue( ( new Statement_Repository() )->find( $sat )->voided );

		$this->assertSame( array(), $this->evaluate( $c ) );
		$this->assertSame( array(), $this->rows() );
		$this->assertSame( array(), $this->audit_events( 'badge_held' ) );
	}

	public function test_storing_request_only_queues_the_job() {
		( new Pipeline_Module() )->register();
		$this->import( self::declaration( 'block-satisfied', 'path-a' ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->completed( self::A2 ), $this->satisfied( self::PATH_A ) );

		$this->assertSame( array(), $this->rows(), 'Nothing is evaluated in the storing request.' );
		global $wpdb;
		$jobs = $wpdb->get_results( 'SELECT type, payload, dedupe_key, done_at FROM ' . Tables::name( 'jobs' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertCount( 1, $jobs );
		$this->assertSame( 'badge_evaluate', $jobs[0]['type'] );
		$this->assertSame( 'badge:' . $this->registration, $jobs[0]['dedupe_key'] );
		$this->assertNull( $jobs[0]['done_at'] );
		$this->assertSame(
			array(
				'registration' => $this->registration,
				'course'       => self::COURSE,
			),
			json_decode( $jobs[0]['payload'], true )
		);

		$this->assertSame( 1, Job_Queue::run() );
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( Badge_Repository::PENDING, $rows[0]['status'] );
	}

	public function test_statements_for_a_course_without_badges_queue_nothing() {
		( new Pipeline_Module() )->register();
		$this->import();
		$this->store( $this->trusted, $this->completed( self::A1 ) );

		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'jobs' ) ) ); // phpcs:ignore WordPress.DB
	}

	public function test_a_batch_holding_only_the_block_satisfied_queues_evaluation() {
		( new Pipeline_Module() )->register();
		$this->import( self::declaration( 'block-satisfied', 'path-a' ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->completed( self::A2 ) );
		Job_Queue::run();
		$this->assertSame( array(), $this->rows() );

		$this->store( $this->trusted, $this->satisfied( self::PATH_A ) );
		$this->assertSame( array(), $this->rows() );
		$this->assertSame( 1, Job_Queue::run() );
		$this->assertCount( 1, $this->rows() );
	}

	public function test_rerunning_the_job_creates_no_duplicate() {
		( new Pipeline_Module() )->register();
		$this->import( self::declaration( 'block-satisfied', 'path-a' ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->completed( self::A2 ), $this->satisfied( self::PATH_A ) );
		Job_Queue::run();
		$this->assertCount( 1, $this->rows() );

		$payload = array(
			'registration' => $this->registration,
			'course'       => self::COURSE,
		);
		Job_Queue::push( 'badge_evaluate', $payload, 'badge:' . $this->registration );
		$this->assertSame( 1, Job_Queue::run() );
		do_action( 'learning_ledger/jobs/badge_evaluate', $payload ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$rows = $this->rows();
		$this->assertCount( 1, $rows );

		// Two evaluations racing past the existence check: the unique key lets only one insert win.
		$again = ( new Badge_Repository() )->create_pending( $rows[0]['achievement'], $this->registration, $rows[0]['actor_hash'], self::COURSE, $rows[0]['trigger_statement_id'], 'x', 0 );
		$this->assertNull( $again );
		$this->assertCount( 1, $this->rows() );
	}

	public function test_a_push_while_the_job_runs_queues_a_follow_up() {
		( new Pipeline_Module() )->register();
		$this->import( self::declaration( 'block-satisfied', 'path-a' ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->completed( self::A2 ) );

		// The satisfied statement is stored after this job's evaluation has read the record.
		$stored = false;
		add_action(
			'learning_ledger/jobs/badge_evaluate',
			function () use ( &$stored ) {
				if ( ! $stored ) {
					$stored = true;
					$this->store( $this->trusted, $this->satisfied( self::PATH_A ) );
				}
			},
			20
		);
		$this->assertSame( 1, Job_Queue::run( 1 ) );
		$this->assertSame( array(), $this->rows() );

		global $wpdb;
		$open = $wpdb->get_col( 'SELECT dedupe_key FROM ' . Tables::name( 'jobs' ) . ' WHERE done_at IS NULL' ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( 'badge:' . $this->registration ), $open, 'The push during the run queued a second job.' );

		$this->assertSame( 1, Job_Queue::run() );
		$this->assertCount( 1, $this->rows() );
	}

	public function test_statements_stored_with_a_child_token_never_trigger() {
		$c      = $this->import( self::declaration( 'block-satisfied', 'path-a', false, 'block' ), self::declaration( 'au-moveon', 'a1', false, 'lesson' ) );
		$parent = Client_Factory::create( array( Scopes::STATEMENTS_WRITE, Scopes::TOKENS_MINT ), array( 'trusted' => true ) );
		$client = ( new Client_Repository() )->find( $parent['id'] );
		$tokens = new Token_Repository();
		$minted = $tokens->mint( $client, new Token_Spec( $this->registration, self::actor(), self::A1 . '/au', Uuid::v4(), 'Normal' ) );
		$child  = Principal::for_token( $client, $tokens->find( $minted['key'] ) );
		$this->assertFalse( $child->trusted_for_satisfaction() );

		$this->store( $child, $this->completed( self::A1 ), $this->completed( self::A2 ), $this->satisfied( self::PATH_A ) );
		$this->assertSame( 'tok:' . $minted['key'], ( new Statement_Repository() )->for_registration( $this->registration )[0]->authority_client );

		$this->assertSame( array(), $this->evaluate( $c ) );
		$this->assertSame( array(), $this->rows() );
		$this->assertSame( array(), $this->audit_events( 'badge_held' ) );
	}

	public function test_au_moveon_takes_the_first_trusted_statement_that_meets_move_on() {
		$c = $this->import( self::declaration( 'au-moveon', 'a1' ) );
		$this->store( $this->untrusted, $this->completed( self::A1 ) );
		$this->store( $this->trusted, $this->defined_statement( Vocab::TERMINATED, self::A1 ) );
		[ $trusted ] = $this->store( $this->trusted, $this->completed( self::A1 ) );

		$ids = $this->evaluate( $c );
		$this->assertCount( 1, $ids );
		$this->assertSame( $trusted, ( new Badge_Repository() )->get( $ids[0] )['trigger_statement_id'] );
	}

	public function test_any_path_with_unmet_recomputation_is_held() {
		$c = $this->import( self::declaration( 'any-path', null ) );
		$this->store( $this->trusted, $this->completed( self::A1 ) );
		[ $sat ] = $this->store( $this->trusted, $this->satisfied( self::PATH_A ) );

		$ids = $this->evaluate( $c );
		$this->assertCount( 1, $ids );
		$badge = ( new Badge_Repository() )->get( $ids[0] );
		$this->assertSame( Badge_Repository::HELD, $badge['status'] );
		$this->assertSame( $sat, $badge['trigger_statement_id'] );
		$this->assertCount( 1, $this->audit_events( 'badge_held' ) );
	}

	public function test_course_satisfied_with_unmet_recomputation_is_held() {
		$c = $this->import( self::declaration( 'course-satisfied', null ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->completed( self::A2 ) );
		[ $sat ] = $this->store( $this->trusted, $this->satisfied( self::COURSE ) );

		$ids = $this->evaluate( $c );
		$this->assertCount( 1, $ids );
		$badge = ( new Badge_Repository() )->get( $ids[0] );
		$this->assertSame( Badge_Repository::HELD, $badge['status'] );
		$this->assertSame( $sat, $badge['trigger_statement_id'] );
		$this->assertCount( 1, $this->audit_events( 'badge_held' ) );
	}

	public function test_held_notice_counts_held_rows_and_clears_its_flag() {
		$c = $this->import( self::declaration( 'block-satisfied', 'path-a' ) );
		$this->store( $this->trusted, $this->completed( self::A1 ), $this->satisfied( self::PATH_A ) );
		[ $id ] = $this->evaluate( $c );
		$module = new Pipeline_Module();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		$module->held_notice();
		$this->assertSame( '', ob_get_clean(), 'Only badge managers see the notice.' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( 'lledger_manage_badges' );
		wp_set_current_user( $admin );
		ob_start();
		$module->held_notice();
		$this->assertStringContainsString( 'held 1 badge', ob_get_clean() );

		( new Badge_Repository() )->set_status( $id, Badge_Repository::REVOKED );
		ob_start();
		$module->held_notice();
		$this->assertSame( '', ob_get_clean() );
		$this->assertFalse( get_option( Pipeline_Module::HELD_OPTION ) );
	}
}
