<?php
/**
 * Router for the xAPI endpoint.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Http;

use LearningLedger\Kernel\Module;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Xapi\Version;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Xapi_Error messages and headers are served as JSON / HTTP headers, never echoed into HTML.

/**
 * Registers `learning-ledger/xapi` (aliased at `/xapi/`), checks version, size and
 * authentication, dispatches to resource handlers and serves their raw responses.
 */
final class Xapi_Router implements Module {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	public const REST_NAMESPACE = 'learning-ledger/xapi';

	/**
	 * Largest request body accepted, in bytes.
	 *
	 * @var int
	 */
	public const MAX_BODY_BYTES = 2097152;

	/**
	 * Version announced by `about` and by responses whose request had no usable version.
	 *
	 * @var string
	 */
	private const LATEST_VERSION = '2.0.0';

	/**
	 * Resource path => method => handler class.
	 *
	 * @var array<string,array<string,string>>
	 */
	public const HANDLERS = array(
		'statements'         => array(
			'GET'  => 'LearningLedger\Lrs\Statements\Statements_Read_Handler',
			'PUT'  => 'LearningLedger\Lrs\Statements\Statements_Write_Handler',
			'POST' => 'LearningLedger\Lrs\Statements\Statements_Write_Handler',
		),
		'activities/state'   => array(
			'GET'    => 'LearningLedger\Lrs\Documents\State_Handler',
			'PUT'    => 'LearningLedger\Lrs\Documents\State_Handler',
			'POST'   => 'LearningLedger\Lrs\Documents\State_Handler',
			'DELETE' => 'LearningLedger\Lrs\Documents\State_Handler',
		),
		'activities/profile' => array(
			'GET'    => 'LearningLedger\Lrs\Documents\Activity_Profile_Handler',
			'PUT'    => 'LearningLedger\Lrs\Documents\Activity_Profile_Handler',
			'POST'   => 'LearningLedger\Lrs\Documents\Activity_Profile_Handler',
			'DELETE' => 'LearningLedger\Lrs\Documents\Activity_Profile_Handler',
		),
		'agents/profile'     => array(
			'GET'    => 'LearningLedger\Lrs\Documents\Agent_Profile_Handler',
			'PUT'    => 'LearningLedger\Lrs\Documents\Agent_Profile_Handler',
			'POST'   => 'LearningLedger\Lrs\Documents\Agent_Profile_Handler',
			'DELETE' => 'LearningLedger\Lrs\Documents\Agent_Profile_Handler',
		),
		'activities'         => array(
			'GET' => 'LearningLedger\Lrs\Resources\Activities_Handler',
		),
		'agents'             => array(
			'GET' => 'LearningLedger\Lrs\Resources\Agents_Handler',
		),
		'about'              => array(
			'GET' => 'LearningLedger\Lrs\Resources\About_Handler',
		),
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'preflight' ), 5, 3 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'clear_core_errors' ), 10, 2 );
		// After core's rest_send_cors_headers (priority 10), so our CORS headers replace its.
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 100, 2 );
		add_filter( 'application_password_is_api_request', array( $this, 'application_password_is_api_request' ) );
	}

	/**
	 * Register the REST route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/(?P<resource>.*)',
			array(
				'methods'             => 'GET, PUT, POST, DELETE',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // Authentication happens in handle(), so its errors are xAPI-shaped.
			)
		);
	}

	/**
	 * Alias `/xapi/...` to the REST route.
	 *
	 * @return void
	 */
	public function add_rewrite(): void {
		add_rewrite_rule( '^xapi/?(.*)$', 'index.php?rest_route=/' . self::REST_NAMESPACE . '/$matches[1]', 'top' );
	}

	/**
	 * Whether a request targets the xAPI endpoint, from server variables alone.
	 *
	 * @param array $server Server variables ($_SERVER shape).
	 *
	 * @return boolean
	 */
	public static function is_xapi_request( array $server ): bool {
		$uri  = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = '/' . trim( $home, '/' );
		if ( '/' !== $home ) {
			if ( ! str_starts_with( $path, $home . '/' ) && $path !== $home ) {
				$path = '';
			} else {
				$path = substr( $path, strlen( $home ) );
			}
		}
		$path   = '/' . ltrim( $path, '/' );
		$prefix = preg_quote( '/' . trim( rest_get_url_prefix(), '/' ) . '/' . self::REST_NAMESPACE, '#' );
		if ( 1 === preg_match( '#^(?:/xapi|' . $prefix . ')(?:/|$)#', $path ) ) {
			return true;
		}

		$query = isset( $server['QUERY_STRING'] ) ? (string) $server['QUERY_STRING'] : (string) wp_parse_url( $uri, PHP_URL_QUERY );
		parse_str( $query, $vars );
		$route = isset( $vars['rest_route'] ) && is_string( $vars['rest_route'] ) ? $vars['rest_route'] : '';
		return str_starts_with( $route, '/' . self::REST_NAMESPACE );
	}

	/**
	 * Keep core's Application Password check off our Basic header.
	 *
	 * @param mixed $is_api_request Whether core treats this as an API request.
	 *
	 * @return mixed
	 */
	public function application_password_is_api_request( mixed $is_api_request ): mixed {
		return self::is_xapi_request( $_SERVER ) ? false : $is_api_request; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only matched against path patterns.
	}

	/**
	 * Answer CORS preflight (OPTIONS) for our routes.
	 *
	 * @param mixed            $result  Earlier result.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public function preflight( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		if ( null !== $result || 'OPTIONS' !== $request->get_method() || ! self::is_our_route( $request ) ) {
			return $result;
		}
		$allowed = class_exists( '\LearningLedger\Lrs\Auth\Origin_Index' ) ? \LearningLedger\Lrs\Auth\Origin_Index::all() : array();
		$res     = Cors::preflight( (string) $request->get_header( 'Origin' ), $allowed );
		return self::to_rest( self::with_version( $res, self::LATEST_VERSION ) );
	}

	/**
	 * Drop core's parameter errors (such as `rest_invalid_json`) for our route, so the
	 * router answers them in xAPI shape.
	 *
	 * @param mixed $response Response so far.
	 * @param array $handler  Matched handler.
	 *
	 * @return mixed
	 */
	public function clear_core_errors( mixed $response, array $handler ): mixed {
		$callback = $handler['callback'] ?? null;
		if ( is_wp_error( $response ) && is_array( $callback ) && ( $callback[0] ?? null ) instanceof self ) {
			return null;
		}
		return $response;
	}

	/**
	 * REST callback for every xAPI resource.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = trim( (string) $request->get_param( 'resource' ), '/' );
		$is_about = 'about' === $resource;
		$version  = self::LATEST_VERSION;
		$xapi     = null;

		try {
			$header = $request->get_header( 'X-Experience-API-Version' );
			if ( null === $header && Alternate_Syntax::is_alternate( $request ) ) {
				$header = Alternate_Syntax::form_field( $request, 'X-Experience-API-Version' );
			}
			try {
				$version = Version::negotiate( $header );
			} catch ( Invalid_Input $e ) {
				if ( ! $is_about ) {
					throw new Xapi_Error( 400, 'Missing or unsupported X-Experience-API-Version header.' );
				}
				$version = self::LATEST_VERSION;
			}

			$request = Alternate_Syntax::apply( $request, $version );
			if ( strlen( (string) $request->get_body() ) > self::MAX_BODY_BYTES ) {
				throw new Xapi_Error( 413, 'Request body is larger than 2 MB.' );
			}

			$xapi  = Xapi_Request::from_rest( $request );
			$class = self::handler_class( $resource, $xapi->method() );
			if ( ! $is_about ) {
				$xapi = $xapi->with_principal( self::authenticate( $xapi ) );
			}
			$handler = new $class();
			if ( ! $handler instanceof Resource_Handler ) {
				throw new Xapi_Error( 501, 'This resource is not implemented.' );
			}
			$res = $handler->handle( $xapi );
		} catch ( Xapi_Error $e ) {
			$res = $e->to_response();
		} catch ( Invalid_Input $e ) {
			$res = ( new Xapi_Error( 400, $e->getMessage() ) )->to_response();
		}

		$principal = null === $xapi ? null : $xapi->principal();
		$res       = Cors::decorate( $res, $request->get_header( 'Origin' ), null === $principal ? array() : $principal->cors_origins() );
		return self::to_rest( self::with_version( $res, $is_about ? self::LATEST_VERSION : $version ) );
	}

	/**
	 * Serve our responses byte for byte; leave every other response to WordPress.
	 *
	 * @param mixed $served Whether the request was already served.
	 * @param mixed $result Result (usually a WP_REST_Response).
	 *
	 * @return mixed
	 */
	public function serve( mixed $served, mixed $result ): mixed {
		if ( $served || ! $result instanceof \WP_HTTP_Response ) {
			return $served;
		}
		$res = $result->get_data();
		if ( ! $res instanceof Xapi_Response ) {
			return $served;
		}
		if ( ! headers_sent() ) {
			foreach ( array( 'Access-Control-Allow-Origin', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers', 'Allow' ) as $name ) {
				header_remove( $name );
			}
			if ( '' === $res->content_type() ) {
				header_remove( 'Content-Type' );
			} else {
				header( 'Content-Type: ' . $res->content_type() );
			}
			foreach ( $res->headers() as $name => $value ) {
				header( $name . ': ' . $value );
			}
			status_header( $res->status() );
		}
		echo $res->body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw xAPI response body, served with its own Content-Type.
		return true;
	}

	/**
	 * Handler class for a resource and method.
	 *
	 * @param string $path   Resource path.
	 * @param string $method HTTP method.
	 *
	 * @return string
	 * @throws Xapi_Error 404 unknown resource, 405 unsupported method, 501 handler not available.
	 */
	private static function handler_class( string $path, string $method ): string {
		$handlers = apply_filters( 'learning_ledger/xapi_handlers', self::HANDLERS ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		if ( ! isset( $handlers[ $path ] ) || ! is_array( $handlers[ $path ] ) ) {
			throw new Xapi_Error( 404, 'Unknown xAPI resource.' );
		}
		if ( ! isset( $handlers[ $path ][ $method ] ) ) {
			throw new Xapi_Error( 405, 'Method not allowed on this resource.', array( 'Allow' => implode( ', ', array_keys( $handlers[ $path ] ) ) ) );
		}
		$class = $handlers[ $path ][ $method ];
		if ( ! is_string( $class ) || ! class_exists( $class ) ) {
			throw new Xapi_Error( 501, 'This resource is not implemented.' );
		}
		return $class;
	}

	/**
	 * Authenticate the caller.
	 *
	 * @param Xapi_Request $xapi Request.
	 *
	 * @return \LearningLedger\Lrs\Auth\Principal
	 * @throws Xapi_Error 401 when authentication is unavailable or fails.
	 */
	private static function authenticate( Xapi_Request $xapi ) {
		if ( ! class_exists( '\LearningLedger\Lrs\Auth\Authenticator' ) ) {
			throw new Xapi_Error( 401, 'Authentication is required.', array( 'WWW-Authenticate' => 'Basic realm="Learning Ledger"' ) );
		}
		return ( new \LearningLedger\Lrs\Auth\Authenticator() )->authenticate( $xapi, $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Authenticator validates the credentials it reads.
	}

	/**
	 * Whether a REST request is for our namespace.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return boolean
	 */
	private static function is_our_route( \WP_REST_Request $request ): bool {
		$route = $request->get_route();
		return '/' . self::REST_NAMESPACE === $route || str_starts_with( $route, '/' . self::REST_NAMESPACE . '/' );
	}

	/**
	 * Copy of a response with the version header set.
	 *
	 * @param Xapi_Response $res     Response.
	 * @param string        $version Version.
	 *
	 * @return Xapi_Response
	 */
	private static function with_version( Xapi_Response $res, string $version ): Xapi_Response {
		return new Xapi_Response( $res->status(), $res->body(), $res->content_type(), array_merge( $res->headers(), array( 'X-Experience-API-Version' => $version ) ) );
	}

	/**
	 * Wrap for the REST server; `serve()` writes it out.
	 *
	 * @param Xapi_Response $res Response.
	 *
	 * @return \WP_REST_Response
	 */
	private static function to_rest( Xapi_Response $res ): \WP_REST_Response {
		return new \WP_REST_Response( $res, $res->status(), $res->headers() );
	}
}
