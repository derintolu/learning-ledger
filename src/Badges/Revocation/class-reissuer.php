<?php
/**
 * Re-issue after a badge key is removed.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Revocation;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables, never cached.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names are interpolated.

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Issuer_Key;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Badges\Signing\Eddsa_Rdfc_2022;
use LearningLedger\Badges\Status\Bitstring;
use LearningLedger\Badges\Status\Status_List_Repository;
use LearningLedger\Crypto\Multibase;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;

/**
 * Moves everything a badge key signed onto the active key (spec §8.2 "Rotation"):
 *
 * - every issued credential whose proof used a removed key is signed again with the same `id`
 *   and a newer `validFrom`, and passed through `learning_ledger/badge_sign` like a claim;
 * - every live status list is re-signed with the active key, so removing an older key never
 *   breaks revocation checks. A key that still signs a live list may only be removed once
 *   another key can take the lists over.
 */
final class Reissuer {

	/**
	 * Allowed key id characters (as Key_Store accepts them).
	 */
	private const KID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

	/**
	 * Badges read per query.
	 */
	private const BATCH = 100;

	/**
	 * Seconds to wait for a status list lock.
	 */
	private const LOCK_TIMEOUT = 10;

	/**
	 * Re-sign every issued credential whose proof used a key, with the active key.
	 *
	 * @param string $removed_kid Key id (usually of a key just removed with `badges remove-key`).
	 *
	 * @return integer Credentials re-issued.
	 *
	 * @throws Invalid_Input When the key id is malformed or is the active key.
	 * @throws \RuntimeException When no key is active, or signing or storage fails.
	 */
	public function reissue_for_key( string $removed_kid ): int {
		if ( 1 !== preg_match( self::KID_PATTERN, $removed_kid ) ) {
			throw new Invalid_Input( 'Not a badge key id.', 'invalid', 'kid' );
		}
		$key = self::active_key();
		if ( hash_equals( $key->kid, $removed_kid ) ) {
			throw new Invalid_Input( 'This is the active key. Run `wp learning-ledger badges rotate` first.', 'invalid', 'kid' );
		}
		$this->resign_status_lists();

		global $wpdb;
		$table = Tables::name( 'badges' );
		$count = 0;
		$after = '';
		do {
			$rows    = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, credential FROM {$table} WHERE status = %s AND id > %s ORDER BY id LIMIT %d", Badge_Repository::ISSUED, $after, self::BATCH ),
				ARRAY_A
			);
			$rows    = is_array( $rows ) ? $rows : array();
			$fetched = count( $rows );
			foreach ( $rows as $row ) {
				$after = (string) $row['id'];
				if ( $this->reissue_row( $after, (string) $row['credential'], $removed_kid, $key ) ) {
					++$count;
				}
			}
		} while ( self::BATCH === $fetched );
		return $count;
	}

	/**
	 * Re-sign every live status list that the active key did not sign.
	 *
	 * @return integer Lists re-signed.
	 *
	 * @throws \RuntimeException When no key is active, or a list cannot be locked, signed or written.
	 */
	public function resign_status_lists(): int {
		$key   = self::active_key();
		$vm    = Did_Document::verification_method_for( $key );
		$count = 0;
		foreach ( self::live_lists() as $list_id ) {
			if ( $this->resign_list( $list_id, $key, $vm ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Get ready to remove a key: hand its status lists to the active key, or refuse when no
	 * other key could sign them (removing the key would leave every revocation check failing).
	 *
	 * @param string $kid Key about to be removed.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the key signs a live list and no other key is active, or re-signing fails.
	 */
	public function prepare_key_removal( string $kid ): void {
		$active = Key_Store::active();
		if ( null !== $active && ! hash_equals( $active->kid, $kid ) ) {
			$this->resign_status_lists();
			return;
		}
		foreach ( self::live_lists() as $list_id ) {
			$credential = ( new Status_List_Repository() )->credential( $list_id );
			if ( self::signer_kid( $credential ) === $kid ) {
				throw new \RuntimeException( 'This key signs the live status lists and no other key could re-sign them. Run `wp learning-ledger badges rotate` first, then remove it.' );
			}
		}
	}

	/**
	 * Re-issue one badge if its credential was signed with the key.
	 *
	 * @param string     $id          Badge id.
	 * @param string     $raw         Stored credential.
	 * @param string     $removed_kid Key id.
	 * @param Issuer_Key $key         Active key.
	 *
	 * @return boolean Whether it was re-issued.
	 *
	 * @throws \RuntimeException When signing or storage fails.
	 */
	private function reissue_row( string $id, string $raw, string $removed_kid, Issuer_Key $key ): bool {
		$decoded    = json_decode( $raw, true );
		$credential = is_array( $decoded ) ? $decoded : $raw;
		if ( self::signer_kid( $credential ) !== $removed_kid ) {
			return false;
		}
		$unsigned = self::unsigned( $credential );
		if ( null === $unsigned || ( $unsigned['id'] ?? null ) !== 'urn:uuid:' . $id ) {
			throw new \RuntimeException( 'Badge ' . esc_html( $id ) . ' has a credential that cannot be re-issued.' );
		}

		$now                   = Clock::now();
		$valid_from            = self::newer( $unsigned['validFrom'] ?? null, $now );
		$unsigned['validFrom'] = $valid_from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
		$signed                = self::sign( $unsigned, $key, $now );

		global $wpdb;
		$table   = Tables::name( 'badges' );
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET credential = %s, valid_from = %s WHERE id = %s AND status = %s AND credential = %s",
				is_string( $signed ) ? $signed : Json::encode_exact( $signed ),
				Timestamp::to_db( $valid_from ),
				$id,
				Badge_Repository::ISSUED,
				$raw
			)
		);
		if ( false === $changed ) {
			throw new \RuntimeException( 'Could not store the re-issued badge.' );
		}
		if ( 1 !== $changed ) {
			return false; // Revoked or re-issued meanwhile.
		}
		Audit_Log::record(
			'badge_reissued',
			array(
				'badge'        => $id,
				'credential'   => $unsigned['id'],
				'kid'          => $key->kid,
				'replaced_kid' => $removed_kid,
			),
			self::audit_actor()
		);
		return true;
	}

	/**
	 * Sign as the claim handler does: an eddsa-rdfc-2022 proof, then `learning_ledger/badge_sign`
	 * (which turns it into a VC-JWT in that mode).
	 *
	 * @param array              $credential Unsigned credential.
	 * @param Issuer_Key         $key        Active key.
	 * @param \DateTimeImmutable $now        Proof creation time.
	 *
	 * @return array|string
	 *
	 * @throws \RuntimeException When the filter returns neither a secured credential with the same id nor a non-empty string.
	 */
	private static function sign( array $credential, Issuer_Key $key, \DateTimeImmutable $now ): array|string {
		$signed = Eddsa_Rdfc_2022::sign( $credential, Did_Document::verification_method_for( $key ), $key->secret(), $now );

		/**
 * This filter is documented in src/Badges/Claim/class-claim-handler.php
*/
		$signed = apply_filters( 'learning_ledger/badge_sign', $signed, $credential, $key ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.

		if ( is_string( $signed ) && '' !== $signed ) {
			return $signed;
		}
		if ( is_array( $signed ) && ( $signed['id'] ?? null ) === $credential['id'] && is_array( $signed['proof'] ?? null ) ) {
			return $signed;
		}
		throw new \RuntimeException( 'The badge could not be signed.' );
	}

	/**
	 * Re-sign one list unless the active key already signed it. Holds the list's lock, the same
	 * one Status_List_Repository::set_revoked() takes, so no revocation is lost in between.
	 *
	 * @param integer    $list_id List id.
	 * @param Issuer_Key $key     Active key.
	 * @param string     $vm      Its verification method.
	 *
	 * @return boolean Whether the list was re-signed.
	 *
	 * @throws \RuntimeException When the lock, signing or the write fails.
	 */
	private function resign_list( int $list_id, Issuer_Key $key, string $vm ): bool {
		global $wpdb;
		$table = Tables::name( 'status_lists' );
		$lock  = substr( $wpdb->prefix . 'lledger_status_list_' . $list_id, 0, 64 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT ) ) ) {
			throw new \RuntimeException( 'Could not lock the status list.' );
		}
		try {
			$row        = $wpdb->get_row( $wpdb->prepare( "SELECT bits, credential FROM {$table} WHERE id = %d", $list_id ), ARRAY_A );
			$credential = is_array( $row ) ? json_decode( (string) $row['credential'], true ) : null;
			if ( ! is_array( $credential ) ) {
				throw new \RuntimeException( 'The status list credential is unreadable.' );
			}
			if ( ( $credential['proof']['verificationMethod'] ?? null ) === $vm ) {
				return false;
			}
			$now = Clock::now();
			unset( $credential['proof'] );
			$credential['issuer']                           = Did_Document::did();
			$credential['validFrom']                        = $now->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
			$credential['credentialSubject']['encodedList'] = Bitstring::encode( (string) $row['bits'] );
			$json = wp_json_encode( Eddsa_Rdfc_2022::sign( $credential, $vm, $key->secret(), $now ), JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $json ) ) {
				throw new \RuntimeException( 'Could not encode the status list credential.' );
			}
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET credential = %s, updated = %s WHERE id = %d", $json, Timestamp::to_db( $now ), $list_id ) );
			if ( 1 !== $updated ) {
				throw new \RuntimeException( 'Could not write the status list.' );
			}
			return true;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Ids of the signed revocation lists.
	 *
	 * @return int[]
	 */
	private static function live_lists(): array {
		global $wpdb;
		$table = Tables::name( 'status_lists' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE purpose = %s AND credential <> '' ORDER BY id", Status_List_Repository::PURPOSE ) );
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Key id that signed a credential: the fragment of its proof's verification method, or of
	 * a VC-JWT's `kid` header.
	 *
	 * @param array|string $credential Secured credential or VC-JWT.
	 *
	 * @return string|null
	 */
	private static function signer_kid( array|string $credential ): ?string {
		if ( is_array( $credential ) ) {
			$vm = $credential['proof']['verificationMethod'] ?? null;
		} else {
			$vm = self::jwt_part( $credential, 0 )['kid'] ?? null;
		}
		if ( ! is_string( $vm ) || false === strrpos( $vm, '#' ) ) {
			return null;
		}
		return substr( $vm, strrpos( $vm, '#' ) + 1 );
	}

	/**
	 * The credential without its proof (for a VC-JWT, its `vc` claim).
	 *
	 * @param array|string $credential Secured credential or VC-JWT.
	 *
	 * @return array|null
	 */
	private static function unsigned( array|string $credential ): ?array {
		$unsigned = is_array( $credential ) ? $credential : ( self::jwt_part( $credential, 1 )['vc'] ?? null );
		if ( ! is_array( $unsigned ) ) {
			return null;
		}
		unset( $unsigned['proof'] );
		return $unsigned;
	}

	/**
	 * A decoded JSON part of a compact JWT.
	 *
	 * @param string  $jwt  Compact JWT.
	 * @param integer $part Part index: 0 for the header, 1 for the payload.
	 *
	 * @return array
	 */
	private static function jwt_part( string $jwt, int $part ): array {
		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			return array();
		}
		try {
			$decoded = json_decode( Multibase::base64url_decode( $parts[ $part ] ), true );
		} catch ( Invalid_Input $e ) {
			return array();
		}
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * A `validFrom` later than the old one: now, or one second after the old one.
	 *
	 * @param mixed              $old Old `validFrom`.
	 * @param \DateTimeImmutable $now Now.
	 *
	 * @return \DateTimeImmutable
	 */
	private static function newer( mixed $old, \DateTimeImmutable $now ): \DateTimeImmutable {
		$now = $now->setTimezone( new \DateTimeZone( 'UTC' ) );
		$now = $now->setTime( (int) $now->format( 'H' ), (int) $now->format( 'i' ), (int) $now->format( 's' ) );
		if ( ! is_string( $old ) ) {
			return $now;
		}
		try {
			$previous = new \DateTimeImmutable( $old );
		} catch ( \Exception $e ) {
			return $now;
		}
		return $now > $previous ? $now : $previous->setTimezone( new \DateTimeZone( 'UTC' ) )->modify( '+1 second' );
	}

	/**
	 * The active key.
	 *
	 * @return Issuer_Key
	 *
	 * @throws \RuntimeException When there is none.
	 */
	private static function active_key(): Issuer_Key {
		$key = Key_Store::active();
		if ( null === $key ) {
			throw new \RuntimeException( 'No badge key is active. Run `wp learning-ledger badges rotate` first.' );
		}
		return $key;
	}

	/**
	 * Audit actor for the current request.
	 *
	 * @return string|null
	 */
	private static function audit_actor(): ?string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		$user = get_current_user_id();
		return $user ? 'user:' . $user : null;
	}
}
