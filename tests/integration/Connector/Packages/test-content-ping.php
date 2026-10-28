<?php
/**
 * Content-changed ping tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixtures.

use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Packages\Content_Ping;
use LearningLedger\Connector\Packages\Package_Builder;
use LearningLedger\Connector\Pairing\Connector_Keys;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Crypto\Memory_Nonce_Store;
use LearningLedger\Crypto\Signed_Request;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;

class Content_Ping_Test extends WP_UnitTestCase {

	private int $program;

	private int $course;

	private int $lesson;

	/** @var array[] */
	private array $requests = array();

	private int $status = 200;

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		$this->clear_jobs();
		delete_option( Connector_Keys::OPTION );
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

		$this->requests = array();
		$this->status   = 200;
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		( new Content_Ping() )->register();
		$this->clear_jobs();
	}

	private function clear_jobs(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::name( 'jobs' ) ) );
	}

	public function dirs(): array {
		return array( dirname( __DIR__, 3 ) . '/fixtures/integrations' );
	}

	public function post_types(): array {
		return array( 'fake' => array( 'fake_course', 'fake_lesson' ) );
	}

	public function fake_http( $pre, $args, $url ) {
		unset( $pre );
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => $this->status,
				'message' => 'X',
			),
			'cookies'  => array(),
			'filename' => null,
		);
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

	private function content( string $text ): string {
		return '<!-- wp:learning-ledger/beat {"id":"one","goal":"Goal","question":"Why?"} --><!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph --><!-- /wp:learning-ledger/beat -->';
	}

	private function fixture(): void {
		$adapter      = Integration_Loader::adapter( 'fake' );
		$this->course = $adapter->upsert_course( new Course_Spec( 'path-a', 'Path A', '', 'publish', array() ) );
		$this->post_with_id(
			$this->course,
			array(
				'post_type'  => 'fake_course',
				'post_title' => 'Path A',
			)
		);
		$this->lesson = $adapter->upsert_lesson( $this->course, new Lesson_Spec( 'lesson-one', 'Lesson One', '', 1, 'publish', array() ) );
		$this->post_with_id(
			$this->lesson,
			array(
				'post_type'    => 'fake_lesson',
				'post_title'   => 'Lesson One',
				'post_content' => $this->content( 'First.' ),
			)
		);

		update_post_meta( $this->lesson, '_lledger_minutes', 4 );

		$this->program = self::factory()->post->create(
			array(
				'post_type'   => Program_Repository::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Demo program',
			)
		);
		$meta          = array(
			'_lledger_stable_key'        => 'demo',
			'_lledger_iri_base'          => 'https://example.org/courses/demo/',
			'_lledger_courses'           => array( $this->course ),
			'_lledger_path_keys'         => array( $this->course => 'path-a' ),
			'_lledger_custody'           => 'server-kept',
			'_lledger_course_server_url' => 'https://demo.example.org/mcp',
			'_lledger_au_url_template'   => 'https://demo.example.org/cmi5/launch?au={key}',
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $this->program, $k, $v );
		}
	}

	private function jobs(): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT type, payload, dedupe_key, run_after, created_at FROM %i WHERE type = %s', Tables::name( 'jobs' ), 'content_ping' ) );
	}

	private function run_job(): void {
		do_action( 'learning_ledger/jobs/content_ping', array( 'program' => $this->program ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	private function edit_lesson( string $text ): void {
		wp_update_post(
			array(
				'ID'           => $this->lesson,
				'post_content' => wp_slash( $this->content( $text ) ),
			)
		);
	}

	public function test_saving_a_lesson_queues_one_ping_job_and_makes_no_http_call() {
		$this->edit_lesson( 'Changed.' );
		$this->edit_lesson( 'Changed again.' );

		$jobs = $this->jobs();
		$this->assertCount( 1, $jobs, 'two saves, one job' );
		$this->assertSame( 'ping:' . $this->program, $jobs[0]->dedupe_key );
		$this->assertSame( array( 'program' => $this->program ), json_decode( $jobs[0]->payload, true ) );
		$this->assertGreaterThan( strtotime( $jobs[0]->created_at . ' UTC' ), strtotime( $jobs[0]->run_after . ' UTC' ), 'the job is delayed so that a burst of saves is one ping' );
		$this->assertSame( array(), $this->requests );
	}

	public function test_saving_the_program_or_its_course_queues_a_ping() {
		wp_update_post(
			array(
				'ID'         => $this->program,
				'post_title' => 'Renamed',
			)
		);
		$this->assertCount( 1, $this->jobs() );

		$this->clear_jobs();
		wp_update_post(
			array(
				'ID'         => $this->course,
				'post_title' => 'Path A renamed',
			)
		);
		$jobs = $this->jobs();
		$this->assertCount( 1, $jobs );
		$this->assertSame( array( 'program' => $this->program ), json_decode( $jobs[0]->payload, true ) );
	}

	public function test_saving_something_unrelated_queues_nothing() {
		self::factory()->post->create( array( 'post_title' => 'A blog post' ) );
		$stray = self::factory()->post->create( array( 'post_type' => 'fake_lesson' ) );
		wp_update_post(
			array(
				'ID'         => $stray,
				'post_title' => 'Not in any program',
			)
		);
		$this->assertSame( array(), $this->jobs() );
	}

	public function test_the_job_sends_a_signed_request_with_the_new_etag() {
		$this->edit_lesson( 'Changed.' );
		$this->run_job();

		$this->assertCount( 1, $this->requests );
		$request = $this->requests[0];
		$this->assertSame( 'https://demo.example.org/mcp/content-changed', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );

		$built = ( new Package_Builder() )->build( $this->program, 'full' );
		$body  = json_decode( $request['args']['body'], true );
		$this->assertSame(
			array(
				'program' => 'demo',
				'version' => $built->version,
				'etag'    => $built->etag,
			),
			$body
		);

		$kid = Signed_Request::verify(
			$request['args']['headers'],
			'POST',
			'/mcp/content-changed',
			$request['args']['body'],
			static fn( string $id ): ?string => Connector_Keys::key_id() === $id ? base64_decode( Connector_Keys::public_b64(), true ) : null,
			new Memory_Nonce_Store(),
			time()
		);
		$this->assertSame( Connector_Keys::key_id(), $kid );
	}

	public function test_unchanged_content_sends_nothing_and_a_change_sends_again() {
		$this->run_job();
		$this->run_job();
		$this->assertCount( 1, $this->requests, 'the second run finds the same ETag' );
		$first = json_decode( $this->requests[0]['args']['body'], true );

		$this->edit_lesson( 'Something new.' );
		$this->run_job();
		$this->assertCount( 2, $this->requests );
		$second = json_decode( $this->requests[1]['args']['body'], true );
		$this->assertNotSame( $first['etag'], $second['etag'] );
		$this->assertSame( $first['version'] + 1, $second['version'] );
	}

	public function test_a_refused_ping_fails_the_job_so_it_is_retried() {
		$this->status = 500;
		try {
			$this->run_job();
			$this->fail( 'the job swallowed a 500' );
		} catch ( RuntimeException $e ) {
			$this->assertCount( 1, $this->requests );
		}

		$this->status = 200;
		$this->run_job();
		$this->assertCount( 2, $this->requests, 'the retry sends again because the first ping was not accepted' );
	}

	public function test_a_program_without_a_course_server_sends_nothing() {
		update_post_meta( $this->program, '_lledger_course_server_url', '' );
		$this->run_job();
		$this->assertSame( array(), $this->requests );
	}
}
