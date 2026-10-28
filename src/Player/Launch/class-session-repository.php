<?php
/**
 * Storage of cmi5 sessions.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Launch;

use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table; sessions are looked up by key.

/**
 * Rows of `lledger_cmi5_sessions`: one per launch. Only the SHA-256 of the fetch token is stored.
 */
final class Session_Repository {

	const OPEN       = 'open';
	const TERMINATED = 'terminated';
	const ABANDONED  = 'abandoned';

	/**
	 * Seconds after launch when a session's child token has expired (the token TTL).
	 */
	const TOKEN_TTL = 28800;

	/**
	 * Open a session for a launch.
	 *
	 * @param integer $user         User id.
	 * @param string  $au_id        AU publisher id.
	 * @param string  $registration Registration UUID.
	 * @param integer $lesson       Lesson id.
	 * @param string  $mode         Launch mode.
	 *
	 * @return string Session id (UUID v4).
	 * @throws \RuntimeException When the row cannot be stored.
	 */
	public function open( int $user, string $au_id, string $registration, int $lesson, string $mode ): string {
		global $wpdb;
		$session = Uuid::v4();
		$now     = Timestamp::to_db( Clock::now() );
		$ok      = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (session_id, registration, user_id, au_id, au_hash, lesson_id, launch_mode, token_key, fetch_hash, fetch_expires_at, fetch_used_at, launched_at, terminated_at, abandoned_at, status)
				VALUES (%s, UNHEX(%s), %d, %s, UNHEX(%s), %d, %s, NULL, NULL, %s, NULL, %s, NULL, NULL, %s)',
				Tables::name( 'cmi5_sessions' ),
				$session,
				bin2hex( Uuid::to_bin( $registration ) ),
				$user,
				$au_id,
				sha1( $au_id ),
				$lesson,
				$mode,
				$now,
				$now,
				self::OPEN
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not store the cmi5 session.' );
		}
		return $session;
	}

	/**
	 * The user's latest open session for an AU.
	 *
	 * @param integer $user  User id.
	 * @param string  $au_id AU publisher id.
	 *
	 * @return array|null Row, see hydrate().
	 */
	public function open_for( int $user, string $au_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND au_hash = UNHEX(%s) AND status = %s ORDER BY launched_at DESC LIMIT 1',
				Tables::name( 'cmi5_sessions' ),
				$user,
				sha1( $au_id ),
				self::OPEN
			),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * A session by id.
	 *
	 * @param string $session Session id.
	 *
	 * @return array|null Row, see hydrate().
	 */
	public function find( string $session ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE session_id = %s', Tables::name( 'cmi5_sessions' ), strtolower( $session ) ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * A session by its fetch token hash, used or not.
	 *
	 * @param string $fetch_hash Raw SHA-256 of the fetch token.
	 *
	 * @return array|null Row, see hydrate().
	 */
	public function find_by_fetch( string $fetch_hash ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE fetch_hash = UNHEX(%s)', Tables::name( 'cmi5_sessions' ), bin2hex( $fetch_hash ) ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Record the child token minted for a session.
	 *
	 * @param string $session   Session id.
	 * @param string $token_key Token key.
	 *
	 * @return void
	 */
	public function set_token( string $session, string $token_key ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET token_key = %s WHERE session_id = %s', Tables::name( 'cmi5_sessions' ), $token_key, strtolower( $session ) ) );
	}

	/**
	 * Store the fetch token hash and its expiry.
	 *
	 * @param string $session    Session id.
	 * @param string $fetch_hash Raw SHA-256 of the fetch token.
	 * @param string $expires_at Expiry, database format (UTC).
	 *
	 * @return void
	 */
	public function set_fetch( string $session, string $fetch_hash, string $expires_at ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET fetch_hash = UNHEX(%s), fetch_expires_at = %s, fetch_used_at = NULL WHERE session_id = %s',
				Tables::name( 'cmi5_sessions' ),
				bin2hex( $fetch_hash ),
				$expires_at,
				strtolower( $session )
			)
		);
	}

	/**
	 * Use a fetch token: atomically marks it used when it is unused, unexpired and its session open.
	 *
	 * @param string $fetch_hash Raw SHA-256 of the fetch token.
	 *
	 * @return array|null The session row, or null when the token is unknown, used, expired or its session closed.
	 */
	public function use_fetch( string $fetch_hash ): ?array {
		global $wpdb;
		$now     = Timestamp::to_db( Clock::now() );
		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET fetch_used_at = %s WHERE fetch_hash = UNHEX(%s) AND fetch_used_at IS NULL AND fetch_expires_at > %s AND status = %s',
				Tables::name( 'cmi5_sessions' ),
				$now,
				bin2hex( $fetch_hash ),
				$now,
				self::OPEN
			)
		);
		if ( 1 !== $changed ) {
			return null;
		}
		return $this->find_by_fetch( $fetch_hash );
	}

	/**
	 * Close an open session.
	 *
	 * @param string      $session Session id.
	 * @param string      $status  Either `terminated` or `abandoned`.
	 * @param string|null $at      Time (any format DateTimeImmutable reads; UTC unless it says otherwise), default now.
	 *
	 * @return void
	 * @throws \InvalidArgumentException On another status.
	 */
	public function mark( string $session, string $status, ?string $at = null ): void {
		if ( self::TERMINATED !== $status && self::ABANDONED !== $status ) {
			throw new \InvalidArgumentException( 'Unknown session status.' );
		}
		$when   = null === $at ? Clock::now() : new \DateTimeImmutable( $at, new \DateTimeZone( 'UTC' ) );
		$column = self::TERMINATED === $status ? 'terminated_at' : 'abandoned_at';
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, %i = %s WHERE session_id = %s AND status = %s',
				Tables::name( 'cmi5_sessions' ),
				$status,
				$column,
				Timestamp::to_db( $when ),
				strtolower( $session ),
				self::OPEN
			)
		);
	}

	/**
	 * Open sessions whose child token has expired (launched more than 8 hours ago), oldest first.
	 *
	 * @param integer $limit Maximum rows.
	 *
	 * @return array[] Rows, see hydrate().
	 */
	public function expired_open( int $limit ): array {
		if ( $limit < 1 ) {
			return array();
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND launched_at < %s ORDER BY launched_at, session_id LIMIT %d',
				Tables::name( 'cmi5_sessions' ),
				self::OPEN,
				Timestamp::to_db( Clock::now()->modify( '-' . self::TOKEN_TTL . ' seconds' ) ),
				$limit
			),
			ARRAY_A
		);
		return array_map( array( self::class, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Row to array: binary columns decoded, ids as integers; the fetch hash is left out.
	 *
	 * @param array $row Database row.
	 *
	 * @return array{session_id:string,registration:string,user_id:int,au_id:string,lesson_id:int,launch_mode:string,token_key:?string,fetch_expires_at:string,fetch_used_at:?string,launched_at:string,terminated_at:?string,abandoned_at:?string,status:string}
	 */
	private static function hydrate( array $row ): array {
		return array(
			'session_id'       => (string) $row['session_id'],
			'registration'     => Uuid::from_bin( $row['registration'] ),
			'user_id'          => (int) $row['user_id'],
			'au_id'            => (string) $row['au_id'],
			'lesson_id'        => (int) $row['lesson_id'],
			'launch_mode'      => (string) $row['launch_mode'],
			'token_key'        => null === $row['token_key'] ? null : (string) $row['token_key'],
			'fetch_expires_at' => (string) $row['fetch_expires_at'],
			'fetch_used_at'    => null === $row['fetch_used_at'] ? null : (string) $row['fetch_used_at'],
			'launched_at'      => (string) $row['launched_at'],
			'terminated_at'    => null === $row['terminated_at'] ? null : (string) $row['terminated_at'],
			'abandoned_at'     => null === $row['abandoned_at'] ? null : (string) $row['abandoned_at'],
			'status'           => (string) $row['status'],
		);
	}
}
