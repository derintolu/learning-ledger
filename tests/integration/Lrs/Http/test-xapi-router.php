<?php
/**
 * xAPI router tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Lrs\Http\Resource_Handler;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Http\Xapi_Response;
use LearningLedger\Lrs\Http\Xapi_Router;
use LearningLedger\Tests\Xapi_Test_Client;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- the fake handler belongs to this test only.

/**
 * Handler that records the request it got and answers with a fixed response.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Xapi_Router_Test_Handler implements Resource_Handler {

	/**
	 * Requests seen.
	 *
	 * @var Xapi_Request[]
	 */
	public static array $seen = array();

	/**
	 * Response to give.
	 *
	 * @var Xapi_Response|null
	 */
	public static ?Xapi_Response $reply = null;

	public function handle( Xapi_Request $r ): Xapi_Response {
		self::$seen[] = $r;
		return self::$reply ?? Xapi_Response::json( 200, array( 'ok' => true ) );
	}
}

class Xapi_Router_Test extends WP_UnitTestCase {

	private array $server_backup = array();

	public function set_up() {
		parent::set_up();
		$this->server_backup             = $_SERVER;
		Xapi_Router_Test_Handler::$seen  = array();
		Xapi_Router_Test_Handler::$reply = null;
		( new Xapi_Router() )->register();
		add_filter(
			'learning_ledger/xapi_handlers',
			static function ( array $handlers ) {
				foreach ( array( 'about', 'activities', 'statements' ) as $path ) {
					foreach ( array_keys( $handlers[ $path ] ) as $method ) {
						$handlers[ $path ][ $method ] = Xapi_Router_Test_Handler::class;
					}
				}
				return $handlers;
			}
		);
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		$_SERVER                   = $this->server_backup;
		parent::tear_down();
	}

	public function test_dispatches_to_handler_and_sets_version_header() {
		$res = ( new Xapi_Test_Client() )->request( 'GET', 'about', array( 'x' => '1' ), null, array( 'X-Experience-API-Version' => '1.0.3' ) );

		$this->assertSame( 200, $res->status() );
		$this->assertSame( '{"ok":true}', $res->body() );
		$this->assertSame( '2.0.0', $res->headers()['X-Experience-API-Version'] );
		$this->assertCount( 1, Xapi_Router_Test_Handler::$seen );
		$seen = Xapi_Router_Test_Handler::$seen[0];
		$this->assertSame( 'GET', $seen->method() );
		$this->assertSame( 'about', $seen->resource() );
		$this->assertSame( array( 'x' => '1' ), $seen->params() );
		$this->assertSame( '1.0.3', $seen->version() );

		// Any other resource answers with the negotiated version, even on an error; no credentials means 401.
		$res = ( new Xapi_Test_Client() )->request( 'GET', 'activities', array( 'activityId' => 'http://x/a' ), null, array( 'X-Experience-API-Version' => '1.0' ) );
		$this->assertSame( 401, $res->status() );
		$this->assertSame( '1.0.3', $res->headers()['X-Experience-API-Version'] );
		$this->assertSame( 'Basic realm="Learning Ledger"', $res->headers()['WWW-Authenticate'] );
		$this->assertTrue( json_decode( $res->body(), true )['error'] );
		$this->assertCount( 1, Xapi_Router_Test_Handler::$seen );
	}

	public function test_unknown_resource_404_and_unsupported_method_405() {
		$res = ( new Xapi_Test_Client() )->request( 'GET', 'nothing/here' );
		$this->assertSame( 404, $res->status() );
		$this->assertSame( '2.0.0', $res->headers()['X-Experience-API-Version'] );
		$this->assertTrue( json_decode( $res->body(), true )['error'] );

		$res = ( new Xapi_Test_Client() )->request( 'DELETE', 'about' );
		$this->assertSame( 405, $res->status() );
		$this->assertSame( 'GET', $res->headers()['Allow'] );
		$this->assertSame( array(), Xapi_Router_Test_Handler::$seen );
	}

	public function test_missing_handler_class_is_501() {
		remove_all_filters( 'learning_ledger/xapi_handlers' );
		add_filter(
			'learning_ledger/xapi_handlers',
			static function ( array $handlers ) {
				$handlers['about']['GET'] = 'LearningLedger\Lrs\Resources\No_Such_Handler';
				return $handlers;
			}
		);
		$res = ( new Xapi_Test_Client() )->request( 'GET', 'about' );
		$this->assertSame( 501, $res->status() );
		$this->assertSame( '2.0.0', $res->headers()['X-Experience-API-Version'] );
	}

	public function test_handlers_constant_maps_every_resource() {
		$h = Xapi_Router::HANDLERS;
		$this->assertSame( 'LearningLedger\Lrs\Statements\Statements_Read_Handler', $h['statements']['GET'] );
		$this->assertSame( 'LearningLedger\Lrs\Statements\Statements_Write_Handler', $h['statements']['PUT'] );
		$this->assertSame( 'LearningLedger\Lrs\Statements\Statements_Write_Handler', $h['statements']['POST'] );
		foreach ( array(
			'activities/state'   => 'LearningLedger\Lrs\Documents\State_Handler',
			'activities/profile' => 'LearningLedger\Lrs\Documents\Activity_Profile_Handler',
			'agents/profile'     => 'LearningLedger\Lrs\Documents\Agent_Profile_Handler',
		) as $path => $class ) {
			$this->assertSame( array_fill_keys( array( 'GET', 'PUT', 'POST', 'DELETE' ), $class ), $h[ $path ] );
		}
		$this->assertSame( array( 'GET' => 'LearningLedger\Lrs\Resources\Activities_Handler' ), $h['activities'] );
		$this->assertSame( array( 'GET' => 'LearningLedger\Lrs\Resources\Agents_Handler' ), $h['agents'] );
		$this->assertSame( array( 'GET' => 'LearningLedger\Lrs\Resources\About_Handler' ), $h['about'] );
	}

	public function test_missing_version_header_400_except_about() {
		foreach ( array( null, '0.9.5', '3.0.0' ) as $version ) {
			$res = ( new Xapi_Test_Client() )->request( 'GET', 'statements', array(), null, array( 'X-Experience-API-Version' => $version ) );
			$this->assertSame( 400, $res->status(), 'version ' . wp_json_encode( $version ) );
			$this->assertSame( '2.0.0', $res->headers()['X-Experience-API-Version'] );
			$this->assertTrue( json_decode( $res->body(), true )['error'] );
		}
		$this->assertSame( array(), Xapi_Router_Test_Handler::$seen );

		$res = ( new Xapi_Test_Client() )->request( 'GET', 'about', array(), null, array( 'X-Experience-API-Version' => null ) );
		$this->assertSame( 200, $res->status() );
		$this->assertSame( '2.0.0', $res->headers()['X-Experience-API-Version'] );
		$this->assertCount( 1, Xapi_Router_Test_Handler::$seen );
	}

	public function test_raw_body_not_reencoded() {
		Xapi_Router_Test_Handler::$reply = new Xapi_Response( 200, '{"a":"http://x/y"}', 'application/json', array( 'ETag' => '"e1"' ) );

		$request = new WP_REST_Request( 'GET', '/learning-ledger/xapi/about' );
		$request->set_header( 'X-Experience-API-Version', '2.0.0' );
		$server   = rest_get_server();
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $server->dispatch( $request ) ), $server, $request );

		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, $response, $request, $server );
		$out    = ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( '{"a":"http://x/y"}', $out );
		$this->assertStringNotContainsString( '_links', $out );
		$this->assertSame( 200, $response->get_status() );

		// A second router registration (plugin boot plus a test) never serves the body twice.
		( new Xapi_Router() )->register();
		ob_start();
		apply_filters( 'rest_pre_serve_request', false, $response, $request, $server );
		$this->assertSame( '{"a":"http://x/y"}', ob_get_clean() );

		// Other REST responses are left to WordPress.
		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, new WP_REST_Response( array( 'a' => 1 ) ), new WP_REST_Request( 'GET', '/wp/v2/posts' ), $server );
		$this->assertSame( '', ob_get_clean() );
		$this->assertFalse( $served );
	}

	public function test_body_over_2mb_413() {
		$res = ( new Xapi_Test_Client() )->request( 'POST', 'statements', array(), str_repeat( 'a', 2 * 1024 * 1024 + 1 ), array( 'Content-Type' => 'application/json' ) );
		$this->assertSame( 413, $res->status() );
		$this->assertSame( '2.0.0', $res->headers()['X-Experience-API-Version'] );
		$this->assertTrue( json_decode( $res->body(), true )['error'] );
		$this->assertSame( array(), Xapi_Router_Test_Handler::$seen );

		// Exactly 2 MB passes the size check and reaches authentication; core's own invalid-JSON error never answers.
		$res = ( new Xapi_Test_Client() )->request( 'POST', 'statements', array(), str_repeat( 'a', 2 * 1024 * 1024 ), array( 'Content-Type' => 'application/json' ) );
		$this->assertSame( 401, $res->status() );
	}

	public function test_alternate_syntax_get_via_post_under_103() {
		$body = http_build_query(
			array(
				'X-Experience-API-Version' => '1.0.3',
				'Authorization'            => 'Basic Zm9vOmJhcg==',
				'activityId'               => 'http://x/a',
			)
		);
		$res  = ( new Xapi_Test_Client() )->request(
			'POST',
			'about',
			array( 'method' => 'GET' ),
			$body,
			array(
				'X-Experience-API-Version' => null,
				'Content-Type'             => 'application/x-www-form-urlencoded',
			)
		);

		$this->assertSame( 200, $res->status() );
		$this->assertCount( 1, Xapi_Router_Test_Handler::$seen );
		$seen = Xapi_Router_Test_Handler::$seen[0];
		$this->assertSame( 'GET', $seen->method() );
		$this->assertSame( array( 'activityId' => 'http://x/a' ), $seen->params() );
		$this->assertSame( 'Basic Zm9vOmJhcg==', $seen->header( 'Authorization' ) );
		$this->assertSame( '1.0.3', $seen->version() );
		$this->assertSame( '', $seen->body() );
	}

	public function test_alternate_syntax_rejected_under_20() {
		$res = ( new Xapi_Test_Client() )->request(
			'POST',
			'about',
			array( 'method' => 'GET' ),
			'activityId=x',
			array( 'Content-Type' => 'application/x-www-form-urlencoded' )
		);
		$this->assertSame( 400, $res->status() );
		$this->assertSame( '2.0.0', $res->headers()['X-Experience-API-Version'] );
		$this->assertSame( array(), Xapi_Router_Test_Handler::$seen );
	}

	public function test_preflight_through_router_has_no_acao_for_unknown_origin() {
		$res = ( new Xapi_Test_Client() )->request( 'OPTIONS', 'statements', array(), null, array( 'Origin' => 'https://evil.example' ) );
		$this->assertSame( 204, $res->status() );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', $res->headers() );
		$this->assertSame( '2.0.0', $res->headers()['X-Experience-API-Version'] );
		$this->assertSame( array(), Xapi_Router_Test_Handler::$seen );
	}

	public function test_application_password_filter_false_for_xapi_paths() {
		$xapi  = array(
			array( 'REQUEST_URI' => '/wp-json/learning-ledger/xapi/statements' ),
			array( 'REQUEST_URI' => '/wp-json/learning-ledger/xapi/activities/state?activityId=x' ),
			array( 'REQUEST_URI' => '/xapi/about' ),
			array( 'REQUEST_URI' => '/xapi' ),
			array(
				'REQUEST_URI'  => '/?rest_route=/learning-ledger/xapi/about',
				'QUERY_STRING' => 'rest_route=/learning-ledger/xapi/about',
			),
			array(
				'REQUEST_URI'  => '/index.php?rest_route=%2Flearning-ledger%2Fxapi%2Fstatements',
				'QUERY_STRING' => 'rest_route=%2Flearning-ledger%2Fxapi%2Fstatements',
			),
		);
		$other = array(
			array( 'REQUEST_URI' => '/wp-json/wp/v2/posts' ),
			array( 'REQUEST_URI' => '/wp-json/learning-ledger/v1/status' ),
			array( 'REQUEST_URI' => '/xapiary/' ),
			array( 'REQUEST_URI' => '/blog/xapi/about' ),
			array(
				'REQUEST_URI'  => '/?rest_route=/wp/v2/users',
				'QUERY_STRING' => 'rest_route=/wp/v2/users',
			),
			array(),
		);
		foreach ( $xapi as $server ) {
			$this->assertTrue( Xapi_Router::is_xapi_request( $server ), $server['REQUEST_URI'] );
			$_SERVER['REQUEST_URI']  = $server['REQUEST_URI'];
			$_SERVER['QUERY_STRING'] = $server['QUERY_STRING'] ?? '';
			$this->assertFalse( apply_filters( 'application_password_is_api_request', true ), $server['REQUEST_URI'] );
		}
		foreach ( $other as $server ) {
			$this->assertFalse( Xapi_Router::is_xapi_request( $server ), $server['REQUEST_URI'] ?? '(empty)' );
			$_SERVER['REQUEST_URI']  = $server['REQUEST_URI'] ?? '';
			$_SERVER['QUERY_STRING'] = $server['QUERY_STRING'] ?? '';
			$this->assertTrue( apply_filters( 'application_password_is_api_request', true ), $server['REQUEST_URI'] ?? '(empty)' );
		}
	}

	public function test_route_and_rewrite_are_registered() {
		$routes = rest_get_server()->get_routes( 'learning-ledger/xapi' );
		$this->assertNotEmpty( $routes );
		$this->assertArrayHasKey( '/learning-ledger/xapi/(?P<resource>.*)', $routes );
		$route = $routes['/learning-ledger/xapi/(?P<resource>.*)'][0];
		$this->assertSame( '__return_true', $route['permission_callback'] );
		foreach ( array( 'GET', 'PUT', 'POST', 'DELETE' ) as $method ) {
			$this->assertTrue( $route['methods'][ $method ] );
		}

		( new Xapi_Router() )->add_rewrite();
		$this->assertSame( 'index.php?rest_route=/learning-ledger/xapi/$matches[1]', $GLOBALS['wp_rewrite']->extra_rules_top['^xapi/?(.*)$'] );
	}
}
