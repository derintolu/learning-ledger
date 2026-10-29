<?php
/**
 * An LRS delivery reaches Sensei on a host that empties anonymous post and user queries.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixtures.

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Vocab;
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
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
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

	public function set_up(): void {
		parent::set_up();
		Installer::install();
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
		Clock::freeze( null );
		parent::tear_down();
	}

	private static function host_filter(): void {
		add_action( 'pre_get_posts', static fn( $q ) => $q->set( 'post__in', array( 0 ) ) );
		add_action( 'pre_get_users', static fn( $q ) => $q->set( 'include', array( 0 ) ) );
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
	 * @param string[] $keys     Lesson keys.
	 * @param boolean  $filtered Whether the host empties post and user queries.
	 *
	 * @return array{user:int,course:int,lessons:int[]}
	 */
	private function through_the_inbox( array $keys, bool $filtered ): array {
		$identity  = new Identity_Map();
		$user      = $identity->create_anonymous_learner();
		$structure = ( new Program_Repository() )->structure( $this->program );
		$lessons   = array_map( array( self::class, 'lesson_id' ), $keys );
		$course    = (int) get_post_meta( $lessons[0], '_lesson_course', true );
		$this->assertTrue( ( new Sensei_Adapter() )->enrol( $user, $course ) );

		if ( $filtered ) {
			self::host_filter();
			$this->assertSame( array(), get_posts( array( 'post_type' => 'lesson' ) ) );
			$this->assertSame( array(), get_users() );
		}
		wp_set_current_user( 0 );

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

	private function one_completion( bool $filtered ): void {
		$au = ( new Program_Repository() )->structure( $this->program )->au_by_key( self::LESSON );
		$this->assertSame( 'Completed', $au->move_on(), 'a lesson without a checkpoint' );
		$run = $this->through_the_inbox( array( self::LESSON ), $filtered );
		$this->assertTrue( (bool) \Sensei_Utils::user_completed_lesson( $run['lessons'][0], $run['user'] ), 'Sensei has the lesson complete for the mapped learner' );
		$this->assertFalse( (bool) \Sensei_Utils::user_completed_course( $run['course'], $run['user'] ), 'one lesson of six does not complete the course' );
	}

	private function whole_path( bool $filtered ): void {
		$keys = array( 'ln-what-is-wordpress', 'ln-posts-and-pages', 'ln-blocks', 'ln-navigation-and-site-editor', 'ln-ai-in-wordpress', 'ln-publish' );
		$run  = $this->through_the_inbox( $keys, $filtered );
		foreach ( $run['lessons'] as $lesson ) {
			$this->assertTrue( (bool) \Sensei_Utils::user_completed_lesson( $lesson, $run['user'] ), (string) $lesson );
		}
		$this->assertTrue( (bool) \Sensei_Utils::user_completed_course( $run['course'], $run['user'] ), 'every lesson done: Sensei completes the course' );
	}

	public function test_a_delivered_completion_reaches_sensei() {
		$this->one_completion( false );
	}

	public function test_a_delivered_completion_reaches_sensei_under_a_host_that_empties_post_and_user_queries() {
		$this->one_completion( true );
	}

	public function test_a_delivered_path_completes_the_sensei_course() {
		$this->whole_path( false );
	}

	public function test_a_delivered_path_completes_the_sensei_course_under_a_host_that_empties_post_and_user_queries() {
		$this->whole_path( true );
	}
}
