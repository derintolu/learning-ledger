<?php
/**
 * Clients admin REST tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Kernel\Capabilities;
use LearningLedger\Lrs\Auth\Authenticator;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Clients\Clients_Controller;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Tests\Client_Factory;

class Clients_Controller_Test extends WP_UnitTestCase {

	private int $admin;

	public function set_up() {
		parent::set_up();
		Installer::install();
		Capabilities::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'audit' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		add_action( 'rest_api_init', array( new Clients_Controller(), 'routes' ) );
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		unset( $_SERVER['HTTP_X_WP_NONCE'], $GLOBALS['wp_rest_auth_cookie'] );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
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

	private function authenticate( string $id, string $secret ) {
		$request = new Xapi_Request( 'GET', 'statements', array(), array( 'Authorization' => 'Basic ' . base64_encode( $id . ':' . $secret ) ), '', '2.0.0', '203.0.113.7' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return ( new Authenticator() )->authenticate( $request, array() );
	}

	private function audit_events(): array {
		return $GLOBALS['wpdb']->get_col( 'SELECT event FROM ' . Tables::name( 'audit' ) . ' ORDER BY id' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public function test_post_returns_a_secret_that_authenticates_and_stores_only_its_hash() {
		$res = $this->call(
			'POST',
			'/clients',
			array(
				'id'     => 'rest-one',
				'scopes' => array( 'all', 'void' ),
				'name'   => 'REST one',
			)
		);
		$this->assertSame( 201, $res->get_status() );
		$data   = $res->get_data();
		$secret = $data['secret'];
		$this->assertGreaterThanOrEqual( 43, strlen( $secret ) );
		$this->assertSame( 'rest-one', $data['id'] );
		$this->assertSame( array( 'all', 'void' ), $data['scopes'] );
		$this->assertSame( 0, preg_match( '/[0-9a-f]{64}/i', wp_json_encode( array_diff_key( $data, array( 'secret' => 1 ) ) ) ) );

		$principal = $this->authenticate( 'rest-one', $secret );
		$this->assertSame( 'rest-one', $principal->client_id() );
		$this->assertFalse( $principal->is_child() );
		$this->assertTrue( $principal->can( 'void' ) );
		$this->assertTrue( ( new Client_Repository() )->find( 'rest-one' )->secret_matches( $secret ) );

		$again = $this->call( 'GET', '/clients/rest-one' )->get_data();
		$this->assertArrayNotHasKey( 'secret', $again );
	}

	public function test_post_rejects_client_supplied_secrets_bad_input_and_duplicates() {
		$ok = array(
			'id'     => 'rest-two',
			'scopes' => array( 'all' ),
		);
		$this->assertSame( 400, $this->call( 'POST', '/clients', $ok + array( 'secret_sha256' => str_repeat( 'a', 64 ) ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/clients', array( 'id' => 'tok_x' ) + $ok )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/clients', array( 'scopes' => array( 'bogus' ) ) + $ok )->get_status() );
		$this->assertNull( ( new Client_Repository() )->find( 'rest-two' ) );
		$this->assertSame( 201, $this->call( 'POST', '/clients', $ok )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', '/clients', $ok )->get_status() );
	}

	public function test_editor_is_forbidden() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( array( array( 'GET', '/clients' ), array( 'POST', '/clients' ), array( 'DELETE', '/clients/x1' ) ) as list( $m, $p ) ) {
			$this->assertSame( 403, $this->call( $m, $p, array() )->get_status(), $m . ' ' . $p );
		}
		$this->assertSame( array(), $this->audit_events() );
	}

	public function test_signed_out_is_401() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'GET', '/clients', null, false )->get_status() );
	}

	public function test_cookie_without_nonce_is_refused() {
		// The route's own check: no Application Password and no nonce.
		$this->assertSame( 403, $this->call( 'GET', '/clients', null, false )->get_status() );
		$this->assertSame(
			403,
			$this->call(
				'POST',
				'/clients',
				array(
					'id'     => 'nonce-x',
					'scopes' => array( 'all' ),
				),
				false
			)->get_status()
		);
		$this->assertNull( ( new Client_Repository() )->find( 'nonce-x' ) );

		// The REST server's cookie step logs the user out when the nonce is missing: 401.
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$this->assertTrue( rest_get_server()->check_authentication() );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 401, $this->call( 'GET', '/clients', null, false )->get_status() );
	}

	public function test_application_password_needs_no_nonce() {
		add_filter( 'application_password_is_api_request', '__return_true' );
		$GLOBALS['wp_rest_application_password_status'] = true;
		$GLOBALS['wp_rest_application_password_uuid']   = 'uuid-1';
		$this->assertSame( 200, $this->call( 'GET', '/clients', null, false )->get_status() );
		unset( $GLOBALS['wp_rest_application_password_status'], $GLOBALS['wp_rest_application_password_uuid'] );
		remove_filter( 'application_password_is_api_request', '__return_true' );
	}

	public function test_list_and_get_show_no_hash() {
		Client_Factory::create( array( 'all' ), array( 'id' => 'listed' ) );
		$list = $this->call( 'GET', '/clients' );
		$this->assertSame( 200, $list->get_status() );
		$this->assertContains( 'listed', array_column( $list->get_data(), 'id' ) );
		$this->assertSame( 0, preg_match( '/[0-9a-f]{64}/i', wp_json_encode( $list->get_data() ) ) );
		$this->assertSame( 404, $this->call( 'GET', '/clients/nobody' )->get_status() );
	}

	public function test_patch_changes_fields_but_not_the_secret() {
		$made = Client_Factory::create( array( 'all' ), array( 'id' => 'patchme' ) );
		$res  = $this->call(
			'PATCH',
			'/clients/patchme',
			array(
				'scopes'  => array( 'state' ),
				'trusted' => true,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$c = ( new Client_Repository() )->find( 'patchme' );
		$this->assertSame( array( 'state' ), $c->scopes );
		$this->assertTrue( $c->trusted );
		$this->assertSame( 400, $this->call( 'PATCH', '/clients/patchme', array( 'secret_sha256' => str_repeat( 'b', 64 ) ) )->get_status() );
		$this->assertSame( 400, $this->call( 'PATCH', '/clients/patchme', array( 'scopes' => array( 'bogus' ) ) )->get_status() );
		$this->assertSame( 400, $this->call( 'PATCH', '/clients/patchme', array() )->get_status() );
		$this->assertSame( 404, $this->call( 'PATCH', '/clients/nobody', array( 'name' => 'x' ) )->get_status() );
		$this->assertSame( 'patchme', $this->authenticate( 'patchme', $made['secret'] )->client_id() );
	}

	public function test_delete_revokes_and_the_next_call_is_401() {
		$made = Client_Factory::create( array( 'all' ), array( 'id' => 'to-revoke' ) );
		$this->assertSame( 'to-revoke', $this->authenticate( 'to-revoke', $made['secret'] )->client_id() );
		$res = $this->call( 'DELETE', '/clients/to-revoke' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertNotNull( $res->get_data()['revoked_at'] );
		try {
			$this->authenticate( 'to-revoke', $made['secret'] );
			$this->fail( 'revoked client authenticated' );
		} catch ( Xapi_Error $e ) {
			$this->assertSame( 401, $e->status() );
		}
		$this->assertSame( 404, $this->call( 'DELETE', '/clients/nobody' )->get_status() );
	}

	public function test_audit_rows_are_written_for_the_user_without_hashes() {
		$this->call(
			'POST',
			'/clients',
			array(
				'id'     => 'audited',
				'scopes' => array( 'all' ),
			)
		);
		$this->call( 'PATCH', '/clients/audited', array( 'name' => 'Audited' ) );
		$this->call( 'DELETE', '/clients/audited' );
		$this->assertSame( array( 'client.create', 'client.update', 'client.revoke' ), $this->audit_events() );
		$rows = $GLOBALS['wpdb']->get_results( 'SELECT actor, data FROM ' . Tables::name( 'audit' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			$this->assertSame( 'user:' . $this->admin, $row['actor'] );
			$this->assertSame( 0, preg_match( '/[0-9a-f]{64}/i', $row['data'] ) );
		}
	}
}
