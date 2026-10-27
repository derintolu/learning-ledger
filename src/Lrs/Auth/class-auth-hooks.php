<?php
/**
 * WordPress hooks for LRS client authentication.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

use LearningLedger\Kernel\Module;

/**
 * Keeps core's Application Password check off the client-facing routes, so their Basic header reaches Client_Route_Auth.
 */
final class Auth_Hooks implements Module {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'application_password_is_api_request', array( $this, 'filter_api_request' ) );
	}

	/**
	 * False for requests to Client_Route_Auth::PATHS.
	 *
	 * @param mixed $is_api_request Core's value (a bool, unless another filter changed it).
	 *
	 * @return mixed
	 */
	public function filter_api_request( mixed $is_api_request ): mixed {
		return self::is_client_route( $_SERVER ) ? false : $is_api_request;
	}

	/**
	 * Whether the current request targets a client-facing route, by pretty URL or `rest_route` query.
	 *
	 * @param array $server Server variables.
	 *
	 * @return boolean
	 */
	public static function is_client_route( array $server ): bool {
		$uri = isset( $server['REQUEST_URI'] ) && is_string( $server['REQUEST_URI'] ) ? wp_unslash( $server['REQUEST_URI'] ) : '';
		if ( '' === $uri ) {
			return false;
		}

		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $vars );
			if ( isset( $vars['rest_route'] ) && is_string( $vars['rest_route'] ) && Client_Route_Auth::matches( $vars['rest_route'] ) ) {
				return true;
			}
		}

		$path   = rawurldecode( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		$marker = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		$pos    = stripos( $path, $marker );
		if ( false === $pos ) {
			return false;
		}
		return Client_Route_Auth::matches( substr( $path, $pos + strlen( $marker ) - 1 ) );
	}
}
