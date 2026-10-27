<?php
/**
 * Pause guard and version gate tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Lms\Activity_Ref;
use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Guarded_Adapter;
use LearningLedger\Connector\Lms\Integration_Descriptor;
use LearningLedger\Connector\Lms\Integration_State;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Tests\Fake_Adapter;
use LearningLedger\Tests\Recording_Sink;

class Guarded_Adapter_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'lledger_integration_state' );
	}

	private function result(): Result {
		return new Result( Result::LESSON_COMPLETED, 1, new Lms_Object( 'lesson', 5 ), 'r', 's' );
	}

	private function descriptor( string $tested_up_to, string $version ): Integration_Descriptor {
		return new Integration_Descriptor( 'fake', static fn() => true, static fn() => new Fake_Adapter(), '1.0', $tested_up_to, static fn() => $version );
	}

	public function test_throwable_pauses_and_defers() {
		$inner        = new Fake_Adapter();
		$inner->throw = true;
		$guard        = new Guarded_Adapter( $inner );

		$this->assertSame( Apply_Outcome::DEFERRED, $guard->apply( $this->result() )->status );
		$this->assertSame( 'paused', Integration_State::status( 'fake' ) );

		$inner->throw = false;
		$this->assertSame( Apply_Outcome::DEFERRED, $guard->apply( $this->result() )->status );
		$this->assertSame( array(), $inner->applied, 'second apply is not forwarded while paused' );
	}

	public function test_safe_values_on_throwable() {
		$inner        = new Fake_Adapter();
		$inner->throw = true;
		$guard        = new Guarded_Adapter( $inner );
		$this->assertNull( $guard->resolve( new Activity_Ref( 'x' ) ) );
		$this->assertFalse( $guard->enrol( 1, 2 ) );
		$progress = $guard->progress( 1, 2 );
		$this->assertSame( array(), $progress->completed_lesson_ids );
		$this->assertFalse( $progress->course_completed );
		$this->assertNull( $guard->certificate_pdf( 1, 2 ) );
	}

	public function test_register_event_listeners_delegates_while_paused() {
		$inner = new Fake_Adapter();
		$guard = new Guarded_Adapter( $inner );
		Integration_State::pause( 'fake', 'test' );
		$sink = new Recording_Sink();
		$guard->register_event_listeners( $sink );
		$course = $inner->upsert_course( new LearningLedger\Connector\Lms\Course_Spec( 'k', 't', '', 'publish', array() ) );
		$inner->enrol( 1, $course );
		$this->assertCount( 1, $sink->events );
	}

	public function test_resume_restores_forwarding() {
		$inner = new Fake_Adapter();
		$guard = new Guarded_Adapter( $inner );
		Integration_State::pause( 'fake', 'test' );
		Integration_State::resume( 'fake' );
		$guard->apply( $this->result() );
		$this->assertCount( 1, $inner->applied );
	}

	public function test_state_option_not_autoloaded() {
		Integration_State::pause( 'fake', 'test' );
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'lledger_integration_state' ) );
		$this->assertContains( $autoload, array( 'off', 'no', 'auto-off' ) );
	}

	public function test_major_version_gate() {
		$this->assertSame( 'paused', Integration_State::gate( $this->descriptor( '4.26', '5.0.0' ) ) );
		Integration_State::confirm_major( 'fake', '5.0.0' );
		$this->assertSame( 'active', Integration_State::gate( $this->descriptor( '4.26', '5.0.0' ) ) );
		$this->assertSame( 'active', Integration_State::status( 'fake' ) );
	}

	public function test_minor_version_runs_with_notice() {
		$this->assertSame( 'active', Integration_State::gate( $this->descriptor( '4.26', '4.27.1' ) ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin )->add_cap( 'lledger_manage_connector' );
		wp_set_current_user( $admin );
		ob_start();
		do_action( 'admin_notices' );
		$out = ob_get_clean();
		$this->assertStringContainsString( 'fake', $out );
		$this->assertStringContainsString( '4.27.1', $out );
	}

	public function test_notice_hidden_without_capability() {
		Integration_State::pause( 'fake', 'boom' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		do_action( 'admin_notices' );
		$this->assertStringNotContainsString( 'boom', ob_get_clean() );
	}

	public function test_throwable_pause_survives_gate() {
		Integration_State::pause( 'fake', 'boom' );
		$this->assertSame( 'paused', Integration_State::gate( $this->descriptor( '4.26', '4.26.1' ) ) );
	}
}
