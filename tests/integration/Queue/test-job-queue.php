<?php
/**
 * Job queue tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;

class Job_Queue_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'jobs' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'audit' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( 'lledger_queue_last_run' );
	}

	public function tear_down() {
		Clock::freeze( null );
		remove_all_actions( 'learning_ledger/jobs/test' );
		remove_all_filters( 'query' );
		parent::tear_down();
	}

	private function jobs(): array {
		return $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Tables::name( 'jobs' ) . ' ORDER BY id' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_push_dedupes_open_jobs() {
		Job_Queue::push( 'test', array( 'n' => 1 ), 'k1' );
		Job_Queue::push( 'test', array( 'n' => 2 ), 'k1' );
		Job_Queue::push( 'test', array( 'n' => 3 ), 'k2' );
		Job_Queue::push( 'test', array( 'n' => 4 ) );
		Job_Queue::push( 'test', array( 'n' => 5 ) );
		$jobs = $this->jobs();
		$this->assertCount( 4, $jobs );
		$this->assertSame( array( 'n' => 1 ), json_decode( $jobs[0]->payload, true ) );
		$this->assertSame( '', $GLOBALS['wpdb']->last_error );

		// Once the open job is done, the same key may be queued again.
		Job_Queue::run();
		Job_Queue::push( 'test', array( 'n' => 6 ), 'k1' );
		$this->assertCount( 5, $this->jobs() );
		$this->assertSame( 1, Job_Queue::due_count() );
	}

	public function test_push_with_delay_is_not_due_yet() {
		Job_Queue::push( 'test', array(), null, 120 );
		$this->assertSame( 0, Job_Queue::due_count() );
		$this->assertSame( 0, Job_Queue::run() );
		$this->assertNull( Job_Queue::last_run(), 'A run with nothing to do records nothing.' );
	}

	public function test_run_dispatches_and_marks_done() {
		$seen = array();
		add_action(
			'learning_ledger/jobs/test',
			static function ( $payload ) use ( &$seen ) {
				$seen[] = $payload;
			}
		);
		Job_Queue::push( 'test', array( 'a' => 1 ) );
		Job_Queue::push( 'test', array( 'a' => 2 ) );
		$this->assertSame( 2, Job_Queue::due_count() );
		$this->assertNull( Job_Queue::last_run() );

		$this->assertSame( 2, Job_Queue::run() );

		$this->assertSame( array( array( 'a' => 1 ), array( 'a' => 2 ) ), $seen );
		foreach ( $this->jobs() as $job ) {
			$this->assertNotNull( $job->done_at );
			$this->assertNull( $job->lock_token );
			$this->assertSame( '1', $job->attempts );
		}
		$this->assertSame( 0, Job_Queue::due_count() );
		$this->assertNotNull( Job_Queue::last_run() );
		$this->assertSame( 0, Job_Queue::run() );
		$this->assertCount( 2, $seen );
	}

	public function test_run_respects_max_jobs() {
		for ( $i = 0; $i < 3; $i++ ) {
			Job_Queue::push( 'test', array( 'i' => $i ) );
		}
		$this->assertSame( 2, Job_Queue::run( 2 ) );
		$this->assertSame( 1, Job_Queue::due_count() );
	}

	public function test_failure_backs_off_and_records_error() {
		add_action(
			'learning_ledger/jobs/test',
			static function () {
				throw new \RuntimeException( 'endpoint down' );
			}
		);
		$now = new \DateTimeImmutable( '2026-10-27 10:00:00', new \DateTimeZone( 'UTC' ) );
		Clock::freeze( $now );
		Job_Queue::push( 'test', array() );
		Job_Queue::run();

		$job = $this->jobs()[0];
		$this->assertSame( '1', $job->attempts );
		$this->assertSame( 'endpoint down', $job->last_error );
		$this->assertNull( $job->done_at );
		$this->assertNull( $job->lock_token );
		$this->assertSame( 60, Timestamp::from_db( $job->run_after )->getTimestamp() - $now->getTimestamp() );
		$this->assertSame( 0, Job_Queue::due_count() );

		// Later attempts back off 5, 15 and 60 minutes, then 6 hours.
		$expected = array( 300, 900, 3600, 21600, 21600 );
		foreach ( $expected as $i => $delay ) {
			$t = Timestamp::from_db( $this->jobs()[0]->run_after );
			Clock::freeze( $t );
			$this->assertSame( 1, Job_Queue::run() );
			$job = $this->jobs()[0];
			$this->assertSame( (string) ( $i + 2 ), $job->attempts );
			$this->assertSame( $delay, Timestamp::from_db( $job->run_after )->getTimestamp() - $t->getTimestamp() );
		}
	}

	public function test_gives_up_after_72_hours_with_audit_row() {
		$calls = 0;
		add_action(
			'learning_ledger/jobs/test',
			static function () use ( &$calls ) {
				++$calls;
				throw new \RuntimeException( 'still down' );
			}
		);
		$start = new \DateTimeImmutable( '2026-10-27 10:00:00', new \DateTimeZone( 'UTC' ) );
		Clock::freeze( $start );
		Job_Queue::push( 'test', array(), 'give-up' );
		Clock::freeze( $start->modify( '+71 hours' ) );
		Job_Queue::run();
		$this->assertNull( $this->jobs()[0]->done_at );
		$this->assertSame( 1, $calls );

		// An attempted job that is 72 hours old is not tried again.
		Clock::freeze( $start->modify( '+72 hours' ) );
		$this->assertSame( 0, Job_Queue::run() );
		$this->assertSame( 1, $calls );
		$job = $this->jobs()[0];
		$this->assertNotNull( $job->done_at );
		$this->assertSame( 'still down', $job->last_error );
		$this->assertSame( 0, Job_Queue::due_count() );
		$audit = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Tables::name( 'audit' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 'job_abandoned', $audit->event );
		$this->assertSame(
			array(
				'job'      => (int) $job->id,
				'type'     => 'test',
				'attempts' => 1,
			),
			json_decode( $audit->data, true )
		);

		// The key is free again.
		Job_Queue::push( 'test', array(), 'give-up' );
		$this->assertCount( 2, $this->jobs() );
	}

	public function test_first_failure_of_a_72_hour_old_job_abandons_it() {
		add_action(
			'learning_ledger/jobs/test',
			static function () {
				throw new \RuntimeException( 'down' );
			}
		);
		$start = new \DateTimeImmutable( '2026-10-27 10:00:00', new \DateTimeZone( 'UTC' ) );
		Clock::freeze( $start );
		Job_Queue::push( 'test', array(), null, 72 * 3600 );
		Clock::freeze( $start->modify( '+72 hours' ) );
		$this->assertSame( 1, Job_Queue::run() );
		$job = $this->jobs()[0];
		$this->assertNotNull( $job->done_at );
		$this->assertSame( '1', $job->attempts );
		$this->assertSame( 'down', $job->last_error );
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'audit' ) . " WHERE event = 'job_abandoned'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_crashed_handler_backs_off_and_is_eventually_abandoned() {
		// The handler dies without throwing (fatal, timeout, exit): from then on the
		// process writes nothing more to the jobs table, so neither finish, fail
		// nor release is recorded.
		$calls   = 0;
		$crashed = false;
		add_action(
			'learning_ledger/jobs/test',
			static function () use ( &$calls, &$crashed ) {
				++$calls;
				$crashed = true;
			}
		);
		add_filter(
			'query',
			static function ( $sql ) use ( &$crashed ) {
				$jobs_write = 0 === strpos( ltrim( $sql ), 'UPDATE' ) && false !== strpos( $sql, Tables::name( 'jobs' ) );
				return $crashed && $jobs_write ? 'SELECT 1' : $sql;
			}
		);
		$start = new \DateTimeImmutable( '2026-10-27 10:00:00', new \DateTimeZone( 'UTC' ) );
		$at    = $start;
		Clock::freeze( $start );
		Job_Queue::push( 'test', array(), 'poison' );

		$expected = array( 60, 300, 900, 3600, 21600 );
		foreach ( $expected as $i => $delay ) {
			Clock::freeze( $at );
			Job_Queue::run();
			$crashed = false;
			$job     = $this->jobs()[0];
			$this->assertSame( $i + 1, $calls );
			$this->assertSame( (string) ( $i + 1 ), $job->attempts );
			$this->assertNull( $job->done_at );
			$this->assertNotNull( $job->lock_token, 'The crash left the lock in place.' );
			$this->assertSame( $delay, Timestamp::from_db( $job->run_after )->getTimestamp() - $at->getTimestamp() );

			// Not reclaimed before its backoff and its lock have both expired.
			Clock::freeze( $at->modify( '+59 seconds' ) );
			$this->assertSame( 0, Job_Queue::run() );
			$at = $at->modify( '+' . max( $delay, 61 ) . ' seconds' );
		}

		Clock::freeze( $start->modify( '+72 hours' ) );
		$this->assertSame( 0, Job_Queue::run() );
		$this->assertSame( 5, $calls );
		$job = $this->jobs()[0];
		$this->assertNotNull( $job->done_at );
		$this->assertNull( $job->dedupe_key );
		$this->assertSame( 'Handler did not finish.', $job->last_error );
		$audit = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Tables::name( 'audit' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 'job_abandoned', $audit->event );
		$this->assertSame( 5, json_decode( $audit->data, true )['attempts'] );
	}

	public function test_job_taken_by_another_runner_mid_batch_is_skipped() {
		$seen = array();
		add_action(
			'learning_ledger/jobs/test',
			static function ( $payload ) use ( &$seen ) {
				$seen[] = $payload['i'];
				if ( 0 === $payload['i'] ) {
					// Another runner takes job 1 while this runner works on job 0.
					$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE ' . Tables::name( 'jobs' ) . " SET lock_token = %s WHERE payload LIKE '%%\"i\":1%%'", str_repeat( 'b', 32 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
			}
		);
		Job_Queue::push( 'test', array( 'i' => 0 ) );
		Job_Queue::push( 'test', array( 'i' => 1 ) );
		$this->assertSame( 1, Job_Queue::run() );
		$this->assertSame( array( 0 ), $seen );
		$job = $this->jobs()[1];
		$this->assertSame( '0', $job->attempts );
		$this->assertSame( str_repeat( 'b', 32 ), $job->lock_token );
	}

	public function test_two_runners_do_not_double_claim() {
		$counts = array();
		add_action(
			'learning_ledger/jobs/test',
			static function ( $payload ) use ( &$counts ) {
				$counts[ $payload['i'] ] = ( $counts[ $payload['i'] ] ?? 0 ) + 1;
			}
		);
		for ( $i = 0; $i < 3; $i++ ) {
			Job_Queue::push( 'test', array( 'i' => $i ) );
		}

		// A second runner claims the same jobs after the first runner selected
		// them but before its claiming UPDATE runs.
		$second = null;
		add_filter(
			'query',
			static function ( $sql ) use ( &$second ) {
				if ( null === $second && 0 === strpos( ltrim( $sql ), 'UPDATE' ) && false !== strpos( $sql, 'SET lock_token' ) ) {
					$second = 0;
					$second = Job_Queue::run();
				}
				return $sql;
			}
		);
		$first = Job_Queue::run();

		$this->assertSame( 3, $second );
		$this->assertSame( 0, $first );
		$this->assertSame( array( 1, 1, 1 ), array_values( $counts ) );
	}

	public function test_expired_lock_is_reclaimed() {
		$seen = 0;
		add_action(
			'learning_ledger/jobs/test',
			static function () use ( &$seen ) {
				++$seen;
			}
		);
		Job_Queue::push( 'test', array() );
		$future = Timestamp::to_db( Clock::now()->modify( '+60 seconds' ) );
		$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE ' . Tables::name( 'jobs' ) . ' SET lock_token = %s, locked_until = %s', str_repeat( 'a', 32 ), $future ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 0, Job_Queue::run() );

		Clock::freeze( Clock::now()->modify( '+61 seconds' ) );
		$this->assertSame( 1, Job_Queue::run() );
		$this->assertSame( 1, $seen );
	}
}
