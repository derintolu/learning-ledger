<?php
/**
 * Every CORS origin any live credential allows.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

use LearningLedger\Util\Clock;

/**
 * Union of active clients' origins and live child tokens' origins, for preflight requests (which carry no credentials).
 */
final class Origin_Index {

	const CACHE_KEY   = 'origins';
	const CACHE_GROUP = 'lledger_auth';
	const CACHE_TTL   = 60;

	/**
	 * All allowed origins, cached for 60 s.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$now = Clock::now();
		$set = array();
		foreach ( ( new Client_Repository() )->all() as $client ) {
			if ( $client->is_active( $now ) ) {
				foreach ( $client->cors_origins as $origin ) {
					$set[ $origin ] = true;
				}
			}
		}
		foreach ( ( new Token_Repository() )->live_origins() as $origin ) {
			$set[ $origin ] = true;
		}
		$origins = array_map( 'strval', array_keys( $set ) );
		wp_cache_set( self::CACHE_KEY, $origins, self::CACHE_GROUP, self::CACHE_TTL );
		return $origins;
	}

	/**
	 * Drop the cached list (after a client or token change).
	 *
	 * @return void
	 */
	public static function flush(): void {
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}
}
