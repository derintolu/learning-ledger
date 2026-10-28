<?php
/**
 * Seed_Command tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Testing\Seed_Command;
use LearningLedger\Xapi\Agent_Identity;

class Seed_Command_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	public function tear_down() {
		// The store commits its own transactions; clear what the seed left behind.
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->delete( Tables::name( 'clients' ), array( 'id' => Seed_Command::CLIENT ) );
		$wpdb->query( 'COMMIT' );
		parent::tear_down();
	}

	public function test_seed_stores_the_count_through_the_store_in_batches_of_500() {
		$batches = array();
		$count   = static function ( array $rows ) use ( &$batches ) {
			$batches[] = count( $rows );
		};
		add_action( 'learning_ledger/statements_stored', $count );

		$stored = ( new Seed_Command() )->seed( 1200, 3, 6 );

		remove_action( 'learning_ledger/statements_stored', $count );
		global $wpdb;
		$this->assertSame( 1200, $stored );
		$this->assertSame( array( 500, 500, 200 ), $batches );
		$this->assertSame( '1200', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'statements' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'statement_refs' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_seeded_values_follow_the_actor_and_registration_counts() {
		( new Seed_Command() )->seed( 600, 3, 6 );

		global $wpdb;
		$table = Tables::name( 'statements' );
		$this->assertSame( '6', $wpdb->get_var( "SELECT COUNT(DISTINCT registration) FROM {$table} WHERE registration IS NOT NULL" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Registration r always belongs to actor r % actors, so agent + registration queries find rows.
		foreach ( range( 0, 5 ) as $r ) {
			$actor  = Agent_Identity::from_array( Seed_Command::actor( $r % 3 ) )->hash();
			$hashes = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT HEX(actor_hash) FROM {$table} WHERE registration = UNHEX(%s) AND is_voiding = 0", str_replace( '-', '', Seed_Command::registration( $r ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertSame( array( strtoupper( bin2hex( $actor ) ) ), $hashes );
		}

		// Some statements are voided by StatementRefs, so the referrer branch has work at scale.
		$this->assertSame( '3', $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE object_kind = 'StatementRef' AND is_voiding = 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( '3', $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE voided = 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Lessons have their course as context parent, for related_activities.
		$lesson = Seed_Command::lesson( 0, 0 );
		$this->assertStringStartsWith( Seed_Command::course( 0 ) . '/', $lesson );
		$refs = Tables::name( 'statement_refs' );
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$refs} WHERE ref_hash = UNHEX(%s) AND ref_kind = 'context-parent'", sha1( Seed_Command::course( 0 ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_invalid_counts_are_rejected() {
		$this->expectException( InvalidArgumentException::class );
		( new Seed_Command() )->seed( 0, 3, 6 );
	}

	/**
	 * Only the enabled side can be observed: the tests env defines LLEDGER_TESTING for the whole
	 * run and a constant cannot be undefined, so the inert side (register() returning early) is
	 * covered by enabled() reading the constant alone.
	 */
	public function test_enabled_follows_lledger_testing() {
		$this->assertTrue( defined( 'LLEDGER_TESTING' ) && LLEDGER_TESTING, 'the tests env defines LLEDGER_TESTING' );
		$this->assertTrue( Seed_Command::enabled() );
	}
}
