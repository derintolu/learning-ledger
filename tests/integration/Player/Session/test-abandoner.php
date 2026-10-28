<?php
/**
 * Abandoner tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table reads.

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Db\Tables;
use LearningLedger\Player\Launch\Session_Repository;
use LearningLedger\Player\Session\Abandoner;

require_once __DIR__ . '/test-player-evaluator.php';

/**
 * Player session abandonment tests.
 *
 * @requires function Sensei
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test class.
class Abandoner_Test extends Lledger_Session_Test_Case {

	private function revoked( string $token_key ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT revoked_at FROM ' . Tables::name( 'tokens' ) . ' WHERE token_key = %s', $token_key ) );
	}

	private function terminated_lookups(): array {
		return array_values(
			array_filter(
				$this->http,
				static fn( array $r ): bool => 'GET' === $r['args']['method'] && str_contains( $r['url'], '/statements?' ) && str_contains( $r['url'], rawurlencode( Vocab::TERMINATED ) )
			)
		);
	}

	public function test_expired_token_without_terminated_writes_one_abandoned() {
		$a1      = $this->launch_au( self::A1 );
		$session = ( new Session_Repository() )->find( $a1['session']['session_id'] );
		$this->assertNotNull( $session['token_key'] );

		$this->tick( '18:00:01' );
		$this->http = array();
		$this->assertSame( 1, ( new Abandoner() )->run() );

		$lookups = $this->terminated_lookups();
		$this->assertCount( 1, $lookups, 'the LRS is asked for the session\'s terminated first' );
		$query = self::query_of( $lookups[0]['url'] );
		$this->assertSame( $session['registration'], $query['registration'] );

		$abandoned = $this->statements( Vocab::ABANDONED );
		$this->assertCount( 1, $abandoned );
		$this->assertSame( 'PT8H1S', $abandoned[0]['result']['duration'] );
		$this->assertSame( $session['session_id'], $abandoned[0]['context']['extensions'][ Vocab::EXT_SESSIONID ] );
		$this->assertSame( $session['registration'], $abandoned[0]['context']['registration'] );
		$this->assertSame( Au::activity_id( self::A1 ), $abandoned[0]['object']['id'] );

		$closed = ( new Session_Repository() )->find( $session['session_id'] );
		$this->assertSame( Session_Repository::ABANDONED, $closed['status'] );
		$this->assertSame( '2026-10-27 18:00:01.000000', $closed['abandoned_at'] );
		$this->assertTrue( $this->revoked( $session['token_key'] ) );

		$this->tick( '18:01:01' );
		$this->assertSame( 0, ( new Abandoner() )->run() );
		$this->assertCount( 1, $this->statements( Vocab::ABANDONED ), 'abandoned once' );
	}

	public function test_session_within_the_token_lifetime_is_left_open() {
		$a1 = $this->launch_au( self::A1 );
		$this->tick( '18:00:00' );
		$this->assertSame( 0, ( new Abandoner() )->run() );
		$this->assertSame( Session_Repository::OPEN, ( new Session_Repository() )->find( $a1['session']['session_id'] )['status'] );
		$this->assertSame( array(), $this->statements( Vocab::ABANDONED ) );
	}

	public function test_session_with_terminated_in_the_lrs_is_marked_terminated() {
		$this->lrs_elsewhere();
		$a1 = $this->launch_au( self::A1 );
		$this->tick( '10:20:00' );
		$this->post_as( $a1['client'], $this->au_statement( $this->learner, Vocab::TERMINATED, self::A1, $a1['session']['session_id'], $a1['session']['registration'] ) );

		$this->tick( '18:00:01' );
		$this->assertSame( 0, ( new Abandoner() )->run() );
		$this->assertSame( array(), $this->statements( Vocab::ABANDONED ), 'never abandoned after terminated' );
		$closed = ( new Session_Repository() )->find( $a1['session']['session_id'] );
		$this->assertSame( Session_Repository::TERMINATED, $closed['status'] );
		$this->assertSame( '2026-10-27 10:20:00.000000', $closed['terminated_at'] );
		$this->assertTrue( $this->revoked( $closed['token_key'] ) );
	}

	public function test_retry_after_a_failure_resends_the_identical_statement() {
		$a1 = $this->launch_au( self::A1 );
		$id = $a1['session']['session_id'];

		$this->tick( '18:00:01' );
		$this->fail = 'v1/tokens/';
		$this->assertSame( 0, ( new Abandoner() )->run() );
		$this->assertSame( Session_Repository::OPEN, ( new Session_Repository() )->find( $id )['status'] );
		$first = $this->statements( Vocab::ABANDONED );
		$this->assertCount( 1, $first );

		$this->tick( '18:05:00' );
		$this->fail = null;
		$this->assertSame( 1, ( new Abandoner() )->run() );
		$abandoned = $this->statements( Vocab::ABANDONED );
		$this->assertCount( 1, $abandoned, 'the resend is the same statement' );
		$this->assertSame( $first[0]['id'], $abandoned[0]['id'] );
		$this->assertSame( 'PT8H1S', $abandoned[0]['result']['duration'], 'the duration of the first attempt' );
		$closed = ( new Session_Repository() )->find( $id );
		$this->assertSame( Session_Repository::ABANDONED, $closed['status'] );
		$this->assertSame( '2026-10-27 18:00:01.000000', $closed['abandoned_at'] );
		$this->assertTrue( $this->revoked( $closed['token_key'] ) );
	}

	public function test_unreachable_lrs_leaves_the_session_open() {
		$a1 = $this->launch_au( self::A1 );
		$this->tick( '18:00:01' );
		$this->fail = 'statements';
		$this->assertSame( 0, ( new Abandoner() )->run() );
		$this->assertSame( Session_Repository::OPEN, ( new Session_Repository() )->find( $a1['session']['session_id'] )['status'] );
		$this->assertNull( ( new Session_Repository() )->find( $a1['session']['session_id'] )['abandoned_at'], 'nothing stamped before the LRS answered' );
	}

	public function test_a_session_that_keeps_failing_is_backed_off() {
		$a1 = $this->launch_au( self::A1 );
		$id = $a1['session']['session_id'];
		$this->tick( '18:00:01' );
		$this->fail = 'statements';
		$this->assertSame( 0, ( new Abandoner() )->run() );
		$this->assertSame( 1, get_option( Abandoner::BACKOFF_OPTION )[ $id ]['attempts'] );

		$this->fail = null;
		$this->tick( '18:00:30' );
		$this->http = array();
		$this->assertSame( 0, ( new Abandoner() )->run(), 'waits out the first minute' );
		$this->assertSame( array(), $this->http, 'without asking the LRS' );

		$this->tick( '18:01:01' );
		$this->fail = 'statements';
		$this->assertSame( 0, ( new Abandoner() )->run() );
		$this->assertSame( 2, get_option( Abandoner::BACKOFF_OPTION )[ $id ]['attempts'] );
		$this->tick( '18:05:00' );
		$this->assertSame( 0, ( new Abandoner() )->run(), 'then five minutes' );
		$this->assertSame( 2, get_option( Abandoner::BACKOFF_OPTION )[ $id ]['attempts'] );

		$this->fail = null;
		$this->tick( '18:06:02' );
		$this->assertSame( 1, ( new Abandoner() )->run() );
		$this->assertSame( Session_Repository::ABANDONED, ( new Session_Repository() )->find( $id )['status'] );
		$this->assertFalse( get_option( Abandoner::BACKOFF_OPTION ), 'forgotten once closed' );
	}

	public function test_backed_off_sessions_do_not_hold_up_others() {
		$first = $this->launch_au( self::A1 );
		$this->tick( '10:10:00' );
		$second = $this->launch_au( self::A2 );
		update_option(
			Abandoner::BACKOFF_OPTION,
			array(
				$first['session']['session_id'] => array(
					'attempts' => 5,
					'next'     => strtotime( '2026-10-28 00:00:00 UTC' ),
				),
			),
			false
		);
		$this->tick( '18:10:01' );
		$this->assertSame( 1, ( new Abandoner() )->run() );
		$this->assertSame( Session_Repository::OPEN, ( new Session_Repository() )->find( $first['session']['session_id'] )['status'] );
		$this->assertSame( Session_Repository::ABANDONED, ( new Session_Repository() )->find( $second['session']['session_id'] )['status'] );
	}

	public function test_runs_every_cron_minute() {
		$a1 = $this->launch_au( self::A1 );
		$this->tick( '18:00:01' );
		do_action( 'learning_ledger/cron_minute' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertSame( Session_Repository::ABANDONED, ( new Session_Repository() )->find( $a1['session']['session_id'] )['status'] );
		$this->assertCount( 1, $this->statements( Vocab::ABANDONED ) );
	}
}
