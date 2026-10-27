<?php
/**
 * Shutdown runner and queue module tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Queue\Queue_Module;
use LearningLedger\Queue\Shutdown_Runner;
use LearningLedger\Util\Clock;

class Shutdown_Runner_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'jobs' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( 'lledger_shutdown_last_run' );
		delete_option( 'lledger_queue_last_run' );
		delete_option( 'lledger_cron_quarter_hour_last' );
	}

	public function tear_down() {
		Clock::freeze( null );
		remove_all_actions( 'learning_ledger/jobs/test' );
		remove_all_actions( 'learning_ledger/cron_minute' );
		remove_all_actions( 'learning_ledger/cron_quarter_hour' );
		remove_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) );
		wp_clear_scheduled_hook( 'lledger_cron_minute' );
		parent::tear_down();
	}

	public function test_shutdown_runner_runs_queue_and_records_time() {
		$seen = 0;
		$cb   = 0;
		add_action(
			'learning_ledger/jobs/test',
			static function () use ( &$seen ) {
				++$seen;
			}
		);
		Job_Queue::push( 'test', array() );
		$this->assertNotFalse( has_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) ) );

		Shutdown_Runner::add(
			static function () use ( &$cb ) {
				++$cb;
			}
		);
		Shutdown_Runner::on_shutdown();

		$this->assertSame( 1, $seen );
		$this->assertSame( 1, $cb );
		$last = get_option( 'lledger_shutdown_last_run' );
		$this->assertIsArray( $last );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/', $last['at'] );
		$this->assertSame( function_exists( 'fastcgi_finish_request' ), $last['fastcgi_finish_request'] );
		$this->assertNotNull( Job_Queue::last_run() );

		// The request is consumed: a second shutdown does nothing until requested again.
		Shutdown_Runner::on_shutdown();
		$this->assertSame( 1, $cb );
	}

	public function test_callback_failure_does_not_stop_others() {
		$ran = false;
		Shutdown_Runner::add(
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);
		Shutdown_Runner::add(
			static function () use ( &$ran ) {
				$ran = true;
			}
		);
		$log = wp_tempnam( 'lledger-log' );
		$old = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		Shutdown_Runner::on_shutdown();
		ini_set( 'error_log', $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$this->assertTrue( $ran );
		$this->assertStringContainsString( 'shutdown callback failed: RuntimeException: boom', (string) file_get_contents( $log ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		unlink( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	public function test_quarter_hour_fires_on_each_15_minute_boundary() {
		$quarter = 0;
		add_action(
			'learning_ledger/cron_quarter_hour',
			static function () use ( &$quarter ) {
				++$quarter;
			}
		);
		$module = new Queue_Module();
		foreach ( array( '10:14:00', '10:15:00', '10:29:59', '10:30:00' ) as $time ) {
			Clock::freeze( new \DateTimeImmutable( "2026-10-27 {$time}", new \DateTimeZone( 'UTC' ) ) );
			$module->on_cron_minute();
		}
		$this->assertSame( 3, $quarter );
	}

	public function test_module_registers_minute_schedule_and_event() {
		$module = new Queue_Module();
		$module->register();
		$schedules = wp_get_schedules();
		$this->assertSame( 60, $schedules['lledger_minute']['interval'] );
		$this->assertNotFalse( has_action( 'init', array( $module, 'schedule' ) ) );
		$module->schedule();
		$module->schedule();
		$this->assertCount( 1, _get_cron_array() ? array_filter( _get_cron_array(), static fn( $hooks ) => isset( $hooks['lledger_cron_minute'] ) ) : array() );
		$this->assertNotFalse( wp_next_scheduled( 'lledger_cron_minute' ) );
		$this->assertSame( 'lledger_minute', wp_get_schedule( 'lledger_cron_minute' ) );
	}

	public function test_cron_minute_runs_queue_and_fires_quarter_hour_every_15_minutes() {
		$minute  = 0;
		$quarter = 0;
		$jobs    = 0;
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
		add_action(
			'learning_ledger/jobs/test',
			static function () use ( &$jobs ) {
				++$jobs;
			}
		);
		( new Queue_Module() )->register();
		$start = new \DateTimeImmutable( '2026-10-27 10:00:00', new \DateTimeZone( 'UTC' ) );
		Clock::freeze( $start );
		Job_Queue::push( 'test', array() );
		for ( $m = 0; $m <= 30; $m++ ) {
			Clock::freeze( $start->modify( "+{$m} minutes" ) );
			do_action( 'lledger_cron_minute' );
		}
		$this->assertSame( 1, $jobs );
		$this->assertSame( 31, $minute );
		$this->assertSame( 3, $quarter );
	}
}
