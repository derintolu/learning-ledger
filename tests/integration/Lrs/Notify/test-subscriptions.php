<?php
/**
 * Subscription repository, admin REST, WP-CLI logic and the About extension.
 *
 * @package LearningLedger
 */

use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Notification_Signature;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Kernel\Capabilities;
use LearningLedger\Lrs\Http\Xapi_Router;
use LearningLedger\Lrs\Notify\Notify_Keys;
use LearningLedger\Lrs\Notify\Notify_Module;
use LearningLedger\Lrs\Notify\Subscription_Command;
use LearningLedger\Lrs\Notify\Subscription_Repository;
use LearningLedger\Lrs\Notify\Subscriptions_Controller;
use LearningLedger\Lrs\Statements\Stored_Statement;
use LearningLedger\Tests\Xapi_Test_Client;
use LearningLedger\Util\Invalid_Input;

class Subscriptions_Test extends WP_UnitTestCase {

	// The test site's own host: it resolves for wp_http_validate_url().
	const URL  = 'https://example.org/wp-json/learning-ledger/v1/inbox';
	const REG  = '6fbd600f-d17c-4c74-801a-2ec2e53231c7';
	const VERB = 'http://adlnet.gov/expapi/verbs/completed';

	private int $admin;

	public function set_up() {
		parent::set_up();
		Installer::install();
		Capabilities::install();
		global $wpdb;
		foreach ( array( 'subscriptions', 'outbox', 'audit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE purpose = %s', Tables::name( 'keys' ), 'notify' ) );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		add_action( 'rest_api_init', array( new Subscriptions_Controller(), 'routes' ) );
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		remove_all_filters( 'learning_ledger/about_extension' );
		remove_all_filters( 'http_request_host_is_external' );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	private static function row( array $statement ): Stored_Statement {
		$statement += array( 'id' => 'a9d6f3e1-5a8b-4c3d-9e2f-1b2c3d4e5f60' );
		return new Stored_Statement( 1, $statement['id'], '2026-10-27T10:00:00.000000Z', '2.0.0', $statement['context']['registration'] ?? null, str_repeat( "\0", 20 ), 'client:x', false, null, false, wp_json_encode( $statement ) );
	}

	private static function statement( string $home = 'https://lms.example', string $activity = 'https://lms.example/course/1/lesson/2', string $verb = self::VERB, ?string $registration = self::REG ): array {
		$st = array(
			'actor'  => array(
				'objectType' => 'Agent',
				'account'    => array(
					'homePage' => $home,
					'name'     => '42',
				),
			),
			'verb'   => array( 'id' => $verb ),
			'object' => array(
				'objectType' => 'Activity',
				'id'         => $activity,
			),
		);
		if ( null !== $registration ) {
			$st['context'] = array( 'registration' => $registration );
		}
		return $st;
	}

	private function call( string $method, string $path, ?array $body = null, bool $nonce = true ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/learning-ledger/v1' . $path );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private static function audit_events(): array {
		return $GLOBALS['wpdb']->get_col( 'SELECT event FROM ' . Tables::name( 'audit' ) . ' ORDER BY id' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public function test_filters_match_and_miss() {
		$repo = new Subscription_Repository();
		$all  = $repo->create( self::URL, array() );
		$home = $repo->create( self::URL, array( 'actor_home_page' => 'https://lms.example/' ) );
		$pref = $repo->create( self::URL, array( 'activity_prefix' => 'https://lms.example/course/1/' ) );
		$verb = $repo->create( self::URL, array( 'verbs' => array( 'http://adlnet.gov/expapi/verbs/passed', self::VERB ) ) );
		$reg  = $repo->create( self::URL, array( 'registrations' => array( strtoupper( self::REG ) ) ) );
		$both = $repo->create(
			self::URL,
			array(
				'actor_home_page' => 'https://lms.example',
				'verbs'           => array( 'http://adlnet.gov/expapi/verbs/passed' ),
			)
		);

		$this->assertSame( array( $all, $home, $pref, $verb, $reg ), $repo->matching( self::row( self::statement() ) ) );

		$this->assertSame( array( $all, $pref, $verb, $reg ), ( new Subscription_Repository() )->matching( self::row( self::statement( 'https://other.example' ) ) ) );
		$this->assertSame( array( $all, $home, $verb, $reg ), ( new Subscription_Repository() )->matching( self::row( self::statement( 'https://lms.example', 'https://lms.example/course/10/x' ) ) ) );
		$this->assertSame( array( $all, $home, $pref, $reg ), ( new Subscription_Repository() )->matching( self::row( self::statement( 'https://lms.example', 'https://lms.example/course/1/a', 'http://adlnet.gov/expapi/verbs/attempted' ) ) ) );
		$this->assertSame( array( $all, $home, $pref, $verb ), ( new Subscription_Repository() )->matching( self::row( self::statement( 'https://lms.example', 'https://lms.example/course/1/a', self::VERB, null ) ) ) );
		$this->assertSame(
			array( $all, $home, $pref, $verb, $reg, $both ),
			( new Subscription_Repository() )->matching( self::row( self::statement( 'https://lms.example', 'https://lms.example/course/1/a', 'http://adlnet.gov/expapi/verbs/passed' ) ) )
		);

		// A non-Activity object (a voiding StatementRef) is not held back by the activity prefix.
		$void           = self::statement();
		$void['object'] = array(
			'objectType' => 'StatementRef',
			'id'         => 'c3a5f1e2-0b1c-4d2e-8f30-415263748596',
		);
		$this->assertContains( $pref, ( new Subscription_Repository() )->matching( self::row( $void ) ) );

		// An mbox actor never matches a homePage filter.
		$mbox          = self::statement();
		$mbox['actor'] = array( 'mbox' => 'mailto:a@lms.example' );
		$this->assertNotContains( $home, ( new Subscription_Repository() )->matching( self::row( $mbox ) ) );
	}

	public function test_create_validates_url_and_filters() {
		$repo = new Subscription_Repository();
		$bad  = array(
			array( 'ftp://connector.example/inbox', array() ),
			array( 'https:///inbox', array() ),
			array( 'not a url', array() ),
			array( 'https://user:pass@connector.example/inbox', array() ),
			array( self::URL, array( 'bogus' => 'x' ) ),
			array( self::URL, array( 'verbs' => 'http://adlnet.gov/expapi/verbs/completed' ) ),
			array( self::URL, array( 'registrations' => array( 'not-a-uuid' ) ) ),
			array( self::URL, array( 'actor_home_page' => 'no scheme' ) ),
			array( self::URL, array( 'activity_prefix' => array( 'x' ) ) ),
			array( self::URL, array( 'activity_prefix' => 'course-1' ) ),
		);
		foreach ( $bad as list( $url, $filters ) ) {
			try {
				$repo->create( $url, $filters );
				$this->fail( 'Accepted ' . $url . ' ' . wp_json_encode( $filters ) );
			} catch ( Invalid_Input $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->assertSame( array(), $repo->all() );

		$id  = $repo->create( self::URL, array( 'registrations' => array( strtoupper( self::REG ) ) ) );
		$sub = $repo->find( $id );
		$this->assertSame( self::URL, $sub['url'] );
		$this->assertSame( array( 'registrations' => array( self::REG ) ), $sub['filters'] );
		$this->assertSame( Subscription_Repository::ACTIVE, $sub['status'] );
		$this->assertNull( $sub['failing_since'] );
		$this->assertSame( 0, $sub['cursor'] );
	}

	public function test_private_and_link_local_targets_are_refused_unless_allowed() {
		$repo = new Subscription_Repository();
		foreach ( array( 'https://169.254.169.254/latest/meta-data', 'https://10.0.0.5/inbox', 'https://192.168.1.2/inbox', 'https://127.0.0.1:8443/inbox', 'https://[::1]/inbox', 'https://[fd00::1]/inbox', 'https://no-such-host.invalid/inbox', 'https://example.org:8443/inbox' ) as $url ) {
			try {
				$repo->create( $url, array() );
				$this->fail( 'Accepted ' . $url );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'unsafe_url', $e->error_code(), $url );
			}
		}

		add_filter( 'http_request_host_is_external', static fn( $external, $host ) => '10.0.0.5' === $host ? true : $external, 10, 2 );
		$this->assertGreaterThan( 0, $repo->create( 'https://10.0.0.5/inbox', array() ) );
	}

	public function test_mark_failing_active_and_delete() {
		$repo = new Subscription_Repository();
		$id   = $repo->create( self::URL, array() );
		$repo->mark_failing( $id );
		$this->assertSame( Subscription_Repository::FAILING, $repo->find( $id )['status'] );
		$repo->mark_active( $id );
		$this->assertSame( Subscription_Repository::ACTIVE, $repo->find( $id )['status'] );
		$this->assertNull( $repo->find( $id )['failing_since'] );

		global $wpdb;
		$wpdb->insert(
			Tables::name( 'outbox' ),
			array(
				'subscription_id' => $id,
				'seq'             => 1,
				'status'          => 'pending',
				'attempts'        => 0,
				'next_attempt_at' => '2026-10-27 10:00:00.000000',
			)
		);
		$this->assertTrue( $repo->delete( $id ) );
		$this->assertNull( $repo->find( $id ) );
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'outbox' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertFalse( $repo->delete( $id ) );
	}

	public function test_rest_create_list_delete() {
		$res = $this->call(
			'POST',
			'/subscriptions',
			array(
				'url'     => self::URL,
				'filters' => array(
					'actor_home_page' => 'https://lms.example',
					'verbs'           => array( self::VERB ),
				),
			)
		);
		$this->assertSame( 201, $res->get_status() );
		$data = $res->get_data();
		$this->assertIsInt( $data['id'] );
		$this->assertSame( self::URL, $data['url'] );
		$this->assertSame( 'active', $data['status'] );
		$this->assertSame( array( self::VERB ), (array) $data['filters']->verbs );

		$this->assertSame( 400, $this->call( 'POST', '/subscriptions', array( 'url' => 'ftp://x.example/' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/subscriptions', array( 'url' => 'https://169.254.169.254/' ) )->get_status() );

		$list = $this->call( 'GET', '/subscriptions' );
		$this->assertSame( 200, $list->get_status() );
		$this->assertCount( 1, $list->get_data() );
		$this->assertSame( $data['id'], $list->get_data()[0]['id'] );

		$this->assertSame( 200, $this->call( 'DELETE', '/subscriptions/' . $data['id'] )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/subscriptions/' . $data['id'] )->get_status() );
		$this->assertSame( array(), $this->call( 'GET', '/subscriptions' )->get_data() );
		$this->assertSame( array( 'subscription.create', 'subscription.delete' ), self::audit_events() );
	}

	public function test_rest_requires_manage_and_nonce() {
		$this->assertSame( 403, $this->call( 'GET', '/subscriptions', null, false )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( array( array( 'GET', '/subscriptions' ), array( 'POST', '/subscriptions' ), array( 'DELETE', '/subscriptions/1' ) ) as list( $m, $p ) ) {
			$this->assertSame( 403, $this->call( $m, $p, array( 'url' => self::URL ) )->get_status(), $m . ' ' . $p );
		}
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'GET', '/subscriptions', null, false )->get_status() );
		$this->assertSame( array(), ( new Subscription_Repository() )->all() );
	}

	public function test_cli_create_list_delete() {
		$id  = Subscription_Command::create_subscription(
			array(
				'target'          => self::URL,
				'actor-home-page' => 'https://lms.example',
				'activity-prefix' => 'https://lms.example/course/',
				'verb'            => self::VERB . ',http://adlnet.gov/expapi/verbs/passed',
				'registration'    => self::REG,
			)
		);
		$sub = ( new Subscription_Repository() )->find( $id );
		$this->assertSame(
			array(
				'actor_home_page' => 'https://lms.example',
				'activity_prefix' => 'https://lms.example/course/',
				'verbs'           => array( self::VERB, 'http://adlnet.gov/expapi/verbs/passed' ),
				'registrations'   => array( self::REG ),
			),
			$sub['filters']
		);
		$rows = Subscription_Command::rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( $id, $rows[0]['id'] );
		$this->assertSame( self::URL, $rows[0]['url'] );
		$this->assertSame( 'active', $rows[0]['status'] );

		Subscription_Command::delete_subscription( (string) $id );
		$this->assertSame( array(), Subscription_Command::rows() );
		$this->expectException( Invalid_Input::class );
		Subscription_Command::delete_subscription( (string) $id );
	}

	public function test_notify_key_is_created_once_sealed_and_signs() {
		$a = Notify_Keys::active();
		$b = Notify_Keys::active();
		$this->assertSame( $a, $b );
		$this->assertSame( 32, strlen( $a['public'] ) );
		$this->assertSame( Ed25519::key_id( $a['public'] ), $a['kid'] );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE purpose = %s', Tables::name( 'keys' ), 'notify' ), ARRAY_A );
		$this->assertSame( $a['kid'], $row['kid'] );
		$this->assertNotEmpty( $row['secret_sealed'] );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE purpose = %s', Tables::name( 'keys' ), 'notify' ) ) );

		$sig = Notify_Keys::sign( '{"a":1}', 1793095200 );
		$this->assertTrue( Notification_Signature::verify( $sig, '1793095200', '{"a":1}', $a['public'] ) );
		$this->assertFalse( Notification_Signature::verify( $sig, '1793095201', '{"a":1}', $a['public'] ) );
	}

	public function test_about_extension_publishes_the_notification_key() {
		( new Notify_Module() )->register();
		( new Xapi_Router() )->register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$body = json_decode( Xapi_Test_Client::request( 'GET', 'about' )->body(), true );
		$ext  = $body['extensions']['https://github.com/derintolu/learning-ledger']['notifications'];
		$key  = Notify_Keys::active();
		$this->assertSame( $key['kid'], $ext['kid'] );
		$this->assertSame( $key['public'], base64_decode( $ext['public_key'], true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertSame( rest_url( 'learning-ledger/v1/subscriptions' ), $ext['subscriptions_url'] );
	}
}
