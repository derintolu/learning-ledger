<?php
/**
 * Voiding tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Lrs\Statements\Stored_Statement;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Uuid;

class Voiding_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	public function tear_down() {
		remove_all_actions( 'learning_ledger/statement_voided' );
		parent::tear_down();
	}

	private static function principal( array $scopes = array( 'statements/write', 'void' ), array $overrides = array() ): Principal {
		$c = Client_Factory::create( $scopes, $overrides );
		return Principal::for_client( ( new Client_Repository() )->find( $c['id'] ) );
	}

	private static function statement( string $name = 'u1' ): array {
		return array(
			'actor'  => array(
				'objectType' => 'Agent',
				'account'    => array(
					'homePage' => 'https://site.example',
					'name'     => $name,
				),
			),
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object' => array( 'id' => 'https://site.example/act/1' ),
		);
	}

	private static function voiding( string $target, string $name = 'admin' ): array {
		$st           = self::statement( $name );
		$st['verb']   = array( 'id' => 'http://adlnet.gov/expapi/verbs/voided' );
		$st['object'] = array(
			'objectType' => 'StatementRef',
			'id'         => $target,
		);
		return $st;
	}

	private static function status_of( callable $call ): int {
		try {
			$call();
		} catch ( Xapi_Error $e ) {
			return $e->status();
		}
		return 0;
	}

	public function test_void_requires_scope() {
		$store      = new Statement_Store();
		$writer     = self::principal( array( 'statements/write' ) );
		[ $target ] = $store->store( array( self::statement() ), $writer, '2.0.0' );
		$this->assertSame( 403, self::status_of( fn() => $store->store( array( self::voiding( $target ) ), $writer, '2.0.0' ) ) );
		$this->assertFalse( ( new Statement_Repository() )->find( $target )->voided );
	}

	public function test_void_sets_flag_and_fires_action() {
		$fired = array();
		add_action(
			'learning_ledger/statement_voided',
			static function ( string $target_id, Stored_Statement $voiding ) use ( &$fired ) {
				$fired[] = array( $target_id, $voiding->id, $voiding->is_voiding, $voiding->voids_id );
			},
			10,
			2
		);
		$store      = new Statement_Store();
		$p          = self::principal();
		[ $target ] = $store->store( array( self::statement() ), $p, '2.0.0' );
		[ $void ]   = $store->store( array( self::voiding( strtoupper( $target ) ) ), $p, '2.0.0' );

		$repo = new Statement_Repository();
		$this->assertTrue( $repo->find( $target )->voided );
		$this->assertTrue( $repo->find( $void )->is_voiding );
		$this->assertSame( $target, $repo->find( $void )->voids_id );
		$this->assertSame( array( array( $target, $void, true, $target ) ), $fired );
	}

	public function test_void_target_in_same_batch() {
		$store    = new Statement_Store();
		$p        = self::principal();
		$st       = self::statement();
		$st['id'] = Uuid::v4();
		$store->store( array( $st, self::voiding( $st['id'] ) ), $p, '2.0.0' );
		$this->assertTrue( ( new Statement_Repository() )->find( $st['id'] )->voided );
	}

	public function test_cannot_void_a_voiding_statement() {
		$store      = new Statement_Store();
		$p          = self::principal();
		[ $target ] = $store->store( array( self::statement() ), $p, '2.0.0' );
		[ $void ]   = $store->store( array( self::voiding( $target ) ), $p, '2.0.0' );
		$this->assertSame( 400, self::status_of( fn() => $store->store( array( self::voiding( $void ) ), $p, '2.0.0' ) ) );
		$this->assertFalse( ( new Statement_Repository() )->find( $void )->voided );
	}

	public function test_pending_void_applied_on_arrival() {
		$fired = array();
		add_action(
			'learning_ledger/statement_voided',
			static function ( string $target_id, Stored_Statement $voiding ) use ( &$fired ) {
				$fired[] = array( $target_id, $voiding->id );
			},
			10,
			2
		);
		$store    = new Statement_Store();
		$p        = self::principal();
		$target   = Uuid::v4();
		[ $void ] = $store->store( array( self::voiding( $target ) ), $p, '2.0.0' );
		$this->assertSame( array(), $fired );

		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'pending_voids' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$st       = self::statement();
		$st['id'] = $target;
		$store->store( array( $st ), $p, '2.0.0' );
		$this->assertTrue( ( new Statement_Repository() )->find( $target )->voided );
		$this->assertSame( array( array( $target, $void ) ), $fired );
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'pending_voids' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_void_target_must_satisfy_bindings() {
		$store      = new Statement_Store();
		[ $target ] = $store->store( array( self::statement( 'u2' ) ), self::principal(), '2.0.0' );
		$bound      = self::principal(
			array( 'statements/write', 'void' ),
			array( 'bindings' => array( 'actor_ifi' => 'account:https://site.example|u1' ) )
		);
		// The voiding statement's own actor is within the bindings; its target is not.
		$this->assertSame( 403, self::status_of( fn() => $store->store( array( self::voiding( $target, 'u1' ) ), $bound, '2.0.0' ) ) );
		$this->assertFalse( ( new Statement_Repository() )->find( $target )->voided );
	}
}
