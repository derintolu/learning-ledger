<?php
/**
 * REST twins of the front-end pages.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Kernel;

/**
 * `learning-ledger/v1/page/...`: the same pages as the pretty front-end paths, for hosts that
 * pass only `/wp-json/` and `?rest_route=` to WordPress and proxy the pretty paths there.
 *
 * A twin calls the page's own serve(), which sends its status and headers, echoes the page
 * and ends the request before the REST server sends anything, so nothing is JSON-encoded.
 * The headers the REST server sends before dispatching are removed first. A page that leaves
 * a 404 to the theme gets a plain HTML 404 here, echoed raw by rest_pre_serve_request.
 */
final class Page_Route {

	const NAMESPACE = 'learning-ledger/v1';

	/**
	 * Headers WP_REST_Server::serve_request() sends before dispatching, which the pretty pages
	 * do not send. Content-Type is replaced by each page's own.
	 */
	const REST_HEADERS = array( 'X-Robots-Tag', 'Link', 'X-Content-Type-Options', 'Access-Control-Expose-Headers', 'Access-Control-Allow-Headers' );

	/**
	 * Register a twin route.
	 *
	 * @param string   $route    Route under `page/`, as a REST route regex.
	 * @param string   $methods  Methods, e.g. `GET` or `GET, POST`.
	 * @param callable $callback Callback taking the WP_REST_Request.
	 *
	 * @return void
	 */
	public static function register( string $route, string $methods, callable $callback ): void {
		register_rest_route(
			self::NAMESPACE,
			'/page/' . $route,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Remove the REST server's own headers before a page sends its own.
	 *
	 * @return void
	 */
	public static function start(): void {
		if ( headers_sent() ) {
			return;
		}
		foreach ( self::REST_HEADERS as $name ) {
			header_remove( $name );
		}
	}

	/**
	 * The 404 for a page that leaves it to the theme, which a REST request does not reach.
	 *
	 * @return \WP_REST_Response
	 */
	public static function not_found(): \WP_REST_Response {
		$title    = esc_html__( 'Not found', 'learning-ledger' );
		$response = new \WP_REST_Response( '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . $title . '</title></head><body><h1>' . $title . '</h1></body></html>', 404 );
		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			$response->header( $name, (string) $value );
		}
		return $response;
	}

	/**
	 * `rest_pre_serve_request`: echo a twin's HTML response as it is instead of JSON.
	 *
	 * @param mixed            $served  Whether the request has been served.
	 * @param mixed            $result  Response.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public static function serve_raw( mixed $served, mixed $result, \WP_REST_Request $request ): mixed {
		if ( $served || ! $result instanceof \WP_HTTP_Response || ! is_string( $result->get_data() )
			|| ! str_starts_with( $request->get_route(), '/' . self::NAMESPACE . '/page/' ) ) {
			return $served;
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the page built by not_found(), escaped there.
		return true;
	}
}
