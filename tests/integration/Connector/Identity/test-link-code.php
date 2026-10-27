<?php
/**
 * Record linking by one-time code tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Identity\Link_Code_Module;
use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Pairing\Connector_Keys;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Memory_Nonce_Store;
use LearningLedger\Crypto\Signed_Request;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

class Link_Code_Test extends WP_UnitTestCase {

	const SERVER = 'https://server.test/base/';

	/** @var array<int,array{url:string,args:array}> */
	private array $calls = array();

	/** @var array|\WP_Error */
	private $reply;

	private int $program;

	private array $actor = array(
		'objectType' => 'Agent',
		'account'    => array(
			'homePage' => 'https://server.test',
			'name'     => 'learner-42',
		),
	);

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		global $wpdb, $wp_rest_server;
		foreach ( array( 'conn_inbox', 'identity_links', 'jobs', 'ratelimit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		delete_option( Connector_Settings::OPTION );
		delete_option( Connector_Secret::OPTION );
		$this->program = self::factory()->post->create( array( 'post_type' => Program_Repository::POST_TYPE ) );
		update_post_meta( $this->program, '_lledger_course_server_url', self::SERVER );

		$this->calls = array();
		$this->reply = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'actor' => $this->actor ) ),
		);
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		( new Link_Code_Module() )->register();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	public function tear_down(): void {
		global $wp_rest_server;
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->calls[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return $this->reply;
	}

	private function post( array $body, bool $nonce = true ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/learning-ledger/v1/identity/link' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		$request->set_body( wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	private function login(): int {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		return $user;
	}

	public function test_happy_path_links_the_actor_rematches_and_queues_the_backfill() {
		$user = $this->login();
		$hash = Agent_Identity::from_array( $this->actor )->hash();
		$id   = Uuid::v4();
		( new Inbox_Repository() )->store( $this->statement( $id ), 'webhook' );
		( new Inbox_Repository() )->mark( $id, Inbox_Repository::UNMATCHED );

		$res = $this->post(
			array(
				'code'    => 'ABCD-1234',
				'program' => $this->program,
			)
		);

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['linked'] );
		$this->assertSame( $user, ( new Identity_Map() )->user_for_actor( $this->actor ) );
		$rows = ( new Inbox_Repository() )->pending( 10 );
		$this->assertCount( 1, $rows, 'the unmatched row is back to pending' );
		$this->assertSame( $hash, $rows[0]['actor_hash'] );

		global $wpdb;
		$table = Tables::name( 'jobs' );
		$jobs  = $wpdb->get_results( "SELECT type, payload FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->assertCount( 1, $jobs );
		$this->assertSame( 'identity_backfill', $jobs[0]->type );
		$payload = json_decode( $jobs[0]->payload, true );
		$this->assertSame( $user, $payload['user'] );
		$this->assertSame( $this->actor, $payload['actor'] );
	}

	public function test_the_outgoing_request_is_signed_over_the_exact_path_and_body() {
		$this->login();
		$this->post(
			array(
				'code'    => 'ABCD-1234',
				'program' => $this->program,
			)
		);

		$this->assertCount( 1, $this->calls );
		$call = $this->calls[0];
		$this->assertSame( 'https://server.test/base/link-codes/redeem', $call['url'] );
		$this->assertSame( 'POST', strtoupper( $call['args']['method'] ) );
		$this->assertSame( '{"code":"ABCD-1234"}', $call['args']['body'] );

		$public = base64_decode( Connector_Keys::public_b64(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixture.
		$kid    = Signed_Request::verify(
			$call['args']['headers'],
			'POST',
			'/base/link-codes/redeem',
			$call['args']['body'],
			static fn( string $k ) => Ed25519::key_id( $public ) === $k ? $public : null,
			new Memory_Nonce_Store(),
			time()
		);
		$this->assertSame( Connector_Keys::key_id(), $kid );
	}

	public function test_a_wrong_code_is_a_generic_400_and_links_nothing() {
		$this->login();
		$this->reply = array(
			'response' => array( 'code' => 404 ),
			'headers'  => array(),
			'body'     => '{"error":"unknown_code"}',
		);

		$res = $this->post(
			array(
				'code'    => 'WRONG-CODE',
				'program' => $this->program,
			)
		);

		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'link_failed', $res->get_data()['code'] );
		$this->assertStringNotContainsString( 'unknown_code', wp_json_encode( $res->get_data() ) );
		$this->assertSame( array(), ( new Identity_Map() )->links_for_user( get_current_user_id() ) );
	}

	public function test_an_unknown_program_or_a_program_without_a_course_server_is_the_same_400() {
		$this->login();
		$this->assertSame(
			400,
			$this->post(
				array(
					'code'    => 'ABCD',
					'program' => 999999,
				)
			)->get_status()
		);
		delete_post_meta( $this->program, '_lledger_course_server_url' );
		$res = $this->post(
			array(
				'code'    => 'ABCD',
				'program' => $this->program,
			)
		);
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( array(), $this->calls );
	}

	public function test_a_garbage_actor_in_the_answer_is_refused() {
		$this->login();
		$this->reply['body'] = wp_json_encode( array( 'actor' => array( 'objectType' => 'Agent' ) ) );
		$res                 = $this->post(
			array(
				'code'    => 'ABCD',
				'program' => $this->program,
			)
		);
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_logged_out_is_401() {
		wp_set_current_user( 0 );
		$res = $this->post(
			array(
				'code'    => 'ABCD',
				'program' => $this->program,
			)
		);
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( array(), $this->calls );
	}

	public function test_a_missing_or_bad_nonce_is_403() {
		$this->login();
		$body = array(
			'code'    => 'ABCD',
			'program' => $this->program,
		);
		$this->assertSame( 403, $this->post( $body, false )->get_status() );

		$request = new WP_REST_Request( 'POST', '/learning-ledger/v1/identity/link' );
		$request->set_header( 'X-WP-Nonce', 'badnonce00' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$this->assertSame( array(), $this->calls );
	}

	public function test_the_sixth_attempt_in_ten_minutes_is_429_and_never_reaches_the_server() {
		$this->login();
		$this->reply = array(
			'response' => array( 'code' => 404 ),
			'headers'  => array(),
			'body'     => '',
		);
		$body        = array(
			'code'    => 'NOPE',
			'program' => $this->program,
		);
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 400, $this->post( $body )->get_status() );
		}
		$sixth = $this->post( $body );
		$this->assertSame( 429, $sixth->get_status() );
		$retry = (int) $sixth->get_headers()['Retry-After'];
		$this->assertGreaterThan( 0, $retry );
		$this->assertLessThanOrEqual( 600, $retry );
		$this->assertCount( 5, $this->calls );

		$other = self::factory()->user->create();
		wp_set_current_user( $other );
		$this->assertSame( 400, $this->post( $body )->get_status(), 'the limit is per user' );
	}

	public function test_statements_are_never_written_to_the_lrs() {
		$this->login();
		$this->post(
			array(
				'code'    => 'ABCD-1234',
				'program' => $this->program,
			)
		);
		foreach ( $this->calls as $call ) {
			$this->assertStringStartsWith( self::SERVER, $call['url'] );
		}
		$this->assertCount( 1, $this->calls, 'only the course server is called while linking' );
	}

	public function test_a_record_linked_to_another_user_is_not_taken_over() {
		$owner = self::factory()->user->create();
		( new Identity_Map() )->link( Agent_Identity::from_array( $this->actor ), $owner );

		$this->login();
		$res = $this->post(
			array(
				'code'    => 'ABCD-1234',
				'program' => $this->program,
			)
		);

		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'link_failed', $res->get_data()['code'] );
		$this->assertSame( $owner, ( new Identity_Map() )->user_for_actor( $this->actor ) );
	}

	public function test_relinking_your_own_record_is_fine() {
		$user = $this->login();
		( new Identity_Map() )->link( Agent_Identity::from_array( $this->actor ), $user );
		$res = $this->post(
			array(
				'code'    => 'ABCD-1234',
				'program' => $this->program,
			)
		);
		$this->assertSame( 200, $res->get_status() );
	}

	public function test_an_actor_of_this_site_is_refused() {
		$other               = self::factory()->user->create();
		$own                 = ( new Identity_Map() )->actor_for_user( $other );
		$this->reply['body'] = wp_json_encode( array( 'actor' => $own ) );
		$this->login();
		$res = $this->post(
			array(
				'code'    => 'ABCD-1234',
				'program' => $this->program,
			)
		);
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( $other, ( new Identity_Map() )->user_for_actor( $own ) );
	}

	public function test_the_job_handler_is_registered() {
		$this->assertNotFalse( has_action( 'learning_ledger/jobs/identity_backfill' ) );
	}

	private function statement( string $id ): array {
		return array(
			'id'      => $id,
			'actor'   => $this->actor,
			'verb'    => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object'  => array(
				'objectType' => 'Activity',
				'id'         => 'https://example.org/act/1',
			),
			'context' => array( 'registration' => Uuid::v4() ),
			'stored'  => '2026-10-27T10:00:00.123456Z',
		);
	}
}
