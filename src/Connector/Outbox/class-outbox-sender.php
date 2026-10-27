<?php
/**
 * Sends the outbox to the LRS.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Outbox;

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Connector\Pairing\Lrs_Client;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;

/**
 * PUTs each due row by its deterministic statement id, so a retry is idempotent.
 * Runs at `shutdown` (after the response) and every cron minute.
 */
final class Outbox_Sender {

	/**
	 * Retry delays in seconds after the 1st to 4th failure: the job queue's backoff (Job_Queue).
	 */
	private const BACKOFF = array( 60, 300, 900, 3600 );

	/**
	 * Retry delay after later failures, as the job queue.
	 */
	private const BACKOFF_LATER = 21600;

	/**
	 * Age after which a row that keeps failing is given up, as the job queue.
	 */
	private const GIVE_UP_SECONDS = 259200;

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
	 * Send due rows: 2xx sent, 409 conflict (audited), other 4xx failed, 5xx or no response retried.
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
			if ( $this->record( $row, $res->status ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Record the outcome of one PUT.
	 *
	 * @param object  $row    Row.
	 * @param integer $status HTTP status, 0 when there was no response.
	 *
	 * @return boolean Whether the row was sent.
	 */
	private function record( object $row, int $status ): bool {
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
		if ( $status >= 400 && $status < 500 ) {
			$this->repo->update( $id, Outbox_Repository::FAILED, $attempts, Timestamp::to_db( $now ) );
			return false;
		}

		$age = $now->getTimestamp() - Timestamp::from_db( (string) $row->created_at )->getTimestamp();
		if ( $age >= self::GIVE_UP_SECONDS ) {
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
		$delay = self::BACKOFF[ $attempts - 1 ] ?? self::BACKOFF_LATER;
		$this->repo->update( $id, Outbox_Repository::PENDING, $attempts, Timestamp::to_db( $now->modify( '+' . $delay . ' seconds' ) ) );
		return false;
	}
}
