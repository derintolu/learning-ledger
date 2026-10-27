<?php
/**
 * Integration loader tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\Integration_State;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Tests\Recording_Sink;

class Integration_Loader_Test extends WP_UnitTestCase {

	/** @var Recording_Sink */
	private $sink;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'lledger_integration_state' );
		unset( $GLOBALS['lledger_fakelms_unavailable'] );
		$this->sink = new Recording_Sink();
		add_filter( 'learning_ledger/integration_dirs', array( $this, 'dirs' ) );
		add_filter( 'learning_ledger/event_sink', array( $this, 'sink' ) );
		add_filter( 'learning_ledger/adapter_post_types', array( $this, 'post_types' ) );
	}

	public function tear_down(): void {
		unset( $GLOBALS['lledger_fakelms_unavailable'] );
		parent::tear_down();
	}

	public function dirs() {
		return array( dirname( __DIR__, 2 ) . '/fixtures/integrations' );
	}

	public function sink() {
		return $this->sink;
	}

	public function post_types() {
		return array( 'fake' => array( 'fake_lesson' ) );
	}

	private function load(): void {
		$loader = new Integration_Loader();
		$loader->load();
	}

	public function test_loads_only_available_and_only_with_connector_role() {
		update_option( 'lledger_roles', array( 'lrs' ) );
		$this->load();
		$this->assertSame( array(), Integration_Loader::active(), 'role off: nothing loaded' );
		$this->assertNull( Integration_Loader::adapter( 'fake' ) );

		update_option( 'lledger_roles', array( 'connector' ) );
		$GLOBALS['lledger_fakelms_unavailable'] = true;
		$this->load();
		$this->assertSame( array(), Integration_Loader::active(), 'unavailable: not loaded' );

		unset( $GLOBALS['lledger_fakelms_unavailable'] );
		$this->load();
		$this->assertCount( 1, Integration_Loader::active() );
		$adapter = Integration_Loader::adapter( 'fake' );
		$this->assertInstanceOf( \LearningLedger\Connector\Lms\Guarded_Adapter::class, $adapter );
	}

	public function test_register_hooks_plugins_loaded_at_20() {
		$loader = new Integration_Loader();
		$loader->register();
		$this->assertSame( 20, has_action( 'plugins_loaded', array( $loader, 'load' ) ) );
	}

	public function test_sink_receives_events_and_paused_adapter_is_not_active() {
		update_option( 'lledger_roles', array( 'connector' ) );
		$this->load();
		$adapter = Integration_Loader::adapter( 'fake' );
		$course  = $adapter->upsert_course( new \LearningLedger\Connector\Lms\Course_Spec( 'k', 't', '', 'publish', array() ) );
		$adapter->enrol( 1, $course );
		$this->assertCount( 1, $this->sink->events );

		Integration_State::pause( 'fake', 'test' );
		$this->assertSame( array(), Integration_Loader::active() );
		$result = new Result( Result::LESSON_COMPLETED, 1, new Lms_Object( 'lesson', 1 ), 'r', 's' );
		$this->assertSame( Apply_Outcome::DEFERRED, $adapter->apply( $result )->status );
		$adapter->enrol( 1, $course );
		$this->assertCount( 2, $this->sink->events, 'events keep queueing while paused' );
	}

	public function test_for_post_matches_post_type() {
		update_option( 'lledger_roles', array( 'connector' ) );
		$this->load();
		register_post_type( 'fake_lesson' );
		$id = self::factory()->post->create( array( 'post_type' => 'fake_lesson' ) );
		$this->assertSame( Integration_Loader::adapter( 'fake' ), Integration_Loader::for_post( $id ) );
		$other = self::factory()->post->create();
		$this->assertNull( Integration_Loader::for_post( $other ) );
		unregister_post_type( 'fake_lesson' );
	}
}
