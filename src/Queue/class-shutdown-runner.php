<?php
/**
 * Runs queued work at shutdown, after the response has been sent.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Queue;

use LearningLedger\Util\Clock;

/**
 * Shutdown-time processing.
 *
 * A request that queues work calls request(); at `shutdown` the response is
 * flushed with fastcgi_finish_request() where available, then the job queue
 * and any added callbacks run.
 */
final class Shutdown_Runner {

	/**
	 * Option recording the last shutdown run.
	 */
	public const LAST_RUN_OPTION = 'lledger_shutdown_last_run';

	/**
	 * Whether a run was requested in this request.
	 *
	 * @var bool
	 */
	private static bool $requested = false;

	/**
	 * Extra callbacks to run after the queue.
	 *
	 * @var callable[]
	 */
	private static array $callbacks = array();

	/**
	 * Ask for the queue to run at shutdown.
	 *
	 * @return void
	 */
	public static function request(): void {
		self::$requested = true;
		if ( false === has_action( 'shutdown', array( self::class, 'on_shutdown' ) ) ) {
			add_action( 'shutdown', array( self::class, 'on_shutdown' ) );
		}
	}

	/**
	 * Run a callback at shutdown, after the queue.
	 *
	 * @param callable $cb Callback.
	 *
	 * @return void
	 */
	public static function add( callable $cb ): void {
		self::$callbacks[] = $cb;
		self::request();
	}

	/**
	 * Shutdown handler.
	 *
	 * @return void
	 */
	public static function on_shutdown(): void {
		if ( ! self::$requested ) {
			return;
		}
		$callbacks       = self::$callbacks;
		self::$requested = false;
		self::$callbacks = array();

		$fastcgi = function_exists( 'fastcgi_finish_request' );
		if ( $fastcgi ) {
			fastcgi_finish_request();
		}

		Job_Queue::run();
		foreach ( $callbacks as $cb ) {
			try {
				$cb();
			} catch ( \Throwable $e ) {
				// One failing callback must not stop the others; the response has already been sent.
				unset( $e );
			}
		}

		update_option(
			self::LAST_RUN_OPTION,
			array(
				'at'                     => Job_Queue::iso( Clock::now() ),
				'fastcgi_finish_request' => $fastcgi,
			),
			false
		);
	}
}
