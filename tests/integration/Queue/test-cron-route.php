<?php
/**
 * Cron trigger route tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Queue\Cron_Route;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Clock;

class Cron_Route_Test extends WP_UnitTestCase {

	private const SECRET = 'correct horse battery staple';

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'jobs' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( 'lledger_cron_secret_sha256' );
		delete_option( 'lledger_cron_quarter_hour_last' );
		( new Cron_Route() )->register();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_actions( 'learning_ledger/jobs/test' );
		remove_all_actions( 'learning_ledger/cron_minute' );
		remove_all_actions( 'learning_ledger/cron_quarter_hour' );
		Clock::freeze( null );
		parent::tear_down();
	}

	private function dispatch( string $method, ?string $header = null, ?string $key = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/learning-ledger/v1/cron' );
		if ( null !== $header ) {
			$request->set_header( 'X-Learning-Ledger-Cron', $header );
		}
		if ( null !== $key ) {
			$request->set_query_params( array( 'key' => $key ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_cron_route_requires_secret_hash() {
		$ran = 0;
		add_action(
			'learning_ledger/jobs/test',
			static function () use ( &$ran ) {
				++$ran;
			}
		);
		Job_Queue::push( 'test', array() );

		// No secret configured: always 403.
		$this->assertSame( 403, $this->dispatch( 'GET', self::SECRET )->get_status() );

		update_option( 'lledger_cron_secret_sha256', hash( 'sha256', self::SECRET ) );
		$this->assertSame( 403, $this->dispatch( 'GET' )->get_status() );
		$this->assertSame( 403, $this->dispatch( 'GET', 'wrong' )->get_status() );
		$this->assertSame( 403, $this->dispatch( 'POST', null, 'wrong' )->get_status() );
		$this->assertSame( 403, $this->dispatch( 'GET', hash( 'sha256', self::SECRET ) )->get_status() );
		$this->assertSame( 0, $ran );

		$ok = $this->dispatch( 'GET', self::SECRET );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 1, $ok->get_data()['processed'] );
		$this->assertSame( 1, $ran );

		$this->assertSame( 200, $this->dispatch( 'POST', null, self::SECRET )->get_status() );
		$this->assertSame( 200, $this->dispatch( 'POST', self::SECRET )->get_status() );
	}

	public function test_cron_route_fires_the_minute_and_quarter_hour_actions() {
		$minute  = 0;
		$quarter = 0;
		add_action(
			'learning_ledger/cron_minute',
			static function () use ( &$minute ) {
				++$minute;
			}
		);
		add_action(
			'learning_ledger/cron_quarter_hour',
			static function () use ( &$quarter ) {
				++$quarter;
			}
		);
		update_option( 'lledger_cron_secret_sha256', hash( 'sha256', self::SECRET ) );

		foreach ( array( '10:14:00', '10:15:00', '10:29:59', '10:30:00' ) as $time ) {
			Clock::freeze( new \DateTimeImmutable( "2026-10-27 {$time}", new \DateTimeZone( 'UTC' ) ) );
			$this->assertSame( 200, $this->dispatch( 'GET', self::SECRET )->get_status() );
		}
		$this->assertSame( 4, $minute, 'every hit is a minute tick' );
		$this->assertSame( 3, $quarter, 'quarter hour once per 15-minute bucket' );

		$this->assertSame( 403, $this->dispatch( 'GET', 'wrong' )->get_status() );
		$this->assertSame( 4, $minute, 'a refused hit fires nothing' );
	}
}
