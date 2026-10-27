<?php
/**
 * Append-only audit log.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Audit;

use LearningLedger\Db\Tables;
use LearningLedger\Util\Invalid_Input;

/**
 * Records voids, erasures, badge issue and revoke, client changes and key rotation.
 * Rows hold counts and hashes only: never statement bodies or personal data.
 */
final class Audit_Log {

	/**
	 * Data keys that would carry content or personal data, rejected at any depth.
	 */
	private const FORBIDDEN_KEYS = array( 'body', 'statement', 'email' );

	/**
	 * Append one audit row.
	 *
	 * @param string      $event Event name (at most 40 characters).
	 * @param array       $data  Counts, hashes and ids.
	 * @param string|null $actor Who did it, e.g. `client:<id>` or `user:<id>`.
	 *
	 * @return void
	 *
	 * @throws Invalid_Input When `$data` holds a forbidden key.
	 */
	public static function record( string $event, array $data, ?string $actor = null ): void {
		self::assert_allowed( $data );
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Tables::name( 'audit' ),
			array(
				'at'    => ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.u' ),
				'event' => $event,
				'actor' => $actor,
				'data'  => wp_json_encode( (object) $data ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Reject forbidden keys anywhere in the data.
	 *
	 * @param array  $data Data.
	 * @param string $path Path so far.
	 *
	 * @return void
	 *
	 * @throws Invalid_Input When a forbidden key is found.
	 */
	private static function assert_allowed( array $data, string $path = '' ): void {
		foreach ( $data as $key => $value ) {
			$here = '' === $path ? (string) $key : $path . '.' . $key;
			if ( in_array( strtolower( (string) $key ), self::FORBIDDEN_KEYS, true ) ) {
				throw new Invalid_Input( 'Audit data must not contain "' . esc_html( (string) $key ) . '".', 'forbidden_audit_key', esc_html( $here ) );
			}
			if ( is_array( $value ) ) {
				self::assert_allowed( $value, $here );
			}
		}
	}
}
