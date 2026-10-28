<?php
/**
 * cmi5 fetch route tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- decoding the returned credential; table reads.

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Token_Repository;
use LearningLedger\Player\Launch\Session_Repository;
use LearningLedger\Tests\Xapi_Test_Client;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;

require_once __DIR__ . '/test-launch.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test class.
class Fetch_Test extends Lledger_Launch_Test_Case {

	const USED = array(
		'error-code' => '1',
		'error-text' => 'Already in use or expired',
	);

	private static function credential( WP_REST_Response $res ): array {
		$token = $res->get_data()['auth-token'];
		return explode( ':', (string) base64_decode( $token, true ), 2 );
	}

	public function test_fetch_works_once_then_answers_error_code_1() {
		$token   = self::fetch_token( $this->launch_ok()['launch_url'] );
		$session = $this->session_for( $this->learner );

		$res = $this->fetch( $token );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'auth-token' ), array_keys( $res->get_data() ) );
		$this->assertSame( 'no-store', $res->get_headers()['Cache-Control'] );
		list( $key, $secret ) = self::credential( $res );

		$minted = ( new Token_Repository() )->find( $key );
		$this->assertNotNull( $minted );
		$this->assertTrue( $minted->secret_matches( $secret ) );
		$this->assertSame( self::CONNECTOR, $minted->parent_client );
		$this->assertSame( $session['registration'], $minted->registration );
		$this->assertSame( $this->actor( $this->learner ), $minted->actor );
		$this->assertSame( Au::activity_id( self::AU_ID ), $minted->activity_id );
		$this->assertSame( $session['session_id'], $minted->session_id );
		$this->assertSame( 'Normal', $minted->launch_mode );
		$this->assertSame( array( 'https://au.example.net' ), $minted->cors_origins );
		$this->assertTrue( $minted->guard );
		$this->assertSame( '2026-10-27 18:00:00', $minted->expires_at->format( 'Y-m-d H:i:s' ), '8 hours' );
		$this->assertSame( $key, ( new Session_Repository() )->find( $session['session_id'] )['token_key'] );

		$again = $this->fetch( $token );
		$this->assertSame( self::USED, $again->get_data() );
		$this->assertSame( 1, $this->count_tokens(), 'no second token' );
	}

	public function test_fetch_expires_five_minutes_after_launch() {
		$token = self::fetch_token( $this->launch_ok()['launch_url'] );
		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:05:00.000001', new DateTimeZone( 'UTC' ) ) );
		$this->assertSame( self::USED, $this->fetch( $token )->get_data() );
		$this->assertSame( 0, $this->count_tokens() );

		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:10:00.000000', new DateTimeZone( 'UTC' ) ) );
		$fresh = self::fetch_token( $this->launch_ok()['launch_url'] );
		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:14:59.000000', new DateTimeZone( 'UTC' ) ) );
		$this->assertArrayHasKey( 'auth-token', $this->fetch( $fresh )->get_data(), 'still valid just before 5 minutes' );
	}

	public function test_unknown_token_answers_error_code_1() {
		$this->assertSame( self::USED, $this->fetch( str_repeat( 'A', 43 ) )->get_data() );
	}

	public function test_minted_token_cannot_write_launch_data_or_void() {
		$token                = self::fetch_token( $this->launch_ok()['launch_url'] );
		$session              = $this->session_for( $this->learner );
		list( $key, $secret ) = self::credential( $this->fetch( $token ) );
		$au                   = Xapi_Test_Client::as( $key, $secret );
		$query                = array(
			'activityId'   => Au::activity_id( self::AU_ID ),
			'agent'        => wp_json_encode( $this->actor( $this->learner ) ),
			'registration' => $session['registration'],
			'stateId'      => 'LMS.LaunchData',
		);

		$this->assertSame( 200, $au->request( 'GET', 'activities/state', $query )->status(), 'LaunchData is readable' );
		$before = $this->launch_data( $this->learner, $session['registration'] )->body();
		$put    = $au->request( 'PUT', 'activities/state', $query, '{"launchMode":"Normal"}', array( 'Content-Type' => 'application/json' ) );
		$this->assertSame( 403, $put->status() );
		$this->assertSame( $before, $this->launch_data( $this->learner, $session['registration'] )->body() );

		$launched = $this->statements( Vocab::LAUNCHED )[0]['id'];
		$void     = array(
			'id'      => Uuid::v4(),
			'actor'   => $this->actor( $this->learner ),
			'verb'    => array( 'id' => 'http://adlnet.gov/expapi/verbs/voided' ),
			'object'  => array(
				'objectType' => 'StatementRef',
				'id'         => $launched,
			),
			'context' => array(
				'registration' => $session['registration'],
				'extensions'   => array( Vocab::EXT_SESSIONID => $session['session_id'] ),
			),
		);
		$res      = $au->request( 'POST', 'statements', array(), (string) wp_json_encode( $void ), array( 'Content-Type' => 'application/json' ) );
		$this->assertSame( 403, $res->status() );
		$this->assertCount( 1, $this->statements( Vocab::LAUNCHED ) );
		$this->assertSame( array(), $this->statements( 'http://adlnet.gov/expapi/verbs/voided' ) );
	}

	public function test_other_methods_are_405() {
		$token = self::fetch_token( $this->launch_ok()['launch_url'] );
		foreach ( array( 'GET', 'PUT', 'DELETE' ) as $method ) {
			$res = $this->fetch( $token, $method );
			$this->assertSame( 405, $res->get_status(), $method );
			$this->assertSame( 'POST', $res->get_headers()['Allow'] );
		}
		$this->assertArrayHasKey( 'auth-token', $this->fetch( $token )->get_data(), 'the token is still unused' );
	}

	public function test_lrs_failure_answers_error_code_2() {
		$token      = self::fetch_token( $this->launch_ok()['launch_url'] );
		$this->fail = 'v1/tokens';
		$res        = $this->fetch( $token );
		$this->assertSame( '2', $res->get_data()['error-code'] );
		$this->assertNotSame( '', $res->get_data()['error-text'] );
	}

	public function test_au_origin_is_allowed_by_cors_and_other_origins_are_not() {
		$token = self::fetch_token( $this->launch_ok()['launch_url'] );
		$other = $this->fetch( str_repeat( 'B', 43 ), 'POST', array( 'Origin' => 'https://au.example.net' ) );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', $other->get_headers(), 'unknown token' );

		$evil = $this->fetch( $token, 'OPTIONS', array( 'Origin' => 'https://evil.example' ) );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', $evil->get_headers() );

		$res = $this->fetch( $token, 'POST', array( 'Origin' => 'https://au.example.net' ) );
		$this->assertArrayHasKey( 'auth-token', $res->get_data() );
		$this->assertSame( 'https://au.example.net', $res->get_headers()['Access-Control-Allow-Origin'] );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Credentials', $res->get_headers() );
	}

	public function test_sandboxed_au_gets_origin_null() {
		$this->import_zip();
		$this->assertTrue( ( new LearningLedger\Integrations\Sensei\Sensei_Adapter() )->enrol( $this->learner, $this->course ) );
		$data = $this->launch_ok();
		$this->assertStringStartsWith( home_url( '/lledger-au/' ), $data['launch_url'], 'served by the CSP-sandboxed fallback' );
		$token = self::fetch_token( $data['launch_url'] );

		$preflight = $this->fetch( $token, 'OPTIONS', array( 'Origin' => 'null' ) );
		$this->assertSame( 204, $preflight->get_status() );
		$this->assertSame( 'null', $preflight->get_headers()['Access-Control-Allow-Origin'] );
		$this->assertStringContainsString( 'POST', $preflight->get_headers()['Access-Control-Allow-Methods'] );

		$res = $this->fetch( $token, 'POST', array( 'Origin' => 'null' ) );
		$this->assertSame( 'null', $res->get_headers()['Access-Control-Allow-Origin'] );
		list( $key ) = self::credential( $res );
		$this->assertSame( array( 'null' ), ( new Token_Repository() )->find( $key )->cors_origins );
	}

	private function count_tokens(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'tokens' ) );
	}
}
