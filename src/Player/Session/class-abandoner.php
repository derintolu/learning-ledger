<?php
/**
 * Abandons cmi5 sessions whose token expired without `terminated`.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Session;

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Inbox\More_Link;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Pairing\Lrs_Client;
use LearningLedger\Player\Launch\Launch_Controller;
use LearningLedger\Player\Launch\Lms_Statements;
use LearningLedger\Player\Launch\Session_Repository;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;

/**
 * Every cron minute: each open session launched more than 8 hours ago (its child token has
 * expired) is closed [cmi5 9.3.6]. The LRS is asked for the session's `terminated` first; a
 * session that has one is marked terminated. Any other gets `abandoned` with the duration up to
 * the abandon time stamped on the session, which a retry keeps, so a resend is identical
 * [cmi5 9.5.4]. Its token is revoked either way. A session that fails is retried after 1, 5,
 * 15 and 60 minutes, then every 6 hours, so one the LRS keeps refusing does not hold up the rest.
 */
final class Abandoner {

	/**
	 * Sessions closed per run, at most.
	 */
	const BATCH = 50;

	/**
	 * Expired sessions read per run, so sessions waiting out a backoff do not fill the batch.
	 */
	const SCAN = 200;

	/**
	 * Option (not autoloaded) holding failing sessions: id => {attempts, next} (Unix time).
	 */
	const BACKOFF_OPTION = 'lledger_abandon_backoff';

	/**
	 * Retry delays in seconds after the 1st to 4th failure, then after every later one.
	 */
	const BACKOFF       = array( 60, 300, 900, 3600 );
	const BACKOFF_LATER = 21600;

	/**
	 * Page size and page cap when looking for a session's `terminated`.
	 */
	const TERMINATED_PAGE  = 500;
	const TERMINATED_PAGES = 10;

	/**
	 * LRS client.
	 *
	 * @var Lrs_Client
	 */
	private Lrs_Client $lrs;

	/**
	 * Sessions.
	 *
	 * @var Session_Repository
	 */
	private Session_Repository $sessions;

	/**
	 * Constructor.
	 *
	 * @param Lrs_Client|null         $lrs      LRS client.
	 * @param Session_Repository|null $sessions Sessions.
	 */
	public function __construct( ?Lrs_Client $lrs = null, ?Session_Repository $sessions = null ) {
		$this->lrs      = $lrs ?? new Lrs_Client();
		$this->sessions = $sessions ?? new Session_Repository();
	}

	/**
	 * Close up to BATCH expired open sessions. A session that fails stays open and is backed off.
	 *
	 * @return integer Sessions abandoned.
	 */
	public function run(): int {
		$now       = Clock::now()->getTimestamp();
		$stored    = get_option( self::BACKOFF_OPTION, array() );
		$backoff   = is_array( $stored ) ? $stored : array();
		$before    = $backoff;
		$abandoned = 0;
		$tried     = 0;
		$seen      = array();
		foreach ( $this->sessions->expired_open( self::SCAN ) as $session ) {
			$id          = $session['session_id'];
			$seen[ $id ] = true;
			if ( (int) ( $backoff[ $id ]['next'] ?? 0 ) > $now || $tried >= self::BATCH ) {
				continue;
			}
			++$tried;
			try {
				if ( $this->close( $session ) ) {
					++$abandoned;
				}
				unset( $backoff[ $id ] );
			} catch ( \RuntimeException $e ) {
				$attempts       = (int) ( $backoff[ $id ]['attempts'] ?? 0 ) + 1;
				$backoff[ $id ] = array(
					'attempts' => $attempts,
					'next'     => $now + ( self::BACKOFF[ $attempts - 1 ] ?? self::BACKOFF_LATER ),
				);
			}
		}
		foreach ( array_keys( $backoff ) as $id ) {
			// Forget sessions closed elsewhere (a relaunch).
			if ( ! isset( $seen[ $id ] ) && Session_Repository::OPEN !== ( $this->sessions->find( (string) $id )['status'] ?? null ) ) {
				unset( $backoff[ $id ] );
			}
		}
		if ( $backoff !== $before ) {
			if ( array() === $backoff ) {
				delete_option( self::BACKOFF_OPTION );
			} else {
				update_option( self::BACKOFF_OPTION, $backoff, false );
			}
		}
		return $abandoned;
	}

	/**
	 * Close one open session now, whatever its age (the CATAPULT test routes' abandonSession).
	 *
	 * @param string $session_id Session id.
	 *
	 * @return boolean|null Whether it was abandoned (false: its `terminated` was found), null when it is not open.
	 * @throws \RuntimeException When the LRS refuses.
	 */
	public function close_now( string $session_id ): ?bool {
		$session = $this->sessions->find( $session_id );
		if ( null === $session || Session_Repository::OPEN !== $session['status'] ) {
			return null;
		}
		return $this->close( $session );
	}

	/**
	 * Close one session.
	 *
	 * @param array $session Session row.
	 *
	 * @return boolean Whether it was abandoned.
	 * @throws \RuntimeException When the LRS refuses.
	 */
	private function close( array $session ): bool {
		$terminated = $this->terminated_at( $session['registration'], $session['session_id'] );
		if ( null !== $terminated ) {
			$this->revoke( $session['token_key'] );
			$this->sessions->mark( $session['session_id'], Session_Repository::TERMINATED, $terminated );
			return false;
		}

		$at = $this->sessions->abandon_at( $session['session_id'] );
		if ( null === $at ) {
			return false; // Closed meanwhile.
		}
		$found    = Launch_Controller::find_au( $session['au_id'] );
		$au       = null === $found ? new Au( array( 'id' => $session['au_id'] ) ) : $found['au'];
		$duration = (float) Timestamp::from_db( $at )->format( 'U.u' ) - (float) Timestamp::from_db( $session['launched_at'] )->format( 'U.u' );
		$actor    = ( new Identity_Map() )->actor_for_user( $session['user_id'] );
		Player_Evaluator::post( $this->lrs, ( new Lms_Statements() )->abandoned( $au, $actor, $session['registration'], $session['session_id'], $duration ) );
		$this->revoke( $session['token_key'] );
		$this->sessions->mark( $session['session_id'], Session_Repository::ABANDONED, $at );
		return true;
	}

	/**
	 * When the session's `terminated` was recorded, if the LRS holds one.
	 *
	 * @param string $registration Registration UUID.
	 * @param string $session      Session id.
	 *
	 * @return string|null Its timestamp (or stored time), database format, or null when there is none.
	 * @throws \RuntimeException When the LRS cannot be read.
	 */
	private function terminated_at( string $registration, string $session ): ?string {
		$resource = 'statements';
		$query    = array(
			'registration' => $registration,
			'verb'         => Vocab::TERMINATED,
			'limit'        => self::TERMINATED_PAGE,
		);
		for ( $page = 0; $page < self::TERMINATED_PAGES; $page++ ) {
			$res  = $this->lrs->xapi( 'GET', $resource, $query );
			$data = 200 === $res->status ? $res->json() : null;
			if ( ! is_array( $data ) || ! is_array( $data['statements'] ?? null ) ) {
				throw new \RuntimeException( 'LRS answered ' . (int) $res->status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never echoed.
			}
			foreach ( $data['statements'] as $statement ) {
				$id = is_array( $statement ) ? ( $statement['context']['extensions'][ Vocab::EXT_SESSIONID ] ?? null ) : null;
				if ( is_string( $id ) && strtolower( $id ) === strtolower( $session ) ) {
					return self::db_time( $statement['timestamp'] ?? $statement['stored'] ?? null );
				}
			}
			$next = More_Link::resolve( is_string( $data['more'] ?? null ) ? $data['more'] : '', Connector_Settings::get() );
			if ( null === $next ) {
				break;
			}
			list( $resource, $query ) = $next;
		}
		return null;
	}

	/**
	 * A statement time in database format; now when it is missing or unreadable.
	 *
	 * @param mixed $value Timestamp.
	 *
	 * @return string
	 */
	private static function db_time( mixed $value ): string {
		try {
			return Timestamp::to_db( is_string( $value ) ? new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) ) : Clock::now() );
		} catch ( \Exception $e ) {
			return Timestamp::to_db( Clock::now() );
		}
	}

	/**
	 * Revoke a session's child token, if one was minted (an unknown token is fine).
	 *
	 * @param string|null $token_key Token key.
	 *
	 * @return void
	 * @throws \RuntimeException When the LRS refuses.
	 */
	private function revoke( ?string $token_key ): void {
		if ( null === $token_key ) {
			return;
		}
		$res = $this->lrs->api( 'DELETE', 'tokens/' . rawurlencode( $token_key ) );
		if ( ! in_array( $res->status, array( 204, 404 ), true ) ) {
			throw new \RuntimeException( 'LRS answered ' . (int) $res->status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never echoed.
		}
	}
}
