<?php
/**
 * Audit log tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;

class Audit_Log_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'audit' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_records_counts_and_rejects_body() {
		Audit_Log::record( 'erase', array( 'statements' => 3 ), 'client:course-server' );
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'audit' ) ) );
		$this->expectException( \InvalidArgumentException::class );
		Audit_Log::record( 'x', array( 'body' => '{}' ) );
	}

	public function test_row_holds_event_actor_data_and_utc_time() {
		$before = gmdate( 'Y-m-d H:i:s' );
		Audit_Log::record( 'erase', array( 'statements' => 3 ), 'client:course-server' );
		$row = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Tables::name( 'audit' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 'erase', $row->event );
		$this->assertSame( 'client:course-server', $row->actor );
		$this->assertSame( array( 'statements' => 3 ), json_decode( $row->data, true ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{6}$/', $row->at );
		$this->assertGreaterThanOrEqual( $before, substr( $row->at, 0, 19 ) );
		$this->assertLessThanOrEqual( gmdate( 'Y-m-d H:i:s' ), substr( $row->at, 0, 19 ) );
	}

	public function test_actor_is_optional() {
		Audit_Log::record( 'key_rotated', array( 'kid' => 'k2' ) );
		$this->assertNull( $GLOBALS['wpdb']->get_var( 'SELECT actor FROM ' . Tables::name( 'audit' ) ) );
	}

	/**
	 * @dataProvider data_forbidden
	 */
	public function test_rejects_forbidden_keys_at_any_depth( array $data ) {
		try {
			Audit_Log::record( 'x', $data );
			$this->fail( 'Expected InvalidArgumentException' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'audit' ) ) );
		}
	}

	public function data_forbidden(): array {
		return array(
			'statement'   => array( array( 'statement' => array( 'id' => 'x' ) ) ),
			'email'       => array( array( 'email' => 'a@example.com' ) ),
			'nested body' => array( array( 'counts' => array( 'body' => '{}' ) ) ),
			'upper case'  => array( array( 'Email' => 'a@example.com' ) ),
		);
	}
}
