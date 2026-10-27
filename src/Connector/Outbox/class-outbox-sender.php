<?php
/**
 * Sends the outbox to the LRS.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Outbox;

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Connector\Pairing\Lrs_Client;
use LearningLedger\Connector\Pairing\Lrs_Response;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;

/**
 * PUTs each due row by its deterministic statement id, so a retry is idempotent.
 * Runs at `shutdown` (after the response) and every cron minute.
 */
final class Outbox_Sender {

	/**
	 * Seconds a claim holds a row.
	 */
	private const LOCK_SECONDS = 60;

	/**
	 * LRS client.
	 *
	 * @var Lrs_Client
	 */
	private Lrs_Client $client;

	/**
	 * Repository.
	 *
	 * @var Outbox_Repository
	 */
	private Outbox_Repository $repo;

	/**
	 * Constructor.
	 *
	 * @param Lrs_Client|null        $client LRS client.
	 * @param Outbox_Repository|null $repo   Repository.
	 */
	public function __construct( ?Lrs_Client $client = null, ?Outbox_Repository $repo = null ) {
		$this->client = $client ?? new Lrs_Client();
		$this->repo   = $repo ?? new Outbox_Repository();
	}

	/**
	 * Send due rows: 2xx sent, 409 conflict (audited), other 4xx failed; 408, 429, 5xx or no response
	 * retried on the job queue's backoff, never earlier than a Retry-After.
	 *
	 * @param integer $limit Most rows to send.
	 *
	 * @return integer Rows sent with a 2xx.
	 */
	public function flush( int $limit = 50 ): int {
		$sent = 0;
		foreach ( $this->repo->due( $limit ) as $row ) {
			$until = Timestamp::to_db( Clock::now()->modify( '+' . self::LOCK_SECONDS . ' seconds' ) );
			if ( ! $this->repo->claim( $row, $until ) ) {
				continue;
			}
			try {
				$res = $this->client->xapi( 'PUT', 'statements', array( 'statementId' => $row->statement_id ), (string) $row->body, array( 'Content-Type' => 'application/json' ) );
			} catch ( \RuntimeException $e ) {
				// Not paired: put the row back as it was and stop.
				$this->repo->update( (int) $row->id, Outbox_Repository::PENDING, (int) $row->attempts, (string) $row->next_attempt_at );
				break;
			}
			if ( $this->record( $row, $res ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Record the outcome of one PUT.
	 *
	 * @param object       $row Row.
	 * @param Lrs_Response $res Response; status 0 when there was none.
	 *
	 * @return boolean Whether the row was sent.
	 */
	private function record( object $row, Lrs_Response $res ): bool {
		$status   = $res->status;
		$id       = (int) $row->id;
		$attempts = (int) $row->attempts + 1;
		$now      = Clock::now();

		if ( $status >= 200 && $status < 300 ) {
			$this->repo->update( $id, Outbox_Repository::SENT, $attempts, Timestamp::to_db( $now ) );
			return true;
		}
		if ( 409 === $status ) {
			$this->repo->update( $id, Outbox_Repository::CONFLICT, $attempts, Timestamp::to_db( $now ) );
			Audit_Log::record(
				'outbox_conflict',
				array(
					'statement_id' => $row->statement_id,
					'course_id'    => (int) $row->course_id,
				)
			);
			return false;
		}
		if ( $status >= 400 && $status < 500 && 408 !== $status && 429 !== $status ) {
			$this->repo->update( $id, Outbox_Repository::FAILED, $attempts, Timestamp::to_db( $now ) );
			return false;
		}

		$age = $now->getTimestamp() - Timestamp::from_db( (string) $row->created_at )->getTimestamp();
		if ( $age >= Job_Queue::give_up_seconds() ) {
			$this->repo->update( $id, Outbox_Repository::FAILED, $attempts, Timestamp::to_db( $now ) );
			Audit_Log::record(
				'outbox_abandoned',
				array(
					'statement_id' => $row->statement_id,
					'attempts'     => $attempts,
					'status'       => $status,
				)
			);
			return false;
		}
		$delay = max( Job_Queue::retry_delay( $attempts ), self::retry_after( $res ) );
		$this->repo->update( $id, Outbox_Repository::PENDING, $attempts, Timestamp::to_db( $now->modify( '+' . $delay . ' seconds' ) ) );
		return false;
	}

	/**
	 * Seconds from a Retry-After header (delta seconds or HTTP date), 0 when absent or invalid.
	 *
	 * @param Lrs_Response $res Response.
	 *
	 * @return integer
	 */
	private static function retry_after( Lrs_Response $res ): int {
		$value = $res->headers['retry-after'] ?? '';
		$value = trim( (string) ( is_array( $value ) ? reset( $value ) : $value ) );
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}
		$at = strtotime( $value );
		return false === $at ? 0 : max( 0, $at - Clock::now()->getTimestamp() );
	}
}
