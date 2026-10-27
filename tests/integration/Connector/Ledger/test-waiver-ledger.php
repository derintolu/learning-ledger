<?php
/**
 * Waiver ledger tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Uuid;

class Waiver_Ledger_Test extends WP_UnitTestCase {

	private Waiver_Ledger $ledger;

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'waivers' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->ledger = new Waiver_Ledger();
	}

	public function test_record_and_get() {
		$reg = Uuid::v4();
		$sid = Uuid::v4();
		$this->ledger->record( 5, 10, $reg, $sid, 'Prior credit', true );
		$row = $this->ledger->get( 5, 10 );
		$this->assertSame( $reg, $row['registration'] );
		$this->assertSame( $sid, $row['statement_id'] );
		$this->assertSame( 'Prior credit', $row['reason'] );
		$this->assertTrue( $row['carried_over'] );
		$this->assertTrue( $this->ledger->is_waived( 5, 10 ) );
		$this->assertFalse( $this->ledger->is_waived( 5, 11 ) );
		$this->assertNull( $this->ledger->get( 6, 10 ) );
	}

	public function test_record_twice_replaces_without_error() {
		$this->ledger->record( 5, 10, Uuid::v4(), Uuid::v4(), 'a', false );
		$sid = Uuid::v4();
		$this->ledger->record( 5, 10, Uuid::v4(), $sid, 'b', true );
		$this->assertCount( 1, $this->ledger->for_user( 5 ) );
		$this->assertSame( 'b', $this->ledger->get( 5, 10 )['reason'] );
		$this->assertSame( $sid, $this->ledger->get( 5, 10 )['statement_id'] );
	}

	public function test_clear_removes_marker() {
		$this->ledger->record( 5, 10, Uuid::v4(), Uuid::v4(), 'a', false );
		$this->ledger->clear( 5, 10 );
		$this->assertFalse( $this->ledger->is_waived( 5, 10 ) );
	}

	public function test_for_user_and_delete_user_touch_only_that_user() {
		$this->ledger->record( 5, 10, Uuid::v4(), Uuid::v4(), 'a', false );
		$this->ledger->record( 5, 11, Uuid::v4(), Uuid::v4(), 'b', false );
		$this->ledger->record( 6, 10, Uuid::v4(), Uuid::v4(), 'c', false );
		$this->assertCount( 2, $this->ledger->for_user( 5 ) );
		$this->assertSame( 2, $this->ledger->delete_user( 5 ) );
		$this->assertSame( array(), $this->ledger->for_user( 5 ) );
		$this->assertTrue( $this->ledger->is_waived( 6, 10 ) );
	}
}
