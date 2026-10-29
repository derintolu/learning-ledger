<?php
/**
 * REST twins of the front-end pages.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Claim\Claim_Module;
use LearningLedger\Badges\Delivery\Delivery_Module;
use LearningLedger\Badges\Issuer\Issuer_Module;
use LearningLedger\Db\Installer;
use LearningLedger\Kernel\Page_Route;
use LearningLedger\Lrs\Links\View_Links_Module;
use LearningLedger\Util\Uuid;

class Page_Route_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		foreach ( array( new Issuer_Module(), new Claim_Module(), new Delivery_Module(), new View_Links_Module() ) as $module ) {
			$module->register();
		}
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	public function test_every_page_has_a_public_twin() {
		$uuid   = '(?P<id>[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})';
		$routes = rest_get_server()->get_routes();
		$twins  = array(
			'/learning-ledger/v1/page/did'            => array( 'GET' ),
			'/learning-ledger/v1/page/claim/(?P<token>[A-Za-z0-9_-]{43})' => array( 'GET', 'POST' ),
			'/learning-ledger/v1/page/badge/' . $uuid => array( 'GET' ),
			'/learning-ledger/v1/page/badge/' . $uuid . '\.(?P<format>json|png)' => array( 'GET' ),
			'/learning-ledger/v1/page/record/(?P<token>[A-Za-z0-9_-]{43})' => array( 'GET' ),
		);
		foreach ( $twins as $route => $methods ) {
			$this->assertArrayHasKey( $route, $routes );
			$this->assertSame( array_fill_keys( $methods, true ), array_intersect_key( $routes[ $route ][0]['methods'], array_fill_keys( $methods, true ) ), $route );
			$this->assertSame( '__return_true', $routes[ $route ][0]['permission_callback'], $route );
		}
	}

	public function test_an_unknown_badge_is_an_html_404_echoed_raw() {
		$request  = new WP_REST_Request( 'GET', '/learning-ledger/v1/page/badge/' . Uuid::v4() . '.json' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'text/html; charset=utf-8', $response->get_headers()['Content-Type'] );
		$this->assertSame( wp_get_nocache_headers()['Cache-Control'], $response->get_headers()['Cache-Control'] );
		$this->assertSame( '/learning-ledger/v1/page/badge/(?P<id>[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})\.(?P<format>json|png)', $response->get_matched_route() );

		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, $response, $request, rest_get_server() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$this->assertTrue( $served );
		$this->assertSame( $response->get_data(), ob_get_clean() );
		$this->assertStringContainsString( '<title>Not found</title>', $response->get_data() );
	}

	public function test_other_responses_are_left_to_the_rest_server() {
		$response = new WP_REST_Response( 'a string', 200 );
		$this->assertFalse( Page_Route::serve_raw( false, $response, new WP_REST_Request( 'GET', '/learning-ledger/v1/issuer' ) ) );
		$this->assertTrue( Page_Route::serve_raw( true, $response, new WP_REST_Request( 'GET', '/learning-ledger/v1/page/did' ) ) );
		$this->assertFalse( Page_Route::serve_raw( false, new WP_REST_Response( array( 'a' => 1 ) ), new WP_REST_Request( 'GET', '/learning-ledger/v1/page/did' ) ) );
	}
}
