<?php
/**
 * Authorization of Ed25519-signed course-server requests.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\CourseServer;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- atomic counter upsert on the plugin-owned ratelimit table.

use LearningLedger\Crypto\Nonce_Store;
use LearningLedger\Crypto\Signature_Error;
use LearningLedger\Crypto\Signed_Request;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;

/**
 * Verifies a signed request against the trusted keys, then the key's capability and, for
 * learner creation, its per-minute and per-day limits. Nothing is written before the
 * signature checks out; the nonce is recorded only after that.
 */
final class Signed_Request_Auth {

	/**
	 * Capability argument for routes any trusted key may call.
	 */
	const ANY = '';

	/**
	 * Trusted keys.
	 *
	 * @var Trusted_Keys
	 */
	private Trusted_Keys $keys;

	/**
	 * Nonce store.
	 *
	 * @var Nonce_Store
	 */
	private Nonce_Store $nonces;

	/**
	 * Constructor.
	 *
	 * @param Trusted_Keys|null $keys   Trusted keys.
	 * @param Nonce_Store|null  $nonces Nonce store.
	 */
	public function __construct( ?Trusted_Keys $keys = null, ?Nonce_Store $nonces = null ) {
		$this->keys   = $keys ?? new Trusted_Keys();
		$this->nonces = $nonces ?? new Db_Nonce_Store();
	}

	/**
	 * Authorize a request for one capability.
	 *
	 * The signed path is the raw path and query of the request URI, exactly as the client sent it.
	 *
	 * @param \WP_REST_Request $r          Request.
	 * @param string           $capability Capability the route needs, or ANY.
	 *
	 * @return string|\WP_Error Key id, or an error: 401 for signature errors, 403 for a missing capability, 429 for limits.
	 */
	public function authorize( \WP_REST_Request $r, string $capability ): string|\WP_Error {
		$path = self::request_uri();
		if ( '' === $path ) {
			return self::signature_error( 'bad_signature' );
		}
		$keys = $this->keys;
		$key  = null;
		try {
			$key_id = Signed_Request::verify(
				$r->get_headers(),
				$r->get_method(),
				$path,
				(string) $r->get_body(),
				static function ( string $id ) use ( $keys, &$key ): ?string {
					$key = $keys->get( $id );
					return null === $key ? null : $key['public'];
				},
				$this->nonces,
				Clock::now()->getTimestamp()
			);
		} catch ( Signature_Error $e ) {
			return self::signature_error( $e->reason() );
		}

		if ( null === $key ) {
			return self::signature_error( 'unknown_key' );
		}
		if ( self::ANY !== $capability && ! in_array( $capability, $key['capabilities'], true ) ) {
			return new \WP_Error( 'lledger_capability', 'This key does not hold the capability this route needs.', array( 'status' => 403 ) );
		}
		if ( Trusted_Keys::CREATE_LEARNERS === $capability ) {
			$limited = $this->limit( $key_id, $key['limits'] );
			if ( null !== $limited ) {
				return $limited;
			}
		}
		return $key_id;
	}

	/**
	 * Turn an authorization error into a REST response, with any headers it carries.
	 *
	 * @param \WP_Error $e Error from authorize().
	 *
	 * @return \WP_REST_Response
	 */
	public static function to_response( \WP_Error $e ): \WP_REST_Response {
		$data   = (array) $e->get_error_data();
		$status = (int) ( $data['status'] ?? 401 );
		$res    = new \WP_REST_Response(
			array(
				'code'    => $e->get_error_code(),
				'message' => $e->get_error_message(),
				'data'    => array( 'status' => $status ),
			),
			$status
		);
		foreach ( (array) ( $data['headers'] ?? array() ) as $name => $value ) {
			$res->header( (string) $name, (string) $value );
		}
		return $res;
	}

	/**
	 * The raw request URI (path and query). It is only compared inside the signature, never output or stored.
	 *
	 * @return string
	 */
	private static function request_uri(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the exact bytes are signed; sanitizing would change them.
		return wp_unslash( $_SERVER['REQUEST_URI'] );
	}

	/**
	 * 401 for a failed signature check.
	 *
	 * @param string $reason Signature_Error reason.
	 *
	 * @return \WP_Error
	 */
	private static function signature_error( string $reason ): \WP_Error {
		return new \WP_Error(
			'lledger_signature_' . $reason,
			'The request signature was not accepted (' . $reason . ').',
			array( 'status' => 401 )
		);
	}

	/**
	 * Count one learner creation against the key's per-minute and per-day windows.
	 *
	 * @param string $key_id Key id.
	 * @param array  $limits Limits `per_minute` and `per_day`.
	 *
	 * @return \WP_Error|null 429 with Retry-After when a window is full.
	 */
	private function limit( string $key_id, array $limits ): ?\WP_Error {
		$now = Clock::now()->getTimestamp();
		foreach ( array(
			'm' => array( 60, (int) $limits['per_minute'] ),
			'd' => array( 86400, (int) $limits['per_day'] ),
		) as $window => list( $seconds, $max ) ) {
			$start = $now - ( $now % $seconds );
			if ( self::count( 'lledger_cs:' . $window . ':' . $key_id, $start ) > $max ) {
				return new \WP_Error(
					'lledger_rate_limited',
					'This key has created as many learners as its limit allows for now.',
					array(
						'status'  => 429,
						'headers' => array( 'Retry-After' => (string) ( $start + $seconds - $now ) ),
					)
				);
			}
		}
		return null;
	}

	/**
	 * Increment a fixed-window counter in `lledger_ratelimit` and return the new count.
	 * The row's `updated_at` holds the window start; a new window resets the count to 1.
	 *
	 * @param string  $bucket Bucket name.
	 * @param integer $start  Window start, Unix seconds.
	 *
	 * @return integer
	 */
	private static function count( string $bucket, int $start ): int {
		global $wpdb;
		$at = gmdate( 'Y-m-d H:i:s', $start ) . '.000000';
		// LAST_INSERT_ID(expr) carries the new count back from the single atomic upsert.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (bucket, tokens, updated_at) VALUES (%s, LAST_INSERT_ID(1), %s)
				ON DUPLICATE KEY UPDATE tokens = LAST_INSERT_ID(IF(updated_at = %s, tokens + 1, 1)), updated_at = %s',
				Tables::name( 'ratelimit' ),
				$bucket,
				$at,
				$at,
				$at
			)
		);
		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	}
}
