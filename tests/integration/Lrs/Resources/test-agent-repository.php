<?php
/**
 * Agent repository tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Resources\Agent_Repository;
use LearningLedger\Xapi\Agent_Identity;

class Agent_Repository_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'agents' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function mbox( string $m = 'mailto:a@example.com' ): Agent_Identity {
		return Agent_Identity::from_array( array( 'mbox' => $m ) );
	}

	public function test_person_has_the_one_ifi() {
		$repo = new Agent_Repository();
		$repo->record( $this->mbox() );
		$this->assertSame(
			array(
				'objectType'   => 'Person',
				'name'         => array(),
				'mbox'         => array( 'mailto:a@example.com' ),
				'mbox_sha1sum' => array(),
				'openid'       => array(),
				'account'      => array(),
			),
			$repo->person_for( $this->mbox() )
		);
	}

	public function test_account_ifi_is_returned_as_object() {
		$repo = new Agent_Repository();
		$a    = Agent_Identity::from_array( array( 'account' => array( 'homePage' => 'https://h.example', 'name' => 'u1' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$repo->record( $a );
		$this->assertSame( array( array( 'homePage' => 'https://h.example', 'name' => 'u1' ) ), $repo->person_for( $a )['account'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
	}

	public function test_record_twice_keeps_one_row_and_person_id_equals_id() {
		global $wpdb;
		$repo = new Agent_Repository();
		$repo->record( $this->mbox() );
		$repo->record( $this->mbox() );
		$this->assertSame( '', $wpdb->last_error );
		$rows = $wpdb->get_results( 'SELECT id, person_id FROM ' . Tables::name( 'agents' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertCount( 1, $rows );
		$this->assertSame( $rows[0]['id'], $rows[0]['person_id'] );
		$this->assertNotSame( '0', $rows[0]['id'] );
	}

	public function test_rows_sharing_person_id_merge_into_one_person() {
		global $wpdb;
		$repo = new Agent_Repository();
		$repo->record( $this->mbox() );
		$repo->record( Agent_Identity::from_array( array( 'openid' => 'https://o.example/u' ) ) );
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Tables::name( 'agents' ) . ' WHERE ifi_hash = UNHEX(%s)', bin2hex( $this->mbox()->hash() ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Tables::name( 'agents' ) . ' SET person_id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$person = $repo->person_for( $this->mbox() );
		$this->assertSame( array( 'mailto:a@example.com' ), $person['mbox'] );
		$this->assertSame( array( 'https://o.example/u' ), $person['openid'] );
	}

	public function test_unknown_agent_gives_empty_person() {
		$person = ( new Agent_Repository() )->person_for( $this->mbox() );
		$this->assertSame( array(), $person['mbox'] );
		$this->assertSame( 'Person', $person['objectType'] );
	}

	public function test_delete_removes_the_row() {
		$repo = new Agent_Repository();
		$repo->record( $this->mbox() );
		$repo->record( $this->mbox( 'mailto:b@example.com' ) );
		$repo->delete( $this->mbox() );
		$this->assertSame( array(), $repo->person_for( $this->mbox() )['mbox'] );
		$this->assertSame( array( 'mailto:b@example.com' ), $repo->person_for( $this->mbox( 'mailto:b@example.com' ) )['mbox'] );
	}

	public function test_delete_throws_when_the_query_fails() {
		$failing = 'DELETE FROM ' . Tables::name( 'agents' );
		$failer  = static function ( string $q ) use ( $failing ) {
			return str_starts_with( $q, $failing ) ? 'DELETE FROM lledger_no_such_table_fx3' : $q;
		};
		$GLOBALS['wpdb']->suppress_errors( true );
		add_filter( 'query', $failer );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Could not delete the agent.' );
		try {
			( new Agent_Repository() )->delete( $this->mbox() );
		} finally {
			remove_filter( 'query', $failer );
			$GLOBALS['wpdb']->suppress_errors( false );
		}
	}
}
