<?php
/**
 * Delivery enqueuer tests: outbox rows in the storing transaction, sending only at shutdown.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Notify\Notify_Module;
use LearningLedger\Lrs\Notify\Subscription_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Queue\Shutdown_Runner;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;

class Delivery_Enqueuer_Test extends WP_UnitTestCase {

	const URL = 'https://example.org/wp-json/learning-ledger/v1/inbox';

	private array $requests = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'subscriptions', 'outbox', 'jobs' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$this->requests = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		remove_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) );
		( new Notify_Module() )->register();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) );
		Clock::freeze( null );
		parent::tear_down();
	}

	public function fake_http( $pre, array $args, string $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return array(
			'headers'  => array(),
			'body'     => '{"stored":1}',
			'response' => array(
				'code'    => 202,
				'message' => 'Accepted',
			),
			'cookies'  => array(),
		);
	}

	private static function principal(): Principal {
		$c = Client_Factory::create( array( 'statements/write' ) );
		return Principal::for_client( ( new Client_Repository() )->find( $c['id'] ) );
	}

	private static function statement( string $activity ): array {
		return array(
			'actor'  => array(
				'objectType' => 'Agent',
				'account'    => array(
					'homePage' => 'https://lms.example',
					'name'     => '7',
				),
			),
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object' => array(
				'objectType' => 'Activity',
				'id'         => $activity,
			),
		);
	}

	private static function outbox(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT subscription_id, seq, status, attempts, event_id FROM ' . Tables::name( 'outbox' ) . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_one_outbox_row_per_matching_subscription_per_statement() {
		$repo  = new Subscription_Repository();
		$all   = $repo->create( self::URL, array() );
		$one   = $repo->create( self::URL, array( 'activity_prefix' => 'https://lms.example/course/1/' ) );
		$other = $repo->create( self::URL, array( 'activity_prefix' => 'https://elsewhere.example/' ) );

		( new Statement_Store() )->store(
			array( self::statement( 'https://lms.example/course/1/a' ), self::statement( 'https://lms.example/course/2/b' ) ),
			self::principal(),
			'2.0.0'
		);

		global $wpdb;
		$seqs = array_map( 'intval', $wpdb->get_col( 'SELECT seq FROM ' . Tables::name( 'statements' ) . ' ORDER BY seq' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = self::outbox();
		$this->assertCount( 3, $rows );
		$pairs = array_map( static fn( $r ) => array( (int) $r['subscription_id'], (int) $r['seq'] ), $rows );
		sort( $pairs );
		$expected = array( array( $all, $seqs[0] ), array( $all, $seqs[1] ), array( $one, $seqs[0] ) );
		sort( $expected );
		$this->assertSame( $expected, $pairs );
		foreach ( $rows as $r ) {
			$this->assertSame( 'pending', $r['status'] );
			$this->assertSame( 0, (int) $r['attempts'] );
			$this->assertNull( $r['event_id'] );
			$this->assertNotSame( $other, (int) $r['subscription_id'] );
		}
	}

	public function test_no_subscription_no_rows_and_no_shutdown_work() {
		( new Statement_Store() )->store( array( self::statement( 'https://lms.example/course/1/a' ) ), self::principal(), '2.0.0' );
		$this->assertSame( array(), self::outbox() );
		$this->assertFalse( has_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) ) );
	}

	public function test_nothing_is_sent_during_the_storing_request_only_at_shutdown() {
		( new Subscription_Repository() )->create( self::URL, array() );

		$started = microtime( true );
		( new Statement_Store() )->store( array( self::statement( 'https://lms.example/course/1/a' ) ), self::principal(), '2.0.0' );

		$this->assertSame( array(), $this->requests, 'No HTTP call in the storing request.' );
		$this->assertNotFalse( has_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) ) );
		$this->assertSame( 'pending', self::outbox()[0]['status'] );

		Shutdown_Runner::on_shutdown();

		// The shutdown run waits out the one-second settle time, then sends.
		$this->assertGreaterThanOrEqual( 1.0, microtime( true ) - $started );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( self::URL, $this->requests[0]['url'] );
		$this->assertSame( 'delivered', self::outbox()[0]['status'] );
	}

	public function test_rolled_back_store_leaves_no_outbox_rows() {
		( new Subscription_Repository() )->create( self::URL, array() );
		add_action(
			'learning_ledger/statements_stored',
			static function () {
				throw new RuntimeException( 'boom' );
			},
			20
		);
		try {
			( new Statement_Store() )->store( array( self::statement( 'https://lms.example/course/1/a' ) ), self::principal(), '2.0.0' );
			$this->fail( 'Expected the store to fail.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertSame( array(), self::outbox() );
	}
}
