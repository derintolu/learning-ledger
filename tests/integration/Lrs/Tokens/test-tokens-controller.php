<?php
/**
 * Child token minting API tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Authenticator;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Auth\Token_Repository;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Tokens\Tokens_Module;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;

class Tokens_Controller_Test extends WP_UnitTestCase {

	const REG     = '6f1c3c1e-9a5e-4c1b-8d4e-2b7f0c9a1d11';
	const SESSION = '0b6c7a52-5d0e-4c3a-9f1e-6a2b3c4d5e6f';

	public function set_up() {
		parent::set_up();
		Installer::install();
		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:00:00.000000', new DateTimeZone( 'UTC' ) ) );
		( new Tokens_Module() )->register();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		Clock::freeze( null );
		parent::tear_down();
	}

	private static function basic( string $user, string $pass ): string {
		return 'Basic ' . base64_encode( $user . ':' . $pass ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function actor( string $name = 'learner-1', string $home = 'https://lms.example' ): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => $home,
				'name'     => $name,
			),
		);
	}

	private static function body( array $overrides = array() ): array {
		return array_merge(
			array(
				'registration' => self::REG,
				'actor'        => self::actor(),
				'activity_id'  => 'https://lms.example/au/1',
				'session_id'   => self::SESSION,
				'launch_mode'  => 'Normal',
			),
			$overrides
		);
	}

	private function client( array $scopes = array( Scopes::TOKENS_MINT, Scopes::STATEMENTS_WRITE, Scopes::STATE ), array $overrides = array() ): array {
		return Client_Factory::create( $scopes, $overrides );
	}

	private function mint( array $c, array $body ): WP_REST_Response {
		$r = new WP_REST_Request( 'POST', '/learning-ledger/v1/tokens' );
		$r->set_header( 'Authorization', self::basic( $c['id'], $c['secret'] ) );
		$r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $r );
	}

	private function revoke( array $c, string $key ): WP_REST_Response {
		$r = new WP_REST_Request( 'DELETE', '/learning-ledger/v1/tokens/' . $key );
		$r->set_header( 'Authorization', self::basic( $c['id'], $c['secret'] ) );
		return rest_get_server()->dispatch( $r );
	}

	private function authenticate( string $key, string $secret ): Principal {
		$request = new Xapi_Request( 'POST', 'statements', array(), array( 'Authorization' => self::basic( $key, $secret ) ), '', '2.0.0', '203.0.113.9' );
		return ( new Authenticator() )->authenticate( $request, array() );
	}

	private function token_count(): int {
		return (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'tokens' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_mint_201_and_token_authenticates_an_xapi_post_for_its_actor_and_registration() {
		$c   = $this->client();
		$res = $this->mint( $c, self::body( array( 'cors_origins' => array( 'https://content.example' ) ) ) );

		$this->assertSame( 201, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( array( 'key', 'secret', 'expires_at' ), array_keys( $data ) );
		$this->assertMatchesRegularExpression( '/^tok_[0-9a-f]{24}$/', $data['key'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $data['secret'] );
		$this->assertSame( '2026-10-27T18:00:00.000000Z', $data['expires_at'] );
		$this->assertSame( 'no-store', $res->get_headers()['Cache-Control'] );

		$token = ( new Token_Repository() )->find( $data['key'] );
		$this->assertSame( $c['id'], $token->parent_client );
		$this->assertSame( self::SESSION, $token->session_id );
		$this->assertSame( 'https://lms.example/au/1', $token->activity_id );
		$this->assertSame( 'Normal', $token->launch_mode );
		$this->assertSame( array( 'https://content.example' ), $token->cors_origins );
		$this->assertTrue( $token->guard );

		$p = $this->authenticate( $data['key'], $data['secret'] );
		$this->assertTrue( $p->is_child() );
		$this->assertSame( $c['id'], $p->client_id() );
		$this->assertSame( $data['key'], $p->token()->key );
		$this->assertTrue( $p->can( Scopes::STATEMENTS_WRITE ) );
		$this->assertTrue( $p->can( Scopes::STATE ) );
		$this->assertFalse( $p->can( Scopes::PROFILE ) );
		$this->assertFalse( $p->can( Scopes::TOKENS_MINT ) );
		$this->assertFalse( $p->can( Scopes::VOID ) );
		$this->assertTrue( $p->guard_enabled() );
		$this->assertSame(
			array(
				'actor_hash'   => sha1( 'account:https://lms.example|learner-1', true ),
				'registration' => self::REG,
			),
			$p->bindings()->read_filter()
		);
		$this->assertFalse( $p->bindings()->allows_actor( LearningLedger\Xapi\Agent_Identity::from_array( self::actor( 'learner-2' ) ) ) );
		$this->assertFalse( $p->bindings()->allows_document( '', null, '00000000-0000-4000-8000-000000000000' ) );
	}

	public function test_without_scope_403() {
		$c   = $this->client( array( Scopes::ALL, Scopes::VOID ) );
		$res = $this->mint( $c, self::body() );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 0, $this->token_count() );
	}

	public function test_bad_credentials_401_with_www_authenticate() {
		$c           = $this->client();
		$c['secret'] = 'wrong';
		$res         = $this->mint( $c, self::body() );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'Basic realm="Learning Ledger"', $res->get_headers()['WWW-Authenticate'] );
		$this->assertSame( 0, $this->token_count() );
	}

	public function test_actor_or_registration_outside_binding_403() {
		$c = $this->client(
			array( Scopes::TOKENS_MINT, Scopes::STATEMENTS_WRITE ),
			array(
				'bindings' => array(
					'actor_home_page' => 'https://lms.example',
					'registration'    => self::REG,
				),
			)
		);

		$res = $this->mint( $c, self::body( array( 'actor' => self::actor( 'learner-1', 'https://other.example' ) ) ) );
		$this->assertSame( 403, $res->get_status() );

		$res = $this->mint( $c, self::body( array( 'registration' => '00000000-0000-4000-8000-000000000000' ) ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 0, $this->token_count() );

		$this->assertSame( 201, $this->mint( $c, self::body() )->get_status() );
	}

	public function test_ttl_over_eight_hours_is_capped() {
		$c   = $this->client();
		$res = $this->mint( $c, self::body( array( 'ttl' => 28801 ) ) );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( '2026-10-27T18:00:00.000000Z', $res->get_data()['expires_at'] );

		$res = $this->mint( $c, self::body( array( 'ttl' => 600 ) ) );
		$this->assertSame( '2026-10-27T10:10:00.000000Z', $res->get_data()['expires_at'] );
	}

	public function test_invalid_body_400() {
		$c = $this->client();
		foreach ( array(
			self::body( array( 'registration' => 'nope' ) ),
			self::body( array( 'actor' => 'learner-1' ) ),
			self::body( array( 'launch_mode' => 'Sideways' ) ),
			self::body( array( 'ttl' => 0 ) ),
			self::body( array( 'ttl' => '600' ) ),
			self::body( array( 'cors_origins' => 'https://content.example' ) ),
			self::body( array( 'guard' => 'no' ) ),
			array_diff_key( self::body(), array( 'session_id' => true ) ),
		) as $i => $body ) {
			$this->assertSame( 400, $this->mint( $c, $body )->get_status(), "case $i" );
		}
		$this->assertSame( 0, $this->token_count() );
	}

	public function test_guard_off_only_when_parent_guard_is_off() {
		$on  = $this->client();
		$res = $this->mint( $on, self::body( array( 'guard' => false ) ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 0, $this->token_count() );

		$off = $this->client( array( Scopes::TOKENS_MINT, Scopes::STATEMENTS_WRITE ), array( 'guard_enabled' => false ) );
		$res = $this->mint( $off, self::body( array( 'guard' => false ) ) );
		$this->assertSame( 201, $res->get_status() );
		$this->assertFalse( ( new Token_Repository() )->find( $res->get_data()['key'] )->guard );
	}

	public function test_another_client_cannot_revoke() {
		$owner  = $this->client();
		$other  = $this->client();
		$minted = $this->mint( $owner, self::body() )->get_data();

		$this->assertSame( 404, $this->revoke( $other, $minted['key'] )->get_status() );
		$this->assertNull( ( new Token_Repository() )->find( $minted['key'] )->revoked_at );
		$this->assertTrue( $this->authenticate( $minted['key'], $minted['secret'] )->is_child() );

		$this->assertSame( 404, $this->revoke( $owner, 'tok_000000000000000000000000' )->get_status() );

		$res = $this->revoke( $owner, $minted['key'] );
		$this->assertSame( 204, $res->get_status() );
		$this->assertNull( $res->get_data() );
		$this->assertNotNull( ( new Token_Repository() )->find( $minted['key'] )->revoked_at );
	}

	public function test_revoked_token_401() {
		$c      = $this->client();
		$minted = $this->mint( $c, self::body() )->get_data();
		$this->assertSame( 204, $this->revoke( $c, $minted['key'] )->get_status() );

		try {
			$this->authenticate( $minted['key'], $minted['secret'] );
			$this->fail( 'Expected Xapi_Error' );
		} catch ( Xapi_Error $e ) {
			$this->assertSame( 401, $e->status() );
		}

		$this->assertSame(
			401,
			$this->revoke(
				array(
					'id'     => $minted['key'],
					'secret' => $minted['secret'],
				),
				$minted['key']
			)->get_status()
		);
	}
}
