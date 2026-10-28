<?php
/**
 * Link repository tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Crypto\Tokens;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Links\Link_Repository;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;

class Link_Repository_Test extends WP_UnitTestCase {

	private \DateTimeImmutable $t0;

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'links' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->t0 = new \DateTimeImmutable( '2026-10-27T10:00:00.000000Z' );
		Clock::freeze( $this->t0 );
	}

	public function tear_down() {
		Clock::freeze( null );
		parent::tear_down();
	}

	private static function actor( string $name = 'alice' ): string {
		return sha1( 'account:https://site.example|' . $name, true );
	}

	private function later( int $seconds ): void {
		Clock::freeze( $this->t0->modify( '+' . $seconds . ' seconds' ) );
	}

	private static function rows(): array {
		return (array) $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Tables::name( 'links' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_mint_returns_a_43_character_token_and_stores_only_its_hash() {
		$token = ( new Link_Repository() )->mint( 'view', self::actor(), 'record', 900, 'client:test' );

		$this->assertMatchesRegularExpression( '/\A[A-Za-z0-9_-]{43}\z/', $token );
		$rows = self::rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( Tokens::hash( $token ), $rows[0]['token_hash'] );
		$this->assertSame( 'view', $rows[0]['kind'] );
		$this->assertSame( self::actor(), $rows[0]['actor_hash'] );
		$this->assertSame( 'record', $rows[0]['subject'] );
		$this->assertSame( '2026-10-27 10:15:00.000000', $rows[0]['expires_at'] );
		$this->assertNull( $rows[0]['used_at'] );
		$this->assertSame( 'client:test', $rows[0]['created_by'] );
		foreach ( $rows[0] as $value ) {
			$this->assertStringNotContainsString( $token, (string) $value );
		}
	}

	public function test_resolve_returns_the_link_until_it_expires() {
		$repo  = new Link_Repository();
		$token = $repo->mint( 'view', self::actor(), 'record', 900, 'client:test' );

		$this->later( 899 );
		$link = $repo->resolve( $token, 'view' );
		$this->assertIsArray( $link );
		$this->assertSame( 'view', $link['kind'] );
		$this->assertSame( self::actor(), $link['actor_hash'] );
		$this->assertSame( 'record', $link['subject'] );
		$this->assertSame( 'client:test', $link['created_by'] );
		$this->assertSame( '2026-10-27T10:15:00.000000Z', $link['expires_at'] );

		$this->later( 900 );
		$this->assertNull( $repo->resolve( $token, 'view' ) );
	}

	public function test_resolve_rejects_unknown_tokens_and_the_wrong_kind() {
		$repo  = new Link_Repository();
		$token = $repo->mint( 'view', self::actor(), 'record', 900, 'client:test' );

		$this->assertNull( $repo->resolve( $token, 'claim' ) );
		$this->assertNull( $repo->resolve( Tokens::random( 32 ), 'view' ) );
		$this->assertNull( $repo->resolve( '', 'view' ) );
	}

	public function test_view_link_resolves_many_times() {
		$repo  = new Link_Repository();
		$token = $repo->mint( 'view', self::actor(), 'record', 900, 'client:test' );

		$this->assertNotNull( $repo->resolve( $token, 'view' ) );
		$this->assertNotNull( $repo->resolve( $token, 'view' ) );
	}

	public function test_consume_succeeds_once_and_a_used_claim_link_no_longer_resolves() {
		$repo  = new Link_Repository();
		$token = $repo->mint( 'claim', self::actor(), 'badge-1', 900, 'client:test' );

		$this->assertNotNull( $repo->resolve( $token, 'claim' ) );
		$this->assertTrue( $repo->consume( $token ) );
		$this->assertFalse( $repo->consume( $token ) );
		$this->assertNull( $repo->resolve( $token, 'claim' ) );
		$this->assertSame( '2026-10-27 10:00:00.000000', self::rows()[0]['used_at'] );
	}

	public function test_consume_fails_for_an_expired_or_unknown_link() {
		$repo  = new Link_Repository();
		$token = $repo->mint( 'claim', self::actor(), 'badge-1', 900, 'client:test' );

		$this->later( 900 );
		$this->assertFalse( $repo->consume( $token ) );
		$this->assertFalse( $repo->consume( Tokens::random( 32 ) ) );
		$this->assertNull( self::rows()[0]['used_at'] );
	}

	public function test_minting_a_claim_link_deletes_earlier_unused_ones_of_the_same_actor_and_subject() {
		$repo = new Link_Repository();
		$used = $repo->mint( 'claim', self::actor(), 'badge-1', 900, 'client:test' );
		$this->assertTrue( $repo->consume( $used ) );
		$first = $repo->mint( 'claim', self::actor(), 'badge-1', 900, 'client:test' );
		$other = $repo->mint( 'claim', self::actor(), 'badge-2', 900, 'client:test' );
		$bob   = $repo->mint( 'claim', self::actor( 'bob' ), 'badge-1', 900, 'client:test' );

		$second = $repo->mint( 'claim', self::actor(), 'badge-1', 900, 'client:test' );

		$this->assertNull( $repo->resolve( $first, 'claim' ) );
		$this->assertNotNull( $repo->resolve( $second, 'claim' ) );
		$this->assertNotNull( $repo->resolve( $other, 'claim' ) );
		$this->assertNotNull( $repo->resolve( $bob, 'claim' ) );
		$this->assertCount( 4, self::rows() ); // The used link stays, as a record of the claim.
	}

	public function test_minting_a_view_link_keeps_earlier_ones() {
		$repo  = new Link_Repository();
		$first = $repo->mint( 'view', self::actor(), 'record', 900, 'client:test' );
		$repo->mint( 'view', self::actor(), 'record', 900, 'client:test' );

		$this->assertNotNull( $repo->resolve( $first, 'view' ) );
		$this->assertCount( 2, self::rows() );
	}

	public function test_mint_rejects_bad_input() {
		$repo = new Link_Repository();
		foreach (
			array(
				array( 'other', self::actor(), 'record', 900 ),
				array( 'view', 'short', 'record', 900 ),
				array( 'view', self::actor(), '', 900 ),
				array( 'view', self::actor(), str_repeat( 'x', 81 ), 900 ),
				array( 'view', self::actor(), 'record', 0 ),
			) as $args
		) {
			try {
				$repo->mint( $args[0], $args[1], $args[2], $args[3], 'client:test' );
				$this->fail( 'Expected Invalid_Input for ' . wp_json_encode( array( $args[0], strlen( $args[1] ), $args[2], $args[3] ) ) );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'invalid_link', $e->error_code() );
			}
		}
		$this->assertSame( array(), self::rows() );
	}

	public function test_prune_deletes_only_expired_links() {
		$repo = new Link_Repository();
		$repo->mint( 'view', self::actor(), 'record', 60, 'client:test' );
		$repo->mint( 'claim', self::actor(), 'badge-1', 60, 'client:test' );
		$kept = $repo->mint( 'view', self::actor(), 'record', 900, 'client:test' );

		$this->later( 60 );
		$this->assertSame( 2, $repo->prune() );
		$this->assertCount( 1, self::rows() );
		$this->assertNotNull( $repo->resolve( $kept, 'view' ) );
		$this->assertSame( 0, $repo->prune() );
	}

	public function test_prune_keeps_used_claim_links_as_the_record_of_the_claim() {
		$repo = new Link_Repository();
		$used = $repo->mint( 'claim', self::actor(), 'badge-1', 60, 'client:test' );
		$repo->mint( 'claim', self::actor(), 'badge-2', 60, 'client:test' );
		$repo->mint( 'view', self::actor(), 'record', 60, 'client:test' );
		$this->assertTrue( $repo->consume( $used ) );

		$this->later( 60 );
		$this->assertSame( 2, $repo->prune(), 'the expired view link and the expired unused claim link' );

		$rows = self::rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'claim', $rows[0]['kind'] );
		$this->assertNotNull( $rows[0]['used_at'] );
		$this->assertSame( 0, $repo->prune() );
	}
}
