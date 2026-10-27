<?php
/**
 * Authenticator, Principal and client-route authentication tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Lrs\Auth\Auth_Hooks;
use LearningLedger\Lrs\Auth\Authenticator;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Client_Route_Auth;
use LearningLedger\Lrs\Auth\Origin_Index;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Auth\Token_Repository;
use LearningLedger\Lrs\Auth\Token_Spec;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;

class Authenticator_Test extends WP_UnitTestCase {

	const REG     = '6f1c3c1e-9a5e-4c1b-8d4e-2b7f0c9a1d11';
	const SESSION = '0b6c7a52-5d0e-4c3a-9f1e-6a2b3c4d5e6f';

	private array $server_backup;

	public function set_up() {
		parent::set_up();
		Installer::install();
		$this->server_backup = $_SERVER;
		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:00:00.000000', new DateTimeZone( 'UTC' ) ) );
	}

	public function tear_down() {
		Clock::freeze( null );
		$_SERVER = $this->server_backup;
		delete_option( 'lledger_rate_classes' );
		parent::tear_down();
	}

	private static function basic( string $user, string $pass ): string {
		return 'Basic ' . base64_encode( $user . ':' . $pass ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function request( array $headers = array(), string $ip = '203.0.113.7' ): Xapi_Request {
		return new Xapi_Request( 'GET', 'statements', array(), $headers, '', '2.0.0', $ip );
	}

	private function auth( Xapi_Request $r, array $server = array() ): Principal {
		return ( new Authenticator() )->authenticate( $r, $server );
	}

	private function expect_error( Xapi_Request $r, array $server = array() ): Xapi_Error {
		try {
			$this->auth( $r, $server );
		} catch ( Xapi_Error $e ) {
			return $e;
		}
		$this->fail( 'Expected Xapi_Error' );
	}

	private function mint_child( array $c, array $scopes_for_parent = array() ): array {
		$parent = ( new Client_Repository() )->find( $c['id'] );
		return ( new Token_Repository() )->mint(
			$parent,
			new Token_Spec(
				self::REG,
				array(
					'objectType' => 'Agent',
					'account'    => array(
						'homePage' => 'https://lms.example',
						'name'     => 'learner-1',
					),
				),
				'https://lms.example/au/1',
				self::SESSION,
				'Normal',
				600,
				array( 'https://content.example' )
			)
		);
	}

	public function test_valid_basic_auth_returns_principal() {
		$c = Client_Factory::create( array( Scopes::STATEMENTS_WRITE ), array( 'cors_origins' => array( 'https://a.example' ) ) );
		$p = $this->auth( self::request( array( 'Authorization' => self::basic( $c['id'], $c['secret'] ) ) ) );

		$this->assertSame( $c['id'], $p->client_id() );
		$this->assertFalse( $p->is_child() );
		$this->assertNull( $p->token() );
		$this->assertTrue( $p->can( Scopes::STATEMENTS_WRITE ) );
		$this->assertFalse( $p->can( Scopes::STATEMENTS_READ ) );
		$this->assertSame( $c['id'], $p->authority_key() );
		$this->assertSame(
			array(
				'objectType' => 'Agent',
				'account'    => array(
					'homePage' => untrailingslashit( home_url() ),
					'name'     => $c['id'],
				),
			),
			$p->authority()
		);
		$this->assertSame( array( 'https://a.example' ), $p->cors_origins() );
		$this->assertFalse( $p->guard_enabled() );
	}

	public function test_header_name_is_case_insensitive() {
		$c = Client_Factory::create( array( Scopes::STATEMENTS_READ ) );
		$p = $this->auth( self::request( array( 'authorization' => self::basic( $c['id'], $c['secret'] ) ) ) );
		$this->assertSame( $c['id'], $p->client_id() );
	}

	public function test_wrong_secret_401_with_www_authenticate() {
		$c = Client_Factory::create( array( Scopes::STATEMENTS_WRITE ) );
		$e = $this->expect_error( self::request( array( 'Authorization' => self::basic( $c['id'], 'not-the-secret' ) ) ) );

		$this->assertSame( 401, $e->status() );
		$this->assertSame( 'Basic realm="Learning Ledger"', $e->headers()['WWW-Authenticate'] );
		$this->assertStringNotContainsString( 'not-the-secret', $e->getMessage() );
		$this->assertStringNotContainsString( $c['secret'], $e->to_response()->body() );
	}

	public function test_unknown_client_and_malformed_header_401() {
		$e = $this->expect_error( self::request( array( 'Authorization' => self::basic( 'nobody', 'x' ) ) ) );
		$this->assertSame( 401, $e->status() );

		$e = $this->expect_error( self::request( array( 'Authorization' => 'Basic %%%not-base64' ) ) );
		$this->assertSame( 401, $e->status() );

		$e = $this->expect_error( self::request( array( 'Authorization' => 'Bearer abc' ) ) );
		$this->assertSame( 401, $e->status() );
		$this->assertSame( 'Basic realm="Learning Ledger"', $e->headers()['WWW-Authenticate'] );
	}

	public function test_basic_auth_read_from_redirect_http_authorization() {
		$c = Client_Factory::create( array( Scopes::STATEMENTS_READ ) );
		$p = $this->auth(
			self::request(),
			array( 'REDIRECT_HTTP_AUTHORIZATION' => self::basic( $c['id'], $c['secret'] ) )
		);
		$this->assertSame( $c['id'], $p->client_id() );
	}

	public function test_basic_auth_read_from_http_authorization_and_php_auth_user() {
		$c = Client_Factory::create( array( Scopes::STATEMENTS_READ ) );

		$p = $this->auth( self::request(), array( 'HTTP_AUTHORIZATION' => self::basic( $c['id'], $c['secret'] ) ) );
		$this->assertSame( $c['id'], $p->client_id() );

		$p = $this->auth(
			self::request(),
			array(
				'PHP_AUTH_USER' => $c['id'],
				'PHP_AUTH_PW'   => $c['secret'],
			)
		);
		$this->assertSame( $c['id'], $p->client_id() );
	}

	public function test_missing_header_401_mentions_authorization_header() {
		$e = $this->expect_error( self::request() );
		$this->assertSame( 401, $e->status() );
		$this->assertSame( 'Authorization header missing (some hosts strip it; see readme)', $e->getMessage() );
		$this->assertSame( 'Basic realm="Learning Ledger"', $e->headers()['WWW-Authenticate'] );
	}

	public function test_child_token_principal_scopes() {
		$c      = Client_Factory::create(
			array( Scopes::ALL, Scopes::VOID, Scopes::TOKENS_MINT ),
			array(
				'trusted'      => true,
				'cors_origins' => array( 'https://lms.example' ),
			)
		);
		$minted = $this->mint_child( $c );
		$p      = $this->auth( self::request( array( 'Authorization' => self::basic( $minted['key'], $minted['secret'] ) ) ) );

		$this->assertTrue( $p->is_child() );
		$this->assertSame( $c['id'], $p->client_id() );
		$this->assertSame( $minted['key'], $p->token()->key );
		$this->assertTrue( $p->can( Scopes::STATEMENTS_WRITE ) );
		$this->assertTrue( $p->can( Scopes::STATEMENTS_READ_MINE ) );
		$this->assertTrue( $p->can( Scopes::STATE ) );
		$this->assertTrue( $p->can( Scopes::PROFILE ) );
		$this->assertFalse( $p->can_write_profile() );
		$this->assertFalse( $p->can( Scopes::VOID ) );
		$this->assertFalse( $p->can( Scopes::STATEMENTS_READ ) );
		$this->assertFalse( $p->can( Scopes::ALL ) );
		$this->assertFalse( $p->can( Scopes::TOKENS_MINT ) );
		$this->assertFalse( $p->can( Scopes::ACTORS_ERASE ) );
		$this->assertStringEndsWith( '/' . $minted['key'], $p->authority()['account']['name'] );
		$this->assertSame( $c['id'] . '/' . $minted['key'], $p->authority()['account']['name'] );
		$this->assertMatchesRegularExpression( '/^tok_[0-9a-f]{24}$/', $minted['key'] );
		$this->assertSame( 'tok:' . $minted['key'], $p->authority_key() );
		$this->assertFalse( $p->trusted_for_satisfaction() );
		$this->assertTrue( $p->guard_enabled() );
		$this->assertSame( array( 'https://lms.example', 'https://content.example' ), $p->cors_origins() );
		$this->assertSame(
			array(
				'actor_hash'   => sha1( 'account:https://lms.example|learner-1', true ),
				'registration' => self::REG,
			),
			$p->bindings()->read_filter()
		);

		$full = $this->auth( self::request( array( 'Authorization' => self::basic( $c['id'], $c['secret'] ) ) ) );
		$this->assertTrue( $full->can_write_profile() );
		$this->assertTrue( $full->trusted_for_satisfaction() );
		$this->assertTrue( $full->can( Scopes::VOID ) );
	}

	public function test_child_guard_follows_parent_switch() {
		$c      = Client_Factory::create( array( Scopes::TOKENS_MINT, Scopes::STATEMENTS_WRITE ), array( 'guard_enabled' => false ) );
		$minted = $this->mint_child( $c );
		$p      = $this->auth( self::request( array( 'Authorization' => self::basic( $minted['key'], $minted['secret'] ) ) ) );
		$this->assertFalse( $p->guard_enabled() );
		$this->assertTrue( $p->can( Scopes::STATEMENTS_WRITE ) );
	}

	public function test_child_scopes_capped_by_parent() {
		$c      = Client_Factory::create( array( Scopes::TOKENS_MINT, Scopes::STATEMENTS_READ_MINE ) );
		$minted = $this->mint_child( $c );
		$p      = $this->auth( self::request( array( 'Authorization' => self::basic( $minted['key'], $minted['secret'] ) ) ) );

		$this->assertTrue( $p->is_child() );
		$this->assertTrue( $p->can( Scopes::STATEMENTS_READ_MINE ) );
		$this->assertFalse( $p->can( Scopes::STATEMENTS_WRITE ) );
		$this->assertFalse( $p->can( Scopes::STATE ) );
		$this->assertFalse( $p->can( Scopes::PROFILE ) );
		$this->assertFalse( $p->can( Scopes::TOKENS_MINT ) );
	}

	public function test_client_id_must_match_exactly() {
		$c = Client_Factory::create( array( Scopes::STATEMENTS_READ ) );
		$this->assertNull( ( new Client_Repository() )->find( strtoupper( $c['id'] ) ) );
		$this->assertSame( 401, $this->expect_error( self::request( array( 'Authorization' => self::basic( strtoupper( $c['id'] ), $c['secret'] ) ) ) )->status() );

		// Nothing is cached under the other-case key, so revoking the canonical id takes effect at once.
		$this->assertSame( $c['id'], ( new Client_Repository() )->find( $c['id'] )->id );
		$this->assertFalse( wp_cache_get( 'id:' . strtoupper( $c['id'] ), Client_Repository::CACHE_GROUP ) );
		( new Client_Repository() )->revoke( $c['id'] );
		$this->assertSame( 401, $this->expect_error( self::request( array( 'Authorization' => self::basic( $c['id'], $c['secret'] ) ) ) )->status() );
	}

	public function test_expired_and_revoked_token_401() {
		$c      = Client_Factory::create( array( Scopes::TOKENS_MINT ) );
		$minted = $this->mint_child( $c );
		$r      = self::request( array( 'Authorization' => self::basic( $minted['key'], $minted['secret'] ) ) );

		$this->assertTrue( $this->auth( $r )->is_child() );

		$e = $this->expect_error( self::request( array( 'Authorization' => self::basic( $minted['key'], 'wrong' ) ) ) );
		$this->assertSame( 401, $e->status() );

		Clock::freeze( Clock::now()->modify( '+601 seconds' ) );
		$this->assertSame( 401, $this->expect_error( $r )->status() );

		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:00:00.000000', new DateTimeZone( 'UTC' ) ) );
		$this->assertTrue( $this->auth( $r )->is_child() );
		( new Token_Repository() )->revoke( $minted['key'] );
		$this->assertSame( 401, $this->expect_error( $r )->status() );
	}

	public function test_revoked_or_expired_client_401_and_kills_its_tokens() {
		$c      = Client_Factory::create( array( Scopes::TOKENS_MINT ) );
		$minted = $this->mint_child( $c );
		( new Client_Repository() )->revoke( $c['id'] );

		$this->assertSame( 401, $this->expect_error( self::request( array( 'Authorization' => self::basic( $c['id'], $c['secret'] ) ) ) )->status() );
		$this->assertSame( 401, $this->expect_error( self::request( array( 'Authorization' => self::basic( $minted['key'], $minted['secret'] ) ) ) )->status() );

		$d = Client_Factory::create(
			array( Scopes::STATEMENTS_READ ),
			array( 'expires_at' => new DateTimeImmutable( '2026-10-27 09:59:59', new DateTimeZone( 'UTC' ) ) )
		);
		$this->assertSame( 401, $this->expect_error( self::request( array( 'Authorization' => self::basic( $d['id'], $d['secret'] ) ) ) )->status() );
	}

	public function test_eleventh_failure_in_a_minute_429() {
		$c   = Client_Factory::create( array( Scopes::STATEMENTS_READ ) );
		$bad = self::request( array( 'Authorization' => self::basic( $c['id'], 'guess' ) ), '198.51.100.9' );

		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertSame( 401, $this->expect_error( $bad )->status(), "attempt $i" );
		}

		$e = $this->expect_error( $bad );
		$this->assertSame( 429, $e->status() );
		$this->assertSame( '60', $e->headers()['Retry-After'] );

		// Blocked even with the right secret; other IPs are unaffected.
		$good = array( 'Authorization' => self::basic( $c['id'], $c['secret'] ) );
		$this->assertSame( 429, $this->expect_error( self::request( $good, '198.51.100.9' ) )->status() );
		$this->assertSame( $c['id'], $this->auth( self::request( $good, '198.51.100.10' ) )->client_id() );

		Clock::freeze( Clock::now()->modify( '+45 seconds' ) );
		$this->assertSame( '15', $this->expect_error( $bad )->headers()['Retry-After'] );

		Clock::freeze( Clock::now()->modify( '+16 seconds' ) );
		$this->assertSame( $c['id'], $this->auth( self::request( $good, '198.51.100.9' ) )->client_id() );
	}

	public function test_missing_header_does_not_count_as_failure() {
		for ( $i = 0; $i < 12; $i++ ) {
			$this->assertSame( 401, $this->expect_error( self::request( array(), '198.51.100.20' ) )->status() );
		}
	}

	public function test_rate_bucket_429_after_burst() {
		update_option( 'lledger_rate_classes', array( 'tiny' => array( 1, 3 ) ) );
		$c = Client_Factory::create( array( Scopes::STATEMENTS_READ ), array( 'rate_class' => 'tiny' ) );
		$r = self::request( array( 'Authorization' => self::basic( $c['id'], $c['secret'] ) ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( $c['id'], $this->auth( $r )->client_id() );
		}
		$e = $this->expect_error( $r );
		$this->assertSame( 429, $e->status() );
		$this->assertSame( '1', $e->headers()['Retry-After'] );

		Clock::freeze( Clock::now()->modify( '+1 second' ) );
		$this->assertSame( $c['id'], $this->auth( $r )->client_id() );
	}

	public function test_default_rate_class_allows_burst_of_100() {
		$c = Client_Factory::create( array( Scopes::STATEMENTS_READ ) );
		$r = self::request( array( 'Authorization' => self::basic( $c['id'], $c['secret'] ) ) );
		for ( $i = 0; $i < 100; $i++ ) {
			$this->auth( $r );
		}
		$this->assertSame( 429, $this->expect_error( $r )->status() );

		$u = Client_Factory::create( array( Scopes::STATEMENTS_READ ), array( 'rate_class' => 'unlimited' ) );
		$r = self::request( array( 'Authorization' => self::basic( $u['id'], $u['secret'] ) ) );
		for ( $i = 0; $i < 150; $i++ ) {
			$this->auth( $r );
		}
		$this->assertSame( $u['id'], $this->auth( $r )->client_id() );
	}

	public function test_system_principal() {
		$p = Principal::system();
		$this->assertTrue( $p->can( Scopes::STATEMENTS_WRITE ) );
		$this->assertTrue( $p->can( Scopes::ALL ) );
		$this->assertFalse( $p->can( Scopes::VOID ) );
		$this->assertFalse( $p->trusted_for_satisfaction() );
		$this->assertFalse( $p->is_child() );
		$this->assertSame( 'learning-ledger', $p->authority()['account']['name'] );
		$this->assertSame( untrailingslashit( home_url() ), $p->authority()['account']['homePage'] );
		$this->assertSame( array(), $p->bindings()->read_filter() );
	}

	public function test_reserved_client_ids_rejected() {
		foreach ( array( 'learning-ledger', 'tok_abc' ) as $id ) {
			try {
				Client_Factory::create( array( Scopes::STATEMENTS_READ ), array( 'id' => $id ) );
				$this->fail( "Expected Invalid_Input for $id" );
			} catch ( \LearningLedger\Util\Invalid_Input $e ) {
				$this->assertSame( 'reserved_id', $e->error_code() );
			}
		}
	}

	public function test_xapi_request_with_principal() {
		$c    = Client_Factory::create( array( Scopes::STATEMENTS_READ ) );
		$r    = self::request( array( 'Authorization' => self::basic( $c['id'], $c['secret'] ) ) );
		$p    = $this->auth( $r );
		$copy = $r->with_principal( $p );

		$this->assertNull( $r->principal() );
		$this->assertSame( $p, $copy->principal() );
		$this->assertNotSame( $r, $copy );
		$this->assertSame( $r->resource(), $copy->resource() );
	}

	public function test_origin_index_unions_active_clients_and_live_tokens() {
		$c = Client_Factory::create( array( Scopes::TOKENS_MINT ), array( 'cors_origins' => array( 'https://lms.example' ) ) );
		$r = Client_Factory::create( array( Scopes::STATEMENTS_READ ), array( 'cors_origins' => array( 'https://revoked.example' ) ) );
		( new Client_Repository() )->revoke( $r['id'] );
		$this->mint_child( $c );

		$all = Origin_Index::all();
		sort( $all );
		$this->assertSame( array( 'https://content.example', 'https://lms.example' ), $all );
	}

	public function test_client_route_auth_scope_and_binding() {
		$c = Client_Factory::create( array( Scopes::STATEMENTS_READ ) );
		$r = new WP_REST_Request( 'POST', '/learning-ledger/v1/tokens' );
		$r->set_header( 'Authorization', self::basic( $c['id'], $c['secret'] ) );

		$err = Client_Route_Auth::principal( $r, Scopes::TOKENS_MINT );
		$this->assertWPError( $err );
		$this->assertSame( 403, $err->get_error_data()['status'] );

		$m = Client_Factory::create( array( Scopes::TOKENS_MINT ) );
		$r->set_header( 'Authorization', self::basic( $m['id'], $m['secret'] ) );
		$p = Client_Route_Auth::principal( $r, Scopes::TOKENS_MINT );
		$this->assertInstanceOf( Principal::class, $p );
		$this->assertSame( $m['id'], $p->client_id() );

		$r->set_header( 'Authorization', self::basic( $m['id'], 'wrong' ) );
		$err = Client_Route_Auth::principal( $r, Scopes::TOKENS_MINT );
		$this->assertWPError( $err );
		$this->assertSame( 401, $err->get_error_data()['status'] );
		$this->assertSame( 'Basic realm="Learning Ledger"', $err->get_error_data()['headers']['WWW-Authenticate'] );

		$r->remove_header( 'Authorization' );
		$err = Client_Route_Auth::principal( $r, Scopes::TOKENS_MINT );
		$this->assertSame( 401, $err->get_error_data()['status'] );
		$this->assertSame( 'Authorization header missing (some hosts strip it; see readme)', $err->get_error_message() );

		( new Auth_Hooks() )->register();
		foreach ( Client_Route_Auth::PATHS as $path ) {
			$_SERVER['REQUEST_URI'] = '/wp-json' . $path;
			$this->assertFalse( apply_filters( 'application_password_is_api_request', true ), $path );
		}
		$_SERVER['REQUEST_URI'] = '/wp-json/learning-ledger/v1/badges/abc/claim-links';
		$this->assertFalse( apply_filters( 'application_password_is_api_request', true ) );
		$_SERVER['REQUEST_URI'] = '/sub/wp-json/learning-ledger/v1/Actors/Erase?x=1';
		$this->assertFalse( apply_filters( 'application_password_is_api_request', true ) );
		$_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Flearning-ledger%2Fv1%2Fcourses';
		$this->assertFalse( apply_filters( 'application_password_is_api_request', true ) );
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
		$this->assertTrue( apply_filters( 'application_password_is_api_request', true ) );
		$_SERVER['REQUEST_URI'] = '/wp-json/learning-ledger/v1/tokensx';
		$this->assertTrue( apply_filters( 'application_password_is_api_request', true ) );
		$_SERVER['REQUEST_URI'] = '/wp-json/learning-ledger/v1/status';
		$this->assertTrue( apply_filters( 'application_password_is_api_request', true ) );
	}
}
