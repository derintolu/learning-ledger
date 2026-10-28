<?php
/**
 * Claim link route.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Claim;

use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Lrs\Auth\Client_Route_Auth;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Links\Link_Repository;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;
use LearningLedger\Xapi\Agent_Identity;

/**
 * `POST /learning-ledger/v1/badges/{id}/claim-links` `{actor}`: Basic auth, scope
 * `badges/claim-links`, the actor within the client's bindings and the badge's own learner,
 * the badge still pending. Answers 201 `{url, expires_at}`; the link is single use, works for
 * 15 minutes, and minting another one for the same badge invalidates it.
 */
final class Claim_Links_Controller {

	const NAMESPACE = 'learning-ledger/v1';

	/**
	 * Lifetime of a claim link, in seconds.
	 */
	const TTL = 900;

	/**
	 * Constructor.
	 *
	 * @param Badge_Repository $badges Badges.
	 * @param Link_Repository  $links  Links.
	 */
	public function __construct(
		private readonly Badge_Repository $badges = new Badge_Repository(),
		private readonly Link_Repository $links = new Link_Repository()
	) {}

	/**
	 * Register the route.
	 *
	 * Authentication runs in the callback rather than in permission_callback, so the
	 * WWW-Authenticate and Retry-After headers of a 401 or 429 reach the response.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/badges/(?P<id>[0-9a-fA-F-]{36})/claim-links',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mint' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Mint a claim link for one badge and its learner.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function mint( \WP_REST_Request $r ): \WP_REST_Response {
		$p = Client_Route_Auth::principal( $r, Scopes::BADGES_CLAIM_LINKS );
		if ( ! $p instanceof Principal ) {
			return self::response_for( $p );
		}

		$body = $r->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['actor'] ) || ! is_array( $body['actor'] ) ) {
			return self::response_for( self::error( 'lledger_invalid', __( 'actor must be an Agent object.', 'learning-ledger' ), 400, 'actor' ) );
		}
		try {
			$actor = Agent_Identity::from_array( $body['actor'] );
		} catch ( Invalid_Input $e ) {
			return self::response_for( self::error( 'lledger_invalid', $e->getMessage(), 400, 'actor' ) );
		}
		$bindings = $p->bindings();
		if ( ! $bindings->allows_actor( $actor ) ) {
			return self::response_for( self::error( 'lledger_forbidden', __( 'The actor is outside the client bindings.', 'learning-ledger' ), 403, 'actor' ) );
		}

		// An unknown badge and another learner's badge answer alike, so a client cannot probe for badges.
		$badge = $this->badges->get( (string) $r->get_param( 'id' ) );
		if ( null === $badge || ! hash_equals( $badge['actor_hash'], $actor->hash() ) ) {
			return self::response_for( self::error( 'lledger_not_found', __( 'This actor has no such badge.', 'learning-ledger' ), 404 ) );
		}
		$filter = $bindings->read_filter();
		if ( isset( $filter['registration'] ) && $filter['registration'] !== $badge['registration'] ) {
			return self::response_for( self::error( 'lledger_forbidden', __( 'The badge is outside the client bindings.', 'learning-ledger' ), 403 ) );
		}
		if ( Badge_Repository::PENDING !== $badge['status'] ) {
			return self::response_for( self::error( 'lledger_not_pending', __( 'Only a pending badge can be claimed.', 'learning-ledger' ), 409 ) );
		}

		$expires = Clock::now()->modify( '+' . self::TTL . ' seconds' );
		try {
			$token = $this->links->mint( 'claim', $actor->hash(), $badge['id'], self::TTL, 'client:' . $p->client_id() );
		} catch ( \RuntimeException | Invalid_Input $e ) {
			return self::response_for( self::error( 'lledger_storage', __( 'The link could not be stored.', 'learning-ledger' ), 500 ) );
		}

		$res = new \WP_REST_Response(
			array(
				'url'        => Claim_Page::url( $token ),
				'expires_at' => Timestamp::to_xapi( $expires ),
			),
			201
		);
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/**
	 * An error as a no-store response, sending any headers in its data (WWW-Authenticate, Retry-After).
	 *
	 * @param \WP_Error $error Error.
	 *
	 * @return \WP_REST_Response
	 */
	private static function response_for( \WP_Error $error ): \WP_REST_Response {
		$data    = $error->get_error_data();
		$headers = is_array( $data ) && is_array( $data['headers'] ?? null ) ? $data['headers'] : array();
		if ( is_array( $data ) && isset( $data['headers'] ) ) {
			unset( $data['headers'] );
			$error = new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
		}
		$res = rest_convert_error_to_response( $error );
		foreach ( $headers as $name => $value ) {
			$res->header( (string) $name, (string) $value );
		}
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/**
	 * A REST error.
	 *
	 * @param string  $code    Error code.
	 * @param string  $message Message.
	 * @param integer $status  HTTP status.
	 * @param string  $field   Offending field, if any.
	 *
	 * @return \WP_Error
	 */
	private static function error( string $code, string $message, int $status, string $field = '' ): \WP_Error {
		$data = array( 'status' => $status );
		if ( '' !== $field ) {
			$data['field'] = $field;
		}
		return new \WP_Error( $code, $message, $data );
	}
}
