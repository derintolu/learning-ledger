<?php
/**
 * Course-server learner route tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixtures.

use LearningLedger\Connector\CourseServer\Course_Server_Module;
use LearningLedger\Connector\CourseServer\Learners_Controller;
use LearningLedger\Connector\CourseServer\Trusted_Keys;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Identity\Identity_Module;
use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Guarded_Adapter;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Outbox\Outbox_Repository;
use LearningLedger\Connector\Pairing\Connector_Keys;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Pairing\Pairing_Module;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Signed_Request;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Tests\Fake_Adapter;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

class Learners_Controller_Test extends WP_UnitTestCase {

	const PREFIX = '/wp-json/learning-ledger/v1/course-server/';

	/** @var array{public:string,secret:string} */
	private array $keys;

	private Fake_Adapter $fake;

	private int $course;

	private int $l1;

	private int $l2;

	private int $l3;

	/** @var array[] */
	private array $requests = array();

	private int $lrs_status = 200;

	private ?string $request_uri = null;

	public function set_up(): void {
		parent::set_up();
		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- restored verbatim in tear_down.
		Installer::install();
		global $wpdb, $wp_rest_server;
		foreach ( array( 'trusted_keys', 'nonces', 'ratelimit', 'identity_links', 'waivers', 'applied', 'registrations', 'conn_outbox', 'conn_inbox', 'jobs' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) );
		}
		delete_option( Connector_Settings::OPTION );
		delete_option( Connector_Secret::OPTION );
		delete_option( Connector_Keys::OPTION );
		Connector_Settings::update(
			array(
				'lrs_url'   => 'https://lrs.example.test/xapi/',
				'api_base'  => 'https://lrs.example.test/wp-json/learning-ledger/v1/',
				'client_id' => 'connector-site',
			)
		);
		Connector_Secret::generate();
		Clock::freeze( new DateTimeImmutable( '2026-10-27T10:00:05Z' ) );
		( new Identity_Module() )->ensure_role();
		$programs = new Program_Module();
		$programs->register_post_type();
		$programs->register_meta();

		$this->keys = Ed25519::keypair();
		( new Trusted_Keys() )->add( 'course-server', base64_encode( $this->keys['public'] ), Trusted_Keys::ALL_CAPABILITIES, Trusted_Keys::DEFAULT_LIMITS );

		$this->fake   = new Fake_Adapter();
		$this->course = $this->fake->upsert_course( new Course_Spec( 'c1', 'Course', '', 'publish', array() ) );
		$this->l1     = $this->fake->upsert_lesson( $this->course, new Lesson_Spec( 'l1', 'One', '', 1, 'publish', array() ) );
		$this->l2     = $this->fake->upsert_lesson( $this->course, new Lesson_Spec( 'l2', 'Two', '', 2, 'publish', array() ) );
		$this->l3     = $this->fake->upsert_lesson( $this->course, new Lesson_Spec( 'l3', 'Three', '', 3, 'publish', array() ) );
		$guarded      = new Guarded_Adapter( $this->fake );
		$course       = $this->course;
		( new Course_Server_Module( static fn( int $id ) => $id === $course ? $guarded : null ) )->register();
		( new Pairing_Module() )->register();

		$this->requests   = array();
		$this->lrs_status = 200;
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	public function tear_down(): void {
		global $wp_rest_server;
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		Clock::freeze( null );
		parent::tear_down();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => 200 === $this->lrs_status ? '{"statements":3}' : '{"error":"down"}',
			'response' => array(
				'code'    => $this->lrs_status,
				'message' => 'X',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function send( string $method, string $route, array $query = array(), ?array $json = null ): WP_REST_Response {
		$uri  = self::PREFIX . $route . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
		$body = null === $json ? '' : (string) wp_json_encode( $json );
		$req  = new WP_REST_Request( $method, '/learning-ledger/v1/course-server/' . $route );
		$req->set_query_params( $query );
		if ( '' !== $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( $body );
		}
		foreach ( Signed_Request::sign( 'course-server', $this->keys['secret'], $method, $uri, $body, Clock::now()->getTimestamp() ) as $name => $value ) {
			$req->set_header( $name, $value );
		}
		$_SERVER['REQUEST_URI'] = $uri;
		return rest_get_server()->dispatch( $req );
	}

	private function create_learner(): array {
		$res = $this->send( 'POST', 'learners' );
		$this->assertSame( 201, $res->get_status() );
		return $res->get_data();
	}

	private function count_rows( string $table, int $user ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::name( $table ) . ' WHERE user_id = %d', $user ) );
	}

	private function outbox_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'conn_outbox' ) );
	}

	private function statement( array $actor ): array {
		return array(
			'id'      => Uuid::v4(),
			'actor'   => $actor,
			'verb'    => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object'  => array(
				'objectType' => 'Activity',
				'id'         => 'https://example.org/act/1',
			),
			'context' => array( 'registration' => Uuid::v4() ),
			'stored'  => '2026-10-27T10:00:00.123456Z',
		);
	}

	public function test_created_learner_has_no_email_and_only_the_learner_role() {
		$data = $this->create_learner();
		$user = get_userdata( $data['user_id'] );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( '', $user->user_email );
		$this->assertSame( array( Identity_Module::ROLE ), array_values( $user->roles ) );
		$this->assertSame( ( new Identity_Map() )->actor_for_user( $data['user_id'] ), $data['actor'] );
		$this->assertSame( untrailingslashit( home_url() ), $data['actor']['account']['homePage'] );
	}

	public function test_enrol_and_progress_return_completed_and_tested_out_lessons() {
		$user = $this->create_learner()['user_id'];

		$res = $this->send( 'POST', 'learners/' . $user . '/enrolments', array(), array( 'course' => $this->course ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['enrolled'] );

		$this->fake->apply( new Result( Result::LESSON_COMPLETED, $user, new Lms_Object( 'lesson', $this->l1 ), Uuid::v4(), Uuid::v4() ) );
		$this->fake->apply( new Result( Result::LESSON_WAIVED, $user, new Lms_Object( 'lesson', $this->l2 ), Uuid::v4(), Uuid::v4(), null, 'Tested Out' ) );

		$res = $this->send( 'GET', 'learners/' . $user . '/progress', array( 'course' => (string) $this->course ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame(
			array(
				'courses'           => array(
					array(
						'course'           => $this->course,
						'completed'        => array( $this->l1, $this->l2 ),
						'tested_out'       => array( $this->l2 ),
						'course_completed' => false,
						'certificate'      => null,
					),
				),
				'program_satisfied' => null,
			),
			$res->get_data()
		);
	}

	public function test_progress_for_a_program_covers_its_courses_and_satisfaction() {
		$user    = $this->create_learner()['user_id'];
		$program = self::factory()->post->create(
			array(
				'post_type'   => Program_Repository::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $program, '_lledger_courses', array( $this->course ) );
		$this->fake->apply( new Result( Result::LESSON_COMPLETED, $user, new Lms_Object( 'lesson', $this->l3 ), Uuid::v4(), Uuid::v4() ) );

		$data = $this->send( 'GET', 'learners/' . $user . '/progress', array( 'program' => (string) $program ) )->get_data();
		$this->assertCount( 1, $data['courses'] );
		$this->assertSame( array( $this->l3 ), $data['courses'][0]['completed'] );
		$this->assertFalse( $data['program_satisfied'] );

		( new Apply_Ledger() )->record( $user, new Lms_Object( 'program', $program ), Result::COURSE_SATISFIED, Uuid::v4() );
		$data = $this->send( 'GET', 'learners/' . $user . '/progress', array( 'program' => (string) $program ) )->get_data();
		$this->assertTrue( $data['program_satisfied'] );
	}

	public function test_progress_needs_a_known_program_or_course() {
		$user = $this->create_learner()['user_id'];
		$this->assertSame( 400, $this->send( 'GET', 'learners/' . $user . '/progress' )->get_status() );
		$this->assertSame( 404, $this->send( 'GET', 'learners/' . $user . '/progress', array( 'course' => '999999' ) )->get_status() );
		$this->assertSame( 404, $this->send( 'GET', 'learners/' . $user . '/progress', array( 'program' => '999999' ) )->get_status() );
	}

	public function test_enrol_unknown_learner_or_course_is_404() {
		$this->assertSame( 404, $this->send( 'POST', 'learners/999999/enrolments', array(), array( 'course' => $this->course ) )->get_status() );
		$user = $this->create_learner()['user_id'];
		$this->assertSame( 404, $this->send( 'POST', 'learners/' . $user . '/enrolments', array(), array( 'course' => 999999 ) )->get_status() );
	}

	public function test_delete_removes_all_connector_rows_and_calls_the_lrs_erase() {
		$data   = $this->create_learner();
		$user   = $data['user_id'];
		$actor  = $data['actor'];
		$linked = array(
			'objectType' => 'Agent',
			'mbox'       => 'mailto:linked@example.org',
		);
		( new Identity_Map() )->link( Agent_Identity::from_array( $linked ), $user );
		( new Waiver_Ledger() )->record( $user, $this->l2, Uuid::v4(), Uuid::v4(), 'Tested Out', false );
		( new Apply_Ledger() )->record( $user, new Lms_Object( 'lesson', $this->l1 ), Result::LESSON_COMPLETED, Uuid::v4() );
		( new Registration_Map() )->get_or_create( $user, 'program:1' );
		( new Outbox_Repository() )->add( $this->statement( $actor ), $this->course );
		$other_actor = ( new Identity_Map() )->actor_for_user( self::factory()->user->create() );
		( new Outbox_Repository() )->add( $this->statement( $other_actor ), $this->course );
		( new Inbox_Repository() )->store( $this->statement( $actor ), 'webhook' );
		( new Inbox_Repository() )->store( $this->statement( $linked ), 'webhook' );

		$res = $this->send( 'DELETE', 'learners/' . $user );

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['deleted'] );
		$this->assertSame( 'erased', $res->get_data()['lrs_erase'] );
		$this->assertFalse( get_userdata( $user ) );
		global $wpdb;
		foreach ( array( 'identity_links', 'waivers', 'applied', 'registrations' ) as $t ) {
			$this->assertSame( 0, $this->count_rows( $t, $user ), $t );
		}
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'conn_inbox' ) ), 'inbox rows of both actors' );
		$this->assertSame( 1, $this->outbox_count(), 'only the other learner\'s outbox row is left' );

		$erase = array_values( array_filter( $this->requests, static fn( $r ) => str_ends_with( $r['url'], '/actors/erase' ) ) );
		$this->assertCount( 2, $erase );
		$this->assertSame( 'https://lrs.example.test/wp-json/learning-ledger/v1/actors/erase', $erase[0]['url'] );
		$this->assertSame( 'POST', $erase[0]['args']['method'] );
		$this->assertSame(
			array(
				'actor'        => $actor,
				'revokeBadges' => true,
			),
			json_decode( $erase[0]['args']['body'], true )
		);
		$this->assertSame(
			array(
				'actor'        => $linked,
				'revokeBadges' => true,
			),
			json_decode( $erase[1]['args']['body'], true )
		);
	}

	public function test_delete_with_revoke_badges_false_asks_the_lrs_to_keep_claimed_badges() {
		$data = $this->create_learner();
		$res  = $this->send( 'DELETE', 'learners/' . $data['user_id'], array( 'revokeBadges' => 'false' ) );
		$this->assertSame( 200, $res->get_status() );
		$erase = array_values( array_filter( $this->requests, static fn( $r ) => str_ends_with( $r['url'], '/actors/erase' ) ) );
		$this->assertSame(
			array(
				'actor'        => $data['actor'],
				'revokeBadges' => false,
			),
			json_decode( $erase[0]['args']['body'], true )
		);

		// A queued retry keeps the choice.
		$queued           = $this->create_learner();
		$this->lrs_status = 503;
		$this->assertSame( 'queued', $this->send( 'DELETE', 'learners/' . $queued['user_id'], array( 'revokeBadges' => 'false' ) )->get_data()['lrs_erase'] );
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( 'jobs' ) . ' WHERE type = %s', Learners_Controller::ERASE_JOB ) );
		$this->assertFalse( json_decode( $job->payload, true )['revokeBadges'] );
		$this->lrs_status = 200;
		$this->requests   = array();
		do_action( 'learning_ledger/jobs/' . Learners_Controller::ERASE_JOB, json_decode( $job->payload, true ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertFalse( json_decode( $this->requests[0]['args']['body'], true )['revokeBadges'] );
	}

	public function test_delete_with_any_other_revoke_badges_value_is_400_and_deletes_nothing() {
		$data = $this->create_learner();
		foreach ( array( 'no', '0', 'FALSE', '' ) as $value ) {
			$res = $this->send( 'DELETE', 'learners/' . $data['user_id'], array( 'revokeBadges' => $value ) );
			$this->assertSame( 400, $res->get_status(), $value );
			$this->assertSame( 'revokeBadges', $res->get_data()['data']['field'] );
		}
		$this->assertInstanceOf( WP_User::class, get_userdata( $data['user_id'] ) );
		$this->assertSame( 'erased', $this->send( 'DELETE', 'learners/' . $data['user_id'], array( 'revokeBadges' => 'true' ) )->get_data()['lrs_erase'] );
	}

	public function test_failed_lrs_erase_is_queued_and_reported() {
		$data             = $this->create_learner();
		$this->lrs_status = 503;

		$res = $this->send( 'DELETE', 'learners/' . $data['user_id'] );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'queued', $res->get_data()['lrs_erase'] );
		$this->assertFalse( get_userdata( $data['user_id'] ) );
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( 'jobs' ) . ' WHERE type = %s', Learners_Controller::ERASE_JOB ) );
		$this->assertNotNull( $job );
		$this->assertSame(
			array(
				'actor'        => $data['actor'],
				'revokeBadges' => true,
			),
			json_decode( $job->payload, true )
		);

		$this->lrs_status = 200;
		$this->requests   = array();
		do_action( 'learning_ledger/jobs/' . Learners_Controller::ERASE_JOB, array( 'actor' => $data['actor'] ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertCount( 1, $this->requests, 'the queued job calls the LRS again' );

		$this->lrs_status = 500;
		$this->expectException( RuntimeException::class );
		do_action( 'learning_ledger/jobs/' . Learners_Controller::ERASE_JOB, array( 'actor' => $data['actor'] ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	public function test_deleting_an_administrator_is_403() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$res   = $this->send( 'DELETE', 'learners/' . $admin );
		$this->assertSame( 403, $res->get_status() );
		$this->assertInstanceOf( WP_User::class, get_userdata( $admin ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_deleting_a_learner_with_a_second_role_is_403() {
		$user = $this->create_learner()['user_id'];
		( new WP_User( $user ) )->add_role( 'subscriber' );
		$this->assertSame( 403, $this->send( 'DELETE', 'learners/' . $user )->get_status() );
		$this->assertInstanceOf( WP_User::class, get_userdata( $user ) );
	}

	public function test_deleting_a_learner_with_a_directly_granted_cap_is_403() {
		$user = $this->create_learner()['user_id'];
		( new WP_User( $user ) )->add_cap( 'edit_posts' );
		$this->assertSame( 403, $this->send( 'DELETE', 'learners/' . $user )->get_status() );
		$this->assertInstanceOf( WP_User::class, get_userdata( $user ) );
	}

	public function test_status_route_returns_the_secret_hash() {
		$res = $this->send( 'GET', 'status' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( Connector_Secret::sha256_hex(), $res->get_data()['connector']['secret_sha256'] );
	}
}
