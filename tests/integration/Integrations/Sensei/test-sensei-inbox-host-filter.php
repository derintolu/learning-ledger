<?php
/**
 * An LRS delivery reaches Sensei on a host that empties anonymous post and user queries.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixtures.

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Connector\CourseServer\Course_Server_Module;
use LearningLedger\Connector\CourseServer\Trusted_Keys;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Inbox\Inbox_Module;
use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Packages\Package_Importer;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Notification_Signature;
use LearningLedger\Crypto\Signed_Request;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
use LearningLedger\Integrations\Sensei\Sensei_Storage;
use LearningLedger\Kernel\Service_Context;
use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Queue\Shutdown_Runner;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;

/**
 * The real inbox route and its shutdown job, with only outgoing HTTP stubbed.
 *
 * @group sensei-contract
 * @requires function Sensei
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Sensei_Inbox_Host_Filter_Test extends WP_UnitTestCase {

	const FIXTURE = LEARNING_LEDGER_DIR . 'tests/fixtures/round-trip/learn-wp-reimagined-authoring.zip';

	const LESSON = 'ln-publish';

	/** @var array{public:string,secret:string} */
	private array $keys;

	private int $program;

	/** @var array{public:string,secret:string} */
	private array $server_keys;

	private ?string $request_uri = null;

	public function set_up(): void {
		parent::set_up();
		// Sensei's progress tables, before any test data: creating a table commits the open transaction.
		if ( ! Sensei_Storage::tables_exist() ) {
			add_filter( 'sensei_feature_flag_tables_based_progress', '__return_true' );
			( new \Sensei\Internal\Installer\Schema( Sensei()->feature_flags ) )->create_tables();
			remove_filter( 'sensei_feature_flag_tables_based_progress', '__return_true' );
		}
		Installer::install();
		self::reload_sensei_storage();
		global $wpdb, $wp_rest_server;
		foreach ( array( 'conn_inbox', 'conn_events', 'conn_cursor', 'applied', 'identity_links', 'waivers', 'registrations' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		delete_option( Connector_Settings::OPTION );
		delete_option( Connector_Secret::OPTION );
		$this->keys = Ed25519::keypair();
		Connector_Settings::update(
			array(
				'notify_kid'        => 'notify-kid-1',
				'notify_public_key' => base64_encode( $this->keys['public'] ),
			)
		);
		Clock::freeze( new DateTimeImmutable( '@' . time() ) );
		$module = new Program_Module();
		$module->register_post_type();
		$module->register_meta();
		update_option( 'lledger_roles', array( 'connector' ) );
		delete_option( 'lledger_integration_state' );
		( new Integration_Loader() )->load();
		( new Inbox_Module() )->register();
		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- restored verbatim in tear_down.
		foreach ( array( 'trusted_keys', 'nonces', 'ratelimit' ) as $t ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::name( $t ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->server_keys = Ed25519::keypair();
		( new Trusted_Keys() )->add( 'course-server', base64_encode( $this->server_keys['public'] ), Trusted_Keys::ALL_CAPABILITIES, Trusted_Keys::DEFAULT_LIMITS );
		( new Course_Server_Module() )->register();
		Service_Context::reset();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		// Only outgoing HTTP is stubbed: the LRS, should anything call it.
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_blocked', 'No network in this test.' ) );

		$report = ( new Package_Importer() )->import( Package::from_zip( self::FIXTURE, new Limits() ), 'sensei', true );
		$this->assertTrue( $report->ok() );
		$this->program = $report->program_id;
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
		Service_Context::reset();
		parent::tear_down();
		// The settings option is rolled back now; Sensei reads it again, as the next request would.
		self::reload_sensei_storage();
	}

	/**
	 * Simulate the host: 'always' empties post and user queries whoever asks; 'anonymous' empties
	 * post, user and comment queries while nobody is logged in; 'everything' empties post, user
	 * and comment queries whoever asks (what Space A reported in 0.1.5).
	 */
	private static function host_filter( string $mode ): void {
		$anon = 'anonymous' === $mode;
		if ( 'everything' === $mode ) {
			// Post, user and comment queries are emptied for every request, logged in or not.
			add_action( 'pre_get_posts', static fn( $q ) => $q->set( 'post__in', array( 0 ) ) );
			add_action( 'pre_get_users', static fn( $q ) => $q->set( 'include', array( 0 ) ) );
			add_action(
				'pre_get_comments',
				static function ( $q ) {
					$q->query_vars['comment__in'] = array( 0 );
				}
			);
			Service_Context::reset();
			return;
		}
		add_action(
			'pre_get_posts',
			static function ( $q ) use ( $anon ) {
				if ( ! $anon || ! is_user_logged_in() ) {
					$q->set( 'post__in', array( 0 ) );
				}
			}
		);
		add_action(
			'pre_get_users',
			static function ( $q ) use ( $anon ) {
				if ( ! $anon || ! is_user_logged_in() ) {
					$q->set( 'include', array( 0 ) );
				}
			}
		);
		if ( $anon ) {
			add_action(
				'pre_get_comments',
				static function ( $q ) {
					if ( ! is_user_logged_in() ) {
						$q->query_vars['comment__in'] = array( 0 );
					}
				}
			);
		}
		Service_Context::reset();
	}

	/**
	 * Make Sensei read its storage settings again and rebuild its progress repositories, as it
	 * does at the start of every request.
	 */
	private static function reload_sensei_storage(): void {
		Sensei()->settings->get_settings();
		$config                                  = \Sensei\Internal\Services\Progress_Storage_Configuration::resolve( Sensei()->settings->settings );
		Sensei()->progress_storage_configuration = $config;
		Sensei()->course_progress_repository     = ( new \Sensei\Internal\Student_Progress\Course_Progress\Repositories\Course_Progress_Repository_Factory( $config ) )->create();
		Sensei()->lesson_progress_repository     = ( new \Sensei\Internal\Student_Progress\Lesson_Progress\Repositories\Lesson_Progress_Repository_Factory( $config ) )->create();
		Sensei()->quiz_progress_repository       = ( new \Sensei\Internal\Student_Progress\Quiz_Progress\Repositories\Quiz_Progress_Repository_Factory( $config ) )->create();
		\Sensei\Internal\Services\Progress_Storage_Settings::reset_cache_enabled();
	}

	/**
	 * Take the simulated host filter off again.
	 */
	private static function no_host_filter(): void {
		remove_all_actions( 'pre_get_posts' );
		remove_all_actions( 'pre_get_users' );
		remove_all_actions( 'pre_get_comments' );
		Service_Context::reset();
	}

	/**
	 * A signed course-server request, as the course server sends it.
	 */
	private function signed( string $method, string $route, array $query = array() ): WP_REST_Response {
		$uri = '/wp-json/learning-ledger/v1/course-server/' . $route . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
		$req = new WP_REST_Request( $method, '/learning-ledger/v1/course-server/' . $route );
		$req->set_query_params( $query );
		foreach ( Signed_Request::sign( 'course-server', $this->server_keys['secret'], $method, $uri, '', Clock::now()->getTimestamp() ) as $name => $value ) {
			$req->set_header( $name, $value );
		}
		$_SERVER['REQUEST_URI'] = $uri;
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * The program's lesson post with a stable key, read straight from the database.
	 */
	private static function lesson_id( string $key ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_lledger_stable_key' WHERE p.post_type = 'lesson' AND m.meta_value = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function deliver( array $statements ): WP_REST_Response {
		$body = (string) wp_json_encode(
			array(
				'event_id'        => Uuid::v4(),
				'subscription_id' => 'sub-1',
				'cursor'          => 1,
				'statements'      => $statements,
			)
		);
		$ts   = Clock::now()->getTimestamp();
		$req  = new WP_REST_Request( 'POST', '/learning-ledger/v1/inbox' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_header( 'X-Learning-Ledger-Timestamp', (string) $ts );
		$req->set_header( 'X-Learning-Ledger-Key', 'notify-kid-1' );
		$req->set_header( 'X-Learning-Ledger-Signature', Notification_Signature::sign( $body, $this->keys['secret'], $ts ) );
		$req->set_body( $body );
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * A cmi5 defined statement about an AU, as the course server sends it.
	 */
	private static function statement( array $actor, Au $au, string $verb, string $registration ): array {
		$result = Vocab::PASSED === $verb ? array( 'success' => true ) : array( 'completion' => true );
		return array(
			'id'      => Uuid::v4(),
			'actor'   => $actor,
			'verb'    => array( 'id' => $verb ),
			'object'  => array(
				'objectType' => 'Activity',
				'id'         => Au::activity_id( $au->id() ),
			),
			'result'  => $result,
			'context' => array(
				'registration'      => $registration,
				'contextActivities' => array(
					'category' => array( array( 'id' => Vocab::CAT_CMI5 ) ),
					'grouping' => array( array( 'id' => $au->id() ) ),
				),
			),
			'stored'  => '2026-10-29T04:47:48.000000Z',
		);
	}

	/**
	 * Deliver statements for lessons of the learner path and run the shutdown job.
	 *
	 * @param string[] $keys   Lesson keys.
	 * @param string   $filter '' for none, 'always' or 'anonymous' (see host_filter()).
	 *
	 * @return array{user:int,course:int,lessons:int[]}
	 */
	private function through_the_inbox( array $keys, string $filter ): array {
		$identity  = new Identity_Map();
		$user      = $identity->create_anonymous_learner();
		$structure = ( new Program_Repository() )->structure( $this->program );
		$lessons   = array_map( array( self::class, 'lesson_id' ), $keys );
		$course    = (int) get_post_meta( $lessons[0], '_lesson_course', true );
		$this->assertTrue( ( new Sensei_Adapter() )->enrol( $user, $course ) );

		wp_set_current_user( 0 );
		if ( '' !== $filter ) {
			self::host_filter( $filter );
			$this->assertSame( array(), get_posts( array( 'post_type' => 'lesson' ) ) );
			$this->assertSame( array(), get_users() );
		}

		$registration = Uuid::v4();
		$statements   = array();
		foreach ( $keys as $key ) {
			$au           = $structure->au_by_key( $key );
			$statements[] = self::statement( $identity->actor_for_user( $user ), $au, Vocab::COMPLETED, $registration );
			if ( 'CompletedAndPassed' === $au->move_on() ) {
				$statements[] = self::statement( $identity->actor_for_user( $user ), $au, Vocab::PASSED, $registration );
			}
		}
		$res = $this->deliver( $statements );
		$this->assertSame( 202, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		foreach ( $lessons as $lesson ) {
			$this->assertFalse( (bool) \Sensei_Utils::user_completed_lesson( $lesson, $user ), 'nothing is applied before the job runs' );
		}

		Shutdown_Runner::on_shutdown();

		$this->assertSame( array(), ( new Inbox_Repository() )->pending( 10 ), 'the job processed the delivery' );
		return array(
			'user'    => $user,
			'course'  => $course,
			'lessons' => $lessons,
		);
	}

	private function one_completion( string $filter ): void {
		$au = ( new Program_Repository() )->structure( $this->program )->au_by_key( self::LESSON );
		$this->assertSame( 'Completed', $au->move_on(), 'a lesson without a checkpoint' );
		$run = $this->through_the_inbox( array( self::LESSON ), $filter );
		$this->assertTrue( (bool) \Sensei_Utils::user_completed_lesson( $run['lessons'][0], $run['user'] ), 'Sensei has the lesson complete for the mapped learner' );
		$this->assertFalse( (bool) \Sensei_Utils::user_completed_course( $run['course'], $run['user'] ), 'one lesson of six does not complete the course' );
	}

	private function whole_path( string $filter ): void {
		$keys = array( 'ln-what-is-wordpress', 'ln-posts-and-pages', 'ln-blocks', 'ln-navigation-and-site-editor', 'ln-ai-in-wordpress', 'ln-publish' );
		$run  = $this->through_the_inbox( $keys, $filter );
		foreach ( $run['lessons'] as $lesson ) {
			$this->assertTrue( (bool) \Sensei_Utils::user_completed_lesson( $lesson, $run['user'] ), (string) $lesson );
		}
		$this->assertTrue( (bool) \Sensei_Utils::user_completed_course( $run['course'], $run['user'] ), 'every lesson done: Sensei completes the course' );
	}

	public function test_a_delivered_completion_reaches_sensei() {
		$this->one_completion( '' );
	}

	public function test_a_delivered_completion_reaches_sensei_under_a_host_that_empties_post_and_user_queries() {
		$this->one_completion( 'always' );
	}

	public function test_a_delivered_path_completes_the_sensei_course() {
		$this->whole_path( '' );
	}

	public function test_a_delivered_path_completes_the_sensei_course_under_a_host_that_empties_post_and_user_queries() {
		$this->whole_path( 'always' );
	}

	/**
	 * The inbox job and the signed progress route, with and without a host that empties post,
	 * user and comment queries for anonymous requests (Sensei keeps progress in comments).
	 *
	 * @param string $filter '' or 'anonymous'.
	 */
	private function progress_route_reports( string $filter ): void {
		$run      = $this->through_the_inbox( array( self::LESSON ), $filter );
		$progress = $this->signed( 'GET', 'learners/' . $run['user'] . '/progress', array( 'course' => (string) $run['course'] ) );
		$this->assertSame( 200, $progress->get_status(), (string) wp_json_encode( $progress->get_data() ) );
		$this->assertSame( array( $run['lessons'][0] ), $progress->get_data()['courses'][0]['completed'] );
		$this->assertSame( 0, get_current_user_id(), 'the route left no user behind' );

		self::no_host_filter();
		$this->assertTrue( (bool) \Sensei_Utils::user_completed_lesson( $run['lessons'][0], $run['user'] ), 'Sensei has the lesson complete' );
		$this->assertFalse( (bool) \Sensei_Utils::user_completed_course( $run['course'], $run['user'] ) );
	}

	public function test_the_progress_route_reports_a_delivered_completion() {
		$this->progress_route_reports( '' );
	}

	public function test_the_progress_route_reports_a_delivered_completion_under_a_host_that_empties_anonymous_post_user_and_comment_queries() {
		$this->progress_route_reports( 'anonymous' );
	}

	public function test_an_unauthenticated_request_never_probes_or_creates_the_service_user() {
		delete_option( Service_Context::OPTION );
		wp_set_current_user( 0 );
		self::host_filter( 'anonymous' );
		$queries = 0;
		add_action(
			'pre_get_posts',
			static function () use ( &$queries ) {
				++$queries;
			}
		);
		$user = ( new Identity_Map() )->create_anonymous_learner();
		foreach ( array( 'GET' => 'learners/' . $user . '/progress', 'POST' => 'learners' ) as $method => $route ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$req = new WP_REST_Request( $method, '/learning-ledger/v1/course-server/' . $route );
			$req->set_query_params( array( 'course' => '1' ) );
			$this->assertSame( 401, rest_get_server()->dispatch( $req )->get_status(), $route );
		}
		$this->assertSame( 0, $queries, 'no probe ran' );
		$this->assertFalse( get_option( Service_Context::OPTION ), 'no service user was made' );
	}

	public function test_signed_status_reports_what_the_host_filters() {
		self::factory()->comment->create( array( 'comment_post_ID' => $this->program ) );
		wp_set_current_user( 0 );
		delete_option( Service_Context::OPTION );
		$this->assertNull( $this->signed( 'GET', 'status' )->get_data()['host_queries']['posts']['service_user'], 'the status never creates the service user' );
		$this->assertFalse( get_option( Service_Context::OPTION ) );
		Service_Context::service_user();
		$plain = $this->signed( 'GET', 'status' )->get_data();
		$caps  = static fn( array $q ): array => array_map( static fn( $k ) => array_diff_key( $k, array( 'with_capability' => 0 ) ), $q );
		foreach ( $plain['host_queries'] as $kind => $q ) {
			$this->assertSame(
				array(
					'read'           => true,
					'edit_posts'     => true,
					'manage_options' => true,
				),
				$q['with_capability'],
				$kind
			);
		}
		$this->assertSame(
			array(
				'posts'    => array(
					'anonymous'    => true,
					'service_user' => true,
				),
				'users'    => array(
					'anonymous'    => true,
					'service_user' => true,
				),
				'comments' => array(
					'anonymous'    => true,
					'service_user' => true,
				),
			),
			$caps( $plain['host_queries'] )
		);
		$this->assertSame( 'comments', $plain['sensei_progress_storage'] );
		$this->assertIsBool( $plain['sensei_progress_tables'] );

		self::host_filter( 'anonymous' );
		$filtered = $this->signed( 'GET', 'status' )->get_data();
		foreach ( array( 'posts', 'users', 'comments' ) as $kind ) {
			$this->assertSame(
				array(
					'anonymous'    => false,
					'service_user' => true,
				),
				$caps( $filtered['host_queries'] )[ $kind ],
				$kind
			);
		}
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Switch Sensei to its tables as the operator's WP-CLI command does, then start a new request.
	 */
	private function sensei_reads_tables(): void {
		$this->assertSame( 0, Sensei_Storage::comment_progress_rows() );
		global $wpdb;
		foreach ( Sensei_Storage::TABLES as $table ) {
			// Rows another test committed (table DDL commits) are not this test's.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$result = Sensei_Storage::switch_to_tables();
		$this->assertSame( 'tables', $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::reload_sensei_storage();
		$this->assertSame( 'tables', Sensei_Storage::status()['storage'] );
		$this->assertTrue( Sensei_Storage::status()['tables'] );
	}

	public function test_with_comment_storage_a_host_that_empties_every_comment_query_hides_progress() {
		$run      = $this->through_the_inbox( array( self::LESSON ), 'everything' );
		$progress = $this->signed( 'GET', 'learners/' . $run['user'] . '/progress', array( 'course' => (string) $run['course'] ) );
		$this->assertSame( array(), $progress->get_data()['courses'][0]['completed'], 'Sensei reads progress through comment queries' );
	}

	public function test_with_table_storage_a_delivered_completion_is_reported_under_a_host_that_empties_everything() {
		$this->sensei_reads_tables();
		$run      = $this->through_the_inbox( array( self::LESSON ), 'everything' );
		$progress = $this->signed( 'GET', 'learners/' . $run['user'] . '/progress', array( 'course' => (string) $run['course'] ) );
		$this->assertSame( 200, $progress->get_status() );
		$this->assertSame( array( $run['lessons'][0] ), $progress->get_data()['courses'][0]['completed'] );
		$this->assertFalse( $progress->get_data()['courses'][0]['course_completed'] );
		$this->assertTrue( (bool) \Sensei_Utils::user_completed_lesson( $run['lessons'][0], $run['user'] ), 'Sensei reads it from its tables' );
	}

	public function test_with_table_storage_a_delivered_path_completes_the_course_under_a_host_that_empties_everything() {
		$this->sensei_reads_tables();
		$keys     = array( 'ln-what-is-wordpress', 'ln-posts-and-pages', 'ln-blocks', 'ln-navigation-and-site-editor', 'ln-ai-in-wordpress', 'ln-publish' );
		$run      = $this->through_the_inbox( $keys, 'everything' );
		$progress = $this->signed( 'GET', 'learners/' . $run['user'] . '/progress', array( 'course' => (string) $run['course'] ) );
		$this->assertEqualsCanonicalizing( $run['lessons'], $progress->get_data()['courses'][0]['completed'] );
		$this->assertTrue( $progress->get_data()['courses'][0]['course_completed'] );
	}

	public function test_signed_status_reports_table_storage() {
		$this->sensei_reads_tables();
		wp_set_current_user( 0 );
		$status = $this->signed( 'GET', 'status' )->get_data();
		$this->assertSame( 'tables', $status['sensei_progress_storage'] );
		$this->assertTrue( $status['sensei_progress_tables'] );
		$this->assertSame( 'complete', $status['sensei_progress_migration'] );
	}

	/**
	 * The lesson and course status events Sensei fires while a delivery is applied.
	 */
	private function status_events( array $keys, string $filter ): array {
		$events = array();
		foreach ( array( 'sensei_lesson_status_updated', 'sensei_course_status_updated' ) as $hook ) {
			add_action(
				$hook,
				static function ( $status ) use ( &$events, $hook ) {
					$events[] = str_replace( array( 'sensei_', '_status_updated' ), '', $hook ) . ':' . $status;
				},
				10,
				1
			);
		}
		$this->through_the_inbox( $keys, $filter );
		return $events;
	}

	public function test_with_table_storage_the_comment_mirror_fires_the_same_status_events_under_a_host_that_empties_everything() {
		$this->sensei_reads_tables();
		$keys  = array( 'ln-posts-and-pages', 'ln-publish' );
		$plain = $this->status_events( $keys, '' );
		self::no_host_filter();
		$hosted = $this->status_events( $keys, 'everything' );
		$this->assertSame( $plain, $hosted, 'no extra in-progress events from a lookup the host emptied' );
		$this->assertNotContains( 'course:complete', $hosted );
	}

	public function test_deleting_a_learner_removes_their_sensei_progress_under_a_host_that_empties_everything() {
		global $wpdb;
		$this->sensei_reads_tables();
		$run    = $this->through_the_inbox( array( 'ln-posts-and-pages', self::LESSON ), 'everything' );
		$user   = $run['user'];
		$left   = static function () use ( $wpdb, $user ): array {
			return array(
				'comments' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d AND comment_type LIKE %s", $user, 'sensei\\_%' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'meta'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta} m LEFT JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id WHERE c.comment_ID IS NULL" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'tables'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sensei_lms_progress WHERE user_id = %d", $user ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			);
		};
		$before = $left();
		$this->assertGreaterThan( 0, $before['comments'] );
		$this->assertGreaterThan( 0, $before['tables'] );

		$res = $this->signed( 'DELETE', 'learners/' . $user, array( 'revokeBadges' => 'false' ) );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertFalse( get_userdata( $user ) );
		$this->assertSame(
			array(
				'comments' => 0,
				'meta'     => $before['meta'],
				'tables'   => 0,
			),
			$left(),
			'no Sensei activity comment, orphaned comment meta or progress row is left'
		);
	}
}
