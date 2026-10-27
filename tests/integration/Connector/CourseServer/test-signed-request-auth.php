<?php
/**
 * Signed course-server request auth tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixtures.

use LearningLedger\Connector\CourseServer\Course_Server_Module;
use LearningLedger\Connector\CourseServer\Db_Nonce_Store;
use LearningLedger\Connector\CourseServer\Signed_Request_Auth;
use LearningLedger\Connector\CourseServer\Trusted_Keys;
use LearningLedger\Connector\Identity\Identity_Module;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Signed_Request;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;

class Signed_Request_Auth_Test extends WP_UnitTestCase {

	const PREFIX = '/wp-json/learning-ledger/v1/course-server/';

	/** @var array{public:string,secret:string} */
	private array $keys;

	private Trusted_Keys $trusted;

	private ?string $request_uri = null;

	public function set_up(): void {
		parent::set_up();
		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- restored verbatim in tear_down.
		Installer::install();
		global $wpdb, $wp_rest_server;
		foreach ( array( 'trusted_keys', 'nonces', 'ratelimit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		Clock::freeze( new DateTimeImmutable( '2026-10-27T10:00:05Z' ) );
		( new Identity_Module() )->ensure_role();
		$this->keys    = Ed25519::keypair();
		$this->trusted = new Trusted_Keys();
		$this->trusted->add( 'course-server', base64_encode( $this->keys['public'] ), Trusted_Keys::DEFAULT_CAPABILITIES, Trusted_Keys::DEFAULT_LIMITS );
		( new Course_Server_Module() )->register();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		Clock::freeze( null );
		parent::tear_down();
	}

	/**
	 * Dispatch a course-server request.
	 *
	 * @param string      $method      Method.
	 * @param string      $route       Route below course-server/, without query.
	 * @param array       $query       Query parameters sent.
	 * @param string      $body        Raw JSON body.
	 * @param array       $sign        Overrides: key_id, secret, now, path (the path and query that is signed), headers (null = unsigned).
	 *
	 * @return WP_REST_Response
	 */
	private function send( string $method, string $route, array $query = array(), string $body = '', array $sign = array() ): WP_REST_Response {
		$uri = self::PREFIX . $route . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
		$req = new WP_REST_Request( $method, '/learning-ledger/v1/course-server/' . $route );
		$req->set_query_params( $query );
		if ( '' !== $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( $body );
		}
		$headers = array_key_exists( 'headers', $sign ) ? $sign['headers'] : Signed_Request::sign(
			$sign['key_id'] ?? 'course-server',
			$sign['secret'] ?? $this->keys['secret'],
			$method,
			$sign['path'] ?? $uri,
			$body,
			$sign['now'] ?? Clock::now()->getTimestamp()
		);
		foreach ( (array) $headers as $name => $value ) {
			$req->set_header( $name, $value );
		}
		$_SERVER['REQUEST_URI'] = $uri;
		return rest_get_server()->dispatch( $req );
	}

	public function test_valid_request_passes() {
		$res = $this->send( 'GET', 'status' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertArrayHasKey( 'plugin_version', $res->get_data() );
	}

	public function test_authorize_returns_key_id() {
		$uri     = self::PREFIX . 'status';
		$req     = new WP_REST_Request( 'GET', '/learning-ledger/v1/course-server/status' );
		$headers = Signed_Request::sign( 'course-server', $this->keys['secret'], 'GET', $uri, '', Clock::now()->getTimestamp() );
		foreach ( $headers as $name => $value ) {
			$req->set_header( $name, $value );
		}
		$_SERVER['REQUEST_URI'] = $uri;
		$this->assertSame( 'course-server', ( new Signed_Request_Auth() )->authorize( $req, Trusted_Keys::READ_PROGRESS ) );
	}

	public function test_wrong_key_is_401() {
		$other = Ed25519::keypair();
		$res   = $this->send( 'GET', 'status', array(), '', array( 'secret' => $other['secret'] ) );
		$this->assertSame( 401, $res->get_status() );
	}

	public function test_unknown_and_revoked_keys_are_401() {
		$this->assertSame( 401, $this->send( 'GET', 'status', array(), '', array( 'key_id' => 'nobody' ) )->get_status() );
		$this->trusted->revoke( 'course-server' );
		$this->assertNull( $this->trusted->get( 'course-server' ) );
		$this->assertSame( 401, $this->send( 'GET', 'status' )->get_status() );
	}

	public function test_stale_timestamp_is_401() {
		$res = $this->send( 'GET', 'status', array(), '', array( 'now' => Clock::now()->getTimestamp() - 301 ) );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'lledger_signature_stale', $res->get_data()['code'] );
	}

	public function test_replayed_nonce_is_401() {
		$uri     = self::PREFIX . 'status';
		$headers = Signed_Request::sign( 'course-server', $this->keys['secret'], 'GET', $uri, '', Clock::now()->getTimestamp() );
		$this->assertSame( 200, $this->send( 'GET', 'status', array(), '', array( 'headers' => $headers ) )->get_status() );
		$res = $this->send( 'GET', 'status', array(), '', array( 'headers' => $headers ) );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'lledger_signature_replay', $res->get_data()['code'] );
	}

	public function test_altered_query_string_is_401() {
		$user = self::factory()->user->create( array( 'role' => Identity_Module::ROLE ) );
		$res  = $this->send(
			'GET',
			'learners/' . $user . '/progress',
			array( 'course' => '2' ),
			'',
			array( 'path' => self::PREFIX . 'learners/' . $user . '/progress?course=1' )
		);
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'lledger_signature_bad_signature', $res->get_data()['code'] );
	}

	public function test_altered_method_is_401() {
		$uri     = self::PREFIX . 'learners';
		$headers = Signed_Request::sign( 'course-server', $this->keys['secret'], 'GET', $uri, '', Clock::now()->getTimestamp() );
		$this->trusted->add( 'course-server', base64_encode( $this->keys['public'] ), Trusted_Keys::ALL_CAPABILITIES, Trusted_Keys::DEFAULT_LIMITS );
		$res = $this->send( 'POST', 'learners', array(), '', array( 'headers' => $headers ) );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 0, $this->learner_count() );
	}

	public function test_unsigned_request_by_an_administrator_is_401() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$res = $this->send( 'GET', 'status', array(), '', array( 'headers' => null ) );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'lledger_signature_missing_header', $res->get_data()['code'] );
	}

	public function test_missing_capability_is_403() {
		$this->trusted->add( 'course-server', base64_encode( $this->keys['public'] ), array( Trusted_Keys::ENROL ), Trusted_Keys::DEFAULT_LIMITS );
		$user = self::factory()->user->create( array( 'role' => Identity_Module::ROLE ) );
		$res  = $this->send( 'GET', 'learners/' . $user . '/progress', array( 'course' => '1' ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'lledger_capability', $res->get_data()['code'] );
	}

	public function test_create_learners_is_off_by_default_until_granted() {
		$this->assertNotContains( Trusted_Keys::CREATE_LEARNERS, $this->trusted->get( 'course-server' )['capabilities'] );
		$this->assertSame( 403, $this->send( 'POST', 'learners' )->get_status() );
		$this->assertSame( 0, $this->learner_count() );

		$this->trusted->add( 'course-server', base64_encode( $this->keys['public'] ), Trusted_Keys::ALL_CAPABILITIES, Trusted_Keys::DEFAULT_LIMITS );
		$this->assertSame( 201, $this->send( 'POST', 'learners' )->get_status() );
		$this->assertSame( 1, $this->learner_count() );
	}

	public function test_31st_learner_in_a_minute_is_429() {
		$this->trusted->add( 'course-server', base64_encode( $this->keys['public'] ), Trusted_Keys::ALL_CAPABILITIES, Trusted_Keys::DEFAULT_LIMITS );
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertSame( 201, $this->send( 'POST', 'learners' )->get_status(), 'learner ' . ( $i + 1 ) );
		}
		$res = $this->send( 'POST', 'learners' );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( '55', $res->get_headers()['Retry-After'] );
		$this->assertSame( 30, $this->learner_count() );

		Clock::freeze( Clock::now()->modify( '+60 seconds' ) );
		$this->assertSame( 201, $this->send( 'POST', 'learners' )->get_status(), 'a new minute opens the bucket' );
	}

	public function test_per_day_limit_is_429() {
		$this->trusted->add(
			'course-server',
			base64_encode( $this->keys['public'] ),
			Trusted_Keys::ALL_CAPABILITIES,
			array(
				'per_minute' => 30,
				'per_day'    => 2,
			)
		);
		$this->assertSame( 201, $this->send( 'POST', 'learners' )->get_status() );
		Clock::freeze( Clock::now()->modify( '+60 seconds' ) );
		$this->assertSame( 201, $this->send( 'POST', 'learners' )->get_status() );
		Clock::freeze( Clock::now()->modify( '+60 seconds' ) );
		$this->assertSame( 429, $this->send( 'POST', 'learners' )->get_status() );
	}

	public function test_trusted_keys_defaults_and_validation() {
		$key = $this->trusted->get( 'course-server' );
		$this->assertSame( $this->keys['public'], $key['public'] );
		$this->assertSame(
			array( 'enrol', 'read_progress', 'delete_learners', 'read_packages', 'import_packages', 'read_certificates' ),
			$key['capabilities']
		);
		$this->assertSame(
			array(
				'per_minute' => 30,
				'per_day'    => 2000,
			),
			$key['limits']
		);

		$this->expectException( Invalid_Input::class );
		$this->trusted->add( 'course-server', base64_encode( 'short' ), array(), Trusted_Keys::DEFAULT_LIMITS );
	}

	public function test_unknown_capability_is_rejected() {
		$this->expectException( Invalid_Input::class );
		$this->trusted->add( 'k2', base64_encode( $this->keys['public'] ), array( 'everything' ), Trusted_Keys::DEFAULT_LIMITS );
	}

	public function test_db_nonce_store_remembers_and_prunes() {
		$store = new Db_Nonce_Store();
		$this->assertTrue( $store->remember( 'k', 'nonce-aaaaaaaaaaaaaaaa', 600 ) );
		$this->assertFalse( $store->remember( 'k', 'nonce-aaaaaaaaaaaaaaaa', 600 ) );
		$this->assertTrue( $store->remember( 'other', 'nonce-aaaaaaaaaaaaaaaa', 600 ), 'nonces are per key' );

		Clock::freeze( Clock::now()->modify( '+601 seconds' ) );
		$this->assertTrue( $store->remember( 'k', 'nonce-aaaaaaaaaaaaaaaa', 600 ), 'an expired nonce may be seen again' );
		$this->assertFalse( $store->remember( 'k', 'nonce-aaaaaaaaaaaaaaaa', 600 ) );

		Clock::freeze( Clock::now()->modify( '+601 seconds' ) );
		$this->assertSame( 2, Db_Nonce_Store::prune() );
	}

	public function test_module_schedules_hourly_prune() {
		do_action( 'init' );
		$this->assertNotFalse( wp_next_scheduled( Course_Server_Module::PRUNE_EVENT ) );
		$this->assertSame( 'hourly', wp_get_schedule( Course_Server_Module::PRUNE_EVENT ) );
	}

	private function learner_count(): int {
		return count( get_users( array( 'role' => Identity_Module::ROLE ) ) );
	}
}
