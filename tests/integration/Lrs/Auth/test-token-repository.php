<?php
/**
 * Child token repository tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Crypto\Tokens;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Auth\Token_Repository;
use LearningLedger\Lrs\Auth\Token_Spec;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;

class Token_Repository_Test extends WP_UnitTestCase {

	const REG     = '6f1c3c1e-9a5e-4c1b-8d4e-2b7f0c9a1d11';
	const SESSION = '0b6c7a52-5d0e-4c3a-9f1e-6a2b3c4d5e6f';

	public function set_up() {
		parent::set_up();
		Installer::install();
		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:00:00.000000', new DateTimeZone( 'UTC' ) ) );
	}

	public function tear_down() {
		Clock::freeze( null );
		parent::tear_down();
	}

	private static function actor( string $name = 'learner-1' ): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => 'https://lms.example',
				'name'     => $name,
			),
		);
	}

	private static function spec( int $ttl = 28800, array $origins = array(), string $session = self::SESSION, array $actor = array() ): Token_Spec {
		return new Token_Spec( self::REG, $actor ? $actor : self::actor(), 'https://lms.example/au/1', $session, 'Normal', $ttl, $origins );
	}

	private function parent( array $scopes = array( Scopes::TOKENS_MINT ), array $overrides = array() ) {
		$c = Client_Factory::create( $scopes, $overrides );
		return ( new Client_Repository() )->find( $c['id'] );
	}

	public function test_mint_returns_key_secret_and_expiry_and_stores_only_the_hash() {
		$repo   = new Token_Repository();
		$minted = $repo->mint( $this->parent(), self::spec() );

		$this->assertSame( array( 'key', 'secret', 'expires_at' ), array_keys( $minted ) );
		$this->assertMatchesRegularExpression( '/^tok_[0-9a-f]{24}$/', $minted['key'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $minted['secret'] );
		$this->assertSame( '2026-10-27T18:00:00.000000Z', $minted['expires_at'] );

		$row = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( 'SELECT * FROM ' . Tables::name( 'tokens' ) . ' WHERE token_key = %s', $minted['key'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( Tokens::hash( $minted['secret'] ), $row['secret_sha256'] );
		$this->assertStringNotContainsString( $minted['secret'], wp_json_encode( array_map( 'bin2hex', $row ) ) . implode( '', $row ) );

		$t = $repo->find( $minted['key'] );
		$this->assertSame( $minted['key'], $t->key );
		$this->assertSame( self::REG, $t->registration );
		$this->assertSame( self::actor(), $t->actor );
		$this->assertSame( 'https://lms.example/au/1', $t->activity_id );
		$this->assertSame( self::SESSION, $t->session_id );
		$this->assertSame( 'Normal', $t->launch_mode );
		$this->assertTrue( $t->guard );
		$this->assertTrue( $t->secret_matches( $minted['secret'] ) );
		$this->assertFalse( $t->secret_matches( 'x' ) );
		$this->assertTrue( $t->is_active( Clock::now() ) );
		$this->assertNull( $repo->find( 'tok_000000000000000000000000' ) );
	}

	public function test_ttl_capped_at_eight_hours() {
		$minted = ( new Token_Repository() )->mint( $this->parent(), self::spec( 999999 ) );
		$this->assertSame( '2026-10-27T18:00:00.000000Z', $minted['expires_at'] );

		$minted = ( new Token_Repository() )->mint( $this->parent(), self::spec( 60 ) );
		$this->assertSame( '2026-10-27T10:01:00.000000Z', $minted['expires_at'] );
	}

	public function test_mint_refuses_bad_input_and_parents_outside_their_rights() {
		$repo    = new Token_Repository();
		$bad     = array(
			fn() => $repo->mint( $this->parent( array( Scopes::ALL ) ), self::spec() ),
			fn() => $repo->mint( $this->parent( array( Scopes::TOKENS_MINT ), array( 'bindings' => array( 'actor_home_page' => 'https://other.example' ) ) ), self::spec() ),
			fn() => $repo->mint( $this->parent( array( Scopes::TOKENS_MINT ), array( 'bindings' => array( 'registration' => '00000000-0000-4000-8000-000000000000' ) ) ), self::spec() ),
			fn() => $repo->mint( $this->parent(), new Token_Spec( 'nope', self::actor(), 'https://lms.example/au/1', self::SESSION, 'Normal' ) ),
			fn() => $repo->mint( $this->parent(), new Token_Spec( self::REG, array( 'name' => 'no ifi' ), 'https://lms.example/au/1', self::SESSION, 'Normal' ) ),
			fn() => $repo->mint( $this->parent(), new Token_Spec( self::REG, self::actor(), 'https://lms.example/au/1', 'nope', 'Normal' ) ),
			fn() => $repo->mint( $this->parent(), new Token_Spec( self::REG, self::actor(), 'https://lms.example/au/1', self::SESSION, 'Sideways' ) ),
			fn() => $repo->mint( $this->parent(), new Token_Spec( self::REG, self::actor(), 'not an iri', self::SESSION, 'Normal' ) ),
			fn() => $repo->mint( $this->parent(), self::spec( 0 ) ),
		);
		$revoked = $this->parent();
		( new Client_Repository() )->revoke( $revoked->id );
		$bad[] = fn() => $repo->mint( ( new Client_Repository() )->find( $revoked->id ), self::spec() );

		foreach ( $bad as $i => $call ) {
			try {
				$call();
				$this->fail( "Expected Invalid_Input for case $i" );
			} catch ( Invalid_Input $e ) {
				$this->assertInstanceOf( Invalid_Input::class, $e );
			}
		}
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'tokens' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_revoke_revoke_session_and_mark_terminated() {
		$repo = new Token_Repository();
		$p    = $this->parent();
		$a    = $repo->mint( $p, self::spec() );
		$b    = $repo->mint( $p, self::spec( 28800, array(), '11111111-1111-4111-8111-111111111111' ) );
		$c    = $repo->mint( $p, self::spec( 28800, array(), '11111111-1111-4111-8111-111111111111' ) );
		$now  = Clock::now();

		$repo->revoke( $a['key'] );
		$this->assertFalse( $repo->find( $a['key'] )->is_active( $now ) );
		$this->assertTrue( $repo->find( $b['key'] )->is_active( $now ) );

		$repo->revoke_session( '11111111-1111-4111-8111-111111111111' );
		$this->assertFalse( $repo->find( $b['key'] )->is_active( $now ) );
		$this->assertFalse( $repo->find( $c['key'] )->is_active( $now ) );

		$d  = $repo->mint( $p, self::spec() );
		$at = new DateTimeImmutable( '2026-10-27 10:05:00.250000', new DateTimeZone( 'UTC' ) );
		$repo->mark_terminated( $d['key'], $at );
		$repo->mark_terminated( $d['key'], $at->modify( '+1 hour' ) );
		$this->assertEquals( $at, $repo->find( $d['key'] )->terminated_at );
		$this->assertTrue( $repo->find( $d['key'] )->is_active( $now ) );
	}

	public function test_live_origins_and_expired_unterminated() {
		$repo = new Token_Repository();
		$p    = $this->parent();
		$live = $repo->mint( $p, self::spec( 600, array( 'https://a.example', 'https://b.example' ) ) );
		$repo->mint( $p, self::spec( 600, array( 'https://a.example' ) ) );
		$gone = $repo->mint( $p, self::spec( 600, array( 'https://revoked.example' ) ) );
		$repo->revoke( $gone['key'] );
		$short = $repo->mint( $p, self::spec( 60, array( 'https://short.example' ) ) );
		$done  = $repo->mint( $p, self::spec( 60 ) );
		$repo->mark_terminated( $done['key'], Clock::now() );

		$origins = $repo->live_origins();
		sort( $origins );
		$this->assertSame( array( 'https://a.example', 'https://b.example', 'https://short.example' ), $origins );
		$this->assertSame( array(), $repo->expired_unterminated( 10 ) );

		Clock::freeze( Clock::now()->modify( '+61 seconds' ) );
		$origins = $repo->live_origins();
		sort( $origins );
		$this->assertSame( array( 'https://a.example', 'https://b.example' ), $origins );
		$expired = $repo->expired_unterminated( 10 );
		$this->assertSame( array( $short['key'] ), array_map( fn( $t ) => $t->key, $expired ) );
		$this->assertSame( array(), $repo->expired_unterminated( 0 ) );
		$this->assertNotNull( $repo->find( $live['key'] ) );
	}
}
