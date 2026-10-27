<?php
/**
 * Sends signed webhook envelopes from the outbox.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Notify;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- outbox and statements are plugin-owned.

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Connector\Inbox\Inbox_Route;
use LearningLedger\Db\Tables;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

/**
 * Runs only from the queue (shutdown after the storing response, or the minute cron), never in a request.
 *
 * Per subscription, up to 100 pending rows in `seq` order (and at most 2 MB serialized) go in one
 * envelope `{event_id, subscription_id, cursor, statements}` holding the stored bodies byte for byte.
 * A failed envelope keeps its rows and event id and is resent unchanged after 1, 5, 15 and 60
 * minutes, then every 6 hours; nothing newer for that subscription is sent before it, so order is
 * kept. A failure streak older than 72 hours marks the subscription failing; the next success
 * marks it active. 2xx, and 409 (the inbox already has this event id), count as delivered.
 */
final class Webhook_Sender {

	const BATCH = 100;

	const TIMEOUT = 5;

	/**
	 * Largest envelope: the connector inbox refuses anything bigger.
	 */
	const MAX_BYTES = Inbox_Route::MAX_BYTES;

	const PENDING   = 'pending';
	const DELIVERED = 'delivered';

	/**
	 * Largest response body read.
	 */
	private const MAX_RESPONSE = 65536;

	/**
	 * Subscriptions.
	 *
	 * @var Subscription_Repository
	 */
	private Subscription_Repository $subs;

	/**
	 * Constructor.
	 *
	 * @param Subscription_Repository|null $subs Subscriptions.
	 */
	public function __construct( ?Subscription_Repository $subs = null ) {
		$this->subs = $subs ?? new Subscription_Repository();
	}

	/**
	 * Send due envelopes, one subscription after another.
	 *
	 * @param integer $max_batches Most envelopes to send in this run.
	 *
	 * @return integer Envelopes sent (delivered or failed).
	 */
	public function run( int $max_batches = 10 ): int {
		$ids  = $this->due_subscriptions();
		$sent = 0;
		while ( $sent < $max_batches && array() !== $ids ) {
			foreach ( $ids as $k => $id ) {
				if ( $sent >= $max_batches ) {
					break;
				}
				$result = $this->send_next( $id );
				if ( null !== $result ) {
					++$sent;
				}
				// Nothing left, or a failure: leave this subscription until its next attempt.
				if ( true !== $result ) {
					unset( $ids[ $k ] );
				}
			}
		}
		return $sent;
	}

	/**
	 * Whether a target may be contacted: not a private, loopback, link-local or reserved address,
	 * unless it is this site's own host or the `http_request_host_is_external` filter allows it
	 * (the same switches wp_safe_remote_post() honours). A host that does not resolve is left to
	 * the HTTP layer, which refuses it.
	 *
	 * @param string $url URL.
	 *
	 * @return boolean
	 */
	public static function target_allowed( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		$host = strtolower( trim( $host, '[].' ) );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( is_string( $home ) && strtolower( $home ) === $host ) {
			return true;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = array( $host );
		} else {
			$ips = gethostbynamel( $host );
			if ( false === $ips ) {
				return true;
			}
		}
		foreach ( $ips as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE ) ) {
				return (bool) apply_filters( 'http_request_host_is_external', false, $host, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own allow switch, as wp_http_validate_url() applies it.
			}
		}
		return true;
	}

	/**
	 * Subscriptions with a due pending row.
	 *
	 * @return int[]
	 */
	private function due_subscriptions(): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT subscription_id FROM %i WHERE status = %s AND next_attempt_at <= %s ORDER BY subscription_id',
					Tables::name( 'outbox' ),
					self::PENDING,
					Timestamp::to_db( Clock::now() )
				)
			)
		);
	}

	/**
	 * Send the next envelope of one subscription, holding a per-subscription lock.
	 *
	 * @param integer $sub_id Subscription id.
	 *
	 * @return boolean|null True delivered, false failed, null nothing sent.
	 */
	private function send_next( int $sub_id ): ?bool {
		$sub = $this->subs->find( $sub_id );
		if ( null === $sub ) {
			global $wpdb;
			$wpdb->delete( Tables::name( 'outbox' ), array( 'subscription_id' => $sub_id ), array( '%d' ) );
			return null;
		}
		if ( ! self::lock( $sub_id ) ) {
			return null;
		}
		try {
			$batch = $this->claim( $sub_id );
			if ( null === $batch ) {
				return null;
			}
			$seqs   = array_column( $batch['rows'], 'seq' );
			$bodies = $this->bodies( $seqs );
			if ( array() === $bodies ) {
				// Every statement in the batch was erased since it was queued; nothing to tell.
				$this->delivered( $batch['rows'] );
				return null;
			}
			$envelope = '{"event_id":' . wp_json_encode( $batch['event_id'] )
				. ',"subscription_id":' . $sub_id
				. ',"cursor":' . (int) max( $seqs )
				. ',"statements":[' . implode( ',', $bodies ) . ']}';

			if ( $this->post( $sub['url'], $envelope ) ) {
				$this->delivered( $batch['rows'] );
				$this->subs->advance_cursor( $sub_id, (int) max( $seqs ) );
				if ( Subscription_Repository::ACTIVE !== $sub['status'] || null !== $sub['failing_since'] ) {
					$this->subs->mark_active( $sub_id );
				}
				return true;
			}
			$this->failed( $batch['rows'] );
			$since = $this->subs->note_failure( $sub_id );
			if ( Subscription_Repository::FAILING !== $sub['status'] && Clock::now()->getTimestamp() - $since->getTimestamp() >= Job_Queue::give_up_seconds() ) {
				$this->subs->mark_failing( $sub_id );
				Audit_Log::record( 'subscription.failing', array( 'subscription_id' => $sub_id ) );
			}
			return false;
		} finally {
			self::unlock( $sub_id );
		}
	}

	/**
	 * The batch to send now: a failed envelope's rows when it is due again, otherwise up to 100
	 * due rows (within 2 MB) in seq order, given a new event id.
	 *
	 * @param integer $sub_id Subscription id.
	 *
	 * @return array{event_id:string,rows:array}|null
	 */
	private function claim( int $sub_id ): ?array {
		global $wpdb;
		$table = Tables::name( 'outbox' );
		$now   = Timestamp::to_db( Clock::now() );

		$retry = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT event_id, next_attempt_at FROM %i WHERE subscription_id = %d AND status = %s AND event_id IS NOT NULL ORDER BY seq, id LIMIT 1',
				$table,
				$sub_id,
				self::PENDING
			)
		);
		if ( null !== $retry ) {
			if ( (string) $retry->next_attempt_at > $now ) {
				return null;
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, seq, attempts FROM %i WHERE subscription_id = %d AND status = %s AND event_id = %s ORDER BY seq, id',
					$table,
					$sub_id,
					self::PENDING,
					(string) $retry->event_id
				),
				ARRAY_A
			);
			return array(
				'event_id' => (string) $retry->event_id,
				'rows'     => $rows,
			);
		}

		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT o.id, o.seq, o.attempts, COALESCE(LENGTH(s.body), 0) AS len FROM %i o LEFT JOIN %i s ON s.seq = o.seq WHERE o.subscription_id = %d AND o.status = %s AND o.event_id IS NULL AND o.next_attempt_at <= %s ORDER BY o.seq, o.id LIMIT %d',
				$table,
				Tables::name( 'statements' ),
				$sub_id,
				self::PENDING,
				$now,
				self::BATCH
			),
			ARRAY_A
		);
		if ( array() === $candidates ) {
			return null;
		}
		$event_id = Uuid::v4();
		// Envelope without statements, with room for the largest seq.
		$size = strlen( '{"event_id":"' . $event_id . '","subscription_id":' . $sub_id . ',"cursor":18446744073709551615,"statements":[]}' );
		$rows = array();
		foreach ( $candidates as $c ) {
			$add = (int) $c['len'] + ( array() === $rows ? 0 : 1 );
			if ( array() !== $rows && $size + $add > self::MAX_BYTES ) {
				break;
			}
			$size  += $add;
			$rows[] = $c;
		}
		$ids = array_map( 'intval', array_column( $rows, 'id' ) );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET event_id = %s WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND event_id IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
				array_merge( array( $table, $event_id ), $ids )
			)
		);
		return array(
			'event_id' => $event_id,
			'rows'     => $rows,
		);
	}

	/**
	 * Stored bodies for these seqs, in seq order. Erased statements are skipped.
	 *
	 * @param int[] $seqs Seqs.
	 *
	 * @return string[]
	 */
	private function bodies( array $seqs ): array {
		global $wpdb;
		$seqs = array_map( 'intval', $seqs );
		return $wpdb->get_col(
			$wpdb->prepare(
				'SELECT body FROM %i WHERE seq IN (' . implode( ',', array_fill( 0, count( $seqs ), '%d' ) ) . ') ORDER BY seq', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
				array_merge( array( Tables::name( 'statements' ) ), $seqs )
			)
		);
	}

	/**
	 * POST a signed envelope.
	 *
	 * @param string $url  Target.
	 * @param string $body Envelope JSON.
	 *
	 * @return boolean Whether it counts as delivered.
	 */
	private function post( string $url, string $body ): bool {
		if ( ! self::target_allowed( $url ) ) {
			return false;
		}
		try {
			$ts  = Clock::now()->getTimestamp();
			$key = Notify_Keys::active();
			$sig = Notify_Keys::sign( $body, $ts );
		} catch ( \RuntimeException $e ) {
			return false;
		}
		$res = wp_safe_remote_post(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_RESPONSE,
				'headers'             => array(
					'Content-Type'                => 'application/json',
					'X-Learning-Ledger-Timestamp' => (string) $ts,
					'X-Learning-Ledger-Key'       => $key['kid'],
					'X-Learning-Ledger-Signature' => $sig,
				),
				'body'                => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return ( $code >= 200 && $code < 300 ) || 409 === $code;
	}

	/**
	 * Mark rows delivered.
	 *
	 * @param array $rows Rows with `id`.
	 *
	 * @return void
	 */
	private function delivered( array $rows ): void {
		global $wpdb;
		$ids = array_map( 'intval', array_column( $rows, 'id' ) );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, delivered_at = %s WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
				array_merge( array( Tables::name( 'outbox' ), self::DELIVERED, Timestamp::to_db( Clock::now() ) ), $ids )
			)
		);
	}

	/**
	 * Count a failed attempt and schedule the retry.
	 *
	 * @param array $rows Rows with `id` and `attempts`.
	 *
	 * @return void
	 */
	private function failed( array $rows ): void {
		global $wpdb;
		$attempts = 1 + (int) max( array_map( 'intval', array_column( $rows, 'attempts' ) ) );
		$next     = Clock::now()->modify( '+' . Job_Queue::retry_delay( $attempts ) . ' seconds' );
		$ids      = array_map( 'intval', array_column( $rows, 'id' ) );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET attempts = %d, next_attempt_at = %s WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
				array_merge( array( Tables::name( 'outbox' ), $attempts, Timestamp::to_db( $next ) ), $ids )
			)
		);
	}

	/**
	 * Take the per-subscription lock without waiting (a concurrent runner already has it).
	 *
	 * @param integer $sub_id Subscription id.
	 *
	 * @return boolean
	 */
	private static function lock( int $sub_id ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name( $sub_id ) ) );
	}

	/**
	 * Release the per-subscription lock.
	 *
	 * @param integer $sub_id Subscription id.
	 *
	 * @return void
	 */
	private static function unlock( int $sub_id ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $sub_id ) ) );
	}

	/**
	 * Lock name, unique per database and table prefix (MySQL allows 64 characters).
	 *
	 * @param integer $sub_id Subscription id.
	 *
	 * @return string
	 */
	private static function lock_name( int $sub_id ): string {
		global $wpdb;
		return 'lledger_notify_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 16 ) . '_' . $sub_id;
	}
}
