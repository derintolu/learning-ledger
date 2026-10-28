<?php
/**
 * Claim and view link storage.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Links;

use LearningLedger\Crypto\Tokens;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin-owned table, never cached: links are single-use or short-lived.

/**
 * Bearer links in `lledger_links`. Only the SHA-256 of a token is stored; the token itself is
 * returned once by mint() and never kept. Every time comparison uses Clock::now().
 */
final class Link_Repository {

	/**
	 * Link kinds.
	 *
	 * @var string[]
	 */
	const KINDS = array( 'view', 'claim' );

	/**
	 * Longest subject and created_by values (the column widths).
	 */
	const MAX_FIELD = 80;

	/**
	 * Create a link.
	 *
	 * For `claim`, earlier unused links of the same actor and subject are deleted first, so only
	 * the newest one works. Used claim links stay as the record of the claim.
	 *
	 * @param string  $kind       Either `view` or `claim`.
	 * @param string  $actor_hash Raw SHA-1 actor hash (Agent_Identity::hash()).
	 * @param string  $subject    What the link is for: `record` for a view link, the badge for a claim link.
	 * @param integer $ttl        Lifetime in seconds.
	 * @param string  $created_by Who asked, e.g. `client:<id>`.
	 *
	 * @return string The token (43 base64url characters).
	 * @throws Invalid_Input Code `invalid_link` on bad arguments.
	 * @throws \RuntimeException When the link cannot be stored.
	 */
	public function mint( string $kind, string $actor_hash, string $subject, int $ttl, string $created_by ): string {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			throw new Invalid_Input( 'Unknown link kind.', 'invalid_link', 'kind' );
		}
		if ( 20 !== strlen( $actor_hash ) ) {
			throw new Invalid_Input( 'The actor hash must be 20 raw bytes.', 'invalid_link', 'actor_hash' );
		}
		if ( '' === $subject || strlen( $subject ) > self::MAX_FIELD ) {
			throw new Invalid_Input( 'The subject must be 1 to 80 characters.', 'invalid_link', 'subject' );
		}
		if ( '' === $created_by || strlen( $created_by ) > self::MAX_FIELD ) {
			throw new Invalid_Input( 'created_by must be 1 to 80 characters.', 'invalid_link', 'created_by' );
		}
		if ( $ttl < 1 ) {
			throw new Invalid_Input( 'The lifetime must be positive.', 'invalid_link', 'ttl' );
		}

		global $wpdb;
		$table = Tables::name( 'links' );
		$token = Tokens::random( 32 );

		if ( 'claim' === $kind ) {
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE kind = %s AND actor_hash = UNHEX(%s) AND subject = %s AND used_at IS NULL',
					$table,
					$kind,
					bin2hex( $actor_hash ),
					$subject
				)
			);
		}

		$ok = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (token_hash, kind, actor_hash, subject, expires_at, used_at, created_by) VALUES (UNHEX(%s), %s, UNHEX(%s), %s, %s, NULL, %s)',
				$table,
				bin2hex( Tokens::hash( $token ) ),
				$kind,
				bin2hex( $actor_hash ),
				$subject,
				Timestamp::to_db( Clock::now()->modify( '+' . $ttl . ' seconds' ) ),
				$created_by
			)
		);
		if ( 1 !== $ok ) {
			throw new \RuntimeException( 'The link could not be stored.' );
		}
		return $token;
	}

	/**
	 * Look up a link that has not expired (and, for `claim`, has not been used).
	 *
	 * @param string $token Token.
	 * @param string $kind  Expected kind.
	 *
	 * @return array{kind:string,actor_hash:string,subject:string,expires_at:string,used_at:?string,created_by:string}|null
	 *         actor_hash raw, times in xAPI format.
	 */
	public function resolve( string $token, string $kind ): ?array {
		if ( '' === $token || ! in_array( $kind, self::KINDS, true ) ) {
			return null;
		}
		global $wpdb;
		$sql = 'SELECT kind, actor_hash, subject, expires_at, used_at, created_by FROM %i WHERE token_hash = UNHEX(%s) AND kind = %s AND expires_at > %s';
		if ( 'claim' === $kind ) {
			$sql .= ' AND used_at IS NULL';
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( $sql, Tables::name( 'links' ), bin2hex( Tokens::hash( $token ) ), $kind, Timestamp::to_db( Clock::now() ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only, built above.
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'kind'       => (string) $row['kind'],
			'actor_hash' => (string) $row['actor_hash'],
			'subject'    => (string) $row['subject'],
			'expires_at' => Timestamp::to_xapi( Timestamp::from_db( (string) $row['expires_at'] ) ),
			'used_at'    => null === $row['used_at'] ? null : Timestamp::to_xapi( Timestamp::from_db( (string) $row['used_at'] ) ),
			'created_by' => (string) $row['created_by'],
		);
	}

	/**
	 * Mark an unexpired link used. Atomic: of two concurrent calls only one succeeds.
	 *
	 * @param string $token Token.
	 *
	 * @return boolean Whether this call used the link.
	 */
	public function consume( string $token ): bool {
		if ( '' === $token ) {
			return false;
		}
		global $wpdb;
		$now = Timestamp::to_db( Clock::now() );
		$n   = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET used_at = %s WHERE token_hash = UNHEX(%s) AND used_at IS NULL AND expires_at > %s',
				Tables::name( 'links' ),
				$now,
				bin2hex( Tokens::hash( $token ) ),
				$now
			)
		);
		return 1 === $n;
	}

	/**
	 * Delete expired links.
	 *
	 * @return integer Links deleted.
	 */
	public function prune(): int {
		global $wpdb;
		$n = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at <= %s', Tables::name( 'links' ), Timestamp::to_db( Clock::now() ) ) );
		return is_int( $n ) ? $n : 0;
	}
}
