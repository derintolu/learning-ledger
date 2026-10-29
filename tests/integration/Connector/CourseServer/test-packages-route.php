<?php
/**
 * Course-server package route tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions -- test fixtures.

use LearningLedger\Connector\CourseServer\Course_Server_Module;
use LearningLedger\Connector\CourseServer\Trusted_Keys;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Signed_Request;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Package\Package_Validator;
use LearningLedger\Package\Package_Writer;
use LearningLedger\Util\Clock;

require_once dirname( __DIR__, 3 ) . '/fixtures/packages/class-package-fixtures.php';

class Packages_Route_Test extends WP_UnitTestCase {

	const PREFIX = '/wp-json/learning-ledger/v1/course-server/';

	/** @var array{public:string,secret:string} */
	private array $keys;

	private int $program;

	private ?string $request_uri = null;

	private array $temp = array();

	public function set_up(): void {
		parent::set_up();
		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- restored verbatim in tear_down.
		Installer::install();
		global $wpdb, $wp_rest_server;
		foreach ( array( 'trusted_keys', 'nonces', 'ratelimit' ) as $t ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::name( $t ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		Clock::freeze( new DateTimeImmutable( '2026-10-27T10:00:05Z' ) );

		register_post_type( 'fake_course', array( 'public' => false ) );
		register_post_type( 'fake_lesson', array( 'public' => false ) );
		$module = new Program_Module();
		$module->register_post_type();
		$module->register_meta();
		update_option( 'lledger_roles', array( 'connector' ) );
		delete_option( 'lledger_integration_state' );
		add_filter( 'learning_ledger/integration_dirs', array( $this, 'dirs' ) );
		add_filter( 'learning_ledger/adapter_post_types', array( $this, 'post_types' ) );
		( new Integration_Loader() )->load();
		$this->fixture();

		$this->keys = Ed25519::keypair();
		( new Trusted_Keys() )->add( 'course-server', base64_encode( $this->keys['public'] ), Trusted_Keys::ALL_CAPABILITIES, Trusted_Keys::DEFAULT_LIMITS );
		( new Trusted_Keys() )->add( 'progress-only', base64_encode( $this->keys['public'] ), array( Trusted_Keys::READ_PROGRESS ), Trusted_Keys::DEFAULT_LIMITS );
		( new Course_Server_Module() )->register();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	public function tear_down(): void {
		global $wp_rest_server;
		remove_all_filters( 'pre_http_request' );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		Clock::freeze( null );
		foreach ( $this->temp as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file.
			}
		}
		parent::tear_down();
	}

	public function dirs(): array {
		return array( dirname( __DIR__, 3 ) . '/fixtures/integrations' );
	}

	public function post_types(): array {
		return array( 'fake' => array( 'fake_course', 'fake_lesson' ) );
	}

	private function post_with_id( int $id, array $args ): void {
		$args['post_content'] = wp_slash( $args['post_content'] ?? '' );
		$made                 = wp_insert_post(
			array_merge(
				array(
					'import_id'   => $id,
					'post_status' => 'publish',
				),
				$args
			)
		);
		$this->assertSame( $id, $made );
	}

	private function fixture(): void {
		$adapter = Integration_Loader::adapter( 'fake' );
		$course  = $adapter->upsert_course( new Course_Spec( 'path-a', 'Path A', '', 'publish', array() ) );
		$this->post_with_id(
			$course,
			array(
				'post_type'    => 'fake_course',
				'post_title'   => 'Path A',
				'post_excerpt' => 'Tagline of Path A',
			)
		);
		$lesson  = $adapter->upsert_lesson( $course, new Lesson_Spec( 'lesson-one', 'Lesson One', '', 1, 'publish', array() ) );
		$attrs   = wp_json_encode(
			array(
				'key'         => 'quotes-check',
				'kind'        => 'free-text',
				'question'    => 'Explain the quotes.',
				'maxAttempts' => 3,
				'checks'      => array(),
				'branches'    => array(
					array(
						'key'     => 'got-it',
						'when'    => 'The learner explains it.',
						'success' => true,
						'to'      => 'lesson-one',
						'message' => 'Correct.',
					),
					array(
						'key'     => 'retry',
						'when'    => 'The learner does not.',
						'success' => false,
						'to'      => '',
						'message' => 'Look again.',
					),
				),
			)
		);
		$content = '<!-- wp:learning-ledger/beat {"id":"quotes","goal":"Goal","question":"Why?"} --><!-- wp:paragraph --><p>Keys use quotes.</p><!-- /wp:paragraph --><!-- /wp:learning-ledger/beat -->'
			. '<!-- wp:learning-ledger/checkpoint ' . $attrs . ' --><!-- wp:learning-ledger/rubric {"text":"RUBRIC-SECRET"} /--><!-- /wp:learning-ledger/checkpoint -->';
		$this->post_with_id(
			$lesson,
			array(
				'post_type'    => 'fake_lesson',
				'post_title'   => 'Lesson One',
				'post_content' => $content,
			)
		);

		update_post_meta( $lesson, '_lledger_minutes', 4 );

		$this->program = self::factory()->post->create(
			array(
				'post_type'   => Program_Repository::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Demo program',
			)
		);
		$meta          = array(
			'_lledger_stable_key'      => 'demo',
			'_lledger_iri_base'        => 'https://example.org/courses/demo/',
			'_lledger_courses'         => array( $course ),
			'_lledger_path_keys'       => array( $course => 'path-a' ),
			'_lledger_custody'         => 'server-kept',
			'_lledger_au_url_template' => 'https://demo.example.org/cmi5/launch?au={key}',
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $this->program, $k, $v );
		}
	}

	private function send( string $method, string $route, array $headers = array(), ?array $json = null, string $key_id = 'course-server', bool $signed = true ): WP_REST_Response {
		$uri  = self::PREFIX . $route;
		$body = null === $json ? '' : (string) wp_json_encode( $json );
		$req  = new WP_REST_Request( $method, '/learning-ledger/v1/course-server/' . $route );
		if ( '' !== $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( $body );
		}
		if ( $signed ) {
			foreach ( Signed_Request::sign( $key_id, $this->keys['secret'], $method, $uri, $body, Clock::now()->getTimestamp() ) as $name => $value ) {
				$req->set_header( $name, $value );
			}
		}
		foreach ( $headers as $name => $value ) {
			$req->set_header( $name, $value );
		}
		$_SERVER['REQUEST_URI'] = $uri;
		return rest_get_server()->dispatch( $req );
	}

	private function package( string $zip ): Package {
		$path         = tempnam( sys_get_temp_dir(), 'llp' );
		$this->temp[] = $path;
		file_put_contents( $path, $zip );
		return Package::from_zip( $path, new Limits() );
	}

	public function test_an_unsigned_get_is_401() {
		$this->assertSame( 401, $this->send( 'GET', 'packages/' . $this->program, array(), null, 'course-server', false )->get_status() );
		$this->assertSame( 401, $this->send( 'GET', 'packages/demo', array(), null, 'unknown-key' )->get_status() );
	}

	public function test_a_key_without_read_packages_is_403() {
		$this->assertSame( 403, $this->send( 'GET', 'packages/' . $this->program, array(), null, 'progress-only' )->get_status() );
	}

	public function test_a_signed_get_returns_the_full_flavour_with_secrets() {
		$res = $this->send( 'GET', 'packages/' . $this->program );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$headers = $res->get_headers();
		$this->assertSame( 'application/zip', $headers['Content-Type'] );
		$this->assertSame( Package_Writer::etag( $res->get_data() ), $headers['ETag'] );
		$this->assertSame( '1', $headers['X-Package-Version'] );

		$pkg = $this->package( $res->get_data() );
		$this->assertSame( array(), Package_Validator::validate( $pkg, 'full' )->errors() );
		$this->assertSame( 'authoring', $pkg->course_json()['flavour'] );
		$this->assertSame( 'RUBRIC-SECRET', $pkg->lesson_json( 'lesson-one' )['checkpoint']['rubric'] );
	}

	public function test_the_program_can_be_named_by_its_stable_key() {
		$by_id  = $this->send( 'GET', 'packages/' . $this->program );
		$by_key = $this->send( 'GET', 'packages/demo' );
		$this->assertSame( 200, $by_key->get_status() );
		$this->assertSame( $by_id->get_headers()['ETag'], $by_key->get_headers()['ETag'] );
		$this->assertSame( 404, $this->send( 'GET', 'packages/no-such-program' )->get_status() );
		$this->assertSame( 404, $this->send( 'GET', 'packages/999999' )->get_status() );
	}

	public function test_a_matching_if_none_match_gets_304() {
		$etag = $this->send( 'GET', 'packages/demo' )->get_headers()['ETag'];
		$res  = $this->send( 'GET', 'packages/demo', array( 'If-None-Match' => $etag ) );
		$this->assertSame( 304, $res->get_status() );
		$this->assertNull( $res->get_data() );
		$this->assertSame( $etag, $res->get_headers()['ETag'] );
		$this->assertSame( 200, $this->send( 'GET', 'packages/demo', array( 'If-None-Match' => '"stale"' ) )->get_status() );
	}

	public function test_posting_a_url_imports_the_package_and_returns_the_report() {
		$inputs = Package_Fixtures::inputs( 'good-full' );
		$zip    = Package_Writer::write( $inputs[0], $inputs[1], $inputs[2], $inputs[3] );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) use ( $zip ) {
				unset( $pre );
				file_put_contents( $args['filename'], $zip );
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => $args['filename'],
				);
			},
			10,
			2
		);

		// A signed course server is a trusted import path: kses is off for its writes, then on again.
		wp_set_current_user( 0 );
		kses_init_filters();
		$seen = array();
		$spy  = static function () use ( &$seen ): void {
			$seen[] = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		};
		add_action( 'save_post', $spy );
		try {
			$res = $this->send(
				'POST',
				'packages',
				array(),
				array(
					'url'     => 'https://example.org/p.zip',
					'adapter' => 'fake',
				)
			);
			$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
		} finally {
			remove_action( 'save_post', $spy );
			kses_remove_filters();
		}
		$this->assertSame( array( false ), array_values( array_unique( $seen ) ) );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertGreaterThan( 0, $res->get_data()['program_id'] );
		$this->assertContains( 'course:json', $res->get_data()['created'] );
	}

	public function test_posting_a_bad_package_is_422_and_a_missing_url_is_reported() {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) {
				unset( $pre );
				file_put_contents( $args['filename'], 'not a zip' );
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => $args['filename'],
				);
			},
			10,
			2
		);
		$bad = $this->send( 'POST', 'packages', array(), array( 'url' => 'https://example.org/p.zip' ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( 'zip_invalid', $bad->get_data()['errors'][0]['code'] );

		$none = $this->send( 'POST', 'packages', array(), array() );
		$this->assertSame( 422, $none->get_status() );
		$this->assertSame( 'missing_package', $none->get_data()['errors'][0]['code'] );
	}

	public function test_posting_needs_a_signature_and_import_packages() {
		$body = array( 'url' => 'https://example.org/p.zip' );
		$this->assertSame( 401, $this->send( 'POST', 'packages', array(), $body, 'course-server', false )->get_status() );
		$this->assertSame( 403, $this->send( 'POST', 'packages', array(), $body, 'progress-only' )->get_status() );
	}
}
