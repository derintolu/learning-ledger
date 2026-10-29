<?php
/**
 * Connector GDPR exporter and eraser tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\CourseServer\Learners_Controller;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Outbox\Outbox_Repository;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Privacy\Connector_Eraser;
use LearningLedger\Connector\Privacy\Connector_Exporter;
use LearningLedger\Connector\Privacy\Connector_Privacy_Module;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

class Connector_Privacy_Test extends WP_UnitTestCase {

	const LRS = 'https://lrs.example.test/xapi/';
	const API = 'https://lrs.example.test/wp-json/learning-ledger/v1/';

	/** @var array<int,array{url:string,args:array}> */
	private array $requests = array();

	/** @var callable|null Replies to a request: fn( string $url, array $args ): array|WP_Error. */
	private $handler = null;

	private int $user;

	private string $email = 'learner@example.org';

	private array $site_actor;

	private array $linked = array(
		'objectType' => 'Agent',
		'mbox'       => 'mailto:linked@example.org',
	);

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'identity_links', 'waivers', 'applied', 'registrations', 'conn_outbox', 'conn_inbox', 'jobs' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		delete_option( Connector_Settings::OPTION );
		delete_option( Connector_Secret::OPTION );
		Connector_Settings::update(
			array(
				'lrs_url'   => self::LRS,
				'api_base'  => self::API,
				'client_id' => 'connector-site',
			)
		);
		Connector_Secret::generate();

		$this->user       = self::factory()->user->create( array( 'user_email' => $this->email ) );
		$this->site_actor = ( new Identity_Map() )->actor_for_user( $this->user );

		$this->requests = array();
		$this->handler  = static fn() => array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'statements' => array() ) ),
		);
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		parent::tear_down();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return call_user_func( $this->handler, $url, $args );
	}

	private function json_reply( array $data, int $status = 200 ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array(),
			'body'     => wp_json_encode( $data ),
		);
	}

	private function statement( array $actor, string $id = '' ): array {
		return array(
			'id'     => '' === $id ? Uuid::v4() : $id,
			'actor'  => $actor,
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object' => array( 'id' => 'https://example.org/lesson/1' ),
		);
	}

	/** Fill every connector table with rows about the user and one other learner. */
	private function seed( int $user, array $site_actor, array $linked ): array {
		( new Identity_Map() )->link( Agent_Identity::from_array( $linked ), $user );
		( new Waiver_Ledger() )->record( $user, 22, Uuid::v4(), Uuid::v4(), 'Tested Out', false );
		( new Apply_Ledger() )->record( $user, new Lms_Object( 'lesson', 21 ), Result::LESSON_COMPLETED, Uuid::v4(), 0.8, true );
		( new Registration_Map() )->get_or_create( $user, 'program:1' );
		$out = $this->statement( $site_actor );
		( new Outbox_Repository() )->add( $out, 5 );
		$in_site   = $this->statement( $site_actor );
		$in_linked = $this->statement( $linked );
		( new Inbox_Repository() )->store( $in_site, 'webhook' );
		( new Inbox_Repository() )->store( $in_linked, 'webhook' );
		return array( $out, $in_site, $in_linked );
	}

	private function count_rows( string $table, string $where = '1=1' ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( $table ) . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	private function group_ids( array $result ): array {
		return array_values( array_unique( array_column( $result['data'], 'group_id' ) ) );
	}

	public function test_module_registers_an_exporter_and_an_eraser() {
		( new Connector_Privacy_Module() )->register();

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertSame( 'Learning Ledger', $exporters['learning-ledger']['exporter_friendly_name'] );
		$this->assertIsCallable( $exporters['learning-ledger']['callback'] );
		$this->assertSame( 'Learning Ledger', $erasers['learning-ledger']['eraser_friendly_name'] );
		$this->assertIsCallable( $erasers['learning-ledger']['callback'] );
	}

	public function test_exporter_returns_all_six_groups_and_the_lrs_statements() {
		list( $out, $in_site ) = $this->seed( $this->user, $this->site_actor, $this->linked );
		$lrs_statement         = $this->statement( $this->site_actor );
		$this->handler         = fn() => $this->json_reply( array( 'statements' => array( $lrs_statement ) ) );

		$result = ( new Connector_Exporter() )->export( $this->email, 1 );

		$this->assertFalse( $result['done'], 'the linked actor is read on the next page' );
		$this->assertEqualsCanonicalizing(
			array(
				'lledger-identity-links',
				'lledger-actor',
				'lledger-outbox',
				'lledger-inbox',
				'lledger-waivers',
				'lledger-applied',
				'lledger-lrs-statements',
			),
			$this->group_ids( $result )
		);
		$flat = wp_json_encode( $result['data'] );
		$this->assertStringContainsString( $this->site_actor['account']['name'], $flat, 'the actor' );
		$this->assertStringContainsString( 'linked@example.org', $flat, 'the identity link' );
		$this->assertStringContainsString( $out['id'], $flat, 'the outbox row' );
		$this->assertStringContainsString( $in_site['id'], $flat, 'the inbox row' );
		$this->assertStringContainsString( 'Tested Out', $flat, 'the waiver' );
		$this->assertStringContainsString( 'lesson_completed', $flat, 'the applied row' );
		$this->assertStringContainsString( $lrs_statement['id'], $flat, 'the LRS statement' );

		foreach ( $result['data'] as $item ) {
			$this->assertArrayHasKey( 'group_label', $item );
			$this->assertArrayHasKey( 'item_id', $item );
			foreach ( $item['data'] as $field ) {
				$this->assertIsString( $field['name'] );
				$this->assertIsString( $field['value'] );
			}
		}
	}

	public function test_exporter_asks_the_lrs_for_each_actor_with_the_connector_credential() {
		$this->seed( $this->user, $this->site_actor, $this->linked );

		( new Connector_Exporter() )->export( $this->email, 1 );
		( new Connector_Exporter() )->export( $this->email, 2 );

		$gets = array_values( array_filter( $this->requests, static fn( $r ) => 'GET' === $r['args']['method'] ) );
		$this->assertCount( 2, $gets, 'one request per page, one actor per page' );
		$agents = array();
		foreach ( $gets as $get ) {
			$this->assertStringStartsWith( self::LRS . 'statements?', $get['url'] );
			$this->assertStringStartsWith( 'Basic ', $get['args']['headers']['Authorization'] );
			parse_str( (string) wp_parse_url( $get['url'], PHP_URL_QUERY ), $q );
			$agents[] = json_decode( $q['agent'], true );
		}
		$this->assertSame( array( $this->site_actor, $this->linked ), $agents );
	}

	public function test_exporter_follows_the_more_link_one_page_at_a_time() {
		$first         = $this->statement( $this->site_actor );
		$second        = $this->statement( $this->site_actor );
		$replies       = array(
			$this->json_reply(
				array(
					'statements' => array( $first ),
					'more'       => '/xapi/statements?cursor=abc',
				)
			),
			$this->json_reply( array( 'statements' => array( $second ) ) ),
		);
		$this->handler = static function () use ( &$replies ) {
			return array_shift( $replies );
		};

		$page1 = ( new Connector_Exporter() )->export( $this->email, 1 );
		$page2 = ( new Connector_Exporter() )->export( $this->email, 2 );

		$this->assertFalse( $page1['done'] );
		$this->assertStringContainsString( $first['id'], wp_json_encode( $page1['data'] ) );
		$this->assertStringNotContainsString( $second['id'], wp_json_encode( $page1['data'] ) );
		$this->assertTrue( $page2['done'] );
		$this->assertStringContainsString( $second['id'], wp_json_encode( $page2['data'] ) );
		$this->assertStringNotContainsString( 'lledger-identity-links', wp_json_encode( $page2['data'] ), 'local groups only on page 1' );
		$this->assertStringContainsString( 'cursor=abc', $this->requests[1]['url'] );
	}

	public function test_exporter_only_exports_the_requested_user() {
		$other_actor = ( new Identity_Map() )->actor_for_user( self::factory()->user->create( array( 'user_email' => 'other@example.org' ) ) );
		$this->seed( $this->user, $this->site_actor, $this->linked );
		$other_out = $this->statement( $other_actor );
		( new Outbox_Repository() )->add( $other_out, 5 );
		( new Waiver_Ledger() )->record( (int) get_user_by( 'email', 'other@example.org' )->ID, 99, Uuid::v4(), Uuid::v4(), 'Other reason', false );

		$flat = wp_json_encode( ( new Connector_Exporter() )->export( $this->email, 1 )['data'] );

		$this->assertStringNotContainsString( $other_out['id'], $flat );
		$this->assertStringNotContainsString( $other_actor['account']['name'], $flat );
		$this->assertStringNotContainsString( 'Other reason', $flat );
	}

	public function test_exporter_for_an_unknown_email_returns_nothing_and_asks_nothing() {
		$result = ( new Connector_Exporter() )->export( 'nobody@example.org', 1 );

		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$result
		);
		$this->assertSame( array(), $this->requests );
	}

	public function test_exporter_says_so_when_the_lrs_cannot_be_read() {
		$this->seed( $this->user, $this->site_actor, $this->linked );
		$this->handler = static fn() => new WP_Error( 'http_request_failed', 'boom' );

		$result = ( new Connector_Exporter() )->export( $this->email, 1 );

		$this->assertContains( 'lledger-waivers', $this->group_ids( $result ), 'local data is still exported' );
		$flat = wp_json_encode( $result['data'] );
		$this->assertStringContainsString( 'could not be read', $flat );
	}

	public function test_eraser_removes_local_rows_and_erases_each_actor_at_the_lrs() {
		$this->seed( $this->user, $this->site_actor, $this->linked );
		$other_user  = self::factory()->user->create();
		$other_actor = ( new Identity_Map() )->actor_for_user( $other_user );
		( new Outbox_Repository() )->add( $this->statement( $other_actor ), 5 );
		( new Inbox_Repository() )->store( $this->statement( $other_actor ), 'webhook' );
		( new Waiver_Ledger() )->record( $other_user, 22, Uuid::v4(), Uuid::v4(), 'Tested Out', false );

		$result = ( new Connector_Eraser() )->erase( $this->email, 1 );

		$this->assertTrue( $result['done'] );
		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( array(), $result['messages'] );
		foreach ( array( 'identity_links', 'waivers', 'applied', 'registrations' ) as $t ) {
			$this->assertSame( 0, $this->count_rows( $t, 'user_id = ' . $this->user ), $t );
		}
		$this->assertSame( 1, $this->count_rows( 'waivers' ), 'the other learner keeps their waiver' );
		$this->assertSame( 1, $this->count_rows( 'conn_inbox' ), 'only the other learner inbox row is left' );
		$this->assertSame( 1, $this->count_rows( 'conn_outbox' ), 'only the other learner outbox row is left' );
		$this->assertSame( '', (string) get_user_meta( $this->user, Identity_Map::META, true ) );
		$this->assertInstanceOf( WP_User::class, get_userdata( $this->user ), 'the WordPress user is WordPress\'s to erase' );

		$erase = array_values( array_filter( $this->requests, static fn( $r ) => str_ends_with( $r['url'], '/actors/erase' ) ) );
		$this->assertCount( 2, $erase );
		$this->assertSame( self::API . 'actors/erase', $erase[0]['url'] );
		$this->assertSame( 'POST', $erase[0]['args']['method'] );
		$this->assertSame(
			array(
				'actor'        => $this->site_actor,
				'revokeBadges' => true,
			),
			json_decode( $erase[0]['args']['body'], true ),
			'the WordPress privacy eraser revokes claimed badges'
		);
		$this->assertSame(
			array(
				'actor'        => $this->linked,
				'revokeBadges' => true,
			),
			json_decode( $erase[1]['args']['body'], true )
		);
	}

	public function test_a_failed_lrs_erase_is_queued_and_reported() {
		$this->seed( $this->user, $this->site_actor, $this->linked );
		$this->handler = fn() => $this->json_reply( array(), 503 );

		$result = ( new Connector_Eraser() )->erase( $this->email, 1 );

		$this->assertTrue( $result['done'] );
		$this->assertTrue( $result['items_removed'], 'local rows are gone' );
		$this->assertTrue( $result['items_retained'], 'the LRS still holds the statements until the retry' );
		$this->assertCount( 2, $result['messages'] );
		$this->assertStringContainsString( 'will be retried', $result['messages'][0] );
		$this->assertSame( 0, $this->count_rows( 'identity_links', 'user_id = ' . $this->user ) );
		global $wpdb;
		$jobs = $wpdb->get_results( $wpdb->prepare( 'SELECT payload FROM ' . Tables::name( 'jobs' ) . ' WHERE type = %s ORDER BY id', Learners_Controller::ERASE_JOB ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->assertCount( 2, $jobs );
		$this->assertSame( array( 'actor' => $this->site_actor ), json_decode( $jobs[0]->payload, true ) );
		$this->assertSame( array( 'actor' => $this->linked ), json_decode( $jobs[1]->payload, true ) );

		// Course_Server_Module registers this handler in a full boot.
		add_action( 'learning_ledger/jobs/' . Learners_Controller::ERASE_JOB, array( Learners_Controller::class, 'erase_job' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->handler  = fn() => $this->json_reply( array() );
		$this->requests = array();
		do_action( 'learning_ledger/jobs/' . Learners_Controller::ERASE_JOB, array( 'actor' => $this->site_actor ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertCount( 1, $this->requests, 'the existing retry job handler calls the LRS again' );
	}

	public function test_eraser_for_an_unknown_email_does_nothing() {
		$result = ( new Connector_Eraser() )->erase( 'nobody@example.org', 1 );

		$this->assertSame(
			array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$result
		);
		$this->assertSame( array(), $this->requests );
	}

	public function test_eraser_with_nothing_held_makes_no_lrs_call() {
		$user   = self::factory()->user->create( array( 'user_email' => 'fresh@example.org' ) );
		$result = ( new Connector_Eraser() )->erase( 'fresh@example.org', 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', (string) get_user_meta( $user, Identity_Map::META, true ), 'no actor is minted for an erase' );
	}
}
