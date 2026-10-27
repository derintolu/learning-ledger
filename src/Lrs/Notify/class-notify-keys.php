<?php
/**
 * The LRS's notification signing key.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Notify;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the keys table is plugin-owned.

use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Notification_Signature;
use LearningLedger\Crypto\Secret_Box;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;

/**
 * One Ed25519 key in `lledger_keys` (purpose `notify`), its seed sealed by Secret_Box,
 * created on first use. Connectors pin its public key from `/about`.
 */
final class Notify_Keys {

	const PURPOSE = 'notify';

	const SEAL_CONTEXT = 'notify-key';

	/**
	 * The active key, creating it on first use.
	 *
	 * When two requests create a key at once, both settle on the oldest, so every
	 * signature matches what `/about` publishes.
	 *
	 * @return array{kid:string,public:string} Key id and raw 32-byte public key.
	 *
	 * @throws \RuntimeException When the key cannot be stored.
	 */
	public static function active(): array {
		$row = self::row();
		if ( null === $row ) {
			self::create();
			$row = self::row();
		}
		if ( null === $row ) {
			throw new \RuntimeException( 'The notification key could not be stored.' );
		}
		return $row;
	}

	/**
	 * Sign a notification body with the active key.
	 *
	 * @param string  $body Body.
	 * @param integer $ts   Unix seconds, as sent in `X-Learning-Ledger-Timestamp`.
	 *
	 * @return string Base64 Ed25519 signature over `ts . "." . body`.
	 *
	 * @throws \RuntimeException When the secret cannot be opened.
	 */
	public static function sign( string $body, int $ts ): string {
		$kid = self::active()['kid'];
		global $wpdb;
		$sealed = $wpdb->get_var(
			$wpdb->prepare( 'SELECT secret_sealed FROM %i WHERE purpose = %s AND kid = %s', Tables::name( 'keys' ), self::PURPOSE, $kid )
		);
		if ( ! is_string( $sealed ) || '' === $sealed ) {
			throw new \RuntimeException( 'The notification key secret is unavailable.' );
		}
		$seed   = Secret_Box::open( $sealed, self::SEAL_CONTEXT );
		$secret = Ed25519::from_seed( $seed )['secret'];
		$sig    = Notification_Signature::sign( $body, $secret, $ts );
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $seed );
			sodium_memzero( $secret );
		}
		return $sig;
	}

	/**
	 * The oldest live key.
	 *
	 * @return array{kid:string,public:string}|null
	 */
	private static function row(): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT kid, public_key FROM %i WHERE purpose = %s AND retired_at IS NULL AND removed_at IS NULL ORDER BY created_at ASC, kid ASC LIMIT 1',
				Tables::name( 'keys' ),
				self::PURPOSE
			)
		);
		if ( null === $row ) {
			return null;
		}
		return array(
			'kid'    => (string) $row->kid,
			'public' => (string) $row->public_key,
		);
	}

	/**
	 * Insert a fresh key with its seed sealed.
	 *
	 * @return void
	 */
	private static function create(): void {
		$seed = random_bytes( 32 );
		$pair = Ed25519::from_seed( $seed );
		global $wpdb;
		$wpdb->insert(
			Tables::name( 'keys' ),
			array(
				'kid'           => Ed25519::key_id( $pair['public'] ),
				'purpose'       => self::PURPOSE,
				'public_key'    => $pair['public'],
				'secret_sealed' => Secret_Box::seal( $seed, self::SEAL_CONTEXT ),
				'created_at'    => Timestamp::to_db( Clock::now() ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $seed );
			sodium_memzero( $pair['secret'] );
		}
	}
}
