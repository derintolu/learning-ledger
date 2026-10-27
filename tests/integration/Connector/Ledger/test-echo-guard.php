<?php
/**
 * Echo guard tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Ledger\Echo_Guard;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Uuid;

class Echo_Guard_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'applied' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_active_only_inside_callback_and_returns_value() {
		$this->assertFalse( Echo_Guard::active() );
		$seen = Echo_Guard::while_applying(
			function () {
				return Echo_Guard::active();
			}
		);
		$this->assertTrue( $seen );
		$this->assertFalse( Echo_Guard::active() );
	}

	public function test_inactive_after_exception() {
		try {
			Echo_Guard::while_applying(
				function () {
					throw new RuntimeException( 'boom' );
				}
			);
			$this->fail( 'Expected exception.' );
		} catch ( RuntimeException $e ) {
			$this->assertFalse( Echo_Guard::active() );
		}
	}

	public function test_nested_calls_stay_active_until_outermost_returns() {
		Echo_Guard::while_applying(
			function () {
				Echo_Guard::while_applying( function () {} );
				$this->assertTrue( Echo_Guard::active() );
			}
		);
		$this->assertFalse( Echo_Guard::active() );
	}

	public function test_was_applied_delegates_to_apply_ledger() {
		$o = new Lms_Object( 'lesson', 10 );
		$this->assertFalse( Echo_Guard::was_applied( 5, $o, 'completed' ) );
		( new Apply_Ledger() )->record( 5, $o, 'completed', Uuid::v4() );
		$this->assertTrue( Echo_Guard::was_applied( 5, $o, 'completed' ) );
	}
}
