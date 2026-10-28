<?php
/**
 * Player_Evaluator tests: LMS `satisfied` and recommended `waived`, the return route, and
 * registration creation.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixtures, table reads and the returned credential.
// phpcs:disable Generic.Files.OneObjectStructurePerFile -- the session tests' shared base class lives here.

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Connector\Inbox\Inbox_Processor;
use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Programs\Program_Iris;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Db\Tables;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
use LearningLedger\Package\Package_Writer;
use LearningLedger\Player\Import\Player_Importer;
use LearningLedger\Player\Launch\Lms_Statements;
use LearningLedger\Player\Launch\Session_Repository;
use LearningLedger\Player\Session\Player_Evaluator;
use LearningLedger\Player\Session\Session_Module;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Tests\Xapi_Test_Client;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Statement_Validator;

require_once dirname( __DIR__ ) . '/Launch/test-launch.php';

/**
 * Shared setup: the launch fixture plus a two-block player course and the session module.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test base class.
abstract class Lledger_Session_Test_Case extends Lledger_Launch_Test_Case {

	const T59_COURSE = 'https://example.org/t59/course';
	const BLOCK_A    = 'https://example.org/t59/block/a';
	const BLOCK_B    = 'https://example.org/t59/block/b';
	const A1         = 'https://example.org/t59/au/a1';
	const A2         = 'https://example.org/t59/au/a2';
	const B1         = 'https://example.org/t59/au/b1';
	const PLACED     = 'https://course-xapi-mcp.sqwrlz.com/xapi/verbs/placed';

	/**
	 * Block A holds two `Completed` AUs; block B one `NotApplicable` AU.
	 */
	const T59_XML = '<?xml version="1.0" encoding="utf-8"?>
<courseStructure xmlns="https://w3id.org/xapi/profiles/cmi5/v1/CourseStructure.xsd">
  <course id="https://example.org/t59/course">
    <title><langstring lang="en">Sessions</langstring></title>
    <description><langstring lang="en">Sessions course.</langstring></description>
  </course>
  <block id="https://example.org/t59/block/a">
    <title><langstring lang="en">Block A</langstring></title>
    <description><langstring lang="en">Block A.</langstring></description>
    <au id="https://example.org/t59/au/a1" moveOn="Completed" launchMethod="AnyWindow">
      <title><langstring lang="en">A1</langstring></title>
      <description><langstring lang="en">A1.</langstring></description>
      <url>https://au.example.net/a1/index.html</url>
    </au>
    <au id="https://example.org/t59/au/a2" moveOn="Completed" launchMethod="AnyWindow">
      <title><langstring lang="en">A2</langstring></title>
      <description><langstring lang="en">A2.</langstring></description>
      <url>https://au.example.net/a2/index.html</url>
    </au>
  </block>
  <block id="https://example.org/t59/block/b">
    <title><langstring lang="en">Block B</langstring></title>
    <description><langstring lang="en">Block B.</langstring></description>
    <au id="https://example.org/t59/au/b1" moveOn="NotApplicable" launchMethod="AnyWindow">
      <title><langstring lang="en">B1</langstring></title>
      <description><langstring lang="en">B1.</langstring></description>
      <url>https://au.example.net/b1/index.html</url>
    </au>
  </block>
</courseStructure>';

	protected int $t59;

	/**
	 * Lesson id by AU id.
	 *
	 * @var array<string,int>
	 */
	protected array $lessons = array();

	/**
	 * Temp files of this class.
	 *
	 * @var string[]
	 */
	private array $session_temp = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb, $wp_rest_server;
		foreach ( array( 'conn_inbox', 'applied', 'waivers', 'conn_cursor', 'identity_links' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) );
		}
		( new Session_Module() )->register();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- reset so the next dispatch registers the routes.

		$path = tempnam( sys_get_temp_dir(), 'llt59' );
		file_put_contents( $path, self::T59_XML );
		$this->session_temp[] = $path;
		$report               = ( new Player_Importer( new Sensei_Adapter() ) )->import( $path );
		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );
		$this->t59 = $report->program_id;
		foreach ( array( self::A1, self::A2, self::B1 ) as $au ) {
			$this->lessons[ $au ] = $this->lesson_for( $au );
		}
		foreach ( ( new Program_Repository() )->get( $this->t59 )->course_ids as $course ) {
			$this->assertTrue( ( new Sensei_Adapter() )->enrol( $this->learner, $course ) );
		}
	}

	public function tear_down(): void {
		foreach ( $this->session_temp as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	protected function lesson_for( string $au_id ): int {
		$ids = get_posts(
			array(
				'post_type'   => 'lesson',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_key'    => Player_Importer::AU_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $au_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$this->assertCount( 1, $ids );
		return (int) $ids[0];
	}

	/**
	 * Launch a lesson as the current user and return the AU's client with the session.
	 *
	 * @return array{client:Xapi_Test_Client,session:array,launch_url:string}
	 */
	protected function launch_au( string $au_id, string $mode = 'Normal' ): array {
		$res = $this->launch(
			array(
				'lesson' => $this->lessons[ $au_id ],
				'mode'   => $mode,
			)
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$url     = $res->get_data()['launch_url'];
		$session = ( new Session_Repository() )->open_for( get_current_user_id(), $au_id );
		$this->assertNotNull( $session );
		list( $key, $secret ) = explode( ':', (string) base64_decode( $this->fetch( self::fetch_token( $url ) )->get_data()['auth-token'], true ), 2 );
		return array(
			'client'     => Xapi_Test_Client::as( $key, $secret ),
			'session'    => $session,
			'launch_url' => $url,
		);
	}

	/**
	 * A cmi5 defined (or allowed, with $cmi5 false) statement about an AU.
	 */
	protected function au_statement( int $user, string $verb, string $au_id, string $session, string $registration, array $result = array(), bool $cmi5 = true, ?string $object_id = null ): array {
		$activities = array( 'grouping' => array( array( 'id' => $au_id ) ) );
		if ( $cmi5 ) {
			$activities['category'] = array( array( 'id' => Vocab::CAT_CMI5 ) );
		} else {
			$activities['parent'] = array( array( 'id' => Au::activity_id( $au_id ) ) );
		}
		$st = array(
			'id'        => wp_generate_uuid4(),
			'actor'     => $this->actor( $user ),
			'verb'      => array( 'id' => $verb ),
			'object'    => array(
				'objectType' => 'Activity',
				'id'         => $object_id ?? Au::activity_id( $au_id ),
			),
			'context'   => array(
				'registration'      => $registration,
				'contextActivities' => $activities,
				'extensions'        => array( Vocab::EXT_SESSIONID => $session ),
			),
			'timestamp' => Clock::now()->format( 'Y-m-d\TH:i:s.v\Z' ),
		);
		if ( array() !== $result ) {
			$st['result'] = $result;
		}
		return $st;
	}

	protected function post_as( Xapi_Test_Client $client, array $statement ): void {
		$res = $client->request( 'POST', 'statements', array(), (string) wp_json_encode( $statement ), array( 'Content-Type' => 'application/json' ) );
		$this->assertSame( 200, $res->status(), $res->body() );
	}

	protected function tick( string $time ): void {
		Clock::freeze( new DateTimeImmutable( '2026-10-27 ' . $time, new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Stored statements of one verb about one object.
	 */
	protected function about( string $verb, string $object_id ): array {
		return array_values( array_filter( $this->statements( $verb ), static fn( array $st ): bool => $object_id === $st['object']['id'] ) );
	}

	protected function registration_of( int $user, int $program ): ?string {
		return ( new Registration_Map() )->find( $user, 'cmi5:' . $program );
	}

	protected function sync( int $program, int $user, string $registration ): void {
		$this->assertSame( 'cmi5_sync', Player_Evaluator::SYNC_JOB );
		do_action(
			'learning_ledger/jobs/cmi5_sync', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			array(
				'program'      => $program,
				'user'         => $user,
				'registration' => $registration,
			)
		);
	}

	protected function statement_posts(): int {
		return count( array_filter( $this->http, static fn( array $r ): bool => 'POST' === $r['args']['method'] && str_ends_with( (string) wp_parse_url( $r['url'], PHP_URL_PATH ), '/statements' ) ) );
	}

	/**
	 * Import the course-xapi-mcp fixture through the player and enrol the learner.
	 */
	protected function import_cxm(): int {
		$path = wp_tempnam( 'llcxm' );
		file_put_contents( $path, Package_Writer::write( ...Package_Fixtures::inputs( 'good-full' ) ) );
		$this->session_temp[] = $path;
		$report               = ( new Player_Importer( new Sensei_Adapter() ) )->import( $path );
		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );
		foreach ( ( new Program_Repository() )->get( $report->program_id )->course_ids as $course ) {
			$this->assertTrue( ( new Sensei_Adapter() )->enrol( $this->learner, $course ) );
		}
		return $report->program_id;
	}

	protected function cxm_lesson( int $program, string $key ): int {
		$ids = get_posts(
			array(
				'post_type'   => 'lesson',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_key'    => '_lledger_stable_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$this->assertCount( 1, $ids );
		$this->assertContains( (int) get_post_meta( $ids[0], '_lesson_course', true ), ( new Program_Repository() )->get( $program )->course_ids );
		return (int) $ids[0];
	}

	protected function cxm_au( int $program, string $key ): string {
		return Program_Iris::au_iri( ( new Program_Repository() )->get( $program ), $key );
	}

	protected function connector_post( array $statement ): void {
		$this->post_as( $this->as_connector(), $statement );
	}
}

/**
 * Player session evaluation tests.
 *
 * @requires function Sensei
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test class.
class Player_Evaluator_Test extends Lledger_Session_Test_Case {

	private function assert_lms_satisfied( array $st, string $registration ): void {
		Statement_Validator::validate( $st, '1.0.3' );
		$this->assertSame( Vocab::SATISFIED, $st['verb']['id'] );
		$this->assertSame( $registration, $st['context']['registration'] );
		$this->assertSame( Vocab::CAT_CMI5, $st['context']['contextActivities']['category'][0]['id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $st['context']['extensions'][ Vocab::EXT_SESSIONID ] );
		$this->assertSame( $this->actor( $this->learner ), array_intersect_key( $st['actor'], array_flip( array( 'objectType', 'account' ) ) ) );
	}

	public function test_not_applicable_au_is_satisfied_at_registration_creation() {
		$this->launch_au( self::A1 );
		$registration = $this->registration_of( $this->learner, $this->t59 );
		$this->assertNotNull( $registration );

		$b = $this->about( Vocab::SATISFIED, self::BLOCK_B );
		$this->assertCount( 1, $b, 'the block made only of a NotApplicable AU is satisfied at once' );
		$this->assert_lms_satisfied( $b[0], $registration );
		$this->assertSame( array(), $this->about( Vocab::SATISFIED, self::BLOCK_A ) );
		$this->assertSame( array(), $this->about( Vocab::SATISFIED, self::T59_COURSE ) );

		// The queued sync (due once the launch is listed) pulls it into the inbox; nothing new is satisfied.
		$this->tick( '10:00:05' );
		$this->assertGreaterThan( 0, Job_Queue::run() );
		$this->assertCount( 1, $this->about( Vocab::SATISFIED, self::BLOCK_B ) );
		$this->assertNotSame( array(), ( new Inbox_Repository() )->for_registration( $registration ) );
		$this->assertTrue( Sensei_Utils::user_completed_lesson( $this->lessons[ self::B1 ], $this->learner ), 'the inbox completes the NotApplicable lesson' );
		$this->assertFalse( Sensei_Utils::user_completed_lesson( $this->lessons[ self::A1 ], $this->learner ) );
	}

	public function test_block_satisfied_by_au_statements_is_written_once() {
		$a1           = $this->launch_au( self::A1 );
		$registration = $a1['session']['registration'];
		$this->tick( '10:00:10' );
		$this->post_as( $a1['client'], $this->au_statement( $this->learner, Vocab::COMPLETED, self::A1, $a1['session']['session_id'], $registration, array( 'completion' => true ) ) );
		$this->tick( '10:00:20' );
		$a2 = $this->launch_au( self::A2 );
		$this->tick( '10:00:30' );
		$this->post_as( $a2['client'], $this->au_statement( $this->learner, Vocab::COMPLETED, self::A2, $a2['session']['session_id'], $registration, array( 'completion' => true ) ) );

		$this->tick( '10:01:00' );
		$this->sync( $this->t59, $this->learner, $registration );

		$a = $this->about( Vocab::SATISFIED, self::BLOCK_A );
		$this->assertCount( 1, $a );
		$this->assert_lms_satisfied( $a[0], $registration );
		$this->assertSame( $a2['session']['session_id'], $a[0]['context']['extensions'][ Vocab::EXT_SESSIONID ], 'the session that satisfied the block' );
		$course = $this->about( Vocab::SATISFIED, self::T59_COURSE );
		$this->assertCount( 1, $course );
		$this->assertCount( 1, $this->about( Vocab::SATISFIED, self::BLOCK_B ) );

		// Evaluated again (the LMS statements come back to the inbox), and once more after that.
		$this->tick( '10:02:00' );
		$this->sync( $this->t59, $this->learner, $registration );
		$this->tick( '10:03:00' );
		$posts = $this->statement_posts();
		$this->sync( $this->t59, $this->learner, $registration );
		do_action( 'learning_ledger/connector_evaluated', $this->t59, $this->learner, $registration, ( new Player_Evaluator() )->evaluation( ( new Program_Repository() )->structure( $this->t59 ), $this->learner, $registration ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertSame( $posts, $this->statement_posts(), 'nothing is posted again once the inbox has the satisfied statements' );

		$this->assertCount( 1, $this->about( Vocab::SATISFIED, self::BLOCK_A ) );
		$this->assertCount( 1, $this->about( Vocab::SATISFIED, self::T59_COURSE ) );
		$this->assertCount( 1, $this->about( Vocab::SATISFIED, self::BLOCK_B ) );
	}

	public function test_return_route_processes_statements_before_redirecting() {
		$a1           = $this->launch_au( self::A1 );
		$session      = $a1['session'];
		$registration = $session['registration'];
		$this->tick( '10:00:10' );
		$this->post_as( $a1['client'], $this->au_statement( $this->learner, Vocab::COMPLETED, self::A1, $session['session_id'], $registration, array( 'completion' => true ) ) );
		$this->tick( '10:00:20' );
		$this->post_as( $a1['client'], $this->au_statement( $this->learner, Vocab::TERMINATED, self::A1, $session['session_id'], $registration ) );
		$this->assertSame( array( Vocab::SATISFIED ), array_column( ( new Inbox_Repository() )->for_registration( $registration ), 'verb_id' ), 'only the LMS\'s own satisfied for block B is in the inbox yet' );

		// Another learner's pending row in another registration.
		$other_user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_reg  = wp_generate_uuid4();
		$other      = $this->au_statement( $other_user, Vocab::COMPLETED, self::A2, wp_generate_uuid4(), $other_reg, array( 'completion' => true ) );
		$this->assertSame( Inbox_Repository::ADDED, ( new Inbox_Repository() )->store( $other, 'webhook' ) );

		$seen = array();
		add_action(
			'learning_ledger/cmi5_return',
			function () use ( &$seen, $registration, $session ) {
				$seen[] = array(
					'rows'    => ( new Inbox_Repository() )->for_registration( $registration ),
					'session' => ( new Session_Repository() )->find( $session['session_id'] ),
				);
			},
			20
		);
		wp_set_current_user( 0 );
		$this->tick( '10:00:30' );
		$res = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/learning-ledger/v1/cmi5/return/' . $session['session_id'] ) );
		$this->assertSame( 302, $res->get_status() );
		$this->assertSame( get_permalink( $this->lessons[ self::A1 ] ), $res->get_headers()['Location'] );

		$this->assertCount( 1, $seen );
		$rows = $seen[0]['rows'];
		$this->assertNotSame( array(), $rows, 'the registration was pulled before the redirect' );
		foreach ( $rows as $row ) {
			$this->assertNotSame( Inbox_Repository::PENDING, $row['status'], 'and processed' );
		}
		$this->assertContains( Vocab::COMPLETED, array_column( $rows, 'verb_id' ) );
		$this->assertSame( Session_Repository::TERMINATED, $seen[0]['session']['status'], 'the inbox terminated closes the session' );
		$this->assertSame( '2026-10-27 10:00:20.000000', $seen[0]['session']['terminated_at'] );
		$this->assertSame( array(), $this->statements( Vocab::ABANDONED ) );
		$this->assertTrue( Sensei_Utils::user_completed_lesson( $this->lessons[ self::A1 ], $this->learner ), 'Sensei has the completion before the redirect' );

		$untouched = ( new Inbox_Repository() )->for_registration( $other_reg );
		$this->assertCount( 1, $untouched );
		$this->assertSame( Inbox_Repository::PENDING, $untouched[0]['status'], 'other registrations are left to the inbox' );
	}

	public function test_return_waits_for_a_held_inbox_lock_then_leaves_the_rows_pending() {
		$a1      = $this->launch_au( self::A1 );
		$session = $a1['session'];
		$this->tick( '10:00:10' );
		$this->post_as( $a1['client'], $this->au_statement( $this->learner, Vocab::COMPLETED, self::A1, $session['session_id'], $session['registration'], array( 'completion' => true ) ) );
		$this->tick( '10:00:20' );

		$holder = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertSame( '1', (string) $holder->get_var( $holder->prepare( 'SELECT GET_LOCK(%s, 0)', Inbox_Processor::lock_name() ) ) );
		try {
			$started = microtime( true );
			$res     = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/learning-ledger/v1/cmi5/return/' . $session['session_id'] ) );
			$waited  = microtime( true ) - $started;
		} finally {
			$holder->get_var( $holder->prepare( 'SELECT RELEASE_LOCK(%s)', Inbox_Processor::lock_name() ) );
			$holder->close();
		}
		$this->assertSame( 302, $res->get_status(), 'the learner is redirected anyway' );
		$this->assertGreaterThanOrEqual( Inbox_Processor::LOCK_WAIT - 0.5, $waited, 'the lock was waited for' );
		$rows = ( new Inbox_Repository() )->for_registration( $session['registration'] );
		$this->assertContains( Vocab::COMPLETED, array_column( $rows, 'verb_id' ), 'pulled' );
		$this->assertSame( array( Inbox_Repository::PENDING ), array_values( array_unique( array_column( array_filter( $rows, static fn( $r ) => Vocab::COMPLETED === $r['verb_id'] ), 'status' ) ) ), 'left for the run holding the lock' );
		$this->assertFalse( Sensei_Utils::user_completed_lesson( $this->lessons[ self::A1 ], $this->learner ) );

		// Once the lock is free, the next return proceeds.
		$this->assertSame( 302, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/learning-ledger/v1/cmi5/return/' . $session['session_id'] ) )->get_status() );
		$this->assertTrue( Sensei_Utils::user_completed_lesson( $this->lessons[ self::A1 ], $this->learner ) );
	}

	public function test_satisfied_id_already_held_by_the_lrs_is_copied_and_not_posted_again() {
		$registration                   = ( new Registration_Map() )->get_or_create( $this->learner, 'cmi5:' . $this->t59 );
		$session                        = Uuid::v5( Lms_Statements::NAMESPACE, $registration . '|' . self::BLOCK_B . '|satisfied-session' );
		$expected                       = ( new Lms_Statements() )->satisfied( self::BLOCK_B, $this->actor( $this->learner ), $registration, $session );
		$conflicting                    = $expected;
		$conflicting['verb']['display'] = array( 'en-US' => 'Satisfied (other)' );
		$this->connector_post( $conflicting );

		do_action( 'learning_ledger/cmi5_registration_created', $this->learner, $this->t59, $registration ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$rows = array_filter( ( new Inbox_Repository() )->for_registration( $registration ), static fn( $r ) => $expected['id'] === $r['statement_id'] );
		$this->assertCount( 1, $rows, 'the LRS copy is in the inbox' );
		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::name( 'jobs' ) . ' WHERE type = %s AND run_after > %s', Player_Evaluator::SYNC_JOB, '2026-10-27 10:00:30' ) ), 'no retry is queued' );

		$posts = $this->statement_posts();
		$this->sync( $this->t59, $this->learner, $registration );
		$this->assertSame( $posts, $this->statement_posts(), 'not posted again' );
		$this->assertCount( 1, $this->about( Vocab::SATISFIED, self::BLOCK_B ) );
	}

	public function test_inbox_terminated_with_matching_session_marks_it_terminated() {
		$a1      = $this->launch_au( self::A1 );
		$session = $a1['session'];
		$this->tick( '10:00:30' );
		$this->post_as( $a1['client'], $this->au_statement( $this->learner, Vocab::TERMINATED, self::A1, wp_generate_uuid4(), $session['registration'] ) );
		$this->tick( '10:00:35' );
		$this->sync( $this->t59, $this->learner, $session['registration'] );
		$this->assertNotSame( array(), ( new Inbox_Repository() )->for_registration( $session['registration'] ) );
		$this->assertSame( Session_Repository::OPEN, ( new Session_Repository() )->find( $session['session_id'] )['status'], 'another session id leaves it open' );

		$this->tick( '10:00:40' );
		$this->post_as( $a1['client'], $this->au_statement( $this->learner, Vocab::TERMINATED, self::A1, $session['session_id'], $session['registration'] ) );
		$this->tick( '10:00:45' );
		$this->sync( $this->t59, $this->learner, $session['registration'] );
		$closed = ( new Session_Repository() )->find( $session['session_id'] );
		$this->assertSame( Session_Repository::TERMINATED, $closed['status'] );
		$this->assertSame( '2026-10-27 10:00:40.000000', $closed['terminated_at'] );
	}

	public function test_waive_recommended_writes_tested_out_for_the_listed_aus_not_yet_satisfied() {
		$cxm          = $this->import_cxm();
		$registration = ( new Registration_Map() )->get_or_create( $this->learner, 'cmi5:' . $cxm );
		$placement    = Program_Iris::placement_iri( ( new Program_Repository() )->get( $cxm ) );
		$what         = $this->cxm_au( $cxm, 'what-is-json' );
		$syntax       = $this->cxm_au( $cxm, 'json-syntax' );
		$session      = wp_generate_uuid4();

		$this->tick( '10:00:10' );
		$this->connector_post( $this->au_statement( $this->learner, Vocab::COMPLETED, $syntax, wp_generate_uuid4(), $registration, array( 'completion' => true ) ) );
		$this->tick( '10:00:20' );
		$this->connector_post(
			$this->au_statement(
				$this->learner,
				self::PLACED,
				$placement,
				$session,
				$registration,
				array( 'extensions' => array( Vocab::CXM_EXT_WAIVE_RECOMMENDED => array( $what, $syntax, 'https://example.org/not-an-au' ) ) ),
				false,
				Program_Iris::block_iri( ( new Program_Repository() )->get( $cxm ), 'json' )
			)
		);

		$this->tick( '10:01:00' );
		$this->sync( $cxm, $this->learner, $registration );

		$waived = $this->statements( Vocab::WAIVED );
		$this->assertCount( 1, $waived, 'only the listed AU that is not satisfied yet' );
		Statement_Validator::validate( $waived[0], '1.0.3' );
		$this->assertSame( Au::activity_id( $what ), $waived[0]['object']['id'] );
		$this->assertSame( 'Tested Out', $waived[0]['result']['extensions'][ Vocab::EXT_REASON ] );
		$this->assertSame( $registration, $waived[0]['context']['registration'] );

		// The queued sync brings the waiver back: Sensei completes the lesson with the reason, and the block is satisfied.
		$this->tick( '10:02:00' );
		Job_Queue::run();
		$lesson = $this->cxm_lesson( $cxm, 'what-is-json' );
		$this->assertTrue( Sensei_Utils::user_completed_lesson( $lesson, $this->learner ) );
		$this->assertSame( 'Tested Out', ( new Waiver_Ledger() )->get( $this->learner, $lesson )['reason'] );
		$this->assertCount( 1, $this->about( Vocab::SATISFIED, Program_Iris::block_iri( ( new Program_Repository() )->get( $cxm ), 'json' ) ) );

		$this->sync( $cxm, $this->learner, $registration );
		$this->assertCount( 1, $this->statements( Vocab::WAIVED ), 'never waived twice' );
	}

	public function test_waive_recommended_is_ignored_when_the_program_turns_it_off() {
		$cxm = $this->import_cxm();
		update_post_meta( $cxm, '_lledger_waive_recommended', 0 );
		$registration = ( new Registration_Map() )->get_or_create( $this->learner, 'cmi5:' . $cxm );
		$this->connector_post(
			$this->au_statement(
				$this->learner,
				self::PLACED,
				Program_Iris::placement_iri( ( new Program_Repository() )->get( $cxm ) ),
				wp_generate_uuid4(),
				$registration,
				array( 'extensions' => array( Vocab::CXM_EXT_WAIVE_RECOMMENDED => array( $this->cxm_au( $cxm, 'what-is-json' ) ) ) ),
				false,
				Program_Iris::block_iri( ( new Program_Repository() )->get( $cxm ), 'json' )
			)
		);
		$this->sync( $cxm, $this->learner, $registration );
		$this->assertSame( array(), $this->statements( Vocab::WAIVED ) );
	}

	public function test_waive_recommended_applies_only_to_course_xapi_mcp_packages() {
		$a1           = $this->launch_au( self::A1 );
		$registration = $a1['session']['registration'];
		$this->post_as( $a1['client'], $this->au_statement( $this->learner, self::PLACED, self::A1, $a1['session']['session_id'], $registration, array( 'extensions' => array( Vocab::CXM_EXT_WAIVE_RECOMMENDED => array( self::A2 ) ) ), false ) );
		$this->sync( $this->t59, $this->learner, $registration );
		$this->assertSame( array(), $this->statements( Vocab::WAIVED ) );
	}

	public function test_preview_registration_gets_no_lms_statements_and_is_not_pulled_on_return() {
		$editor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		foreach ( ( new Program_Repository() )->get( $this->t59 )->course_ids as $course ) {
			$this->assertTrue( ( new Sensei_Adapter() )->enrol( $editor, $course ) );
		}
		wp_set_current_user( $editor );
		$browse  = $this->launch_au( self::A1, 'Browse' );
		$preview = $browse['session']['registration'];
		$this->assertSame( ( new Registration_Map() )->find( $editor, 'preview:' . $this->t59 . ':' . $editor ), $preview );
		$this->post_as( $browse['client'], $this->au_statement( $editor, Vocab::TERMINATED, self::A1, $browse['session']['session_id'], $preview ) );
		$this->tick( '10:00:05' );

		rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/learning-ledger/v1/cmi5/return/' . $browse['session']['session_id'] ) );
		$this->assertSame( array(), ( new Inbox_Repository() )->for_registration( $preview ), 'a Browse return pulls nothing' );

		$structure = ( new Program_Repository() )->structure( $this->t59 );
		do_action( 'learning_ledger/connector_evaluated', $this->t59, $editor, $preview, ( new Player_Evaluator() )->evaluation( $structure, $editor, $preview ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->sync( $this->t59, $editor, $preview );
		$this->assertSame( array(), $this->statements( Vocab::SATISFIED ), 'the NotApplicable block is not satisfied in a preview registration' );
		$this->assertSame( array(), $this->statements( Vocab::WAIVED ) );
		$this->assertSame( Session_Repository::TERMINATED, ( new Session_Repository() )->find( $browse['session']['session_id'] )['status'], 'its terminated still closes the session' );
		$this->assertFalse( Sensei_Utils::user_completed_lesson( $this->lessons[ self::B1 ], $editor ), 'nothing reaches Sensei' );
	}

	public function test_lrs_failure_while_writing_queues_a_retry() {
		$this->fail   = 'statements';
		$registration = ( new Registration_Map() )->get_or_create( $this->learner, 'cmi5:' . $this->t59 );
		do_action( 'learning_ledger/cmi5_registration_created', $this->learner, $this->t59, $registration ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertSame( array(), $this->statements( Vocab::SATISFIED ) );
		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::name( 'jobs' ) . ' WHERE type = %s', Player_Evaluator::SYNC_JOB ) ) );

		$this->fail = null;
		$this->tick( '10:05:00' );
		Job_Queue::run();
		$this->assertCount( 1, $this->about( Vocab::SATISFIED, self::BLOCK_B ) );
	}
}
