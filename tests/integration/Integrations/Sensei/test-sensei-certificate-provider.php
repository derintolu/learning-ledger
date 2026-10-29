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
use LearningLedger\Connector\Lms\Integration_State;
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
 * @requires function Sensei
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Sensei_Certificate_Provider_Test extends WP_UnitTestCase {

	const PLUGIN = 'sensei-certificates/woothemes-sensei-certificates.php';

	const PREFIX = '/wp-json/learning-ledger/v1/course-server/';

	/** @var array{public:string,secret:string} */
	private array $keys;

	private ?string $request_uri = null;

	private int $course;

	/** @var string[] */
	private array $temp_files = array();

	private ?string $error_log = null;

	public function set_up(): void {
		parent::set_up();
		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- restored verbatim in tear_down.
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . self::PLUGIN ) ) {
			$this->markTestSkipped( 'Sensei LMS Certificates is not installed.' );
		}
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
		add_action(
			'rest_api_init',
			static function () use ( $guarded ) {
				( new Certificates_Route( null, static fn( int $id ) => 'course' === get_post_type( $id ) ? $guarded : null ) )->register();
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
		if ( null !== $this->error_log ) {
			ini_set( 'error_log', $this->error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restores the setting changed by the render-failure tests.
		}
		foreach ( $this->temp_files as $file ) {
			wp_delete_file( $file );
		}
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

	private function learner( bool $completed, ?int $course = null ): int {
		$user = self::factory()->user->create( array( 'display_name' => 'Learner' ) );
		Sensei_Utils::update_course_status( $user, $course ?? $this->course, $completed ? 'complete' : 'in-progress' );
		return $user;
	}

	/**
	 * Use a background image file with the given bytes and extension for every certificate.
	 */
	private function background( string $bytes, string $extension ): void {
		$file = get_temp_dir() . 'lledger-bg-' . wp_generate_password( 8, false ) . '.' . $extension;
		file_put_contents( $file, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->temp_files[] = $file;
		add_filter( 'woothemes_sensei_certificates_background', static fn() => $file );
	}

	private function assert_render_failure_is_500_and_integration_stays_active( int $user ): void {
		$log                = get_temp_dir() . 'lledger-log-' . wp_generate_password( 8, false ) . '.log';
		$this->temp_files[] = $log;
		$this->error_log    = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- capture the provider's log line; restored in tear_down.
		$this->assertInstanceOf( WP_Error::class, ( new Sensei_Certificate_Provider() )->certificate( $user, $this->course ) );
		$this->assertNull( ( new Sensei_Certificate_Provider() )->certificate_pdf( $user, $this->course ) );
		$res = $this->get( 'learners/' . $user . '/certificates/' . $this->course );
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'certificate_render_failed', $res->get_data()['code'] );
		$this->assertSame( Integration_State::ACTIVE, Integration_State::status( 'sensei' ) );

		$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
		$this->assertStringContainsString( 'Learning Ledger: Sensei certificate ', $logged );
		$this->assertStringNotContainsString( 'Learner', $logged );
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

	public function test_unknown_learner_is_404() {
		$res = $this->get( 'learners/999999/certificates/' . $this->course );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'lledger_unknown_learner', $res->get_data()['code'] );
	}

	public function test_unknown_course_is_404() {
		$user = $this->learner( true );
		$res  = $this->get( 'learners/' . $user . '/certificates/999999' );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'lledger_unknown_course', $res->get_data()['code'] );
	}

	public function test_completed_course_without_certificate_post_is_404() {
		$course = self::factory()->post->create(
			array(
				'post_type'   => 'course',
				'post_status' => 'publish',
				'post_title'  => 'No template',
			)
		);
		$user   = $this->learner( true, $course );
		$this->assertTrue( Sensei_Utils::user_completed_course( $course, $user ) );
		$this->assertNull( WooThemes_Sensei_Certificates::instance()->get_certificate_id( $course, $user ) );

		$res = $this->get( 'learners/' . $user . '/certificates/' . $course );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'lledger_no_certificate', $res->get_data()['code'] );
	}

	public function test_two_completed_learners_each_get_their_own_certificate() {
		$first  = $this->learner( true );
		$second = $this->learner( true );
		$drawn  = array();
		add_action(
			'sensei_certificates_before_pdf_output',
			static function ( $pdf ) use ( &$drawn ) {
				$drawn[] = (int) $pdf->certificate_id;
			},
			5
		);

		foreach ( array( $first, $second ) as $user ) {
			$res = $this->get( 'learners/' . $user . '/certificates/' . $this->course );
			$this->assertSame( 200, $res->get_status() );
			$this->assertStringStartsWith( '%PDF', $res->get_data() );
		}

		$this->assertCount( 2, $drawn );
		$this->assertNotSame( $drawn[0], $drawn[1] );
		$this->assertSame( $first, (int) get_post_meta( $drawn[0], 'learner_id', true ) );
		$this->assertSame( $second, (int) get_post_meta( $drawn[1], 'learner_id', true ) );
		$this->assertSame( $this->course, (int) get_post_meta( $drawn[0], 'course_id', true ) );
		$this->assertSame( $this->course, (int) get_post_meta( $drawn[1], 'course_id', true ) );
	}

	public function test_background_tfpdf_rejects_is_500_and_integration_stays_active() {
		$user = $this->learner( true );
		// A real PNG under an extension tFPDF cannot parse: getimagesize() accepts it, tFPDF throws.
		$png = file_get_contents( WP_PLUGIN_DIR . '/sensei-certificates/assets/images/certificate_template.png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
		$this->background( (string) $png, 'bmp' );
		$this->assert_render_failure_is_500_and_integration_stays_active( $user );
	}

	public function test_corrupt_background_is_500_and_integration_stays_active() {
		$user = $this->learner( true );
		$this->background( 'not an image', 'png' );
		$this->assert_render_failure_is_500_and_integration_stays_active( $user );
	}

	public function test_a_host_that_empties_post_queries_does_not_get_a_duplicate_certificate() {
		global $wpdb;
		add_action( 'sensei_course_status_updated', array( Sensei_Certificate_Provider::class, 'guard_duplicate' ), 8, 3 );
		add_action( 'pre_get_posts', static fn( $q ) => $q->set( 'post__in', array( 0 ) ) );
		$user   = self::factory()->user->create();
		$course = $this->course;
		$count  = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'course_id' AND m.meta_value = %d WHERE p.post_type = 'certificate' AND p.post_author = %d", $course, $user ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		Sensei_Utils::update_course_status( $user, $this->course, 'complete' );
		$this->assertSame( 1, $count() );
		Sensei_Utils::update_course_status( $user, $this->course, 'complete' );
		$this->assertSame( 1, $count(), 'Certificates is skipped when the certificate exists' );
		$this->assertSame( 9, has_action( 'sensei_course_status_updated', array( WooThemes_Sensei_Certificates::instance(), 'handle_course_completed' ) ), 'and is hooked again for the next event' );

		// A trashed certificate does not count, as in Certificates' own check (post_status any).
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_status = 'trash' WHERE post_type = 'certificate' AND post_author = %d", $user ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( 0 );
		remove_all_actions( 'pre_get_posts' );
		Sensei_Utils::update_course_status( $user, $this->course, 'complete' );
		$this->assertSame( 2, $count(), 'a new certificate replaces the trashed one' );
	}
}
