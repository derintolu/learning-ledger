<?php
/**
 * Webhook sender tests: envelopes, signatures, retries, failing state and recovery.
 *
 * @package LearningLedger
 */

use LearningLedger\Crypto\Notification_Signature;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Kernel\Capabilities;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Http\Xapi_Router;
use LearningLedger\Lrs\Notify\Notify_Module;
use LearningLedger\Lrs\Notify\Subscription_Repository;
use LearningLedger\Lrs\Notify\Webhook_Sender;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Queue\Shutdown_Runner;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Tests\Xapi_Test_Client;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

class Webhook_Sender_Test extends WP_UnitTestCase {

	const URL = 'https://connector.example/wp-json/learning-ledger/v1/inbox';

	private array $requests = array();

	private int $status = 202;

	private ?Principal $principal = null;

	private \DateTimeImmutable $t0;

	public function set_up() {
		parent::set_up();
		Installer::install();
		Capabilities::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'subscriptions', 'outbox', 'jobs', 'audit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE purpose = %s', Tables::name( 'keys' ), 'notify' ) );
		$this->requests  = array();
		$this->status    = 202;
		$this->principal = null;
		$this->t0        = new DateTimeImmutable( '2026-10-27T10:00:00.000000Z' );
		Clock::freeze( $this->t0 );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		( new Notify_Module() )->register();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) );
		Clock::freeze( null );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	public function fake_http( $pre, array $args, string $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => $this->status,
				'message' => 'x',
			),
			'cookies'  => array(),
		);
	}

	private function store( int $n, string $padding = '' ): void {
		if ( null === $this->principal ) {
			$c               = Client_Factory::create( array( 'statements/write' ) );
			$this->principal = Principal::for_client( ( new Client_Repository() )->find( $c['id'] ) );
		}
		$statements = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$st = array(
				'actor'  => array(
					'objectType' => 'Agent',
					'account'    => array(
						'homePage' => 'https://lms.example',
						'name'     => (string) $i,
					),
				),
				'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
				'object' => array(
					'objectType' => 'Activity',
					'id'         => 'https://lms.example/course/1/lesson/' . $i,
				),
			);
			if ( '' !== $padding ) {
				$st['result'] = array( 'extensions' => array( 'https://lms.example/ext/pad' => $padding ) );
			}
			$statements[] = $st;
		}
		( new Statement_Store() )->store( $statements, $this->principal, '2.0.0' );
	}

	private static function bodies(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT seq, body FROM ' . Tables::name( 'statements' ) . ' ORDER BY seq', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function outbox(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . Tables::name( 'outbox' ) . ' ORDER BY seq', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function next_attempt(): \DateTimeImmutable {
		return Timestamp::from_db( (string) self::outbox()[0]['next_attempt_at'] );
	}

	private function envelope( int $i ): array {
		return json_decode( $this->requests[ $i ]['args']['body'], true );
	}

	private function about_key(): array {
		( new Xapi_Router() )->register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
		$body = json_decode( Xapi_Test_Client::request( 'GET', 'about' )->body(), true );
		return $body['extensions']['https://github.com/derintolu/learning-ledger']['notifications'];
	}

	private function notice(): string {
		ob_start();
		( new Notify_Module() )->render_notices();
		return (string) ob_get_clean();
	}

	public function test_150_statements_go_as_two_signed_envelopes_in_order() {
		$sub = ( new Subscription_Repository() )->create( self::URL, array() );
		$this->store( 150 );
		$this->assertSame( array(), $this->requests );

		$this->assertSame( 2, ( new Webhook_Sender() )->run() );

		$this->assertCount( 2, $this->requests );
		$about  = $this->about_key();
		$public = base64_decode( $about['public_key'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$rows   = self::bodies();
		$sent   = array();
		foreach ( array( 0, 1 ) as $i ) {
			$req     = $this->requests[ $i ];
			$headers = $req['args']['headers'];
			$this->assertSame( self::URL, $req['url'] );
			$this->assertSame( 5, $req['args']['timeout'] );
			$this->assertSame( 0, $req['args']['redirection'] );
			$this->assertTrue( $req['args']['reject_unsafe_urls'] );
			$this->assertSame( (string) $this->t0->getTimestamp(), $headers['X-Learning-Ledger-Timestamp'] );
			$this->assertSame( $about['kid'], $headers['X-Learning-Ledger-Key'] );
			$this->assertTrue( Notification_Signature::verify( $headers['X-Learning-Ledger-Signature'], $headers['X-Learning-Ledger-Timestamp'], $req['args']['body'], $public ) );
			$this->assertFalse( Notification_Signature::verify( $headers['X-Learning-Ledger-Signature'], $headers['X-Learning-Ledger-Timestamp'], $req['args']['body'] . ' ', $public ) );

			$env = $this->envelope( $i );
			$this->assertSame( array( 'event_id', 'subscription_id', 'cursor', 'statements' ), array_keys( $env ) );
			$this->assertTrue( Uuid::is_valid( $env['event_id'] ) );
			$this->assertSame( '4', $env['event_id'][14] );
			$this->assertSame( $sub, $env['subscription_id'] );
			$sent[] = $env;
		}
		$this->assertCount( 100, $sent[0]['statements'] );
		$this->assertCount( 50, $sent[1]['statements'] );
		$this->assertNotSame( $sent[0]['event_id'], $sent[1]['event_id'] );
		$this->assertSame( (int) $rows[99]['seq'], $sent[0]['cursor'] );
		$this->assertSame( (int) $rows[149]['seq'], $sent[1]['cursor'] );

		// Exact bodies, in seq order.
		$all = array_merge( $sent[0]['statements'], $sent[1]['statements'] );
		foreach ( $rows as $k => $row ) {
			$this->assertSame( json_decode( $row['body'], true )['id'], $all[ $k ]['id'] );
			$this->assertStringContainsString( $row['body'], $this->requests[ $k < 100 ? 0 : 1 ]['args']['body'] );
		}

		foreach ( self::outbox() as $o ) {
			$this->assertSame( 'delivered', $o['status'] );
			$this->assertNotNull( $o['delivered_at'] );
		}
		$this->assertSame( (int) $rows[149]['seq'], ( new Subscription_Repository() )->find( $sub )['cursor'] );
		$this->assertSame( 0, ( new Webhook_Sender() )->run() );
		$this->assertCount( 2, $this->requests );
	}

	public function test_500_is_retried_after_60_seconds_with_the_same_event_and_not_lost() {
		( new Subscription_Repository() )->create( self::URL, array() );
		$this->store( 2 );
		$this->status = 500;

		$this->assertSame( 1, ( new Webhook_Sender() )->run() );
		$first = $this->envelope( 0 );
		foreach ( self::outbox() as $o ) {
			$this->assertSame( 'pending', $o['status'] );
			$this->assertSame( 1, (int) $o['attempts'] );
		}
		$this->assertSame( $this->t0->getTimestamp() + 60, self::next_attempt()->getTimestamp() );

		Clock::freeze( $this->t0->modify( '+59 seconds' ) );
		$this->assertSame( 0, ( new Webhook_Sender() )->run() );
		$this->assertCount( 1, $this->requests );

		Clock::freeze( $this->t0->modify( '+60 seconds' ) );
		$this->status = 202;
		$this->assertSame( 1, ( new Webhook_Sender() )->run() );
		$this->assertCount( 2, $this->requests );
		$again = $this->envelope( 1 );
		$this->assertSame( $first['event_id'], $again['event_id'] );
		$this->assertSame( $first['statements'], $again['statements'] );
		foreach ( self::outbox() as $o ) {
			$this->assertSame( 'delivered', $o['status'] );
		}
	}

	public function test_timeout_is_a_failure() {
		( new Subscription_Repository() )->create( self::URL, array() );
		$this->store( 1 );
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5000 milliseconds' ) );

		( new Webhook_Sender() )->run();

		$this->assertSame( 'pending', self::outbox()[0]['status'] );
		$this->assertSame( 1, (int) self::outbox()[0]['attempts'] );
	}

	public function test_409_from_the_inbox_counts_as_delivered() {
		$sub = ( new Subscription_Repository() )->create( self::URL, array() );
		$this->store( 1 );
		$this->status = 409;

		( new Webhook_Sender() )->run();

		$this->assertSame( 'delivered', self::outbox()[0]['status'] );
		$this->assertNull( ( new Subscription_Repository() )->find( $sub )['failing_since'] );
	}

	public function test_later_statements_wait_behind_a_failed_batch() {
		( new Subscription_Repository() )->create( self::URL, array() );
		$this->store( 1 );
		$this->status = 503;
		( new Webhook_Sender() )->run();

		$this->store( 1 );
		$this->assertSame( 0, ( new Webhook_Sender() )->run(), 'The new statement is not sent ahead of the failed one.' );
		$this->assertCount( 1, $this->requests );

		Clock::freeze( $this->t0->modify( '+60 seconds' ) );
		$this->status = 202;
		$this->assertSame( 2, ( new Webhook_Sender() )->run() );
		$rows = self::bodies();
		$this->assertSame( $this->envelope( 0 )['event_id'], $this->envelope( 1 )['event_id'] );
		$this->assertSame( (int) $rows[0]['seq'], $this->envelope( 1 )['cursor'] );
		$this->assertCount( 1, $this->envelope( 1 )['statements'] );
		$this->assertSame( (int) $rows[1]['seq'], $this->envelope( 2 )['cursor'] );
	}

	public function test_72_hours_of_failures_mark_failing_with_notice_and_recovery_sets_active() {
		$sub = ( new Subscription_Repository() )->create( self::URL, array() );
		$this->store( 1 );
		$this->status = 500;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$t      = $this->t0;
		$delays = array();
		for ( $i = 0; $i < 100; $i++ ) {
			Clock::freeze( $t );
			$this->assertSame( 1, ( new Webhook_Sender() )->run() );
			$state = ( new Subscription_Repository() )->find( $sub );
			if ( $t->getTimestamp() - $this->t0->getTimestamp() >= 72 * HOUR_IN_SECONDS ) {
				$this->assertSame( Subscription_Repository::FAILING, $state['status'] );
				break;
			}
			$this->assertSame( Subscription_Repository::ACTIVE, $state['status'], 'Still active after ' . ( $t->getTimestamp() - $this->t0->getTimestamp() ) . ' s' );
			$this->assertSame( '', $this->notice() );
			$next     = self::next_attempt();
			$delays[] = $next->getTimestamp() - $t->getTimestamp();
			$t        = $next;
		}
		$this->assertSame( array( 60, 300, 900, 3600, 21600, 21600 ), array_slice( $delays, 0, 6 ) );
		$this->assertSame( 'pending', self::outbox()[0]['status'] );
		$this->assertStringContainsString( 'notice-error', $this->notice() );
		$this->assertStringContainsString( esc_html( self::URL ), $this->notice() );
		$this->assertContains( 'subscription.failing', $GLOBALS['wpdb']->get_col( 'SELECT event FROM ' . Tables::name( 'audit' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', $this->notice() );

		// Failing subscriptions keep being tried every 6 hours; a success makes them active again.
		$this->assertSame( 21600, self::next_attempt()->getTimestamp() - $t->getTimestamp() );
		Clock::freeze( self::next_attempt() );
		$this->status = 202;
		$this->assertSame( 1, ( new Webhook_Sender() )->run() );
		$state = ( new Subscription_Repository() )->find( $sub );
		$this->assertSame( Subscription_Repository::ACTIVE, $state['status'] );
		$this->assertNull( $state['failing_since'] );
		$this->assertSame( 'delivered', self::outbox()[0]['status'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( '', $this->notice() );
	}

	public function test_envelopes_are_capped_at_2_mb() {
		( new Subscription_Repository() )->create( self::URL, array() );
		$pad = str_repeat( 'x', 800000 );
		$this->store( 1, $pad );
		$this->store( 1, $pad );
		$this->store( 1, $pad );

		$this->assertSame( 2, ( new Webhook_Sender() )->run() );
		$this->assertCount( 2, $this->envelope( 0 )['statements'] );
		$this->assertCount( 1, $this->envelope( 1 )['statements'] );
		foreach ( $this->requests as $req ) {
			$this->assertLessThanOrEqual( 2097152, strlen( $req['args']['body'] ) );
		}
	}

	public function test_private_target_is_never_contacted() {
		global $wpdb;
		$wpdb->insert(
			Tables::name( 'subscriptions' ),
			array(
				'target_url' => 'https://169.254.169.254/latest/meta-data',
				'filters'    => '{}',
				'status'     => 'active',
				'cursor_seq' => 0,
				'created_at' => Timestamp::to_db( $this->t0 ),
			)
		);
		$this->store( 1 );

		( new Webhook_Sender() )->run();

		$this->assertSame( array(), $this->requests );
		$this->assertSame( 'pending', self::outbox()[0]['status'] );
		$this->assertSame( 1, (int) self::outbox()[0]['attempts'] );
	}

	public function test_cron_minute_sends_as_catch_up() {
		( new Subscription_Repository() )->create( self::URL, array() );
		$this->store( 1 );
		do_action( 'learning_ledger/cron_minute' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertCount( 1, $this->requests );
	}
}
