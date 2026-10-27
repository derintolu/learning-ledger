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
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Invalid_Input;
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
		$user = get_current_user_id();
		if ( ! self::take_attempt( $user ) ) {
			return self::error( 429, 'too_many_attempts', 'Too many attempts. Try again in a few minutes.' );
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

		( new Identity_Map() )->link( $identity, $user );
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
	 * Count an attempt for a user.
	 *
	 * @param integer $user User id.
	 *
	 * @return boolean False when the user is over the limit.
	 */
	private static function take_attempt( int $user ): bool {
		$key   = 'lledger_link_attempts_' . $user;
		$state = get_transient( $key );
		$now   = time();
		if ( ! is_array( $state ) || (int) ( $state['reset'] ?? 0 ) <= $now ) {
			$state = array(
				'count' => 0,
				'reset' => $now + self::WINDOW,
			);
		}
		if ( (int) $state['count'] >= self::ATTEMPTS ) {
			return false;
		}
		++$state['count'];
		set_transient( $key, $state, max( 1, (int) $state['reset'] - $now ) );
		return true;
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
