<?php
/**
 * Statement_Query tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Statements\More_Cursor;
use LearningLedger\Lrs\Statements\Query_Params;
use LearningLedger\Lrs\Statements\Statement_Query;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Json;
use LearningLedger\Util\Uuid;

class Statement_Query_Test extends WP_UnitTestCase {

	private const NOW = '2026-10-27T09:00:00Z';

	private const REG_1 = '11111111-1111-4111-8111-111111111111';

	private const REG_2 = '22222222-2222-4222-8222-222222222222';

	private Principal $writer;

	private Principal $reader;

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$this->writer = self::principal( array( 'statements/write', 'void' ) );
		$this->reader = self::principal( array( 'statements/read' ) );
	}

	public function tear_down() {
		Clock::freeze( null );
		parent::tear_down();
	}

	private static function principal( array $scopes, array $overrides = array() ): Principal {
		$c = Client_Factory::create( $scopes, $overrides );
		return Principal::for_client( ( new Client_Repository() )->find( $c['id'] ) );
	}

	private static function agent( string $name ): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => 'https://site.example',
				'name'     => $name,
			),
		);
	}

	private static function statement( string $actor = 'u1', string $activity = 'https://site.example/act/1', string $verb = 'completed', ?string $registration = null ): array {
		$st = array(
			'actor'  => self::agent( $actor ),
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/' . $verb ),
			'object' => array(
				'objectType' => 'Activity',
				'id'         => $activity,
			),
		);
		if ( null !== $registration ) {
			$st['context'] = array( 'registration' => $registration );
		}
		return $st;
	}

	private function store_at( string $at, array $statements, ?Principal $p = null ): array {
		Clock::freeze( new DateTimeImmutable( $at ) );
		return ( new Statement_Store() )->store( $statements, $p ?? $this->writer, '2.0.0' );
	}

	private function query( array $params, ?Principal $p = null, ?More_Cursor $c = null, string $version = '2.0.0' ): array {
		Clock::freeze( new DateTimeImmutable( self::NOW ) );
		$q = null === $c
			? Query_Params::from_request( new Xapi_Request( 'GET', 'statements', $params, array(), '', $version ) )
			: Query_Params::from_cursor( $c );
		return ( new Statement_Query() )->run( $q, $p ?? $this->reader, $c );
	}

	private static function ids( array $result ): array {
		return array_map( static fn( $row ) => $row->id, $result['rows'] );
	}

	/**
	 * Four statements: A (u1, act/1, completed, REG_1), B (u2, act/1, attempted),
	 * C (u1, act/2, attempted, REG_2), D (u3 mentored agent u1, REG_1).
	 */
	private function seed(): array {
		[ $a ] = $this->store_at( '2026-10-27T08:00:01Z', array( self::statement( 'u1', 'https://site.example/act/1', 'completed', self::REG_1 ) ) );
		[ $b ] = $this->store_at( '2026-10-27T08:00:02Z', array( self::statement( 'u2', 'https://site.example/act/1', 'attempted' ) ) );
		[ $c ] = $this->store_at( '2026-10-27T08:00:03Z', array( self::statement( 'u1', 'https://site.example/act/2', 'attempted', self::REG_2 ) ) );
		$d     = self::statement( 'u3', 'unused', 'mentored', self::REG_1 );

		$d['object'] = self::agent( 'u1' );
		[ $d ]       = $this->store_at( '2026-10-27T08:00:04Z', array( $d ) );
		return array( $a, $b, $c, $d );
	}

	public function test_each_filter_alone() {
		[ $a, $b, $c, $d ] = $this->seed();

		$this->assertSame( array( $d, $c, $b, $a ), self::ids( $this->query( array() ) ) );
		$this->assertSame( array( $d, $c, $a ), self::ids( $this->query( array( 'agent' => Json::encode_exact( self::agent( 'u1' ) ) ) ) ), 'agent as actor or object' );
		$this->assertSame( array( $a ), self::ids( $this->query( array( 'verb' => 'http://adlnet.gov/expapi/verbs/completed' ) ) ) );
		$this->assertSame( array( $b, $a ), self::ids( $this->query( array( 'activity' => 'https://site.example/act/1' ) ) ) );
		$this->assertSame( array( $d, $a ), self::ids( $this->query( array( 'registration' => strtoupper( self::REG_1 ) ) ) ) );
		$this->assertSame( array( $d, $c ), self::ids( $this->query( array( 'since' => '2026-10-27T08:00:02Z' ) ) ), 'since is exclusive' );
		$this->assertSame( array( $b, $a ), self::ids( $this->query( array( 'until' => '2026-10-27T08:00:02Z' ) ) ), 'until is inclusive' );
		$this->assertSame( array(), self::ids( $this->query( array( 'verb' => 'http://adlnet.gov/expapi/verbs/nothing' ) ) ) );
	}

	public function test_filters_combined() {
		[ $a, $b, $c, $d ] = $this->seed();

		$agent = Json::encode_exact( self::agent( 'u1' ) );
		$this->assertSame(
			array( $d, $a ),
			self::ids(
				$this->query(
					array(
						'agent'        => $agent,
						'registration' => self::REG_1,
					)
				)
			)
		);
		$this->assertSame(
			array( $b ),
			self::ids(
				$this->query(
					array(
						'activity' => 'https://site.example/act/1',
						'since'    => '2026-10-27T08:00:01Z',
					)
				)
			)
		);
		$this->assertSame(
			array( $c ),
			self::ids(
				$this->query(
					array(
						'agent' => $agent,
						'verb'  => 'http://adlnet.gov/expapi/verbs/attempted',
						'until' => '2026-10-27T08:00:03Z',
					)
				)
			)
		);
	}

	/**
	 * Review Focus 2: `since` with an offset and microseconds is compared in UTC, to the microsecond.
	 */
	public function test_since_with_offset_and_microseconds() {
		[ $first ]  = $this->store_at( '2026-10-27T08:00:00.123455Z', array( self::statement() ) );
		[ $second ] = $this->store_at( '2026-10-27T08:00:00.123456Z', array( self::statement() ) );
		[ $third ]  = $this->store_at( '2026-10-27T08:00:00.123457Z', array( self::statement() ) );

		$this->assertSame( array( $third ), self::ids( $this->query( array( 'since' => '2026-10-27T10:00:00.123456+02:00' ) ) ) );
		$this->assertSame( array( $third ), self::ids( $this->query( array( 'since' => '2026-10-27T10:00:00.123456+02:00' ), null, null, '1.0.3' ) ) );
		$this->assertSame( array( $second, $first ), self::ids( $this->query( array( 'until' => '2026-10-27T10:00:00.123456+02:00' ) ) ) );
		$this->assertSame( array( $third, $second ), self::ids( $this->query( array( 'since' => '2026-10-27T03:00:00.123455-05:00' ) ) ) );
	}

	public function test_related_activities_finds_parent() {
		$st                                 = self::statement( 'u1', 'https://site.example/lesson/9' );
		$st['context']['contextActivities'] = array( 'parent' => array( array( 'id' => 'https://site.example/course/1' ) ) );
		[ $id ]                             = $this->store_at( '2026-10-27T08:00:00Z', array( $st, self::statement() ) );

		$this->assertSame( array(), self::ids( $this->query( array( 'activity' => 'https://site.example/course/1' ) ) ) );
		$this->assertSame(
			array( $id ),
			self::ids(
				$this->query(
					array(
						'activity'           => 'https://site.example/course/1',
						'related_activities' => 'true',
					)
				)
			)
		);
		$this->assertSame(
			array( $id ),
			self::ids(
				$this->query(
					array(
						'activity'           => 'https://site.example/lesson/9',
						'related_activities' => 'true',
					)
				)
			),
			'the object still matches'
		);
	}

	public function test_related_agents_finds_instructor() {
		$st                          = self::statement( 'u5' );
		$st['context']['instructor'] = self::agent( 'teacher' );
		[ $id ]                      = $this->store_at( '2026-10-27T08:00:00Z', array( $st, self::statement( 'u6' ) ) );

		$teacher = Json::encode_exact( self::agent( 'teacher' ) );
		$this->assertSame( array(), self::ids( $this->query( array( 'agent' => $teacher ) ) ) );
		$this->assertSame(
			array( $id ),
			self::ids(
				$this->query(
					array(
						'agent'          => $teacher,
						'related_agents' => 'true',
					)
				)
			)
		);
	}

	public function test_voided_excluded_voiding_included() {
		[ $target, $other ] = $this->store_at( '2026-10-27T08:00:00Z', array( self::statement(), self::statement( 'u2' ) ) );
		$voiding            = self::statement( 'u1' );
		$voiding['verb']    = array( 'id' => 'http://adlnet.gov/expapi/verbs/voided' );
		$voiding['object']  = array(
			'objectType' => 'StatementRef',
			'id'         => $target,
		);
		[ $voider ]         = $this->store_at( '2026-10-27T08:00:01Z', array( $voiding ) );

		$this->assertSame( array( $voider, $other ), self::ids( $this->query( array() ) ) );
	}

	public function test_paging_250_rows_three_pages_stable_while_rows_arrive() {
		foreach ( array( 'false', 'true' ) as $ascending ) {
			global $wpdb;
			$wpdb->query( 'DELETE FROM ' . Tables::name( 'statements' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$all = $this->store_at( '2026-10-27T08:00:00Z', array_map( static fn( $i ) => self::statement( 'u' . $i ), range( 1, 250 ) ) );

			$pages = array();
			$res   = $this->query(
				array(
					'limit'     => '100',
					'ascending' => $ascending,
				)
			);
			while ( true ) {
				$pages[] = self::ids( $res );
				$this->assertSame( '2026-10-27T08:59:59.000000Z', $res['consistent_through'] );
				if ( null === $res['more'] ) {
					break;
				}
				$this->assertLessThan( 5, count( $pages ) );
				// New statements arrive between pages; they are stored after the first page's consistent-through.
				$this->store_at( self::NOW, array( self::statement( 'late' ), self::statement( 'late' ) ) );
				$res = $this->query( array(), null, More_Cursor::decode( $res['more'] ) );
			}

			$this->assertSame( array( 100, 100, 50 ), array_map( 'count', $pages ), 'ascending=' . $ascending );
			$expected = 'true' === $ascending ? $all : array_reverse( $all );
			$this->assertSame( $expected, array_merge( ...$pages ), 'ascending=' . $ascending );
		}

		// Rows stored after consistent-through (now - 1 s) are not returned to a fresh query either.
		$this->assertCount( 250, $this->query( array( 'limit' => '500' ) )['rows'] );
	}

	public function test_limit_default_and_cap() {
		$this->store_at( '2026-10-27T08:00:00Z', array_map( static fn( $i ) => self::statement( 'u' . $i ), range( 1, 120 ) ) );
		$this->assertCount( 100, $this->query( array() )['rows'] );
		$this->assertCount( 100, $this->query( array( 'limit' => '0' ) )['rows'] );
		$this->assertCount( 7, $this->query( array( 'limit' => '7' ) )['rows'] );
		$this->assertNull( $this->query( array( 'limit' => '500' ) )['more'] );
		$this->assertSame( 500, Query_Params::from_request( new Xapi_Request( 'GET', 'statements', array( 'limit' => '9000' ), array(), '', '2.0.0' ) )->limit );
	}

	public function test_ascending() {
		[ $a, $b, $c, $d ] = $this->seed();
		$this->assertSame( array( $a, $b, $c, $d ), self::ids( $this->query( array( 'ascending' => 'true' ) ) ) );
		$this->assertSame( array( $d, $c, $b, $a ), self::ids( $this->query( array( 'ascending' => 'false' ) ) ) );
	}

	public function test_read_mine_sees_only_its_own() {
		$mine   = self::principal( array( 'statements/write', 'statements/read/mine' ) );
		$theirs = self::principal( array( 'statements/write', 'statements/read/mine' ) );
		[ $m ]  = $this->store_at( '2026-10-27T08:00:00Z', array( self::statement() ), $mine );
		[ $t ]  = $this->store_at( '2026-10-27T08:00:01Z', array( self::statement() ), $theirs );

		$this->assertSame( array( $m ), self::ids( $this->query( array(), $mine ) ) );
		$this->assertSame( array( $t ), self::ids( $this->query( array(), $theirs ) ) );
		$this->assertSame( array( $t, $m ), self::ids( $this->query( array() ) ) );
	}

	public function test_binding_read_filter_hides_foreign_actors() {
		[ $a, $b, $c, $d ] = $this->seed();
		$bound             = self::principal( array( 'statements/read' ), array( 'bindings' => array( 'actor_ifi' => 'account:https://site.example|u1' ) ) );
		$this->assertSame( array( $c, $a ), self::ids( $this->query( array(), $bound ) ) );
		$this->assertSame( array(), self::ids( $this->query( array( 'agent' => Json::encode_exact( self::agent( 'u2' ) ) ), $bound ) ) );

		$by_reg = self::principal( array( 'statements/read' ), array( 'bindings' => array( 'registration' => self::REG_1 ) ) );
		$this->assertSame( array( $d, $a ), self::ids( $this->query( array(), $by_reg ) ) );
		$this->assertSame( array(), self::ids( $this->query( array( 'registration' => self::REG_2 ), $by_reg ) ) );

		$by_home = self::principal( array( 'statements/read' ), array( 'bindings' => array( 'actor_home_page' => 'https://elsewhere.example' ) ) );
		$this->assertSame( array(), self::ids( $this->query( array(), $by_home ) ) );
		unset( $b );
	}

	public function test_cursor_of_another_principal_is_rejected() {
		$this->store_at( '2026-10-27T08:00:00Z', array_map( static fn( $i ) => self::statement( 'u' . $i ), range( 1, 3 ) ) );
		$res   = $this->query( array( 'limit' => '1' ) );
		$other = self::principal( array( 'statements/read' ) );
		try {
			$this->query( array(), $other, More_Cursor::decode( $res['more'] ) );
			$this->fail( 'Expected 400' );
		} catch ( Xapi_Error $e ) {
			$this->assertSame( 400, $e->status() );
		}
		$this->assertCount( 1, $this->query( array(), null, More_Cursor::decode( $res['more'] ) )['rows'] );
		$this->assertTrue( Uuid::is_valid( $this->query( array(), null, More_Cursor::decode( $res['more'] ) )['rows'][0]->id ) );
	}
}
