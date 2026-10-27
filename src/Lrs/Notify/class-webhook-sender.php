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
 * A 400 for a multi-statement envelope resends its rows one statement per envelope; a single
 * statement the receiver refuses with a 4xx (other than 408, 409, 429) is skipped and audited.
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
	const SKIPPED   = 'skipped';

	/**
	 * Seconds a statement must have been stored before it is batched (consistent-through `now - 1 s`).
	 */
	const SETTLE_SECONDS = 1;

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
				$sent  += $result['sent'];
				// Nothing left, or a failure: leave this subscription until its next attempt.
				if ( ! $result['more'] ) {
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
	 * A 400 for an envelope of several statements means one of them is unacceptable to the
	 * receiver: its rows are resent as single-statement envelopes, each with its own event id.
	 *
	 * @param integer $sub_id Subscription id.
	 *
	 * @return array{sent:int,more:bool} Envelopes posted, and whether to go on with this subscription.
	 */
	private function send_next( int $sub_id ): array {
		$stop = array(
			'sent' => 0,
			'more' => false,
		);
		$sub  = $this->subs->find( $sub_id );
		if ( null === $sub ) {
			global $wpdb;
			$wpdb->delete( Tables::name( 'outbox' ), array( 'subscription_id' => $sub_id ), array( '%d' ) );
			return $stop;
		}
		if ( ! self::lock( $sub_id ) ) {
			return $stop;
		}
		try {
			$batch = $this->claim( $sub_id );
			if ( null === $batch ) {
				return $stop;
			}
			$seqs   = array_map( 'intval', array_column( $batch['rows'], 'seq' ) );
			$bodies = $this->bodies( $seqs );
			if ( array() === $bodies ) {
				// Every statement in the batch was erased since it was queued; nothing to tell.
				$this->delivered( $sub, $batch['rows'] );
				return array(
					'sent' => 0,
					'more' => true,
				);
			}

			$code = $this->post( $sub['url'], self::envelope( $batch['event_id'], $sub_id, max( $seqs ), $bodies ) );
			if ( self::is_delivered( $code ) ) {
				$this->delivered( $sub, $batch['rows'] );
				return array(
					'sent' => 1,
					'more' => true,
				);
			}
			if ( 400 === $code ) {
				if ( count( $batch['rows'] ) > 1 ) {
					$singles = $this->send_singly( $sub, $batch['rows'] );
					return array(
						'sent' => 1 + $singles['sent'],
						'more' => $singles['more'],
					);
				}
				$this->skip( $sub, $batch['rows'][0], $code );
				return array(
					'sent' => 1,
					'more' => true,
				);
			}
			$this->failed( $sub, $batch['rows'] );
			return array(
				'sent' => 1,
				'more' => false,
			);
		} finally {
			self::unlock( $sub_id );
		}
	}

	/**
	 * Resend a refused batch one statement per envelope, in seq order. A statement the receiver
	 * still refuses with a 4xx (other than 408, 409 and 429) is skipped; a transport failure,
	 * 5xx, 408 or 429 stops here and that statement is retried on the backoff schedule, the
	 * rest after it.
	 *
	 * @param array $sub  Subscription.
	 * @param array $rows Rows with `id`, `seq` and `attempts`.
	 *
	 * @return array{sent:int,more:bool}
	 */
	private function send_singly( array $sub, array $rows ): array {
		global $wpdb;
		$table = Tables::name( 'outbox' );
		foreach ( $rows as $k => $row ) {
			$rows[ $k ]['event_id'] = Uuid::v4();
			$wpdb->update( $table, array( 'event_id' => $rows[ $k ]['event_id'] ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
		}
		$sent = 0;
		foreach ( $rows as $row ) {
			$seq    = (int) $row['seq'];
			$bodies = $this->bodies( array( $seq ) );
			if ( array() === $bodies ) {
				$this->delivered( $sub, array( $row ) );
				continue;
			}
			$code = $this->post( $sub['url'], self::envelope( $row['event_id'], $sub['id'], $seq, $bodies ) );
			++$sent;
			if ( self::is_delivered( $code ) ) {
				$this->delivered( $sub, array( $row ) );
				continue;
			}
			if ( $code >= 400 && $code < 500 && ! in_array( $code, array( 408, 429 ), true ) ) {
				$this->skip( $sub, $row, $code );
				continue;
			}
			$this->failed( $sub, array( $row ) );
			return array(
				'sent' => $sent,
				'more' => false,
			);
		}
		return array(
			'sent' => $sent,
			'more' => true,
		);
	}

	/**
	 * Envelope JSON with the stored bodies embedded byte for byte.
	 *
	 * @param string   $event_id Event id.
	 * @param integer  $sub_id   Subscription id.
	 * @param integer  $cursor   Last seq in the envelope.
	 * @param string[] $bodies   Statement bodies in seq order.
	 *
	 * @return string
	 */
	private static function envelope( string $event_id, int $sub_id, int $cursor, array $bodies ): string {
		return '{"event_id":' . wp_json_encode( $event_id )
			. ',"subscription_id":' . $sub_id
			. ',"cursor":' . $cursor
			. ',"statements":[' . implode( ',', $bodies ) . ']}';
	}

	/**
	 * Whether a response status counts as delivered: 2xx, or 409 (the inbox already has this event id).
	 *
	 * @param integer $code HTTP status, 0 when nothing was received.
	 *
	 * @return boolean
	 */
	private static function is_delivered( int $code ): bool {
		return ( $code >= 200 && $code < 300 ) || 409 === $code;
	}

	/**
	 * The batch to send now: a failed envelope's rows when it is due again, otherwise up to 100
	 * due rows (within 2 MB) in seq order, given a new event id. A new batch takes only statements
	 * stored at least a second ago (the read API's consistent-through point), so a statement whose
	 * transaction took a lower seq but commits later is not overtaken.
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
				'SELECT o.id, o.seq, o.attempts, COALESCE(LENGTH(s.body), 0) AS len FROM %i o LEFT JOIN %i s ON s.seq = o.seq WHERE o.subscription_id = %d AND o.status = %s AND o.event_id IS NULL AND o.next_attempt_at <= %s AND (s.seq IS NULL OR s.`stored` <= %s) ORDER BY o.seq, o.id LIMIT %d',
				$table,
				Tables::name( 'statements' ),
				$sub_id,
				self::PENDING,
				$now,
				Timestamp::to_db( Clock::now()->modify( '-' . self::SETTLE_SECONDS . ' seconds' ) ),
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
	 * @return integer HTTP status, or 0 when nothing was received (refused target, transport error, timeout).
	 */
	private function post( string $url, string $body ): int {
		if ( ! self::target_allowed( $url ) ) {
			return 0;
		}
		try {
			$ts  = Clock::now()->getTimestamp();
			$key = Notify_Keys::active();
			$sig = Notify_Keys::sign( $body, $ts );
		} catch ( \RuntimeException $e ) {
			return 0;
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
		return is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	}

	/**
	 * Mark rows delivered, move the cursor past them and end any failure streak.
	 *
	 * @param array $sub  Subscription.
	 * @param array $rows Rows with `id` and `seq`.
	 *
	 * @return void
	 */
	private function delivered( array $sub, array $rows ): void {
		$this->finish( $rows, self::DELIVERED );
		$this->subs->advance_cursor( $sub['id'], (int) max( array_map( 'intval', array_column( $rows, 'seq' ) ) ) );
		if ( Subscription_Repository::ACTIVE !== $sub['status'] || null !== $sub['failing_since'] ) {
			$this->subs->mark_active( $sub['id'] );
		}
	}

	/**
	 * Skip a statement the receiver refuses, with an audit row (ids and status only), and move the cursor past it.
	 *
	 * @param array   $sub  Subscription.
	 * @param array   $row  Row with `id` and `seq`.
	 * @param integer $code HTTP status received.
	 *
	 * @return void
	 */
	private function skip( array $sub, array $row, int $code ): void {
		$this->finish( array( $row ), self::SKIPPED );
		$this->subs->advance_cursor( $sub['id'], (int) $row['seq'] );
		Audit_Log::record(
			'webhook_statement_skipped',
			array(
				'subscription_id' => $sub['id'],
				'seq'             => (int) $row['seq'],
				'status'          => $code,
			)
		);
	}

	/**
	 * Set rows to a final status.
	 *
	 * @param array  $rows   Rows with `id`.
	 * @param string $status Final status, `delivered` or `skipped`.
	 *
	 * @return void
	 */
	private function finish( array $rows, string $status ): void {
		global $wpdb;
		$ids = array_map( 'intval', array_column( $rows, 'id' ) );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, delivered_at = %s WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
				array_merge( array( Tables::name( 'outbox' ), $status, Timestamp::to_db( Clock::now() ) ), $ids )
			)
		);
	}

	/**
	 * Count a failed attempt, schedule the retry and mark the subscription failing once the
	 * failure streak is 72 hours old.
	 *
	 * @param array $sub  Subscription.
	 * @param array $rows Rows with `id` and `attempts`.
	 *
	 * @return void
	 */
	private function failed( array $sub, array $rows ): void {
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
		$since = $this->subs->note_failure( $sub['id'] );
		if ( Subscription_Repository::FAILING !== $sub['status'] && Clock::now()->getTimestamp() - $since->getTimestamp() >= Job_Queue::give_up_seconds() ) {
			$this->subs->mark_failing( $sub['id'] );
			Audit_Log::record( 'subscription.failing', array( 'subscription_id' => $sub['id'] ) );
		}
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
