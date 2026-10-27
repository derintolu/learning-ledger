<?php
/**
 * Inbox webhook route tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixtures.

use LearningLedger\Connector\Inbox\Catch_Up_Poller;
use LearningLedger\Connector\Inbox\Inbox_Module;
use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Notification_Signature;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Queue\Shutdown_Runner;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;

class Inbox_Route_Test extends WP_UnitTestCase {

	/** @var array{public:string,secret:string} */
	private array $keys;

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		global $wpdb, $wp_rest_server;
		foreach ( array( 'conn_inbox', 'conn_events', 'conn_cursor' ) as $t ) {
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
		( new Inbox_Module() )->register();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Clock::freeze( null );
		parent::tear_down();
	}

	private function statement( ?string $id = null ): array {
		return array(
			'id'      => $id ?? Uuid::v4(),
			'actor'   => array(
				'objectType' => 'Agent',
				'mbox'       => 'mailto:learner@example.org',
			),
			'verb'    => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object'  => array(
				'objectType' => 'Activity',
				'id'         => 'https://example.org/act/1',
			),
			'context' => array( 'registration' => Uuid::v4() ),
			'stored'  => '2026-10-27T10:00:00.123456Z',
		);
	}

	private function body( array $statements, ?string $event_id = null ): string {
		return (string) wp_json_encode(
			array(
				'event_id'        => $event_id ?? Uuid::v4(),
				'subscription_id' => 'sub-1',
				'cursor'          => 42,
				'statements'      => $statements,
			)
		);
	}

	private function send( string $body, ?int $ts = null, string $kid = 'notify-kid-1', ?string $signed_body = null ): WP_REST_Response {
		$ts  = $ts ?? Clock::now()->getTimestamp();
		$req = new WP_REST_Request( 'POST', '/learning-ledger/v1/inbox' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_header( 'X-Learning-Ledger-Timestamp', (string) $ts );
		$req->set_header( 'X-Learning-Ledger-Key', $kid );
		$req->set_header( 'X-Learning-Ledger-Signature', Notification_Signature::sign( $signed_body ?? $body, $this->keys['secret'], $ts ) );
		$req->set_body( $body );
		return rest_get_server()->dispatch( $req );
	}

	private function inbox_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'conn_inbox' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public function test_valid_signature_returns_202_and_stores_rows() {
		$a   = $this->statement();
		$b   = $this->statement();
		$res = $this->send( $this->body( array( $a, $b, $a ) ) );

		$this->assertSame( 202, $res->get_status() );
		$this->assertSame( 2, $this->inbox_count(), 'deduplicated by statement id' );
		$rows = ( new Inbox_Repository() )->pending( 10 );
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'webhook', 'webhook' ), array_column( $rows, 'source' ) );
		$this->assertEqualsCanonicalizing( array( $a['id'], $b['id'] ), array_column( $rows, 'statement_id' ) );
		$this->assertSame( $a['context']['registration'], $this->find( $rows, $a['id'] )['registration'] );
		$this->assertSame( '2026-10-27 10:00:00.123456', $this->find( $rows, $a['id'] )['stored'] );
		$this->assertSame( $a, $this->find( $rows, $a['id'] )['statement'] );
		$this->assertNotFalse( has_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) ), 'processing is scheduled for shutdown' );
	}

	public function test_delivery_after_a_failed_poll_runs_the_poll_at_shutdown() {
		Connector_Settings::update(
			array(
				'lrs_url'   => 'https://lrs.example.test/xapi/',
				'client_id' => 'connector-site',
			)
		);
		Connector_Secret::generate();
		$urls = array();
		$http = static function ( $pre, $args, $url ) use ( &$urls ) {
			$urls[] = $url;
			return array(
				'headers'  => array(),
				'body'     => '{"statements":[],"more":""}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $http, 10, 3 );

		$poller = new Catch_Up_Poller();
		$poller->need_recovery();
		$this->assertSame( 202, $this->send( $this->body( array( $this->statement() ) ) )->get_status() );
		$this->assertSame( array(), $urls, 'nothing is fetched during the request' );
		Shutdown_Runner::on_shutdown();
		remove_filter( 'pre_http_request', $http, 10 );

		$this->assertCount( 1, $urls );
		$this->assertStringStartsWith( 'https://lrs.example.test/xapi/statements?', $urls[0] );
		$this->assertFalse( $poller->recovery_needed() );
	}

	public function test_insert_error_is_503_and_the_event_can_be_redelivered() {
		global $wpdb;
		$good  = $this->statement();
		$bad   = $this->statement();
		$event = Uuid::v4();
		$body  = $this->body( array( $good, $bad ), $event );
		$break = static function ( $q ) use ( $bad ) {
			return false !== strpos( $q, 'INSERT IGNORE INTO' ) && false !== strpos( $q, $bad['id'] ) ? 'SELECT lledger_broken(' : $q;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		$res      = $this->send( $body );
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );

		$this->assertSame( 503, $res->get_status() );
		$this->assertTrue( ( new Catch_Up_Poller() )->recovery_needed() );
		$this->assertSame( 202, $this->send( $body )->get_status(), 'the event claim was released' );
		$this->assertSame( 2, $this->inbox_count() );
	}

	public function test_body_over_two_megabytes_is_413() {
		$big           = $this->statement();
		$big['result'] = array( 'response' => str_repeat( 'a', 2 * 1024 * 1024 ) );
		$this->assertSame( 413, $this->send( $this->body( array( $big ) ) )->get_status() );
		$this->assertSame( 0, $this->inbox_count() );
	}

	public function test_tampered_body_is_401_and_stores_nothing() {
		$body     = $this->body( array( $this->statement() ) );
		$tampered = str_replace( 'learner@example.org', 'attacker@example.org', $body );
		$res      = $this->send( $tampered, null, 'notify-kid-1', $body );

		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 0, $this->inbox_count() );
	}

	public function test_stale_timestamp_is_401() {
		$res = $this->send( $this->body( array( $this->statement() ) ), Clock::now()->getTimestamp() - 301 );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 0, $this->inbox_count() );

		$future = $this->send( $this->body( array( $this->statement() ) ), Clock::now()->getTimestamp() + 301 );
		$this->assertSame( 401, $future->get_status() );

		$edge = $this->send( $this->body( array( $this->statement() ) ), Clock::now()->getTimestamp() - 300 );
		$this->assertSame( 202, $edge->get_status(), '300 s is inside the window' );
	}

	public function test_replayed_event_id_is_409() {
		$event = Uuid::v4();
		$this->assertSame( 202, $this->send( $this->body( array( $this->statement() ), $event ) )->get_status() );
		$again = $this->send( $this->body( array( $this->statement() ), $event ), Clock::now()->getTimestamp() - 5 );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 1, $this->inbox_count(), 'the replay stored nothing' );
	}

	public function test_wrong_key_id_or_missing_headers_is_401() {
		$this->assertSame( 401, $this->send( $this->body( array( $this->statement() ) ), null, 'other-kid' )->get_status() );

		$req = new WP_REST_Request( 'POST', '/learning-ledger/v1/inbox' );
		$req->set_body( $this->body( array( $this->statement() ) ) );
		$this->assertSame( 401, rest_get_server()->dispatch( $req )->get_status() );

		$this->assertSame( 401, $this->send( $this->body( array( $this->statement() ) ), null, '' )->get_status() );
		$this->assertSame( 0, $this->inbox_count() );
	}

	public function test_unpaired_connector_is_401() {
		delete_option( Connector_Settings::OPTION );
		$this->assertSame( 401, $this->send( $this->body( array( $this->statement() ) ) )->get_status() );
	}

	public function test_signed_malformed_bodies_are_400_never_500() {
		$bodies = array(
			'not json',
			'[]',
			'{"event_id":"not-a-uuid","statements":[]}',
			'{"event_id":"' . Uuid::v4() . '","statements":"x"}',
			'{"event_id":"' . Uuid::v4() . '"}',
			'"string"',
		);
		foreach ( $bodies as $body ) {
			$this->assertSame( 400, $this->send( $body )->get_status(), $body );
		}

		$odd                  = $this->statement();
		$odd['verb']          = 'completed';
		$odd['actor']['mbox'] = array( 'x' );
		$odd2                 = $this->statement();
		$odd2['object']       = array( 'id' => array( 1 ) );
		$odd2['stored']       = 'yesterday';
		$odd3                 = $this->statement();
		$odd3['actor']        = array(
			'objectType' => 'Group',
			'member'     => array(),
		);
		$bad_rows             = $this->send( $this->body( array( 'x', array( 'id' => 'nope' ), array(), 7, null, $odd, $odd2, $odd3, $this->statement() ) ) );
		$this->assertSame( 202, $bad_rows->get_status(), 'invalid statements are skipped, valid ones kept' );
		$this->assertSame( 1, $this->inbox_count() );

		$bad_ts = new WP_REST_Request( 'POST', '/learning-ledger/v1/inbox' );
		$bad_ts->set_header( 'X-Learning-Ledger-Timestamp', '12abc' );
		$bad_ts->set_header( 'X-Learning-Ledger-Key', 'notify-kid-1' );
		$bad_ts->set_header( 'X-Learning-Ledger-Signature', '!!!' );
		$bad_ts->set_body( '{}' );
		$this->assertSame( 401, rest_get_server()->dispatch( $bad_ts )->get_status() );
	}

	private function find( array $rows, string $id ): array {
		foreach ( $rows as $row ) {
			if ( $row['statement_id'] === $id ) {
				return $row;
			}
		}
		$this->fail( 'row not found' );
	}
}
