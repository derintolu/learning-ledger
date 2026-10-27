<?php
/**
 * Record linking route.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Identity;

use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Pairing\Connector_Keys;
use LearningLedger\Connector\Pairing\Lrs_Client;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Db\Tables;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;
use LearningLedger\Xapi\Agent_Identity;

/**
 * `POST /learning-ledger/v1/identity/link`: redeem a course-server one-time code for an actor.
 *
 * The course server owns the code (hashed at rest, single use, 15-minute lifetime). This side
 * authenticates the learner, limits attempts, sends the signed redeem request and stores the link.
 */
final class Link_Code_Controller {

	const NS       = 'learning-ledger/v1';
	const ROUTE    = '/identity/link';
	const ATTEMPTS = 5;
	const WINDOW   = 600;
	const TIMEOUT  = 10;

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	/**
	 * Logged-in user, with a valid REST nonce unless an Application Password authenticated the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error
	 */
	public function permission( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
		}
		if ( ! empty( $GLOBALS['wp_rest_application_password_uuid'] ) ) {
			return true;
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Redeem a code and link the returned actor.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$user  = get_current_user_id();
		$retry = self::take_attempt( $user );
		if ( $retry > 0 ) {
			$res = self::error( 429, 'too_many_attempts', 'Too many attempts. Try again in a few minutes.' );
			$res->header( 'Retry-After', (string) $retry );
			return $res;
		}

		$code    = $request->get_param( 'code' );
		$program = $request->get_param( 'program' );
		$code    = is_string( $code ) ? trim( $code ) : '';
		if ( 1 !== preg_match( '/\A[A-Za-z0-9-]{4,64}\z/', $code ) || ! is_numeric( $program ) ) {
			return self::failed();
		}
		$program = ( new Program_Repository() )->get( (int) $program );
		if ( null === $program || '' === $program->course_server_url ) {
			return self::failed();
		}

		$actor = $this->redeem( $program->course_server_url, $code );
		if ( null === $actor ) {
			return self::failed();
		}
		try {
			$identity = Agent_Identity::from_array( $actor );
		} catch ( Invalid_Input $e ) {
			return self::failed();
		}

		$map = new Identity_Map();
		// Never take over a record another user holds, nor accept one of this site's own accounts.
		$holder = $map->user_for_actor( $actor );
		if ( ( null !== $holder && $holder !== $user ) || ( $identity->to_array()['account']['homePage'] ?? null ) === $map->home_page() ) {
			return self::failed();
		}

		$map->link( $identity, $user );
		( new Inbox_Repository() )->rematch_actor( $identity->hash() );
		Job_Queue::push(
			Link_Code_Module::JOB,
			array(
				'user'  => $user,
				'actor' => $identity->to_array(),
			),
			Link_Code_Module::JOB . ':' . $user . ':' . bin2hex( $identity->hash() )
		);
		return new \WP_REST_Response( array( 'linked' => true ), 200 );
	}

	/**
	 * Send the signed redeem request.
	 *
	 * @param string $server Course server URL.
	 * @param string $code   One-time code.
	 *
	 * @return array|null The actor, or null on any failure.
	 */
	private function redeem( string $server, string $code ): ?array {
		$url  = trailingslashit( $server ) . 'link-codes/redeem';
		$body = (string) wp_json_encode( array( 'code' => $code ) );
		try {
			$signature = Connector_Keys::sign( 'POST', $url, $body );
		} catch ( \RuntimeException $e ) {
			return null;
		}
		$res  = Lrs_Client::to_response(
			wp_remote_request(
				$url,
				array(
					'method'      => 'POST',
					'headers'     => array_merge( array( 'Content-Type' => 'application/json' ), $signature ),
					'body'        => $body,
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
				)
			)
		);
		$data = 200 === $res->status ? $res->json() : null;
		return is_array( $data ) && is_array( $data['actor'] ?? null ) ? $data['actor'] : null;
	}

	/**
	 * Count an attempt for a user in a fixed window, atomically.
	 *
	 * @param integer $user User id.
	 *
	 * @return integer 0 when the attempt is allowed, else the seconds until the window ends.
	 */
	private static function take_attempt( int $user ): int {
		global $wpdb;
		$now    = Clock::now();
		$at     = Timestamp::to_db( $now );
		$cutoff = Timestamp::to_db( $now->modify( '-' . self::WINDOW . ' seconds' ) );
		$bucket = 'link:' . $user;
		$table  = Tables::name( 'ratelimit' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- atomic upsert on a small plugin-owned table.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (bucket, tokens, updated_at) VALUES (%s, 1, %s)
				ON DUPLICATE KEY UPDATE tokens = IF(updated_at <= %s, 1, tokens + 1), updated_at = IF(updated_at <= %s, %s, updated_at)',
				$table,
				$bucket,
				$at,
				$cutoff,
				$cutoff,
				$at
			)
		);
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT tokens, updated_at FROM %i WHERE bucket = %s', $table, $bucket ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		if ( ! $row || (int) $row->tokens <= self::ATTEMPTS ) {
			return 0;
		}
		$started = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s.u', (string) $row->updated_at, new \DateTimeZone( 'UTC' ) );
		$elapsed = $started ? $now->getTimestamp() - $started->getTimestamp() : 0;
		return max( 1, self::WINDOW - $elapsed );
	}

	/**
	 * The one answer for every redeem failure, so a response never says why.
	 *
	 * @return \WP_REST_Response
	 */
	private static function failed(): \WP_REST_Response {
		return self::error( 400, 'link_failed', 'That code did not work. Check it and try again.' );
	}

	/**
	 * Error response.
	 *
	 * @param integer $status  HTTP status.
	 * @param string  $code    Reason code.
	 * @param string  $message Message.
	 *
	 * @return \WP_REST_Response
	 */
	private static function error( int $status, string $code, string $message ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
