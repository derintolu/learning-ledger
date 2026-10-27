<?php
/**
 * Seen-nonce store backed by `lledger_nonces`.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\CourseServer;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin-owned table; replay checks must not be cached.

use LearningLedger\Crypto\Nonce_Store;
use LearningLedger\Crypto\Signed_Request;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;

/**
 * Remembers each (key id, nonce) with the time it was first seen. Pruned hourly.
 */
final class Db_Nonce_Store implements Nonce_Store {

	/**
	 * Remember a nonce in one atomic upsert. A row older than the TTL counts as unseen and is renewed.
	 *
	 * @param string  $key_id Key id.
	 * @param string  $nonce  Nonce.
	 * @param integer $ttl    Seconds to remember it.
	 *
	 * @return boolean False if the nonce was already seen for this key within the TTL.
	 */
	public function remember( string $key_id, string $nonce, int $ttl ): bool {
		global $wpdb;
		$now     = Clock::now();
		$at      = Timestamp::to_db( $now );
		$expired = Timestamp::to_db( $now->modify( '-' . max( 0, $ttl ) . ' seconds' ) );
		// Affected rows: 1 inserted, 2 renewed an expired row, 0 left a live row unchanged.
		$n = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (key_id, nonce, seen_at) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE seen_at = IF(seen_at < %s, %s, seen_at)',
				Tables::name( 'nonces' ),
				$key_id,
				$nonce,
				$at,
				$expired,
				$at
			)
		);
		return is_int( $n ) && $n > 0;
	}

	/**
	 * Delete nonces older than the TTL.
	 *
	 * @param integer $ttl Seconds a nonce is kept.
	 *
	 * @return integer Rows deleted.
	 */
	public static function prune( int $ttl = Signed_Request::NONCE_TTL ): int {
		global $wpdb;
		$n = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE seen_at < %s',
				Tables::name( 'nonces' ),
				Timestamp::to_db( Clock::now()->modify( '-' . max( 0, $ttl ) . ' seconds' ) )
			)
		);
		return is_int( $n ) ? $n : 0;
	}
}
