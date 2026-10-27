<?php
/**
 * Outbox sender tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Outbox\Outbox_Module;
use LearningLedger\Connector\Outbox\Outbox_Repository;
use LearningLedger\Connector\Outbox\Outbox_Sender;
use LearningLedger\Connector\Pairing\Connector_Keys;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;

class Outbox_Sender_Test extends WP_UnitTestCase {

	/** @var array[] */
	private array $requests = array();

	/** @var int|WP_Error */
	private $reply = 204;

	/** @var array */
	private array $reply_headers = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'conn_outbox', 'audit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		delete_option( Connector_Settings::OPTION );
		delete_option( Connector_Secret::OPTION );
		delete_option( Connector_Keys::OPTION );
		Connector_Settings::update(
			array(
				'lrs_url'   => 'https://lrs.example.test/xapi/',
				'api_base'  => 'https://lrs.example.test/wp-json/learning-ledger/v1/',
				'client_id' => 'connector-site',
			)
		);
		Connector_Secret::generate();
		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:00:00.000000', new DateTimeZone( 'UTC' ) ) );
		$this->requests      = array();
		$this->reply         = 204;
		$this->reply_headers = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		Clock::freeze( null );
		parent::tear_down();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( $this->reply instanceof WP_Error ) {
			return $this->reply;
		}
		return array(
			'headers'  => array_merge( array( 'x-experience-api-version' => '1.0.3' ), $this->reply_headers ),
			'body'     => '',
			'response' => array(
				'code'    => $this->reply,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function statement(): array {
		return array(
			'id'     => Uuid::v4(),
			'actor'  => array(
				'objectType' => 'Agent',
				'account'    => array(
					'homePage' => 'https://site.test',
					'name'     => 'abc',
				),
			),
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object' => array(
				'objectType' => 'Activity',
				'id'         => 'https://site.test/activities/courses/5/lessons/7',
			),
		);
	}

	private function row( string $id ): array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( 'conn_outbox' ) . ' WHERE statement_id = UNHEX(%s)', bin2hex( Uuid::to_bin( $id ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	private function queue(): array {
		$s = $this->statement();
		$this->assertTrue( ( new Outbox_Repository() )->add( $s, 5 ) );
		return $s;
	}

	public function test_204_marks_sent_with_put_by_statement_id() {
		$s = $this->queue();

		$this->assertSame( 1, ( new Outbox_Sender() )->flush() );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'PUT', $this->requests[0]['args']['method'] );
		$this->assertSame( 'https://lrs.example.test/xapi/statements?statementId=' . $s['id'], $this->requests[0]['url'] );
		$this->assertSame( $s, json_decode( $this->requests[0]['args']['body'], true ) );
		$this->assertSame( 'application/json', $this->requests[0]['args']['headers']['Content-Type'] );
		$this->assertSame( 'sent', $this->row( $s['id'] )['status'] );

		$this->assertSame( 0, ( new Outbox_Sender() )->flush() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_500_is_retried_later_with_backoff() {
		$s           = $this->queue();
		$this->reply = 500;

		( new Outbox_Sender() )->flush();
		$row = $this->row( $s['id'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( '1', $row['attempts'] );
		$this->assertSame( '2026-10-27 10:01:00.000000', $row['next_attempt_at'] );

		( new Outbox_Sender() )->flush();
		$this->assertCount( 1, $this->requests, 'Not retried before the backoff.' );

		Clock::freeze( Clock::now()->modify( '+61 seconds' ) );
		( new Outbox_Sender() )->flush();
		$this->assertCount( 2, $this->requests );
		$row = $this->row( $s['id'] );
		$this->assertSame( '2', $row['attempts'] );
		$this->assertSame( '2026-10-27 10:06:01.000000', $row['next_attempt_at'] );

		Clock::freeze( Clock::now()->modify( '+301 seconds' ) );
		$this->reply = 204;
		( new Outbox_Sender() )->flush();
		$this->assertCount( 3, $this->requests );
		$this->assertSame( 'sent', $this->row( $s['id'] )['status'] );
	}

	public function test_timeout_is_retried() {
		$s           = $this->queue();
		$this->reply = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		( new Outbox_Sender() )->flush();
		$row = $this->row( $s['id'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( '1', $row['attempts'] );
	}

	public function test_409_marks_conflict_with_audit_row() {
		global $wpdb;
		$s           = $this->queue();
		$this->reply = 409;

		( new Outbox_Sender() )->flush();

		$this->assertSame( 'conflict', $this->row( $s['id'] )['status'] );
		$audit = $wpdb->get_row( 'SELECT event, data FROM ' . Tables::name( 'audit' ) . " WHERE event = 'outbox_conflict'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNotNull( $audit );
		$this->assertSame( $s['id'], json_decode( $audit['data'], true )['statement_id'] );

		( new Outbox_Sender() )->flush();
		$this->assertCount( 1, $this->requests );
	}

	public function test_other_4xx_marks_failed() {
		$s           = $this->queue();
		$this->reply = 400;

		( new Outbox_Sender() )->flush();
		$this->assertSame( 'failed', $this->row( $s['id'] )['status'] );

		Clock::freeze( Clock::now()->modify( '+1 day' ) );
		( new Outbox_Sender() )->flush();
		$this->assertCount( 1, $this->requests );
	}

	public function test_429_is_retried_no_earlier_than_retry_after() {
		$s                   = $this->queue();
		$this->reply         = 429;
		$this->reply_headers = array( 'retry-after' => '600' );

		( new Outbox_Sender() )->flush();
		$row = $this->row( $s['id'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( '1', $row['attempts'] );
		$this->assertGreaterThanOrEqual( '2026-10-27 10:10:00.000000', $row['next_attempt_at'] );

		Clock::freeze( Clock::now()->modify( '+301 seconds' ) );
		( new Outbox_Sender() )->flush();
		$this->assertCount( 1, $this->requests, 'Not retried before Retry-After.' );
	}

	public function test_429_without_retry_after_uses_backoff() {
		$s           = $this->queue();
		$this->reply = 429;

		( new Outbox_Sender() )->flush();
		$row = $this->row( $s['id'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( '2026-10-27 10:01:00.000000', $row['next_attempt_at'] );
	}

	public function test_408_is_retried() {
		$s           = $this->queue();
		$this->reply = 408;

		( new Outbox_Sender() )->flush();
		$row = $this->row( $s['id'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( '1', $row['attempts'] );
	}

	public function test_gives_up_after_72_hours() {
		$s           = $this->queue();
		$this->reply = 503;
		Clock::freeze( Clock::now()->modify( '+72 hours' ) );

		( new Outbox_Sender() )->flush();
		$this->assertSame( 'failed', $this->row( $s['id'] )['status'] );
	}

	public function test_flush_respects_limit() {
		$this->queue();
		$this->queue();
		$this->queue();
		$this->assertSame( 2, ( new Outbox_Sender() )->flush( 2 ) );
		$this->assertCount( 2, $this->requests );
	}

	public function test_runs_at_cron_minute() {
		$s = $this->queue();
		( new Outbox_Module() )->register();
		do_action( 'learning_ledger/cron_minute' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertSame( 'sent', $this->row( $s['id'] )['status'] );
	}

	public function test_unpaired_sends_nothing_and_keeps_rows() {
		$s = $this->queue();
		delete_option( Connector_Secret::OPTION );

		$this->assertSame( 0, ( new Outbox_Sender() )->flush() );
		$this->assertCount( 0, $this->requests );
		$row = $this->row( $s['id'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( '0', $row['attempts'] );
	}
}
