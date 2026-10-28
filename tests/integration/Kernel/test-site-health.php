<?php
/**
 * Site Health checks and runtime report tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Kernel\Capabilities;
use LearningLedger\Kernel\Roles;
use LearningLedger\Kernel\Site_Health;
use LearningLedger\Lrs\Auth\Authenticator;
use LearningLedger\Player\Store\Content_Host;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Queue\Shutdown_Runner;
use LearningLedger\Util\Clock;

class Site_Health_Test extends WP_UnitTestCase {

	/**
	 * Requests the faked HTTP layer saw.
	 *
	 * @var array
	 */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->query( 'DELETE FROM ' . Tables::name( 'jobs' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . Tables::name( 'statements' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( Roles::OPTION );
		delete_option( Job_Queue::LAST_RUN_OPTION );
		delete_option( Shutdown_Runner::LAST_RUN_OPTION );
		delete_option( Content_Host::OPTION );
		delete_option( Did_Document::MODE_OPTION );
		$this->requests = array();
	}

	public function tear_down(): void {
		Ed25519::force_backend( null );
		Clock::freeze( null );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'learning_ledger/db_version_string' );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	private function health(): Site_Health {
		return new Site_Health();
	}

	private function fake_http( $response ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $response ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $response;
			},
			10,
			3
		);
	}

	private static function http( int $code, string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function push_due_job(): void {
		Job_Queue::push( 'site_health_test', array() );
	}

	public function test_registers_direct_and_async_tests() {
		$this->health()->register();
		$tests = apply_filters(
			'site_status_tests',
			array(
				'direct' => array(),
				'async'  => array(),
			)
		); // phpcs:ignore Universal.Arrays.MixedKeyedUnkeyedArray

		foreach ( array( 'lledger_sodium', 'lledger_badge_key_storage', 'lledger_roles_together', 'lledger_cron', 'lledger_shutdown', 'lledger_content_host', 'lledger_db' ) as $name ) {
			$this->assertArrayHasKey( $name, $tests['direct'], $name );
			$this->assertTrue( is_callable( $tests['direct'][ $name ]['test'] ), $name );
			$this->assertNotSame( '', $tests['direct'][ $name ]['label'] );
		}
		foreach ( array( 'lledger_authorization_header', 'lledger_did_json' ) as $name ) {
			$this->assertArrayHasKey( $name, $tests['async'], $name );
			$this->assertTrue( $tests['async'][ $name ]['has_rest'], $name );
			$this->assertStringContainsString( 'learning-ledger/v1/site-health/', $tests['async'][ $name ]['test'], $name );
			$this->assertTrue( is_callable( $tests['async'][ $name ]['async_direct_test'] ), $name );
		}
	}

	public function test_sodium_is_recommended_on_compat() {
		Ed25519::force_backend( 'compat' );
		$result = $this->health()->test_sodium();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'lledger_sodium', $result['test'] );

		Ed25519::force_backend( 'native' );
		$this->assertSame( 'good', $this->health()->test_sodium()['status'] );
	}

	public function test_badge_key_storage_is_recommended_on_database_tier() {
		$result = $this->health()->test_badge_key_storage();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'lledger_badge_key_storage', $result['test'] );
		$this->assertStringContainsString( 'LLEDGER_BADGE_KEY', $result['actions'] );
		$this->assertStringContainsString( 'salts', $result['description'], 'Names the encryption key source.' );
		$this->assertStringNotContainsString( AUTH_KEY, $result['description'] );
	}

	public function test_roles_together_notice_needs_both_roles_and_over_a_million_statements() {
		$health = $this->health();
		$this->assertSame( 'good', $health->test_roles_together()['status'] );

		$this->insert_statement_with_seq( 1000001 );

		update_option( Roles::OPTION, array( 'lrs' ) );
		$this->assertSame( 'good', $health->test_roles_together()['status'], 'One role only.' );
		update_option( Roles::OPTION, array( 'connector' ) );
		$this->assertSame( 'good', $health->test_roles_together()['status'], 'One role only.' );

		update_option( Roles::OPTION, array( 'lrs', 'connector' ) );
		$result = $health->test_roles_together();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'own WordPress', $result['description'] );
	}

	public function test_roles_together_is_good_at_exactly_a_million() {
		update_option( Roles::OPTION, array( 'lrs', 'connector' ) );
		$this->insert_statement_with_seq( 1000000 );
		$this->assertSame( 'good', $this->health()->test_roles_together()['status'] );
	}

	private function insert_statement_with_seq( int $seq ): void {
		$GLOBALS['wpdb']->insert(
			Tables::name( 'statements' ),
			array(
				'seq'              => $seq,
				'statement_id'     => random_bytes( 16 ),
				'stored'           => '2026-01-01 00:00:00.000000',
				'timestamp'        => '2026-01-01 00:00:00.000000',
				'version'          => '2.0.0',
				'verb_hash'        => str_repeat( 'a', 20 ),
				'actor_hash'       => str_repeat( 'b', 20 ),
				'object_kind'      => 'Activity',
				'object_hash'      => str_repeat( 'c', 20 ),
				'authority_client' => 'test',
				'content_sha256'   => str_repeat( 'd', 32 ),
				'body'             => '{}',
			)
		);
		$this->assertSame( '', $GLOBALS['wpdb']->last_error );
	}

	public function test_cron_is_critical_when_work_is_due_and_the_queue_has_not_run_for_ten_minutes() {
		$now = new DateTimeImmutable( '2026-03-01T12:00:00Z' );
		Clock::freeze( $now );
		$health = $this->health();

		$this->assertSame( 'good', $health->test_cron()['status'], 'Idle queue.' );

		$this->push_due_job();
		$result = $health->test_cron();
		$this->assertSame( 'critical', $result['status'], 'Due work and the queue never ran.' );
		$this->assertSame( 'lledger_cron', $result['test'] );

		update_option( Job_Queue::LAST_RUN_OPTION, Job_Queue::iso( $now->modify( '-9 minutes' ) ) );
		$this->assertSame( 'good', $health->test_cron()['status'] );

		update_option( Job_Queue::LAST_RUN_OPTION, Job_Queue::iso( $now->modify( '-11 minutes' ) ) );
		$this->assertSame( 'critical', $health->test_cron()['status'] );
	}

	public function test_cron_is_good_when_the_queue_is_idle_even_if_it_ran_long_ago() {
		$now = new DateTimeImmutable( '2026-03-01T12:00:00Z' );
		Clock::freeze( $now );
		update_option( Job_Queue::LAST_RUN_OPTION, Job_Queue::iso( $now->modify( '-3 days' ) ) );
		$this->assertSame( 'good', $this->health()->test_cron()['status'] );
	}

	public function test_shutdown_follows_the_recorded_fastcgi_flag() {
		update_option(
			Shutdown_Runner::LAST_RUN_OPTION,
			array(
				'at'                     => '2026-03-01T12:00:00Z',
				'fastcgi_finish_request' => false,
			)
		);
		$result = $this->health()->test_shutdown();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'lledger_shutdown', $result['test'] );

		update_option(
			Shutdown_Runner::LAST_RUN_OPTION,
			array(
				'at'                     => '2026-03-01T12:00:00Z',
				'fastcgi_finish_request' => true,
			)
		);
		$this->assertSame( 'good', $this->health()->test_shutdown()['status'] );
	}

	public function test_shutdown_without_a_recorded_run_checks_this_request() {
		$expected = function_exists( 'fastcgi_finish_request' ) ? 'good' : 'recommended';
		$this->assertSame( $expected, $this->health()->test_shutdown()['status'] );
	}

	public function test_content_host_is_critical_when_unsafe() {
		$health = $this->health();
		$this->assertSame( 'good', $health->test_content_host()['status'], 'None configured: the sandbox fallback.' );

		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		update_option( Content_Host::OPTION, $home );
		$result = $health->test_content_host();
		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'lledger_content_host', $result['test'] );

		update_option( Content_Host::OPTION, 'content.separate-origin.test' );
		$this->assertSame( 'good', $health->test_content_host()['status'] );
	}

	public function test_db_is_critical_only_for_the_lrs_role_on_an_old_server() {
		$old    = static fn() => '5.7.44';
		add_filter( 'learning_ledger/db_version_string', $old );
		$health = $this->health();

		$this->assertSame( 'good', $health->test_db()['status'], 'LRS off.' );

		update_option( Roles::OPTION, array( 'connector' ) );
		$this->assertSame( 'good', $health->test_db()['status'], 'Connector only.' );

		update_option( Roles::OPTION, array( 'lrs' ) );
		$result = $health->test_db();
		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'lledger_db', $result['test'] );

		remove_filter( 'learning_ledger/db_version_string', $old );
		add_filter( 'learning_ledger/db_version_string', static fn() => '8.0.36' );
		$this->assertSame( 'good', $health->test_db()['status'] );
	}

	public function test_authorization_header_probe() {
		$health = $this->health();

		$this->fake_http( new WP_Error( 'http_request_failed', 'unused' ) );
		$this->assertSame( 'good', $health->test_authorization_header()['status'], 'LRS off: nothing to probe.' );
		$this->assertCount( 0, $this->requests );

		update_option( Roles::OPTION, array( 'lrs' ) );

		$result = $health->test_authorization_header();
		$this->assertSame( 'recommended', $result['status'], 'The loopback could not run.' );
		$this->assertSame( 'lledger_authorization_header', $result['test'] );
		$this->assertSame( home_url( '/xapi/statements' ), $this->requests[0]['url'] );
		$headers = $this->requests[0]['args']['headers'];
		$this->assertSame( 'Basic ' . base64_encode( 'lledger-site-health:probe' ), $headers['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertSame( '2.0.0', $headers['X-Experience-API-Version'] );

		remove_all_filters( 'pre_http_request' );
		$this->fake_http(
			self::http(
				401,
				wp_json_encode(
					array(
						'error'   => true,
						'message' => Authenticator::INVALID_MESSAGE,
					)
				)
			)
		);
		$this->assertSame( 'good', $health->test_authorization_header()['status'], 'The header reached PHP.' );

		remove_all_filters( 'pre_http_request' );
		$this->fake_http(
			self::http(
				401,
				wp_json_encode(
					array(
						'error'   => true,
						'message' => Authenticator::MISSING_MESSAGE,
					)
				)
			)
		);
		$this->assertSame( 'critical', $health->test_authorization_header()['status'], 'The host stripped the header.' );

		remove_all_filters( 'pre_http_request' );
		$this->fake_http( self::http( 404, '<html>Not found</html>' ) );
		$this->assertSame( 'recommended', $health->test_authorization_header()['status'], 'The xAPI path did not reach WordPress.' );

		remove_all_filters( 'pre_http_request' );
		$this->fake_http( self::http( 429, '{}' ) );
		$this->assertSame( 'recommended', $health->test_authorization_header()['status'], 'Rate limited: unknown.' );
	}

	public function test_did_json_probe() {
		$health = $this->health();
		$this->assertSame( 'good', $health->test_did_json()['status'], 'LRS off.' );

		update_option( Roles::OPTION, array( 'lrs' ) );
		$this->fake_http( self::http( 200, wp_json_encode( array( 'id' => Did_Document::did() ) ) ) );
		$result = $health->test_did_json();
		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'lledger_did_json', $result['test'] );
		$this->assertSame( home_url( '/.well-known/did.json' ), $this->requests[0]['url'] );

		remove_all_filters( 'pre_http_request' );
		$this->fake_http( self::http( 404, '<html>Not found</html>' ) );
		$result = $health->test_did_json();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'did-mode path', wp_strip_all_tags( $result['actions'] . $result['description'] ) );

		remove_all_filters( 'pre_http_request' );
		$this->fake_http( self::http( 200, '<html>A static page</html>' ) );
		$this->assertSame( 'recommended', $health->test_did_json()['status'], 'A 200 that is not the DID document.' );
	}

	public function test_did_json_probe_in_path_mode_checks_the_issuer_path() {
		update_option( Roles::OPTION, array( 'lrs' ) );
		update_option( Did_Document::MODE_OPTION, 'path' );
		$this->fake_http( self::http( 200, wp_json_encode( array( 'id' => Did_Document::did() ) ) ) );
		$this->assertSame( 'good', $this->health()->test_did_json()['status'] );
		$this->assertSame( home_url( '/issuer/did.json' ), $this->requests[0]['url'] );

		remove_all_filters( 'pre_http_request' );
		$this->fake_http( self::http( 404, '' ) );
		$result = $this->health()->test_did_json();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringNotContainsString( 'did-mode path', $result['actions'] . $result['description'], 'Already in path mode.' );
	}

	public function test_results_have_the_site_health_shape() {
		Ed25519::force_backend( 'compat' );
		$result = $this->health()->test_sodium();
		foreach ( array( 'label', 'status', 'badge', 'description', 'actions', 'test' ) as $key ) {
			$this->assertArrayHasKey( $key, $result, $key );
		}
		$this->assertContains( $result['status'], array( 'good', 'recommended', 'critical' ) );
		$this->assertArrayHasKey( 'label', $result['badge'] );
		$this->assertArrayHasKey( 'color', $result['badge'] );
	}

	public function test_about_extension_carries_the_runtime_report() {
		Clock::freeze( new DateTimeImmutable( '2026-03-01T12:00:00Z' ) );
		Ed25519::force_backend( 'native' );
		update_option( Job_Queue::LAST_RUN_OPTION, '2026-03-01T11:58:00Z' );
		update_option(
			Shutdown_Runner::LAST_RUN_OPTION,
			array(
				'at'                     => '2026-03-01T11:59:00Z',
				'fastcgi_finish_request' => true,
			)
		);
		$this->push_due_job();
		$this->push_due_job();

		$this->health()->register();
		$ext = apply_filters( 'learning_ledger/about_extension', array( 'plugin_version' => '0.1.0' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		$this->assertSame( '0.1.0', $ext['plugin_version'], 'Keeps earlier keys.' );
		$this->assertSame(
			array(
				'sodium'           => 'native',
				'shutdown_last'    => '2026-03-01T11:59:00Z',
				'shutdown_fastcgi' => true,
				'cron_last'        => '2026-03-01T11:58:00Z',
				'queue_due'        => 2,
			),
			$ext['runtime']
		);
	}

	public function test_runtime_is_null_where_nothing_has_run() {
		$runtime = $this->health()->runtime();
		$this->assertNull( $runtime['shutdown_last'] );
		$this->assertNull( $runtime['cron_last'] );
		$this->assertSame( 0, $runtime['queue_due'] );
		$this->assertIsBool( $runtime['shutdown_fastcgi'] );
	}

	public function test_probe_route_needs_site_health_capability() {
		Capabilities::install();
		$this->health()->register();
		update_option( Roles::OPTION, array( 'lrs' ) );
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$path = '/learning-ledger/v1/site-health/authorization-header';
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( new WP_REST_Request( 'GET', $path ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', $path ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->fake_http(
			self::http(
				401,
				wp_json_encode(
					array(
						'error'   => true,
						'message' => Authenticator::INVALID_MESSAGE,
					)
				)
			)
		);
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $path ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'good', $response->get_data()['status'] );

		$unknown = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/learning-ledger/v1/site-health/nope' ) );
		$this->assertSame( 404, $unknown->get_status() );
	}
}
