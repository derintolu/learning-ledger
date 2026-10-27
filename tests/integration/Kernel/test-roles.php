<?php
/**
 * Role switching tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Kernel\Rewrites;
use LearningLedger\Kernel\Roles;

class Roles_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Roles::OPTION );
		delete_option( Rewrites::OPTION );
		delete_option( 'lledger_schema_version' );
	}

	public function test_enable_and_disable_store_a_list_of_role_names() {
		$this->assertFalse( Roles::is_enabled( 'connector' ) );
		$this->assertTrue( Roles::enable( 'connector' ) );
		$this->assertTrue( Roles::enable( 'lrs' ) );
		$this->assertTrue( Roles::enable( 'lrs' ) );
		$this->assertSame( array( 'connector', 'lrs' ), get_option( Roles::OPTION ) );
		$this->assertTrue( Roles::is_enabled( 'lrs' ) );
		$this->assertNotFalse( get_option( Rewrites::OPTION ), 'enable schedules a rewrite flush' );
		$this->assertSame( \LearningLedger\Db\Schema::VERSION, (int) get_option( 'lledger_schema_version' ), 'enable installs the schema' );

		Roles::disable( 'connector' );
		$this->assertSame( array( 'lrs' ), get_option( Roles::OPTION ) );
		$this->assertFalse( Roles::is_enabled( 'connector' ) );
	}

	public function test_enable_lrs_refuses_old_db() {
		update_option( Roles::OPTION, array( 'connector' ) );
		$old = static function () {
			return '5.7.44';
		};
		add_filter( 'learning_ledger/db_version_string', $old );

		$result = Roles::enable( 'lrs' );

		$this->assertWPError( $result );
		$this->assertSame( 'lledger_db_version', $result->get_error_code() );
		$this->assertSame( array( 'connector' ), get_option( Roles::OPTION ) );
		$this->assertFalse( Roles::is_enabled( 'lrs' ) );
		$this->assertFalse( get_option( Rewrites::OPTION ) );

		$this->assertTrue( Roles::enable( 'connector' ), 'the connector role has no database floor' );
	}

	public function test_unknown_role() {
		$result = Roles::enable( 'teacher' );
		$this->assertWPError( $result );
		$this->assertSame( 'unknown_role', $result->get_error_code() );
		$this->assertFalse( get_option( Roles::OPTION ) );
		$this->assertFalse( Roles::is_enabled( 'teacher' ) );
		$this->assertFalse( Roles::is_enabled( 'core' ) );
	}
}
