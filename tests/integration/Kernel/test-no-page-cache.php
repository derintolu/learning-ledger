<?php
/**
 * Private responses are never page-cached.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixtures.

use LearningLedger\Badges\Claim\Claim_Page;
use LearningLedger\Badges\Delivery\Badge_Page;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Badges\Status\Status_List_Repository;
use LearningLedger\Connector\CourseServer\Trusted_Keys;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Signed_Request;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Kernel\No_Page_Cache;
use LearningLedger\Kernel\Plugin;
use LearningLedger\Lrs\Links\Record_Page;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;

require_once dirname( __DIR__, 2 ) . '/Support/batcache-cancel.php';

class No_Page_Cache_Test extends WP_UnitTestCase {

	/** @var array{public:string,secret:string} */
	private array $keys;

	private ?string $request_uri = null;

	public function set_up(): void {
		parent::set_up();
		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- restored verbatim in tear_down.
		Installer::install();
		global $wpdb, $wp_rest_server;
		foreach ( array( 'trusted_keys', 'nonces', 'ratelimit' ) as $t ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::name( $t ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		Clock::freeze( new DateTimeImmutable( '@' . time() ) );
		$this->keys = Ed25519::keypair();
		( new Trusted_Keys() )->add( 'course-server', base64_encode( $this->keys['public'] ), Trusted_Keys::ALL_CAPABILITIES, Trusted_Keys::DEFAULT_LIMITS );
		// Every module, whatever the site's roles: the REST hooks are what is tested.
		foreach ( array_keys( Plugin::MODULES ) as $class ) {
			if ( class_exists( $class ) && ! str_contains( $class, '\\Testing\\' ) ) {
				( new $class() )->register();
			}
		}
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		Clock::freeze( null );
		No_Page_Cache::reset();
		parent::tear_down();
	}

	/**
	 * Dispatch one request as WP_REST_Server::serve_request() does, up to the response headers.
	 *
	 * @return array{status:int,headers:array,cancels:int,applied:bool}
	 */
	private function request( string $method, string $route, bool $signed = false ): array {
		No_Page_Cache::reset();
		$GLOBALS['lledger_batcache_cancels'] = 0;
		$req                                 = new WP_REST_Request( $method, $route );
		if ( $signed ) {
			$uri = '/wp-json' . $route;
			foreach ( Signed_Request::sign( 'course-server', $this->keys['secret'], $method, $uri, '', Clock::now()->getTimestamp() ) as $name => $value ) {
				$req->set_header( $name, $value );
			}
			$_SERVER['REQUEST_URI'] = $uri;
		}
		$server   = rest_get_server();
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $server->dispatch( $req ) ), $server, $req ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return array(
			'status'  => $response->get_status(),
			'headers' => $response->get_headers(),
			'cancels' => $GLOBALS['lledger_batcache_cancels'],
			'applied' => No_Page_Cache::applied(),
		);
	}

	private function assert_private( array $r, string $label ): void {
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, $label );
		$this->assertTrue( $r['applied'], $label );
		$this->assertSame( 1, $r['cancels'], $label . ': batcache_cancel() once' );
		foreach ( No_Page_Cache::HEADERS as $name => $value ) {
			$this->assertSame( $value, $r['headers'][ $name ] ?? null, $label . ': ' . $name );
		}
	}

	public function test_signed_and_authenticated_routes_are_private_whatever_the_answer() {
		$uuid  = Uuid::v4();
		$token = str_repeat( 'A', 43 );
		$cases = array(
			array( 'GET', '/learning-ledger/v1/course-server/status', true, 200 ),
			array( 'GET', '/learning-ledger/v1/course-server/status', false, 401 ),
			array( 'GET', '/learning-ledger/v1/course-server/packages/course', false, 401 ),
			array( 'POST', '/learning-ledger/v1/course-server/packages', false, 401 ),
			array( 'POST', '/learning-ledger/v1/course-server/learners', false, 401 ),
			array( 'GET', '/learning-ledger/v1/course-server/learners/1/progress', false, 401 ),
			array( 'DELETE', '/learning-ledger/v1/course-server/learners/1', false, 401 ),
			array( 'POST', '/learning-ledger/v1/inbox', false, 401 ),
			array( 'POST', '/learning-ledger/v1/view-links', false, 401 ),
			array( 'POST', '/learning-ledger/v1/badges/' . $uuid . '/claim-links', false, 401 ),
			array( 'POST', '/learning-ledger/v1/tokens', false, 401 ),
			array( 'POST', '/learning-ledger/v1/actors/erase', false, 401 ),
			array( 'GET', '/learning-ledger/v1/subscriptions', false, 401 ),
			array( 'GET', '/learning-ledger/v1/clients', false, 401 ),
			array( 'PUT', '/learning-ledger/v1/courses', false, 401 ),
			array( 'GET', '/learning-ledger/v1/export', false, 401 ),
			array( 'GET', '/learning-ledger/v1/programs/1/package', false, 401 ),
			array( 'POST', '/learning-ledger/v1/programs/import', false, 401 ),
			// Without the xAPI version header the router answers 400 before authenticating.
			array( 'GET', '/learning-ledger/xapi/statements', false, 400 ),
			array( 'GET', '/learning-ledger/xapi/about', false, 200 ),
			array( 'GET', '/learning-ledger/v1/no-such-route', false, 404 ),
			array( 'GET', '/learning-ledger/xapi/no-such-route', false, 400 ),
			array( 'GET', '/learning-ledger', false, 404 ),
			// The namespace indexes.
			array( 'GET', '/learning-ledger/v1', false, 200 ),
			array( 'GET', '/learning-ledger/xapi', false, 200 ),
			array( 'GET', '/learning-ledger/v1/cmi5/fetch/' . $token, false, 0 ),
			array( 'GET', '/learning-ledger/v1/cmi5/return/' . $uuid, false, 0 ),
		);
		foreach ( $cases as [ $method, $route, $signed, $status ] ) {
			$r     = $this->request( $method, $route, $signed );
			$label = $method . ' ' . $route . ' -> ' . $r['status'];
			if ( $status > 0 ) {
				$this->assertSame( $status, $r['status'], $label );
			}
			$this->assert_private( $r, $label );
		}
	}

	public function test_public_routes_are_never_page_cached_but_keep_a_public_cache_control() {
		Key_Store::generate();
		$list = ( new Status_List_Repository() )->allocate()['list_id'];
		foreach ( array(
			'/learning-ledger/v1/status'                => null,
			'/learning-ledger/v1/issuer'                => 'public, max-age=300',
			// An unknown achievement: a 404, which no page cache may keep either.
			'/learning-ledger/v1/achievements/' . str_repeat( 'a', 40 ) => null,
			'/learning-ledger/v1/status-lists/' . $list => No_Page_Cache::SHORT_CACHE_CONTROL,
		) as $route => $cache_control ) {
			$r = $this->request( 'GET', $route );
			$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, $route );
			$this->assertSame( 1, $r['cancels'], $route . ': batcache_cancel() once, whatever the answer (' . $r['status'] . ')' );
			$this->assertFalse( $r['applied'], $route . ': no no-store headers' );
			$this->assertSame( $cache_control, $r['headers']['Cache-Control'] ?? null, $route );
		}
		$this->assertTrue( No_Page_Cache::covers( '/learning-ledger/v1/page/did' ) );
		$this->assertTrue( No_Page_Cache::is_public_route( '/learning-ledger/v1/page/did' ) );
		$this->assertTrue( No_Page_Cache::is_private_route( '/learning-ledger/v1/page/record/' . str_repeat( 'A', 43 ) ) );
		$this->assertTrue( No_Page_Cache::is_private_route( '/learning-ledger/v1/page/claim/' . str_repeat( 'A', 43 ) ) );
		$this->assertTrue( No_Page_Cache::is_private_route( '/learning-ledger/v1/page/badge/' . Uuid::v4() . '.json' ) );
		$this->assertFalse( No_Page_Cache::covers( '/wp/v2/posts' ), 'other plugins and core are left alone' );
		$this->assertFalse( No_Page_Cache::covers( '/learning-ledgerx/v1' ) );
		$this->assertFalse( No_Page_Cache::covers( '/' ) );
	}

	/**
	 * The record, claim and badge pages (pretty URL and REST twin both end in serve()).
	 */
	public function test_record_claim_and_badge_pages_are_private_for_a_found_and_an_unknown_item() {
		$pages = array(
			'record' => static function () {
				$page = new class() extends Record_Page {
					protected function send_headers( int $status, array $headers ): void {}
					protected function finish(): void {}
				};
				ob_start();
				$page->serve( str_repeat( 'A', 43 ), '', '' );
				ob_end_clean();
			},
			'claim'  => static function () {
				$page = new class() extends Claim_Page {
					protected function send_headers( int $status, array $headers ): void {}
					protected function finish(): void {}
				};
				ob_start();
				$page->serve( str_repeat( 'A', 43 ), 'POST', array() );
				ob_end_clean();
			},
			'badge'  => static function () {
				$page = new class() extends Badge_Page {
					protected function send_headers( int $status, array $headers ): void {}
					protected function not_found(): void {}
					protected function finish(): void {}
				};
				$page->serve( Uuid::v4(), 'json', '', '' );
			},
		);
		foreach ( $pages as $name => $serve ) {
			No_Page_Cache::reset();
			$GLOBALS['lledger_batcache_cancels'] = 0;
			$serve();
			$this->assertTrue( No_Page_Cache::applied(), $name );
			$this->assertSame( 1, $GLOBALS['lledger_batcache_cancels'], $name );
		}
	}

	public function test_apply_is_idempotent() {
		No_Page_Cache::reset();
		$GLOBALS['lledger_batcache_cancels'] = 0;
		No_Page_Cache::apply();
		No_Page_Cache::apply();
		$this->assertSame( 1, $GLOBALS['lledger_batcache_cancels'] );
		$this->assertTrue( DONOTCACHEPAGE );
	}

	public function test_a_request_is_covered_as_it_arrives_before_the_rest_server_runs() {
		No_Page_Cache::reset();
		$GLOBALS['lledger_batcache_cancels'] = 0;
		$wp                                  = new WP();
		$wp->query_vars                      = array( 'rest_route' => '/learning-ledger/v1/course-server/packages/course' );
		$this->assertSame( 0, has_action( 'parse_request', array( No_Page_Cache::class, 'on_request' ) ), 'before the REST server (rest_api_loaded, priority 10)' );
		No_Page_Cache::on_request( $wp );
		$this->assertTrue( No_Page_Cache::applied() );
		$this->assertSame( 1, $GLOBALS['lledger_batcache_cancels'] );

		No_Page_Cache::reset();
		$wp->query_vars = array( 'rest_route' => '/learning-ledger/v1/page/did' );
		No_Page_Cache::on_request( $wp );
		$this->assertTrue( No_Page_Cache::cancelled() );
		$this->assertFalse( No_Page_Cache::applied() );

		foreach ( array( '/learning-ledger/v1/status', 'learning-ledger/v1', '/learning-ledger/xapi', '/learning-ledger/v1/no-such-route' ) as $route ) {
			No_Page_Cache::reset();
			$wp->query_vars = array( 'rest_route' => $route );
			No_Page_Cache::on_request( $wp );
			$this->assertTrue( No_Page_Cache::cancelled(), $route );
		}

		No_Page_Cache::reset();
		$wp->query_vars = array( 'rest_route' => '/wp/v2/posts' );
		No_Page_Cache::on_request( $wp );
		$this->assertFalse( No_Page_Cache::cancelled(), 'not ours' );
		$wp->query_vars = array();
		No_Page_Cache::on_request( $wp );
		$this->assertFalse( No_Page_Cache::cancelled(), 'not a REST request' );
	}
}
