<?php
/**
 * Token-bucket rate limiter.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- atomic upsert on a small custom table; the object cache is used instead when persistent.

/**
 * Token buckets kept in a persistent object cache when there is one, else in `lledger_ratelimit`.
 */
final class Rate_Limiter {

	const OPTION      = 'lledger_rate_classes';
	const CACHE_GROUP = 'lledger_ratelimit';

	/**
	 * Default classes: name => [rate per second, burst], or null for unlimited.
	 *
	 * @var array<string,array{0:int|float,1:int}|null>
	 */
	const DEFAULT_CLASSES = array(
		'default'   => array( 20, 100 ),
		'bulk'      => array( 200, 2000 ),
		'unlimited' => null,
	);

	/**
	 * Rate classes: the defaults overlaid with option `lledger_rate_classes` (array or JSON). Invalid entries are ignored.
	 *
	 * @return array<string,array{0:int|float,1:int}|null>
	 */
	public static function classes(): array {
		$opt = get_option( self::OPTION, array() );
		if ( is_string( $opt ) ) {
			$opt = json_decode( $opt, true );
		}
		$classes = self::DEFAULT_CLASSES;
		foreach ( is_array( $opt ) ? $opt : array() as $name => $spec ) {
			if ( null === $spec ) {
				$classes[ (string) $name ] = null;
			} elseif ( is_array( $spec ) && 2 === count( $spec ) && isset( $spec[0], $spec[1] )
				&& is_numeric( $spec[0] ) && $spec[0] > 0 && is_int( $spec[1] ) && $spec[1] >= 1 ) {
				$classes[ (string) $name ] = array( $spec[0], $spec[1] );
			}
		}
		return $classes;
	}

	/**
	 * Take one token from a bucket.
	 *
	 * @param string  $bucket Bucket name (at most 120 characters).
	 * @param float   $rate   Refill per second.
	 * @param integer $burst  Bucket size.
	 *
	 * @return boolean False when the bucket is empty.
	 */
	public function consume( string $bucket, float $rate, int $burst ): bool {
		$burst = max( 1, $burst );
		$now   = Clock::now();
		return wp_using_ext_object_cache()
			? $this->consume_cache( $bucket, $rate, $burst, $now )
			: $this->consume_db( $bucket, $rate, $burst, $now );
	}

	/**
	 * Object-cache bucket.
	 *
	 * @param string             $bucket Bucket.
	 * @param float              $rate   Rate.
	 * @param integer            $burst  Burst.
	 * @param \DateTimeImmutable $now    Now.
	 *
	 * @return boolean
	 */
	private function consume_cache( string $bucket, float $rate, int $burst, \DateTimeImmutable $now ): bool {
		$t     = (float) $now->format( 'U.u' );
		$state = wp_cache_get( $bucket, self::CACHE_GROUP );
		if ( is_array( $state ) && 2 === count( $state ) ) {
			$tokens = min( (float) $burst, $state[0] + max( 0.0, $t - $state[1] ) * $rate );
		} else {
			$tokens = (float) $burst;
		}
		$ok = $tokens >= 1;
		if ( $ok ) {
			--$tokens;
		}
		wp_cache_set( $bucket, array( $tokens, $t ), self::CACHE_GROUP, (int) ceil( $burst / $rate ) + 1 );
		return $ok;
	}

	/**
	 * Table bucket: one atomic upsert; LAST_INSERT_ID(expr) carries whether a token was taken.
	 *
	 * @param string             $bucket Bucket.
	 * @param float              $rate   Rate.
	 * @param integer            $burst  Burst.
	 * @param \DateTimeImmutable $now    Now.
	 *
	 * @return boolean
	 */
	private function consume_db( string $bucket, float $rate, int $burst, \DateTimeImmutable $now ): bool {
		global $wpdb;
		$at     = Timestamp::to_db( $now );
		$refill = $wpdb->prepare(
			'LEAST(%f, tokens + GREATEST(0, TIMESTAMPDIFF(MICROSECOND, updated_at, %s)) / 1000000 * %f)',
			$burst,
			$at,
			$rate
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $refill is itself prepared above.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (bucket, tokens, updated_at) VALUES (%s, %f + LAST_INSERT_ID(1) - 1, %s)
				ON DUPLICATE KEY UPDATE tokens = IF(LAST_INSERT_ID({$refill} >= 1), {$refill} - 1, {$refill}), updated_at = %s",
				Tables::name( 'ratelimit' ),
				$bucket,
				$burst - 1,
				$at,
				$at
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	}
}
