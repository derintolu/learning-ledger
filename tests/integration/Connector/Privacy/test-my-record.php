<?php
/**
 * "My learning record" block tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Blocks\Blocks_Module;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Privacy\Connector_Privacy_Module;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Uuid;

class My_Record_Test extends WP_UnitTestCase {

	const BLOCK = '<!-- wp:learning-ledger/my-record /-->';
	const ROUTE = '/learning-ledger/v1/my-record/view-link';

	/** @var array<int,array{url:string,args:array}> */
	private array $requests = array();

	/** @var array|WP_Error */
	private $reply;

	private int $user;

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		global $wpdb, $wp_rest_server;
		foreach ( array( 'identity_links', 'waivers', 'applied' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		delete_option( Connector_Settings::OPTION );
		delete_option( Connector_Secret::OPTION );
		Connector_Settings::update(
			array(
				'lrs_url'   => 'https://lrs.example.test/xapi/',
				'api_base'  => 'https://lrs.example.test/wp-json/learning-ledger/v1/',
				'client_id' => 'connector-site',
			)
		);
		Connector_Secret::generate();

		( new Blocks_Module() )->register_blocks();
		( new Connector_Privacy_Module() )->register();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->user );

		$this->requests = array();
		$this->reply    = array(
			'response' => array( 'code' => 201 ),
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'url'        => 'https://lrs.example.test/records/abc',
					'expires_at' => '2026-10-27T10:15:00Z',
				)
			),
		);
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return $this->reply;
	}

	private function lesson( string $title ): int {
		return self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
	}

	private function post_view_link( bool $nonce = true ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		return rest_do_request( $request );
	}

	public function test_block_is_registered() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'learning-ledger/my-record' ) );
	}

	public function test_renders_nothing_for_logged_out_visitors() {
		wp_set_current_user( 0 );

		$this->assertSame( '', trim( do_blocks( self::BLOCK ) ) );
	}

	public function test_lists_completed_and_tested_out_lessons_with_scores() {
		$done   = $this->lesson( 'Loops' );
		$tested = $this->lesson( 'Variables' );
		( new Apply_Ledger() )->record( $this->user, new Lms_Object( 'lesson', $done ), Result::LESSON_COMPLETED, Uuid::v4(), 0.85, true );
		( new Waiver_Ledger() )->record( $this->user, $tested, Uuid::v4(), Uuid::v4(), 'Tested Out', false );

		$html = do_blocks( self::BLOCK );

		$this->assertStringContainsString( 'Loops', $html );
		$this->assertStringContainsString( '85%', $html );
		$this->assertStringContainsString( 'Variables', $html );
		$this->assertStringContainsString( 'Tested out', $html );
	}

	public function test_a_flagged_completion_is_not_listed() {
		$lesson = $this->lesson( 'Voided lesson' );
		$id     = Uuid::v4();
		( new Apply_Ledger() )->record( $this->user, new Lms_Object( 'lesson', $lesson ), Result::LESSON_COMPLETED, $id );
		( new Apply_Ledger() )->flag_void( $id );

		$this->assertStringNotContainsString( 'Voided lesson', do_blocks( self::BLOCK ) );
	}

	public function test_shows_only_the_signed_in_users_own_record() {
		$other  = self::factory()->user->create();
		$theirs = $this->lesson( 'Their private lesson' );
		( new Apply_Ledger() )->record( $other, new Lms_Object( 'lesson', $theirs ), Result::LESSON_COMPLETED, Uuid::v4(), 0.5 );
		( new Waiver_Ledger() )->record( $other, $theirs, Uuid::v4(), Uuid::v4(), 'Tested Out', false );

		$this->assertStringNotContainsString( 'Their private lesson', do_blocks( self::BLOCK ) );
	}

	public function test_escapes_lesson_titles() {
		$lesson = $this->lesson( 'placeholder' );
		global $wpdb;
		// Straight to the table: a title written by other code can hold anything.
		$wpdb->update( $wpdb->posts, array( 'post_title' => '<script>alert(1)</script><img src=x onerror=y>' ), array( 'ID' => $lesson ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $lesson );
		( new Apply_Ledger() )->record( $this->user, new Lms_Object( 'lesson', $lesson ), Result::LESSON_COMPLETED, Uuid::v4() );

		$html = do_blocks( self::BLOCK );

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)', $html );
	}

	public function test_says_so_when_there_is_nothing_yet() {
		$this->assertStringContainsString( 'Nothing here yet', do_blocks( self::BLOCK ) );
		$this->assertStringNotContainsString( '<button', do_blocks( self::BLOCK ), 'no record at the LRS to view' );
	}

	public function test_button_carries_the_endpoint_and_a_rest_nonce_and_no_write_form() {
		( new Identity_Map() )->actor_for_user( $this->user );

		$html = do_blocks( self::BLOCK );

		$this->assertStringContainsString( '<button', $html );
		$this->assertStringContainsString( 'data-endpoint="' . esc_url( rest_url( 'learning-ledger/v1/my-record/view-link' ) ) . '"', $html );
		$this->assertStringContainsString( 'data-nonce="' . wp_create_nonce( 'wp_rest' ) . '"', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '<input', $html );
	}

	public function test_view_link_route_requires_a_login() {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->post_view_link()->get_status() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_view_link_route_requires_a_rest_nonce() {
		( new Identity_Map() )->actor_for_user( $this->user );

		$res = $this->post_view_link( false );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( array(), $this->requests, 'the LRS is not asked' );
	}

	public function test_view_link_route_asks_the_lrs_for_the_users_own_actor() {
		$actor = ( new Identity_Map() )->actor_for_user( $this->user );

		$res = $this->post_view_link();

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'url' => 'https://lrs.example.test/records/abc' ), $res->get_data() );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://lrs.example.test/wp-json/learning-ledger/v1/view-links', $this->requests[0]['url'] );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$this->assertSame( array( 'actor' => $actor ), json_decode( $this->requests[0]['args']['body'], true ) );
	}

	public function test_view_link_route_ignores_an_actor_sent_by_the_caller() {
		$actor   = ( new Identity_Map() )->actor_for_user( $this->user );
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'actor' => array( 'mbox' => 'mailto:victim@example.org' ) ) ) );

		rest_do_request( $request );

		$this->assertSame( array( 'actor' => $actor ), json_decode( $this->requests[0]['args']['body'], true ) );
	}

	public function test_view_link_route_without_a_record_is_404_and_asks_nothing() {
		$res = $this->post_view_link();

		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', (string) get_user_meta( $this->user, Identity_Map::META, true ), 'no actor is minted' );
	}

	public function test_view_link_route_reports_an_lrs_failure() {
		( new Identity_Map() )->actor_for_user( $this->user );
		$this->reply = array(
			'response' => array( 'code' => 503 ),
			'headers'  => array(),
			'body'     => 'down',
		);

		$res = $this->post_view_link();

		$this->assertSame( 502, $res->get_status() );
		$this->assertStringNotContainsString( 'down', wp_json_encode( $res->get_data() ) );
	}

	public function test_view_link_route_refuses_a_link_that_leaves_the_lrs() {
		( new Identity_Map() )->actor_for_user( $this->user );
		foreach ( array( 'https://evil.example.test/records/abc', 'javascript:alert(1)' ) as $url ) {
			$this->reply = array(
				'response' => array( 'code' => 201 ),
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'url' => $url ) ),
			);

			$res = $this->post_view_link();

			$this->assertSame( 502, $res->get_status(), $url );
			$this->assertStringNotContainsString( 'evil', wp_json_encode( $res->get_data() ) );
		}
	}

	public function test_view_link_route_accepts_only_post() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE, $routes );
		foreach ( $routes[ self::ROUTE ] as $handler ) {
			$this->assertSame( array( 'POST' ), array_keys( array_filter( $handler['methods'] ) ) );
		}
	}
}
