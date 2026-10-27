<?php
/**
 * Status route tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Kernel\Capabilities;
use LearningLedger\Kernel\Roles;
use LearningLedger\Kernel\Status_Route;

class Status_Route_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Capabilities::install();
		update_option( Roles::OPTION, array( 'connector' ) );
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_X_WP_NONCE'], $GLOBALS['wp_rest_auth_cookie'] );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	private function get(): WP_REST_Response {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/learning-ledger/v1/status' ) );
		$this->assertSame( 200, $response->get_status() );
		return $response;
	}

	private function keys_recursive( array $data ): array {
		$keys = array();
		foreach ( $data as $k => $v ) {
			$keys[] = (string) $k;
			if ( is_array( $v ) ) {
				$keys = array_merge( $keys, $this->keys_recursive( $v ) );
			}
		}
		return $keys;
	}

	public function test_status_public_payload() {
		$data = $this->get()->get_data();

		$this->assertSame( LEARNING_LEDGER_VERSION, $data['plugin_version'] );
		$this->assertSame( get_bloginfo( 'version' ), $data['wp_version'] );
		$this->assertSame( PHP_VERSION, $data['php_version'] );
		$this->assertSame( array( 'connector' ), $data['roles'] );
		$this->assertContains( $data['sodium'], array( 'native', 'compat' ) );
		$this->assertArrayHasKey( 'schema_version', $data );
		foreach ( $this->keys_recursive( $data ) as $key ) {
			$this->assertStringNotContainsStringIgnoringCase( 'secret', $key );
		}
	}

	/**
	 * Run the REST server's own authentication step the way a real request does.
	 */
	private function authenticate_cookie( ?string $nonce ): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		if ( null !== $nonce ) {
			$_SERVER['HTTP_X_WP_NONCE'] = $nonce;
		}
		$this->assertTrue( rest_get_server()->check_authentication() );
	}

	public function test_status_privileged_and_filter() {
		$seen = array();
		add_filter(
			'learning_ledger/status',
			static function ( array $payload, bool $privileged ) use ( &$seen ) {
				$seen[] = $privileged;
				if ( $privileged ) {
					$payload['x'] = 'privileged-only';
				}
				return $payload;
			},
			10,
			2
		);

		$this->assertArrayNotHasKey( 'x', $this->get()->get_data(), 'anonymous' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->authenticate_cookie( null );
		$this->assertSame( 0, get_current_user_id(), 'cookie without nonce is treated as logged out' );
		$this->assertArrayNotHasKey( 'x', $this->get()->get_data(), 'admin without nonce' );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->authenticate_cookie( wp_create_nonce( 'wp_rest' ) );
		$this->assertArrayNotHasKey( 'x', $this->get()->get_data(), 'subscriber with nonce' );

		wp_set_current_user( $admin );
		$this->authenticate_cookie( wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( $admin, get_current_user_id() );
		$this->assertSame( 'privileged-only', $this->get()->get_data()['x'], 'admin with nonce' );

		$this->assertSame( array( false, false, false, true ), $seen );
		$this->assertSame( 'privileged-only', Status_Route::payload( true )['x'] );
		$this->assertArrayNotHasKey( 'x', Status_Route::payload( false ) );
	}
}
