<?php
/**
 * Seen-nonce store for replay protection.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Crypto;

/**
 * Records nonces per key for a time-to-live.
 */
interface Nonce_Store {

	/**
	 * Remember a nonce.
	 *
	 * @param string  $key_id Key id.
	 * @param string  $nonce  Nonce.
	 * @param integer $ttl    Seconds to remember it.
	 *
	 * @return boolean False if the nonce was already seen for this key.
	 */
	public function remember( string $key_id, string $nonce, int $ttl ): bool;
}
