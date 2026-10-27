<?php
/**
 * Basic authentication for the plugin's client-facing REST routes.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;

/**
 * The xAPI Basic authentication, applied to `learning-ledger/v1` routes that LRS clients call.
 */
final class Client_Route_Auth {

	/**
	 * Route prefixes (each matches itself and anything below it).
	 *
	 * @var string[]
	 */
	const PATHS = array(
		'/learning-ledger/v1/tokens',
		'/learning-ledger/v1/courses',
		'/learning-ledger/v1/view-links',
		'/learning-ledger/v1/badges',
		'/learning-ledger/v1/actors/erase',
	);

	/**
	 * Authenticate a REST request and require a scope.
	 *
	 * @param \WP_REST_Request $r     Request.
	 * @param string           $scope Required scope.
	 *
	 * @return Principal|\WP_Error 401/403/429 errors carry `status` and `headers` in their data.
	 */
	public static function principal( \WP_REST_Request $r, string $scope ): Principal|\WP_Error {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an IP.
		}
		$request = new Xapi_Request( $r->get_method(), $r->get_route(), array(), $r->get_headers(), '', '', $ip );

		try {
			$p = ( new Authenticator() )->authenticate( $request, $_SERVER );
		} catch ( Xapi_Error $e ) {
			return new \WP_Error(
				429 === $e->status() ? 'lledger_rate_limited' : 'lledger_unauthorized',
				$e->getMessage(),
				array(
					'status'  => $e->status(),
					'headers' => $e->headers(),
				)
			);
		}

		if ( ! $p->can( $scope ) ) {
			return new \WP_Error(
				'lledger_forbidden',
				__( 'This client does not hold the scope this route needs.', 'learning-ledger' ),
				array( 'status' => 403 )
			);
		}
		return $p;
	}

	/**
	 * Whether a REST route is one of PATHS (case-insensitive, as REST routing is).
	 *
	 * @param string $route Route, e.g. `/learning-ledger/v1/tokens/abc`.
	 *
	 * @return boolean
	 */
	public static function matches( string $route ): bool {
		$route = strtolower( '/' . ltrim( $route, '/' ) );
		foreach ( self::PATHS as $prefix ) {
			if ( $route === $prefix || str_starts_with( $route, $prefix . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
