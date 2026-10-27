<?php
/**
 * WordPress privacy exporter and eraser on the LRS.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Privacy\Lrs_Privacy;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Tests\Client_Factory;

class Lrs_Privacy_Test extends WP_UnitTestCase {

	const EMAIL = 'jane.learner@example.com';

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'documents', 'tokens', 'links', 'audit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		remove_all_filters( 'wp_privacy_personal_data_exporters' );
		remove_all_filters( 'wp_privacy_personal_data_erasers' );
		( new Lrs_Privacy() )->register();
	}

	public function tear_down() {
		remove_all_filters( 'wp_privacy_personal_data_exporters' );
		remove_all_filters( 'wp_privacy_personal_data_erasers' );
		parent::tear_down();
	}

	private static function statement( array $actor, string $activity = 'https://site.example/act/1' ): array {
		return array(
			'actor'  => array( 'objectType' => 'Agent' ) + $actor,
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object' => array(
				'objectType' => 'Activity',
				'id'         => $activity,
			),
		);
	}

	private static function mbox(): array {
		return array( 'mbox' => 'mailto:' . self::EMAIL );
	}

	private static function sha1sum(): array {
		return array( 'mbox_sha1sum' => sha1( 'mailto:' . self::EMAIL ) );
	}

	private static function other(): array {
		return array( 'mbox' => 'mailto:someone.else@example.com' );
	}

	private static function store( array $statements ): array {
		$c = Client_Factory::create( array( Scopes::STATEMENTS_WRITE ) );
		$p = Principal::for_client( ( new Client_Repository() )->find( $c['id'] ) );
		return ( new Statement_Store() )->store( $statements, $p, '2.0.0' );
	}

	private static function hook_callback( string $filter ): callable {
		$list = apply_filters( $filter, array() );
		return $list['learning-ledger-lrs']['callback'];
	}

	private static function statements_of( array $actor ): int {
		global $wpdb;
		$hash = sha1( isset( $actor['mbox'] ) ? 'mbox:' . $actor['mbox'] : 'mbox_sha1sum:' . $actor['mbox_sha1sum'] );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::name( 'statements' ) . ' WHERE actor_hash = UNHEX(%s)', $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_registers_an_exporter_and_an_eraser() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'learning-ledger-lrs', $exporters );
		$this->assertNotEmpty( $exporters['learning-ledger-lrs']['exporter_friendly_name'] );
		$this->assertIsCallable( $exporters['learning-ledger-lrs']['callback'] );
		$this->assertArrayHasKey( 'learning-ledger-lrs', $erasers );
		$this->assertNotEmpty( $erasers['learning-ledger-lrs']['eraser_friendly_name'] );
		$this->assertIsCallable( $erasers['learning-ledger-lrs']['callback'] );
	}

	public function test_eraser_removes_the_mbox_and_mbox_sha1sum_actors_statements() {
		self::store( array( self::statement( self::mbox() ), self::statement( self::mbox() ), self::statement( self::sha1sum() ), self::statement( self::other() ) ) );

		$result = call_user_func( self::hook_callback( 'wp_privacy_personal_data_erasers' ), self::EMAIL, 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertIsArray( $result['messages'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 0, self::statements_of( self::mbox() ) );
		$this->assertSame( 0, self::statements_of( self::sha1sum() ) );
		$this->assertSame( 1, self::statements_of( self::other() ) );
	}

	public function test_eraser_with_nothing_stored_removes_nothing() {
		$result = call_user_func( self::hook_callback( 'wp_privacy_personal_data_erasers' ), self::EMAIL, 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_audit_rows_have_counts_and_no_email() {
		self::store( array( self::statement( self::mbox() ), self::statement( self::sha1sum() ) ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		call_user_func( self::hook_callback( 'wp_privacy_personal_data_erasers' ), self::EMAIL, 1 );

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT actor, data FROM ' . Tables::name( 'audit' ) . ' WHERE event = %s', 'actor_erased' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNotEmpty( $rows );
		$total = 0;
		foreach ( $rows as $row ) {
			$this->assertSame( 'user:' . $admin, $row['actor'] );
			$data = json_decode( $row['data'], true );
			$this->assertSame( array( 'statements', 'refs', 'documents', 'agents', 'tokens', 'links' ), array_keys( $data ) );
			foreach ( $data as $value ) {
				$this->assertIsInt( $value );
			}
			$total += $data['statements'];
			foreach ( array( self::EMAIL, 'jane', sha1( 'mailto:' . self::EMAIL ), sha1( 'mbox:mailto:' . self::EMAIL ) ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $row['data'] );
				$this->assertStringNotContainsString( $needle, (string) $row['actor'] );
			}
		}
		$this->assertSame( 2, $total );
	}

	public function test_exporter_returns_exact_statement_json_in_pages_of_100() {
		$statements = array();
		for ( $i = 0; $i < 120; $i++ ) {
			$statements[] = self::statement( 0 === $i % 2 ? self::mbox() : self::sha1sum(), 'https://site.example/act/' . $i );
		}
		$ids = self::store( $statements );
		self::store( array( self::statement( self::other() ) ) );
		$export = self::hook_callback( 'wp_privacy_personal_data_exporters' );

		$first  = call_user_func( $export, self::EMAIL, 1 );
		$second = call_user_func( $export, self::EMAIL, 2 );

		$this->assertCount( 100, $first['data'] );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 20, $second['data'] );
		$this->assertTrue( $second['done'] );

		$items = array_merge( $first['data'], $second['data'] );
		$repo  = new Statement_Repository();
		foreach ( $items as $n => $item ) {
			$this->assertSame( 'learning-ledger-statements', $item['group_id'] );
			$this->assertNotEmpty( $item['group_label'] );
			$this->assertSame( 'learning-ledger-statement-' . $ids[ $n ], $item['item_id'] );
			$values = array_column( $item['data'], 'value', 'name' );
			$this->assertSame( $repo->find( $ids[ $n ] )->body, $values['Statement'] );
		}
	}

	public function test_exporter_page_past_the_end_is_empty_and_done() {
		$result = call_user_func( self::hook_callback( 'wp_privacy_personal_data_exporters' ), self::EMAIL, 3 );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}
}
