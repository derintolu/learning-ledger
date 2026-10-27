<?php
/**
 * Registration map tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Uuid;

class Registration_Map_Test extends WP_UnitTestCase {

	private Registration_Map $map;

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'registrations' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->map = new Registration_Map();
	}

	public function test_get_or_create_mints_uuid_v4_and_is_stable() {
		$this->assertNull( $this->map->find( 5, 'course:10' ) );
		$reg = $this->map->get_or_create( 5, 'course:10' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $reg );
		$this->assertSame( $reg, $this->map->get_or_create( 5, 'course:10' ) );
		$this->assertSame( $reg, $this->map->find( 5, 'course:10' ) );
		$this->assertNotSame( $reg, $this->map->get_or_create( 5, 'cmi5:10' ) );
		$this->assertNotSame( $reg, $this->map->get_or_create( 6, 'course:10' ) );
	}

	public function test_end_makes_next_get_or_create_mint_a_new_uuid() {
		$old = $this->map->get_or_create( 5, 'course:10' );
		$this->map->end( 5, 'course:10' );
		$this->assertNull( $this->map->find( 5, 'course:10' ) );
		$new = $this->map->get_or_create( 5, 'course:10' );
		$this->assertTrue( Uuid::is_valid( $new ) );
		$this->assertNotSame( $old, $new );
		$this->assertSame( $new, $this->map->get_or_create( 5, 'course:10' ) );
	}

	public function test_user_for() {
		$reg = $this->map->get_or_create( 5, 'preview:10:5' );
		$this->assertSame( 5, $this->map->user_for( $reg ) );
		$this->assertNull( $this->map->user_for( Uuid::v4() ) );
	}

	public function test_delete_user() {
		$this->map->get_or_create( 5, 'course:10' );
		$this->map->get_or_create( 5, 'cmi5:11' );
		$other = $this->map->get_or_create( 6, 'course:10' );
		$this->assertSame( 2, $this->map->delete_user( 5 ) );
		$this->assertNull( $this->map->find( 5, 'course:10' ) );
		$this->assertSame( $other, $this->map->find( 6, 'course:10' ) );
	}
}
