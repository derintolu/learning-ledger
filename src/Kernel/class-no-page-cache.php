<?php
/**
 * Keeps private and authenticated responses out of shared page caches.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Kernel;

/**
 * Some hosts run a page cache (Batcache on WordPress.com Atomic, for one) that stores anonymous
 * GET responses by URL, ignores request headers and ignores `Cache-Control: no-store`. A signed
 * or Basic-authenticated request is "anonymous" to such a cache, so it would replay that answer
 * (a secret package, a status with the connector secret's hash, a record page after the learner
 * was erased, even a 401) to anyone requesting the same URL.
 *
 * No response of the plugin's REST namespaces is ever kept by such a cache: as the request
 * arrives (parse_request, before the REST server runs, so errors WordPress answers before any
 * route, failed authorisation and page twins that end the request are covered too) and again
 * before dispatch, the page cache is cancelled for every route under `/learning-ledger/`,
 * the namespace indexes included. After dispatch every response carries the no-store headers
 * too, except PUBLIC_ROUTES, identical for everyone, which keep their own short public
 * Cache-Control for browsers and verifiers. The front-end pages (record, claim, badge, did.json)
 * call apply() themselves.
 */
final class No_Page_Cache implements Module {

	/**
	 * Headers that keep a response out of browsers' and proxies' caches.
	 */
	public const HEADERS = array(
		'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
		'Pragma'        => 'no-cache',
		'Expires'       => 'Wed, 11 Jan 1984 05:00:00 GMT',
	);

	/**
	 * The plugin's REST routes: the `learning-ledger/v1` and `learning-ledger/xapi` namespaces,
	 * their indexes, and anything else under `/learning-ledger`.
	 */
	public const ROUTES = '#^/learning-ledger(?:/|$)#';

	/**
	 * Public routes, identical for everyone: the page cache is cancelled for them too, but they
	 * keep their own public Cache-Control instead of the no-store headers (the public status,
	 * the issuer profile, achievement definitions, did.json's twin and badge status lists).
	 */
	public const PUBLIC_ROUTES = array(
		'#^/learning-ledger/v1/status/?$#',
		'#^/learning-ledger/v1/issuer/?$#',
		'#^/learning-ledger/v1/achievements/[0-9a-f]{40}/?$#',
		'#^/learning-ledger/v1/page/did/?$#',
		'#^/learning-ledger/v1/status-lists/[1-9][0-9]{0,18}/?$#',
	);

	/**
	 * Cache-Control of the revocation- and rotation-bearing public responses (did.json and its
	 * twin, badge status lists): browsers and verifiers may keep them for a minute.
	 */
	public const SHORT_CACHE_CONTROL = 'public, max-age=60';

	/**
	 * Whether apply() ran in this request.
	 *
	 * @var boolean
	 */
	private static bool $applied = false;

	/**
	 * Whether the page cache was cancelled in this request.
	 *
	 * @var boolean
	 */
	private static bool $cancelled = false;

	/**
	 * Hook the REST server.
	 *
	 * @return void
	 */
	public function register(): void {
		// As the request arrives, so errors WordPress answers before any route runs (authentication,
		// no route, invalid parameters) and page twins that end the request are covered too.
		add_action( 'parse_request', array( self::class, 'on_request' ), 0 );
		add_filter( 'rest_pre_dispatch', array( self::class, 'pre_dispatch' ), 0, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'post_dispatch' ), PHP_INT_MAX, 3 );
	}

	/**
	 * `parse_request`: a REST request for one of the plugin's routes, before the REST server runs.
	 *
	 * @param mixed $wp WordPress environment.
	 *
	 * @return void
	 */
	public static function on_request( mixed $wp ): void {
		$route = $wp instanceof \WP && is_string( $wp->query_vars['rest_route'] ?? null ) ? '/' . ltrim( $wp->query_vars['rest_route'], '/' ) : '';
		self::for_route( $route );
	}

	/**
	 * Cancel the page cache for any of the plugin's routes, with the no-store headers unless public.
	 *
	 * @param string $route REST route.
	 *
	 * @return void
	 */
	private static function for_route( string $route ): void {
		if ( self::covers( $route ) ) {
			self::apply( ! self::is_public_route( $route ) );
		}
	}

	/**
	 * Whether a REST route is one of the plugin's (none of them is ever page-cached).
	 *
	 * @param string $route Route, e.g. `/learning-ledger/v1/course-server/status`.
	 *
	 * @return boolean
	 */
	public static function covers( string $route ): bool {
		return 1 === preg_match( self::ROUTES, $route );
	}

	/**
	 * Whether a route is one of the PUBLIC_ROUTES.
	 *
	 * @param string $route REST route.
	 *
	 * @return boolean
	 */
	public static function is_public_route( string $route ): bool {
		foreach ( self::PUBLIC_ROUTES as $public ) {
			if ( 1 === preg_match( $public, $route ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a REST route is one of the plugin's private routes (no-store headers).
	 *
	 * @param string $route REST route.
	 *
	 * @return boolean
	 */
	public static function is_private_route( string $route ): bool {
		return self::covers( $route ) && ! self::is_public_route( $route );
	}

	/**
	 * `rest_pre_dispatch`: cancel page caching before the route runs or authorises anything.
	 *
	 * @param mixed            $result  Short-circuit value.
	 * @param mixed            $server  Server.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public static function pre_dispatch( mixed $result, mixed $server, \WP_REST_Request $request ): mixed {
		unset( $server );
		self::for_route( $request->get_route() );
		return $result;
	}

	/**
	 * `rest_post_dispatch`: the no-store headers on every private response, success or error.
	 *
	 * @param mixed            $response Response.
	 * @param mixed            $server   Server.
	 * @param \WP_REST_Request $request  Request.
	 *
	 * @return mixed
	 */
	public static function post_dispatch( mixed $response, mixed $server, \WP_REST_Request $request ): mixed {
		unset( $server );
		$route = $request->get_route();
		self::for_route( $route );
		if ( $response instanceof \WP_HTTP_Response && self::is_private_route( $route ) ) {
			foreach ( self::HEADERS as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}

	/**
	 * Keep this request's response out of page caches: DONOTCACHEPAGE and batcache_cancel(), and
	 * for a private response the no-store headers (sent now, unless output has started). Safe to
	 * call more than once.
	 *
	 * @param boolean $is_private Whether the response is private (no-store), not merely uncacheable here.
	 *
	 * @return void
	 */
	public static function apply( bool $is_private = true ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page-cache convention.
		}
		if ( ! self::$cancelled ) {
			self::$cancelled = true;
			if ( function_exists( 'batcache_cancel' ) ) {
				batcache_cancel();
			}
		}
		if ( ! $is_private || self::$applied ) {
			return;
		}
		self::$applied = true;
		if ( ! headers_sent() ) {
			foreach ( self::HEADERS as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}
	}

	/**
	 * Whether the page cache was cancelled in this request.
	 *
	 * @return boolean
	 */
	public static function cancelled(): bool {
		return self::$cancelled;
	}

	/**
	 * Whether apply() ran in this request.
	 *
	 * @return boolean
	 */
	public static function applied(): bool {
		return self::$applied;
	}

	/**
	 * Forget that apply() ran (tests: one test, several requests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$applied   = false;
		self::$cancelled = false;
	}
}
