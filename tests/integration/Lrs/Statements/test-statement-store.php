<?php
/**
 * Statement store tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Statements\Statement_Guard;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Lrs\Statements\Stored_Statement;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

class Statement_Store_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	public function tear_down() {
		Clock::freeze( null );
		remove_all_filters( 'learning_ledger/statement_guards' );
		remove_all_actions( 'learning_ledger/statements_stored' );
		remove_all_actions( 'learning_ledger/statements_committed' );
		parent::tear_down();
	}

	public static function principal( array $scopes = array( 'statements/write' ), array $overrides = array() ): Principal {
		$c = Client_Factory::create( $scopes, $overrides );
		return Principal::for_client( ( new Client_Repository() )->find( $c['id'] ) );
	}

	public static function statement( string $name = 'u1', string $activity = 'https://site.example/act/1' ): array {
		return array(
			'actor'  => array(
				'objectType' => 'Agent',
				'account'    => array(
					'homePage' => 'https://site.example',
					'name'     => $name,
				),
			),
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object' => array(
				'objectType' => 'Activity',
				'id'         => $activity,
			),
		);
	}

	private static function count_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'statements' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function status_of( callable $call ): int {
		try {
			$call();
		} catch ( Xapi_Error $e ) {
			return $e->status();
		}
		return 0;
	}

	public function test_post_sets_id_stored_authority_version() {
		$now = new DateTimeImmutable( '2026-10-27T10:00:00.123456Z' );
		Clock::freeze( $now );
		$p   = self::principal();
		$ids = ( new Statement_Store() )->store( array( self::statement() ), $p, '2.0.0' );

		$this->assertCount( 1, $ids );
		$this->assertTrue( Uuid::is_valid( $ids[0] ) );
		$row = ( new Statement_Repository() )->find( $ids[0] );
		$this->assertInstanceOf( Stored_Statement::class, $row );
		$st = $row->statement();
		$this->assertSame( $ids[0], $st['id'] );
		$this->assertSame( '2026-10-27T10:00:00.123456Z', $st['stored'] );
		$this->assertSame( '2026-10-27T10:00:00.123456Z', $st['timestamp'] );
		$this->assertSame( $p->authority(), $st['authority'] );
		$this->assertSame( '2.0.0', $st['version'] );
		$this->assertSame( '2026-10-27T10:00:00.123456Z', $row->stored );
		$this->assertSame( '2.0.0', $row->version );
		$this->assertSame( $p->authority_key(), $row->authority_client );
		$this->assertSame( Agent_Identity::from_array( self::statement()['actor'] )->hash(), $row->actor_hash );
		$this->assertFalse( $row->is_voiding );
		$this->assertFalse( $row->voided );
		$this->assertNull( $row->registration );

		global $wpdb;
		$db = $wpdb->get_row( $wpdb->prepare( 'SELECT HEX(verb_hash) v, HEX(object_hash) o, object_kind k, HEX(content_sha256) c, HEX(actor_home_hash) h FROM ' . Tables::name( 'statements' ) . ' WHERE seq = %d', $row->seq ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( strtoupper( sha1( 'http://adlnet.gov/expapi/verbs/completed' ) ), $db['v'] );
		$this->assertSame( strtoupper( sha1( 'https://site.example/act/1' ) ), $db['o'] );
		$this->assertSame( 'Activity', $db['k'] );
		$this->assertSame( strtoupper( hash( 'sha256', $row->body ) ), $db['c'] );
		$this->assertSame( strtoupper( bin2hex( Agent_Identity::home_hash( 'https://site.example' ) ) ), $db['h'] );

		// 1.0.3 statements without a version get 1.0.0.
		$ids = ( new Statement_Store() )->store( array( self::statement() ), $p, '1.0.3' );
		$this->assertSame( '1.0.0', ( new Statement_Repository() )->find( $ids[0] )->statement()['version'] );
	}

	public function test_version_column_holds_the_longest_prerelease_the_validator_accepts() {
		$p = self::principal();
		foreach ( array( '1.0.0-rc.1', '1.0.0-rc.12345678901' ) as $version ) {
			$st            = self::statement( 'v' . strlen( $version ) );
			$st['version'] = $version;
			$ids           = ( new Statement_Store() )->store( array( $st ), $p, '1.0.3' );
			$row           = ( new Statement_Repository() )->find( $ids[0] );
			$this->assertSame( $version, $row->version );
			$this->assertSame( $version, $row->statement()['version'] );
		}
		$this->assertSame( 20, strlen( '1.0.0-rc.12345678901' ) );
	}

	public function test_hooks_fire_inside_and_after_the_transaction() {
		$seen = array();
		add_action(
			'learning_ledger/statements_stored',
			static function ( array $rows, Principal $p ) use ( &$seen ) {
				$seen[] = array( 'stored', count( $rows ), $p->client_id() );
			},
			10,
			2
		);
		add_action(
			'learning_ledger/statements_committed',
			static function ( array $rows ) use ( &$seen ) {
				$seen[] = array( 'committed', count( $rows ), $rows[0] instanceof Stored_Statement );
			}
		);
		$p = self::principal();
		( new Statement_Store() )->store( array( self::statement( 'a' ), self::statement( 'b' ) ), $p, '2.0.0' );
		$this->assertSame( array( array( 'stored', 2, $p->client_id() ), array( 'committed', 2, true ) ), $seen );
	}

	public function test_batch_all_or_nothing() {
		$bad = self::statement();
		unset( $bad['verb'] );
		$p      = self::principal();
		$status = 0;
		try {
			( new Statement_Store() )->store( array( self::statement( 'a' ), $bad, self::statement( 'c' ) ), $p, '2.0.0' );
		} catch ( Xapi_Error $e ) {
			$status = $e->status();
			$this->assertStringContainsString( 'verb', $e->getMessage() );
		}
		$this->assertSame( 400, $status );
		$this->assertSame( 0, self::count_rows() );
	}

	public function test_failure_inside_transaction_rolls_back() {
		add_action(
			'learning_ledger/statements_stored',
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);
		try {
			( new Statement_Store() )->store( array( self::statement( 'a' ), self::statement( 'b' ) ), self::principal(), '2.0.0' );
			$this->fail( 'Expected an exception.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertSame( 0, self::count_rows() );
	}

	public function test_duplicate_id_in_batch_400() {
		$id      = Uuid::v4();
		$a       = self::statement( 'a' );
		$a['id'] = $id;
		$b       = self::statement( 'b' );
		$b['id'] = strtoupper( $id );
		$this->assertSame( 400, self::status_of( fn() => ( new Statement_Store() )->store( array( $a, $b ), self::principal(), '2.0.0' ) ) );
		$this->assertSame( 0, self::count_rows() );
	}

	public function test_existing_id_identical_is_silent_and_different_is_409() {
		$p        = self::principal();
		$st       = self::statement();
		$st['id'] = Uuid::v4();
		$store    = new Statement_Store();
		$this->assertSame( array( $st['id'] ), $store->store( array( $st ), $p, '2.0.0' ) );
		$this->assertSame( array( $st['id'] ), $store->store( array( $st ), $p, '2.0.0' ) );
		$this->assertSame( 1, self::count_rows() );

		$other           = $st;
		$other['object'] = array( 'id' => 'https://site.example/act/2' );
		$this->assertSame( 409, self::status_of( fn() => $store->store( array( self::statement( 'new' ), $other ), $p, '2.0.0' ) ) );
		$this->assertSame( 1, self::count_rows() );
	}

	public function test_scope_required() {
		$this->assertSame( 403, self::status_of( fn() => ( new Statement_Store() )->store( array( self::statement() ), self::principal( array( 'statements/read' ) ), '2.0.0' ) ) );
	}

	public function test_binding_rejects_foreign_actor() {
		$p     = self::principal(
			array( 'statements/write' ),
			array( 'bindings' => array( 'actor_ifi' => 'account:https://site.example|u1' ) )
		);
		$store = new Statement_Store();
		$this->assertCount( 1, $store->store( array( self::statement( 'u1' ) ), $p, '2.0.0' ) );
		$this->assertSame( 403, self::status_of( fn() => $store->store( array( self::statement( 'u1' ), self::statement( 'u2' ) ), $p, '2.0.0' ) ) );
		$this->assertSame( 1, self::count_rows() );
	}

	public function test_reject_non_account_client() {
		$p           = self::principal( array( 'statements/write' ), array( 'reject_non_account' => true ) );
		$st          = self::statement();
		$st['actor'] = array( 'mbox' => 'mailto:a@b.example' );
		$store       = new Statement_Store();
		$this->assertSame( 403, self::status_of( fn() => $store->store( array( $st ), $p, '2.0.0' ) ) );
		$this->assertCount( 1, $store->store( array( self::statement() ), $p, '2.0.0' ) );
	}

	public function test_guard_filter_can_reject() {
		$seen_principal = null;
		add_filter(
			'learning_ledger/statement_guards',
			static function ( array $guards, Principal $p ) use ( &$seen_principal ) {
				$seen_principal = $p;
				$guards[]       = new class() implements Statement_Guard {
					public function check( array $statement, Principal $p ): void {
						if ( 'blocked' === $statement['actor']['account']['name'] ) {
							throw new Xapi_Error( 403, 'Blocked by guard.' );
						}
					}
				};
				return $guards;
			},
			10,
			2
		);
		$p     = self::principal();
		$store = new Statement_Store();
		$this->assertCount( 1, $store->store( array( self::statement( 'ok' ) ), $p, '2.0.0' ) );
		$this->assertSame( $p, $seen_principal );
		$this->assertSame( 403, self::status_of( fn() => $store->store( array( self::statement( 'ok' ), self::statement( 'blocked' ) ), $p, '2.0.0' ) ) );
		$this->assertSame( 1, self::count_rows() );
	}

	public function test_activities_and_agents_recorded() {
		$st                         = self::statement();
		$st['object']['definition'] = array( 'name' => array( 'en-US' => 'One' ) );
		$st['context']              = array(
			'instructor'        => array( 'mbox' => 'mailto:teacher@b.example' ),
			'contextActivities' => array( 'parent' => array( 'id' => 'https://site.example/course' ) ),
		);
		( new Statement_Store() )->store( array( $st ), self::principal( array( 'statements/write', 'define' ) ), '2.0.0' );

		global $wpdb;
		$iris = $wpdb->get_col( 'SELECT iri FROM ' . Tables::name( 'activities' ) . ' ORDER BY iri' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( array( 'https://site.example/act/1', 'https://site.example/course' ), $iris );
		$agents = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'agents' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// Actor, instructor and authority.
		$this->assertSame( 3, $agents );
	}

	public function test_repository_registration_and_counts() {
		$reg          = Uuid::v4();
		$a            = self::statement( 'a' );
		$a['context'] = array( 'registration' => strtoupper( $reg ) );
		$b            = self::statement( 'b' );
		$b['context'] = array( 'registration' => $reg );
		$c            = self::statement( 'c' );
		$ids          = ( new Statement_Store() )->store( array( $a, $b, $c ), self::principal(), '2.0.0' );
		$repo         = new Statement_Repository();
		$rows         = $repo->for_registration( $reg );
		$this->assertSame( array( $ids[0], $ids[1] ), array_map( static fn( $r ) => $r->id, $rows ) );
		$this->assertSame( $reg, $rows[0]->registration );
		$this->assertSame( $repo->find( $ids[2] )->seq, $repo->approx_count() );
		$this->assertNull( $repo->find( Uuid::v4() ) );

		$hash = Agent_Identity::from_array( $a['actor'] )->hash();
		$this->assertSame( 1, $repo->delete_for_actor( $hash ) );
		$this->assertNull( $repo->find( $ids[0] ) );
	}
}
