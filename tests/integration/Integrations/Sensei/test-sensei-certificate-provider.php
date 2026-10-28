<?php
/**
 * Sensei LMS Certificates provider and the course-server certificate route.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- test fixtures.

use LearningLedger\Connector\CourseServer\Certificates_Route;
use LearningLedger\Connector\CourseServer\Trusted_Keys;
use LearningLedger\Connector\Lms\Guarded_Adapter;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Signed_Request;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
use LearningLedger\Integrations\Sensei\Sensei_Certificate_Provider;
use LearningLedger\Util\Clock;

/**
 * Certificate provider and route tests.
 *
 * @group sensei-contract
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Sensei_Certificate_Provider_Test extends WP_UnitTestCase {

	const PLUGIN = 'sensei-certificates/woothemes-sensei-certificates.php';

	const PREFIX = '/wp-json/learning-ledger/v1/course-server/';

	/** @var array{public:string,secret:string} */
	private array $keys;

	private ?string $request_uri = null;

	private int $course;

	public function set_up(): void {
		parent::set_up();
		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- restored verbatim in tear_down.
		Installer::install();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		self::load_certificates_plugin();
		update_option( 'active_plugins', array_values( array_unique( array_merge( (array) get_option( 'active_plugins', array() ), array( self::PLUGIN ) ) ) ) );

		global $wpdb, $wp_rest_server;
		foreach ( array( 'trusted_keys', 'nonces', 'ratelimit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) );
		}
		Clock::freeze( new DateTimeImmutable( '2026-10-27T10:00:05Z' ) );
		$this->keys = Ed25519::keypair();
		( new Trusted_Keys() )->add( 'course-server', base64_encode( $this->keys['public'] ), Trusted_Keys::ALL_CAPABILITIES, Trusted_Keys::DEFAULT_LIMITS );

		$this->course = $this->course_with_template();

		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$guarded        = new Guarded_Adapter( new Sensei_Adapter() );
		$course         = $this->course;
		add_action(
			'rest_api_init',
			static function () use ( $guarded, $course ) {
				( new Certificates_Route( null, static fn( int $id ) => $id === $course ? $guarded : null ) )->register();
			}
		);
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
		parent::tear_down();
	}

	/**
	 * Load Sensei LMS Certificates as WordPress would for an active plugin. The integration
	 * bootstrap loads only Sensei, and each test's tear_down restores the hooks from before the
	 * first test, so the plugin's init step (hooks and post types) runs again for every test.
	 */
	private static function load_certificates_plugin(): void {
		if ( ! class_exists( 'WooThemes_Sensei_Certificates' ) ) {
			require_once WP_PLUGIN_DIR . '/' . self::PLUGIN;
		}
		// The plugin's dependency check reads Sensei's installed version.
		update_option( 'sensei-version', Sensei()->version );
		WooThemes_Sensei_Certificates::init();
		WooThemes_Sensei_Certificates::instance()->setup_certificates_post_type();
		$GLOBALS['woothemes_sensei_certificate_templates']->setup_certificate_templates_post_type();
	}

	private function course_with_template(): int {
		$course   = self::factory()->post->create(
			array(
				'post_type'   => 'course',
				'post_status' => 'publish',
				'post_title'  => 'Course',
			)
		);
		$template = self::factory()->post->create(
			array(
				'post_type'   => 'certificate_template',
				'post_status' => 'publish',
				'post_title'  => 'Template',
			)
		);
		update_post_meta( $course, '_course_certificate_template', $template );
		return $course;
	}

	private function learner( bool $completed ): int {
		$user = self::factory()->user->create( array( 'display_name' => 'Learner' ) );
		Sensei_Utils::update_course_status( $user, $this->course, $completed ? 'complete' : 'in-progress' );
		return $user;
	}

	private function get( string $route, bool $signed = true ): WP_REST_Response {
		$uri = self::PREFIX . $route;
		$req = new WP_REST_Request( 'GET', '/learning-ledger/v1/course-server/' . $route );
		if ( $signed ) {
			foreach ( Signed_Request::sign( 'course-server', $this->keys['secret'], 'GET', $uri, '', Clock::now()->getTimestamp() ) as $name => $value ) {
				$req->set_header( $name, $value );
			}
		}
		$_SERVER['REQUEST_URI'] = $uri;
		return rest_get_server()->dispatch( $req );
	}

	public function test_completed_course_returns_the_learners_pdf() {
		$user = $this->learner( true );

		$pdf = ( new Sensei_Certificate_Provider() )->certificate_pdf( $user, $this->course );
		$this->assertIsString( $pdf );
		$this->assertStringStartsWith( '%PDF', $pdf );

		$res = $this->get( 'learners/' . $user . '/certificates/' . $this->course );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'application/pdf', $res->get_headers()['Content-Type'] );
		$this->assertStringStartsWith( '%PDF', $res->get_data() );

		// Served as the raw bytes, not JSON.
		$req = new WP_REST_Request( 'GET', '/learning-ledger/v1/course-server/learners/' . $user . '/certificates/' . $this->course );
		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, $res, $req, rest_get_server() );
		$body   = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( $res->get_data(), $body );
	}

	public function test_incomplete_course_is_404_even_when_another_learner_has_a_certificate() {
		$done    = $this->learner( true );
		$pending = $this->learner( false );
		$this->assertNotNull( ( new Sensei_Certificate_Provider() )->certificate_pdf( $done, $this->course ) );

		$this->assertNull( ( new Sensei_Certificate_Provider() )->certificate_pdf( $pending, $this->course ) );
		$res = $this->get( 'learners/' . $pending . '/certificates/' . $this->course );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'lledger_no_certificate', $res->get_data()['code'] );
	}

	public function test_inactive_plugin_is_501() {
		$user = $this->learner( true );
		deactivate_plugins( self::PLUGIN, true );

		$this->assertFalse( Sensei_Certificate_Provider::available() );
		$this->assertNull( ( new Sensei_Certificate_Provider() )->certificate_pdf( $user, $this->course ) );
		$res = $this->get( 'learners/' . $user . '/certificates/' . $this->course );
		$this->assertSame( 501, $res->get_status() );
		$this->assertSame( 'certificate_unavailable', $res->get_data()['code'] );
	}

	public function test_unsigned_request_is_401() {
		$user = $this->learner( true );
		$res  = $this->get( 'learners/' . $user . '/certificates/' . $this->course, false );
		$this->assertSame( 401, $res->get_status() );
		$this->assertStringStartsWith( 'lledger_signature_', $res->get_data()['code'] );
	}

	public function test_key_without_read_certificates_is_403() {
		$user = $this->learner( true );
		( new Trusted_Keys() )->add( 'course-server', base64_encode( $this->keys['public'] ), array( Trusted_Keys::READ_PROGRESS ), Trusted_Keys::DEFAULT_LIMITS );
		$res = $this->get( 'learners/' . $user . '/certificates/' . $this->course );
		$this->assertSame( 403, $res->get_status() );
	}
}
