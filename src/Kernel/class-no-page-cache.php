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
 * Every REST response of the plugin's namespaces is private by default: before dispatch (so
 * errors and failed authorisation are covered too) the cache is cancelled, and after dispatch
 * the response carries no-store headers. Only the routes in PUBLIC_ROUTES, identical for
 * everyone, may be cached. The front-end pages (record, claim, badge) call apply() themselves.
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
	 * REST route prefixes of the plugin (`learning-ledger/v1`, `learning-ledger/xapi`).
	 */
	public const PREFIX = '/learning-ledger/';

	/**
	 * Public routes, identical for everyone, that a page cache may keep: the public status, the
	 * issuer profile, did.json's twin, badge status lists and achievement definitions.
	 */
	public const PUBLIC_ROUTES = array(
		'#^/learning-ledger/v1/status/?$#',
		'#^/learning-ledger/v1/issuer/?$#',
		'#^/learning-ledger/v1/page/did/?$#',
		'#^/learning-ledger/v1/status-lists/[1-9][0-9]{0,18}/?$#',
		'#^/learning-ledger/v1/achievements/[0-9a-f]{40}/?$#',
	);

	/**
	 * Whether apply() ran in this request.
	 *
	 * @var boolean
	 */
	private static bool $applied = false;

	/**
	 * Hook the REST server.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'pre_dispatch' ), 0, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'post_dispatch' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Whether a REST route is one of the plugin's private routes.
	 *
	 * @param string $route Route, e.g. `/learning-ledger/v1/course-server/status`.
	 *
	 * @return boolean
	 */
	public static function is_private_route( string $route ): bool {
		if ( ! str_starts_with( $route, self::PREFIX ) ) {
			return false;
		}
		foreach ( self::PUBLIC_ROUTES as $public ) {
			if ( 1 === preg_match( $public, $route ) ) {
				return false;
			}
		}
		return true;
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
		if ( self::is_private_route( $request->get_route() ) ) {
			self::apply();
		}
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
		if ( $response instanceof \WP_HTTP_Response && self::is_private_route( $request->get_route() ) ) {
			self::apply();
			foreach ( self::HEADERS as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}

	/**
	 * Keep this request's response out of page caches: DONOTCACHEPAGE, batcache_cancel() and the
	 * no-store headers (sent now, unless output has started). Safe to call more than once.
	 *
	 * @return void
	 */
	public static function apply(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page-cache convention.
		}
		if ( self::$applied ) {
			return;
		}
		self::$applied = true;
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		if ( ! headers_sent() ) {
			foreach ( self::HEADERS as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}
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
		self::$applied = false;
	}
}
