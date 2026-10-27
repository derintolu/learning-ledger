<?php
/**
 * Signed webhook receiver.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Inbox;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- conn_events is plugin-owned.

use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Crypto\Notification_Signature;
use LearningLedger\Db\Tables;
use LearningLedger\Queue\Shutdown_Runner;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

/**
 * `POST /learning-ledger/v1/inbox`.
 *
 * Order of checks: pinned key id, timestamp window, Ed25519 signature over `ts.body`, then the
 * body shape, then `event_id` replay. Nothing is written before the signature verifies.
 */
final class Inbox_Route {

	const NS     = 'learning-ledger/v1';
	const ROUTE  = '/inbox';
	const WINDOW = 300;

	/**
	 * Largest envelope accepted: twice the LRS's 1 MB statements request limit.
	 */
	const MAX_BYTES = 2097152;

	/**
	 * Inbox.
	 *
	 * @var Inbox_Repository
	 */
	private Inbox_Repository $inbox;

	/**
	 * Constructor.
	 *
	 * @param Inbox_Repository|null $inbox Inbox.
	 */
	public function __construct( ?Inbox_Repository $inbox = null ) {
		$this->inbox = $inbox ?? new Inbox_Repository();
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// The route authenticates itself: the LRS signs every delivery with its pinned key.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle a delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$ts   = (string) $request->get_header( 'X-Learning-Ledger-Timestamp' );
		$kid  = (string) $request->get_header( 'X-Learning-Ledger-Key' );
		$sig  = (string) $request->get_header( 'X-Learning-Ledger-Signature' );
		$body = (string) $request->get_body();

		$settings   = Connector_Settings::get();
		$pinned_kid = (string) $settings['notify_kid'];
		$public_key = base64_decode( (string) $settings['notify_public_key'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- public key transport encoding.

		if ( '' === $ts || '' === $kid || '' === $sig ) {
			return self::error( 401, 'missing_header' );
		}
		if ( '' === $pinned_kid || false === $public_key || 32 !== strlen( $public_key ) || ! hash_equals( $pinned_kid, $kid ) ) {
			return self::error( 401, 'unknown_key' );
		}
		if ( 1 !== preg_match( '/\A[0-9]{1,12}\z/', $ts ) || abs( Clock::now()->getTimestamp() - (int) $ts ) > self::WINDOW ) {
			return self::error( 401, 'stale' );
		}
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return self::error( 413, 'too_large' );
		}
		if ( ! Notification_Signature::verify( $sig, $ts, $body, $public_key ) ) {
			return self::error( 401, 'bad_signature' );
		}

		try {
			// The LRS accepts statements nested 64 deep; the envelope object and its statements list add two.
			$envelope = Json::decode_strict( $body, self::MAX_BYTES, 64 + 2 );
		} catch ( Invalid_Input $e ) {
			return self::error( 400, 'malformed' );
		}
		if ( ! is_array( $envelope ) || ! is_string( $envelope['event_id'] ?? null ) || ! Uuid::is_valid( $envelope['event_id'] ) || ! is_array( $envelope['statements'] ?? null ) || ! Json::is_list( $envelope['statements'] ) ) {
			return self::error( 400, 'malformed' );
		}

		$event_id = strtolower( $envelope['event_id'] );
		$seen     = self::claim_event( $event_id );
		if ( null === $seen ) {
			( new Catch_Up_Poller() )->need_recovery();
			return self::error( 503, 'unavailable' );
		}
		if ( false === $seen ) {
			return self::error( 409, 'replay' );
		}

		$stored = 0;
		foreach ( $envelope['statements'] as $statement ) {
			if ( ! is_array( $statement ) ) {
				continue;
			}
			$result = $this->inbox->store( $statement, 'webhook' );
			if ( Inbox_Repository::ERROR === $result ) {
				// Forget the event so the LRS's redelivery is accepted; rows already stored dedupe by id.
				self::release_event( $event_id );
				( new Catch_Up_Poller() )->need_recovery();
				return self::error( 503, 'unavailable' );
			}
			if ( Inbox_Repository::ADDED === $result ) {
				++$stored;
			}
		}

		Shutdown_Runner::add( array( new Inbox_Processor(), 'process_pending' ) );
		$poller = new Catch_Up_Poller();
		if ( $poller->recovery_needed() ) {
			Shutdown_Runner::add( array( $poller, 'run' ) );
		}

		return new \WP_REST_Response( array( 'stored' => $stored ), 202 );
	}

	/**
	 * Delete remembered event ids older than `$seconds`.
	 *
	 * @param integer $seconds Age.
	 *
	 * @return integer Rows deleted.
	 */
	public static function prune_events( int $seconds ): int {
		global $wpdb;
		$table = Tables::name( 'conn_events' );
		$n     = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE received_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Timestamp::to_db( Clock::now()->modify( '-' . max( 0, $seconds ) . ' seconds' ) )
			)
		);
		return false === $n ? 0 : (int) $n;
	}

	/**
	 * Remember an event id.
	 *
	 * @param string $event_id Lowercase UUID.
	 *
	 * @return boolean|null True when new, false when already seen, null on a database error.
	 */
	private static function claim_event( string $event_id ): ?bool {
		global $wpdb;
		$table = Tables::name( 'conn_events' );
		$n     = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$table}` (event_id, received_at) VALUES (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$event_id,
				Timestamp::to_db( Clock::now() )
			)
		);
		if ( false === $n ) {
			return null;
		}
		return 1 === $n;
	}

	/**
	 * Forget an event id.
	 *
	 * @param string $event_id Lowercase UUID.
	 *
	 * @return void
	 */
	private static function release_event( string $event_id ): void {
		global $wpdb;
		$wpdb->delete( Tables::name( 'conn_events' ), array( 'event_id' => $event_id ), array( '%s' ) );
	}

	/**
	 * Error response with a reason code only.
	 *
	 * @param integer $status HTTP status.
	 * @param string  $code   Reason code.
	 *
	 * @return \WP_REST_Response
	 */
	private static function error( int $status, string $code ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'error' => $code ), $status );
	}
}
