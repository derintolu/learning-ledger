<?php
/**
 * Failed-authentication limiter.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- atomic upsert on a small custom table; the object cache is used instead when persistent.

/**
 * Counts failed authentications per IP in a fixed 60 s window; 10 failures block the IP until the window ends.
 */
final class Auth_Fail_Limiter {

	const MAX_FAILURES = 10;
	const WINDOW       = 60;

	/**
	 * Record one failure.
	 *
	 * @param string $ip Client IP.
	 *
	 * @return void
	 */
	public function hit( string $ip ): void {
		$now = Clock::now();
		if ( wp_using_ext_object_cache() ) {
			$t     = (float) $now->format( 'U.u' );
			$state = $this->cached( $ip, $t );
			$state = $state ? array( $state[0] + 1, $state[1] ) : array( 1, $t );
			wp_cache_set( self::bucket( $ip ), $state, Rate_Limiter::CACHE_GROUP, self::WINDOW + 1 );
			return;
		}
		global $wpdb;
		$at     = Timestamp::to_db( $now );
		$cutoff = Timestamp::to_db( $now->modify( '-' . self::WINDOW . ' seconds' ) );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (bucket, tokens, updated_at) VALUES (%s, 1, %s)
				ON DUPLICATE KEY UPDATE tokens = IF(updated_at <= %s, 1, tokens + 1), updated_at = IF(updated_at <= %s, %s, updated_at)',
				Tables::name( 'ratelimit' ),
				self::bucket( $ip ),
				$at,
				$cutoff,
				$cutoff,
				$at
			)
		);
	}

	/**
	 * Seconds until the IP may try again, or 0 when it is not blocked.
	 *
	 * @param string $ip Client IP.
	 *
	 * @return integer
	 */
	public function blocked_for( string $ip ): int {
		$now = Clock::now();
		$t   = (float) $now->format( 'U.u' );
		if ( wp_using_ext_object_cache() ) {
			$state = $this->cached( $ip, $t );
		} else {
			global $wpdb;
			$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT tokens, updated_at FROM %i WHERE bucket = %s', Tables::name( 'ratelimit' ), self::bucket( $ip ) ) );
			$state = $row ? array( (int) $row->tokens, (float) Timestamp::from_db( $row->updated_at )->format( 'U.u' ) ) : null;
			if ( $state && $state[1] <= $t - self::WINDOW ) {
				$state = null;
			}
		}
		if ( ! $state || $state[0] < self::MAX_FAILURES ) {
			return 0;
		}
		return max( 1, (int) ceil( $state[1] + self::WINDOW - $t ) );
	}

	/**
	 * Current window from the object cache, or null when none is open.
	 *
	 * @param string $ip IP.
	 * @param float  $t  Now (Unix seconds).
	 *
	 * @return array{0:int,1:float}|null
	 */
	private function cached( string $ip, float $t ): ?array {
		$state = wp_cache_get( self::bucket( $ip ), Rate_Limiter::CACHE_GROUP );
		if ( ! is_array( $state ) || 2 !== count( $state ) || $state[1] <= $t - self::WINDOW ) {
			return null;
		}
		return $state;
	}

	/**
	 * Bucket name for an IP.
	 *
	 * @param string $ip IP.
	 *
	 * @return string
	 */
	private static function bucket( string $ip ): string {
		return 'authfail:' . $ip;
	}
}
