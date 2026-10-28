<?php
/**
 * View link route and record page tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Crypto\Tokens;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Export\Ndjson_Exporter;
use LearningLedger\Lrs\Links\Link_Repository;
use LearningLedger\Lrs\Links\Record_Page;
use LearningLedger\Lrs\Links\View_Links_Module;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

class View_Links_Test extends WP_UnitTestCase {

	const HOME  = 'https://site.example';
	const ROUTE = '/learning-ledger/v1/view-links';

	private \DateTimeImmutable $t0;

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'activities', 'agents', 'links', 'ratelimit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$this->t0 = new \DateTimeImmutable( gmdate( 'Y-m-d\TH:i:s' ) . '.000000Z' );
		Clock::freeze( $this->t0 );

		( new View_Links_Module() )->register();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Clock::freeze( null );
		unset( $_GET['download'], $_GET['more'] );
		parent::tear_down();
	}

	private static function agent( string $name, string $home = self::HOME ): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => $home,
				'name'     => $name,
			),
		);
	}

	private static function statement( array $actor, string $name = 'Lesson one' ): array {
		return array(
			'id'     => Uuid::v4(),
			'actor'  => $actor,
			'verb'   => array(
				'id'      => 'http://adlnet.gov/expapi/verbs/completed',
				'display' => array( 'en-US' => 'completed' ),
			),
			'object' => array(
				'objectType' => 'Activity',
				'id'         => 'https://site.example/act/1',
				'definition' => array( 'name' => array( 'en-US' => $name ) ),
			),
		);
	}

	private static function store( array $statements ): array {
		$c = Client_Factory::create( array( Scopes::STATEMENTS_WRITE ) );
		$p = Principal::for_client( ( new Client_Repository() )->find( $c['id'] ) );
		return ( new Statement_Store() )->store( $statements, $p, '2.0.0' );
	}

	private static function basic( array $client ): string {
		return 'Basic ' . base64_encode( $client['id'] . ':' . $client['secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function minter( array $scopes = array( Scopes::RECORDS_VIEW_LINKS ), array $bindings = array( 'actor_home_page' => self::HOME ) ): array {
		return Client_Factory::create( $scopes, array( 'bindings' => $bindings ) );
	}

	private function post( array $client, mixed $body ): WP_REST_Response {
		$r = new WP_REST_Request( 'POST', self::ROUTE );
		if ( array() !== $client ) {
			$r->set_header( 'Authorization', self::basic( $client ) );
		}
		$r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $r );
	}

	/**
	 * Mint a view link for an actor through the route; returns the token.
	 */
	private function mint( array $actor ): string {
		$res = $this->post( self::minter(), array( 'actor' => $actor ) );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 1, preg_match( '#/records/([A-Za-z0-9_-]{43})$#', $res->get_data()['url'], $m ) );
		return $m[1];
	}

	private function page(): Record_Page {
		return new class() extends Record_Page {
			public int $status = 0;
			public array $sent = array();

			protected function send_headers( int $status, array $headers ): void {
				$this->status = $status;
				$this->sent   = $headers;
			}

			protected function finish(): void {
				throw new RuntimeException( 'finished' );
			}
		};
	}

	/**
	 * Serve a record page request and return its body; the request must finish.
	 */
	private function serve( Record_Page $page, string $token, string $download = '', string $more = '' ): string {
		ob_start();
		try {
			$page->serve( $token, $download, $more );
			$this->fail( 'Expected the response to finish' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'finished', $e->getMessage() );
		} finally {
			$body = (string) ob_get_clean();
		}
		return $body;
	}

	private function later( int $seconds ): void {
		Clock::freeze( $this->t0->modify( '+' . $seconds . ' seconds' ) );
	}

	private function assert_private_headers( Record_Page $page ): void {
		$this->assertSame( 'no-store', $page->sent['Cache-Control'] );
		$this->assertSame( 'noindex', $page->sent['X-Robots-Tag'] );
		$this->assertSame( 'no-referrer', $page->sent['Referrer-Policy'] );
	}

	private static function exported( array $actor ): string {
		$out = '';
		( new Ndjson_Exporter() )->stream(
			array( 'agent' => $actor ),
			static function ( string $line ) use ( &$out ): void {
				$out .= $line;
			}
		);
		return $out;
	}

	// Minting.

	public function test_mint_without_credentials_is_401_with_www_authenticate() {
		$res = $this->post( array(), array( 'actor' => self::agent( 'alice' ) ) );

		$this->assertSame( 401, $res->get_status() );
		$this->assertArrayHasKey( 'WWW-Authenticate', $res->get_headers() );
		$this->assertSame( array(), $GLOBALS['wpdb']->get_col( 'SELECT kind FROM ' . Tables::name( 'links' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_mint_requires_the_records_view_links_scope() {
		$res = $this->post( self::minter( array( Scopes::ALL, Scopes::ACTORS_ERASE ) ), array( 'actor' => self::agent( 'alice' ) ) );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( array(), $GLOBALS['wpdb']->get_col( 'SELECT kind FROM ' . Tables::name( 'links' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_mint_for_an_actor_outside_the_binding_is_403() {
		$res = $this->post( self::minter(), array( 'actor' => self::agent( 'alice', 'https://other.example' ) ) );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'actor', $res->get_data()['data']['field'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->get_col( 'SELECT kind FROM ' . Tables::name( 'links' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_mint_without_a_valid_actor_is_400() {
		foreach ( array( array(), array( 'actor' => 'alice' ), array( 'actor' => array( 'name' => 'no identifier' ) ) ) as $body ) {
			$res = $this->post( self::minter(), $body );
			$this->assertSame( 400, $res->get_status(), (string) wp_json_encode( $body ) );
		}
	}

	public function test_mint_returns_the_url_and_expiry_and_stores_only_the_hash() {
		$client = self::minter();
		$res    = $this->post( $client, array( 'actor' => self::agent( 'alice' ) ) );

		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 'no-store', $res->get_headers()['Cache-Control'] );
		$data = $res->get_data();
		$this->assertSame( array( 'url', 'expires_at' ), array_keys( $data ) );
		$this->assertSame( Timestamp::to_xapi( $this->t0->modify( '+900 seconds' ) ), $data['expires_at'] );
		$this->assertSame( 1, preg_match( '#\A' . preg_quote( home_url( '/records/' ), '#' ) . '([A-Za-z0-9_-]{43})\z#', $data['url'], $m ) );
		$token = $m[1];

		$rows = (array) $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Tables::name( 'links' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertCount( 1, $rows );
		$this->assertSame( Tokens::hash( $token ), $rows[0]['token_hash'] );
		$this->assertSame( 'view', $rows[0]['kind'] );
		$this->assertSame( Agent_Identity::from_array( self::agent( 'alice' ) )->hash(), $rows[0]['actor_hash'] );
		$this->assertSame( 'record', $rows[0]['subject'] );
		$this->assertSame( 'client:' . $client['id'], $rows[0]['created_by'] );
		foreach ( $rows[0] as $value ) {
			$this->assertStringNotContainsString( $token, (string) $value );
		}
	}

	// The record page.

	public function test_page_shows_only_the_links_actors_statements_with_private_headers() {
		$alice = self::statement( self::agent( 'alice' ) );
		$bob   = self::statement( self::agent( 'bob' ) );
		$about = self::statement( self::agent( 'bob' ) );

		$about['object'] = self::agent( 'alice' ); // Another actor's statement about alice is not alice's record.
		self::store( array( $alice, $bob, $about ) );
		$token = $this->mint( self::agent( 'alice' ) );
		$this->later( 5 );

		$page = $this->page();
		$body = $this->serve( $page, $token );

		$this->assertSame( 200, $page->status );
		$this->assert_private_headers( $page );
		$this->assertSame( 'text/html; charset=utf-8', $page->sent['Content-Type'] );
		$this->assertStringContainsString( $alice['id'], $body );
		$this->assertStringNotContainsString( $bob['id'], $body );
		$this->assertStringNotContainsString( $about['id'], $body );
		$this->assertStringNotContainsString( '&quot;bob&quot;', $body );
		$this->assertStringContainsString( esc_url( home_url( '/records/' . $token ) . '?download=ndjson' ), $body );
		$this->assertStringContainsString( esc_url( home_url( '/records/' . $token ) . '?download=json' ), $body );
	}

	public function test_page_escapes_markup_inside_a_statement() {
		$evil = self::statement( self::agent( 'alice' ), '</pre><script>alert("x")</script>' );
		self::store( array( $evil ) );
		$token = $this->mint( self::agent( 'alice' ) );
		$this->later( 5 );

		$body = $this->serve( $this->page(), $token );

		$this->assertStringContainsString( $evil['id'], $body );
		$this->assertStringNotContainsString( '<script>', $body );
		$this->assertStringNotContainsString( '</pre><script', $body );
		$this->assertStringContainsString( '&lt;script&gt;alert(', $body );
	}

	public function test_page_shows_literal_entities_in_stored_json_exactly() {
		self::store( array( self::statement( self::agent( 'alice' ), 'Tom &amp; Jerry &lt;b&gt;' ) ) );
		$token = $this->mint( self::agent( 'alice' ) );
		$this->later( 5 );

		$body = $this->serve( $this->page(), $token );

		$this->assertSame( 1, preg_match( '#<pre>(.*?)</pre>#s', $body, $m ) );
		$this->assertStringContainsString( 'Tom &amp;amp; Jerry &amp;lt;b&amp;gt;', $m[1], 'a stored "&amp;" must display as "&amp;", not "&"' );
		$this->assertStringNotContainsString( 'Tom &amp; Jerry', $m[1] );
	}

	public function test_page_lists_newest_first_100_per_page() {
		$statements = array();
		for ( $i = 0; $i < 101; $i++ ) {
			$statements[] = self::statement( self::agent( 'alice' ), 'Lesson ' . $i );
		}
		self::store( $statements );
		$token = $this->mint( self::agent( 'alice' ) );
		$this->later( 5 );

		$first = $this->serve( $this->page(), $token );

		$this->assertSame( 100, substr_count( $first, '<pre' ) );
		$this->assertStringNotContainsString( $statements[0]['id'], $first );
		$this->assertLessThan( strpos( $first, $statements[99]['id'] ), strpos( $first, $statements[100]['id'] ) );
		$this->assertSame( 1, preg_match( '/href="([^"]*[?&](?:amp;|#038;)?more=[^"]*)"/', $first, $m ) );
		parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $query );
		$this->assertIsString( $query['more'] );

		$second = $this->serve( $this->page(), $token, '', $query['more'] );

		$this->assertSame( 1, substr_count( $second, '<pre' ) );
		$this->assertStringContainsString( $statements[0]['id'], $second );
		$this->assertStringNotContainsString( 'more=', $second );
	}

	public function test_ndjson_download_matches_the_exporter() {
		$alice = self::statement( self::agent( 'alice' ) );
		$bob   = self::statement( self::agent( 'bob' ) );
		self::store( array( $alice, self::statement( self::agent( 'alice' ) ), $bob ) );
		$token = $this->mint( self::agent( 'alice' ) );
		$this->later( 5 );

		$page = $this->page();
		$body = $this->serve( $page, $token, 'ndjson' );

		$this->assertSame( 200, $page->status );
		$this->assert_private_headers( $page );
		$this->assertSame( 'application/x-ndjson; charset=utf-8', $page->sent['Content-Type'] );
		$this->assertStringStartsWith( 'attachment; filename="', $page->sent['Content-Disposition'] );
		$this->assertSame( 'nosniff', $page->sent['X-Content-Type-Options'] );
		$this->assertSame( self::exported( self::agent( 'alice' ) ), $body );
		$this->assertSame( 2, substr_count( $body, "\n" ) );
		$this->assertStringContainsString( $alice['id'], $body );
		$this->assertStringNotContainsString( $bob['id'], $body );
	}

	public function test_json_download_holds_the_exporters_records_as_an_array() {
		self::store( array( self::statement( self::agent( 'alice' ) ), self::statement( self::agent( 'alice' ) ), self::statement( self::agent( 'bob' ) ) ) );
		$token = $this->mint( self::agent( 'alice' ) );
		$this->later( 5 );

		$page = $this->page();
		$body = $this->serve( $page, $token, 'json' );

		$this->assertSame( 'application/json; charset=utf-8', $page->sent['Content-Type'] );
		$this->assertStringStartsWith( 'attachment; filename="', $page->sent['Content-Disposition'] );
		$this->assert_private_headers( $page );
		$lines    = array_filter( explode( "\n", self::exported( self::agent( 'alice' ) ) ) );
		$expected = array_map( static fn( string $line ) => json_decode( $line, true ), array_values( $lines ) );
		$this->assertCount( 2, $expected );
		$this->assertSame( $expected, json_decode( $body, true ) );
	}

	public function test_an_actor_without_statements_gets_an_empty_record_and_never_everyone_elses() {
		self::store( array( self::statement( self::agent( 'alice' ) ) ) );
		$token = $this->mint( self::agent( 'carol' ) );
		$this->later( 5 );

		$page = $this->page();
		$html = $this->serve( $page, $token );
		$this->assertSame( 200, $page->status );
		$this->assertSame( 0, substr_count( $html, '<pre' ) );
		$this->assertStringNotContainsString( '&quot;alice&quot;', $html );

		$this->assertSame( '', $this->serve( $this->page(), $token, 'ndjson' ) );
		$this->assertSame( array(), json_decode( $this->serve( $this->page(), $token, 'json' ), true ) );
	}

	public function test_link_is_gone_after_15_minutes() {
		$alice = self::statement( self::agent( 'alice' ) );
		self::store( array( $alice ) );
		$token = $this->mint( self::agent( 'alice' ) );

		$this->later( 899 );
		$this->assertStringContainsString( $alice['id'], $this->serve( $this->page(), $token ) );

		$this->later( 900 );
		foreach ( array( '', 'ndjson', 'json' ) as $download ) {
			$page = $this->page();
			$body = $this->serve( $page, $token, $download );
			$this->assertSame( 410, $page->status, $download );
			$this->assert_private_headers( $page );
			$this->assertSame( 'text/html; charset=utf-8', $page->sent['Content-Type'] );
			$this->assertStringNotContainsString( $alice['id'], $body );
		}
	}

	public function test_unknown_and_claim_tokens_are_gone() {
		$claim = ( new Link_Repository() )->mint( 'claim', Agent_Identity::from_array( self::agent( 'alice' ) )->hash(), 'badge-1', 900, 'client:test' );
		foreach ( array( Tokens::random( 32 ), $claim, 'not-a-token' ) as $token ) {
			$page = $this->page();
			$this->serve( $page, $token );
			$this->assertSame( 410, $page->status );
			$this->assert_private_headers( $page );
		}
	}

	public function test_records_path_is_routed_to_the_page() {
		self::store( array( self::statement( self::agent( 'alice' ) ) ) );
		$token = $this->mint( self::agent( 'alice' ) );
		$this->later( 5 );
		$this->set_permalink_structure( '/%postname%/' );
		( new Record_Page() )->add_rewrite();
		flush_rewrite_rules( false );

		$this->go_to( home_url( '/records/' . $token . '/' ) );
		$this->assertSame( $token, get_query_var( Record_Page::QUERY_VAR ) );
		$page = $this->page();
		ob_start();
		try {
			$page->maybe_serve();
			$this->fail( 'Expected the response to finish' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'finished', $e->getMessage() );
		} finally {
			ob_end_clean();
		}
		$this->assertSame( 200, $page->status );

		$this->go_to( home_url( '/records/' . substr( $token, 1 ) . '/' ) );
		$this->assertSame( '', get_query_var( Record_Page::QUERY_VAR ) );
		ob_start();
		$this->page()->maybe_serve();
		$this->assertSame( '', ob_get_clean() );
	}
}
