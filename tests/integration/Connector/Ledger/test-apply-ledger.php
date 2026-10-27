<?php
/**
 * Apply ledger tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Uuid;

class Apply_Ledger_Test extends WP_UnitTestCase {

	private Apply_Ledger $ledger;

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'applied' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->ledger = new Apply_Ledger();
	}

	public function test_record_then_has() {
		$o = new Lms_Object( 'lesson', 10 );
		$this->assertFalse( $this->ledger->has( 5, $o, 'completed' ) );
		$this->ledger->record( 5, $o, 'completed', Uuid::v4() );
		$this->assertTrue( $this->ledger->has( 5, $o, 'completed' ) );
		$this->assertFalse( $this->ledger->has( 5, $o, 'passed' ) );
		$this->assertFalse( $this->ledger->has( 6, $o, 'completed' ) );
		$this->assertFalse( $this->ledger->has( 5, new Lms_Object( 'quiz', 10 ), 'completed' ) );
	}

	public function test_record_is_idempotent_on_primary_key_and_first_wins() {
		$o     = new Lms_Object( 'lesson', 10 );
		$first = Uuid::v4();
		$this->ledger->record( 5, $o, 'completed', $first, 0.5, false );
		$this->ledger->record( 5, $o, 'completed', Uuid::v4(), 0.9, true );
		$rows = $this->ledger->for_user( 5 );
		$this->assertCount( 1, $rows );
		$this->assertSame( $first, $rows[0]['statement_id'] );
		$this->assertSame( 0.5, $rows[0]['score_scaled'] );
		$this->assertFalse( $rows[0]['success'] );
	}

	public function test_score() {
		$this->assertNull( $this->ledger->score( 5, 10 ) );
		$o = new Lms_Object( 'lesson', 10 );
		$this->ledger->record( 5, $o, 'completed', Uuid::v4() );
		$this->assertNull( $this->ledger->score( 5, 10 ) );
		$this->ledger->record( 5, $o, 'passed', Uuid::v4(), 0.8, true );
		$this->assertSame(
			array(
				'score_scaled' => 0.8,
				'success'      => true,
			),
			$this->ledger->score( 5, 10 )
		);
	}

	public function test_flag_void_marks_rows_and_lists_them() {
		$o   = new Lms_Object( 'lesson', 10 );
		$sid = Uuid::v4();
		$this->ledger->record( 5, $o, 'completed', $sid );
		$this->ledger->record( 5, new Lms_Object( 'lesson', 11 ), 'completed', Uuid::v4() );
		$this->assertSame( array(), $this->ledger->flagged() );
		$this->assertSame( 1, $this->ledger->flag_void( $sid ) );
		$this->assertSame( 0, $this->ledger->flag_void( $sid ) );
		$flagged = $this->ledger->flagged();
		$this->assertCount( 1, $flagged );
		$this->assertSame( $sid, $flagged[0]['statement_id'] );
		$this->assertTrue( $flagged[0]['flagged'] );
	}

	public function test_delete_user_touches_only_that_user() {
		$o = new Lms_Object( 'lesson', 10 );
		$this->ledger->record( 5, $o, 'completed', Uuid::v4() );
		$this->ledger->record( 5, $o, 'passed', Uuid::v4() );
		$this->ledger->record( 6, $o, 'completed', Uuid::v4() );
		$this->assertSame( 2, $this->ledger->delete_user( 5 ) );
		$this->assertSame( array(), $this->ledger->for_user( 5 ) );
		$this->assertTrue( $this->ledger->has( 6, $o, 'completed' ) );
	}
}
