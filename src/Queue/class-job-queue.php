<?php
/**
 * Jobs table queue.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Queue;

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;

/**
 * Deferred work: pushed with one INSERT, run at shutdown or by cron.
 *
 * Handlers listen on `learning_ledger/jobs/{$type}` and receive the payload.
 * A handler that throws is retried after 1, 5, 15 and 60 minutes, then every
 * 6 hours, and abandoned (with an audit row) once the job is 72 hours old.
 */
final class Job_Queue {

	/**
	 * Option holding the time of the last run.
	 */
	public const LAST_RUN_OPTION = 'lledger_queue_last_run';

	/**
	 * Seconds a claim holds a job.
	 */
	private const LOCK_SECONDS = 60;

	/**
	 * Retry delays in seconds after the 1st, 2nd, 3rd and 4th failure.
	 */
	private const BACKOFF = array( 60, 300, 900, 3600 );

	/**
	 * Retry delay in seconds after the 5th and later failures.
	 */
	private const BACKOFF_LATER = 21600;

	/**
	 * Age in seconds after which a failing job is abandoned.
	 */
	private const GIVE_UP_SECONDS = 259200;

	/**
	 * Longest error message kept.
	 */
	private const MAX_ERROR_LENGTH = 1000;

	/**
	 * Queue a job. A `dedupe_key` already held by an undone job makes this a no-op.
	 *
	 * @param string      $type       Job type (the hook suffix).
	 * @param array       $payload    JSON-serialisable payload.
	 * @param string|null $dedupe_key Optional key; at most one undone job holds it.
	 * @param integer     $delay      Seconds before the job is due.
	 *
	 * @return void
	 */
	public static function push( string $type, array $payload, ?string $dedupe_key = null, int $delay = 0 ): void {
		global $wpdb;
		$now   = Clock::now();
		$table = Tables::name( 'jobs' );
		$args  = array( $type, wp_json_encode( $payload ) );
		if ( null === $dedupe_key ) {
			$key_sql = 'NULL';
		} else {
			$key_sql = '%s';
			$args[]  = $dedupe_key;
		}
		$args[] = Timestamp::to_db( $now->modify( '+' . max( 0, $delay ) . ' seconds' ) );
		$args[] = Timestamp::to_db( $now );

		// ON DUPLICATE KEY makes a concurrent push of the same key a no-op instead of an error.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are passed as one array.
				"INSERT INTO {$table} (type, payload, dedupe_key, run_after, attempts, created_at) VALUES (%s, %s, {$key_sql}, %s, 0, %s) ON DUPLICATE KEY UPDATE id = id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$args
			)
		);

		if ( $delay <= 0 ) {
			Shutdown_Runner::request();
		}
	}

	/**
	 * Claim and run due jobs.
	 *
	 * @param integer $max_jobs    Most jobs to claim.
	 * @param integer $max_seconds Stop starting new jobs after this many seconds.
	 *
	 * @return integer Jobs run (succeeded or failed).
	 */
	public static function run( int $max_jobs = 50, int $max_seconds = 20 ): int {
		$started = microtime( true );
		$token   = bin2hex( random_bytes( 16 ) );
		self::abandon_stale();
		$jobs = self::claim( $token, max( 1, $max_jobs ) );
		$ran  = 0;

		foreach ( $jobs as $job ) {
			if ( microtime( true ) - $started >= $max_seconds ) {
				break;
			}
			if ( ! self::start( $job ) ) {
				continue;
			}
			self::execute( $job );
			++$ran;
		}
		self::release( $token );

		if ( $ran > 0 ) {
			update_option( self::LAST_RUN_OPTION, self::iso( Clock::now() ), false );
		}
		return $ran;
	}

	/**
	 * Undone jobs that are due now.
	 *
	 * @return integer
	 */
	public static function due_count(): int {
		global $wpdb;
		$table = Tables::name( 'jobs' );
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE done_at IS NULL AND run_after <= %s", Timestamp::to_db( Clock::now() ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Time of the last run (ISO 8601, UTC), or null if the queue never ran.
	 *
	 * @return string|null
	 */
	public static function last_run(): ?string {
		$v = get_option( self::LAST_RUN_OPTION, null );
		return is_string( $v ) && '' !== $v ? $v : null;
	}

	/**
	 * Lock due, unlocked jobs with this runner's token and return them.
	 *
	 * The UPDATE repeats the due-and-unlocked condition, so a job another
	 * runner claimed after our SELECT is not taken twice.
	 *
	 * @param string  $token    Lock token.
	 * @param integer $max_jobs Most jobs to claim.
	 *
	 * @return object[] Claimed rows, in due order.
	 */
	private static function claim( string $token, int $max_jobs ): array {
		global $wpdb;
		$table = Tables::name( 'jobs' );
		$now   = Timestamp::to_db( Clock::now() );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE done_at IS NULL AND run_after <= %s AND (locked_until IS NULL OR locked_until < %s) ORDER BY run_after, id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$now,
				$max_jobs
			)
		);
		if ( empty( $ids ) ) {
			return array();
		}
		$ids          = array_map( 'intval', $ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$until        = Timestamp::to_db( Clock::now()->modify( '+' . self::LOCK_SECONDS . ' seconds' ) );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are passed as one array.
				"UPDATE {$table} SET lock_token = %s, locked_until = %s WHERE id IN ({$placeholders}) AND done_at IS NULL AND run_after <= %s AND (locked_until IS NULL OR locked_until < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $token, $until ), $ids, array( $now, $now ) )
			)
		);

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE lock_token = %s AND done_at IS NULL ORDER BY run_after, id", $token ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Abandon undone jobs that were already attempted and are now 72 hours old.
	 *
	 * Catches jobs whose handler died without throwing (fatal error, timeout,
	 * exit): they never reach fail(), but start() counted the attempt.
	 *
	 * @return void
	 */
	private static function abandon_stale(): void {
		global $wpdb;
		$table   = Tables::name( 'jobs' );
		$now     = Timestamp::to_db( Clock::now() );
		$cut_off = Timestamp::to_db( Clock::now()->modify( '-' . self::GIVE_UP_SECONDS . ' seconds' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, type, attempts FROM {$table} WHERE done_at IS NULL AND attempts > 0 AND created_at <= %s AND run_after <= %s AND (locked_until IS NULL OR locked_until < %s) ORDER BY id LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cut_off,
				$now,
				$now
			)
		);
		foreach ( $rows as $row ) {
			$changed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"UPDATE {$table} SET done_at = %s, dedupe_key = NULL, lock_token = NULL, locked_until = NULL, last_error = COALESCE(last_error, %s) WHERE id = %d AND done_at IS NULL AND (locked_until IS NULL OR locked_until < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$now,
					'Handler did not finish.',
					(int) $row->id,
					$now
				)
			);
			if ( 1 === $changed ) {
				self::audit_abandoned( (int) $row->id, (string) $row->type, (int) $row->attempts );
			}
		}
	}

	/**
	 * Confirm this runner still holds the job, and count the attempt before dispatch.
	 *
	 * The attempt and its backoff are written first, so a handler that dies
	 * without throwing still moves the job down the backoff schedule. The lock
	 * is refreshed so later jobs in a long batch stay held.
	 *
	 * @param object $job Claimed row; `attempts` is updated in place.
	 *
	 * @return boolean False if another runner took the job.
	 */
	private static function start( object $job ): bool {
		global $wpdb;
		$table    = Tables::name( 'jobs' );
		$attempts = (int) $job->attempts + 1;
		$now      = Clock::now();
		$changed  = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET attempts = %d, run_after = %s, locked_until = %s WHERE id = %d AND lock_token = %s AND done_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$attempts,
				Timestamp::to_db( $now->modify( '+' . self::retry_delay( $attempts ) . ' seconds' ) ),
				Timestamp::to_db( $now->modify( '+' . self::LOCK_SECONDS . ' seconds' ) ),
				(int) $job->id,
				(string) $job->lock_token
			)
		);
		if ( 1 !== $changed ) {
			return false;
		}
		$job->attempts = $attempts;
		return true;
	}

	/**
	 * Dispatch one started job and record the outcome.
	 *
	 * @param object $job Row, with `attempts` already counted.
	 *
	 * @return void
	 */
	private static function execute( object $job ): void {
		$payload = json_decode( (string) $job->payload, true );
		try {
			do_action( "learning_ledger/jobs/{$job->type}", is_array( $payload ) ? $payload : array() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		} catch ( \Throwable $e ) {
			self::fail( $job, $e );
			return;
		}
		self::finish( $job, array() );
	}

	/**
	 * Record a failure: back off, or abandon once the job is 72 hours old.
	 *
	 * @param object     $job Row, with `attempts` already counted.
	 * @param \Throwable $e   What the handler threw.
	 *
	 * @return void
	 */
	private static function fail( object $job, \Throwable $e ): void {
		global $wpdb;
		$attempts = (int) $job->attempts;
		$error    = mb_substr( $e->getMessage(), 0, self::MAX_ERROR_LENGTH );
		$now      = Clock::now();
		$age      = $now->getTimestamp() - Timestamp::from_db( (string) $job->created_at )->getTimestamp();

		if ( $age >= self::GIVE_UP_SECONDS ) {
			self::finish( $job, array( 'last_error' => $error ) );
			self::audit_abandoned( (int) $job->id, (string) $job->type, $attempts );
			return;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Tables::name( 'jobs' ),
			array(
				'last_error'   => $error,
				'run_after'    => Timestamp::to_db( $now->modify( '+' . self::retry_delay( $attempts ) . ' seconds' ) ),
				'lock_token'   => null,
				'locked_until' => null,
			),
			array(
				'id'         => (int) $job->id,
				'lock_token' => (string) $job->lock_token,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Seconds to wait before retrying after the given attempt.
	 *
	 * @param integer $attempts Attempts made so far (1 or more).
	 *
	 * @return integer
	 */
	public static function retry_delay( int $attempts ): int {
		return self::BACKOFF[ $attempts - 1 ] ?? self::BACKOFF_LATER;
	}

	/**
	 * Age in seconds after which failing work is given up.
	 *
	 * @return integer
	 */
	public static function give_up_seconds(): int {
		return self::GIVE_UP_SECONDS;
	}

	/**
	 * Audit row for a job given up on.
	 *
	 * @param integer $id       Job id.
	 * @param string  $type     Job type.
	 * @param integer $attempts Attempts made.
	 *
	 * @return void
	 */
	private static function audit_abandoned( int $id, string $type, int $attempts ): void {
		Audit_Log::record(
			'job_abandoned',
			array(
				'job'      => $id,
				'type'     => $type,
				'attempts' => $attempts,
			)
		);
	}

	/**
	 * Mark a job done. Its dedupe key is released so the same work can be queued again.
	 *
	 * @param object $job    Row.
	 * @param array  $fields Extra columns to set (`last_error`).
	 *
	 * @return void
	 */
	private static function finish( object $job, array $fields ): void {
		global $wpdb;
		$data = array_merge(
			$fields,
			array(
				'done_at'      => Timestamp::to_db( Clock::now() ),
				'dedupe_key'   => null,
				'lock_token'   => null,
				'locked_until' => null,
			)
		);
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Tables::name( 'jobs' ),
			$data,
			array(
				'id'         => (int) $job->id,
				'lock_token' => (string) $job->lock_token,
			),
			array_fill( 0, count( $data ), '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Unlock jobs this runner claimed but did not reach.
	 *
	 * @param string $token Lock token.
	 *
	 * @return void
	 */
	private static function release( string $token ): void {
		global $wpdb;
		$table = Tables::name( 'jobs' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "UPDATE {$table} SET lock_token = NULL, locked_until = NULL WHERE lock_token = %s AND done_at IS NULL", $token ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * ISO 8601 UTC with microseconds, e.g. `2026-10-27T10:00:00.000000Z`.
	 *
	 * @param \DateTimeImmutable $t Time.
	 *
	 * @return string
	 */
	public static function iso( \DateTimeImmutable $t ): string {
		return $t->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.u\Z' );
	}
}
