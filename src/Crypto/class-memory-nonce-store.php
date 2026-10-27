<?php
/**
 * In-memory nonce store for tests.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Crypto;

/**
 * Keeps nonces in an array for the life of the object.
 */
final class Memory_Nonce_Store implements Nonce_Store {

	/**
	 * Expiry time by key id and nonce.
	 *
	 * @var array<string,int>
	 */
	private array $seen = array();

	/**
	 * Remember a nonce.
	 *
	 * @param string  $key_id Key id.
	 * @param string  $nonce  Nonce.
	 * @param integer $ttl    Seconds to remember it.
	 *
	 * @return boolean
	 */
	public function remember( string $key_id, string $nonce, int $ttl ): bool {
		$k   = $key_id . "\n" . $nonce;
		$now = time();
		if ( isset( $this->seen[ $k ] ) && $this->seen[ $k ] > $now ) {
			return false;
		}
		$this->seen[ $k ] = $now + $ttl;
		return true;
	}
}
