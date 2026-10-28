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
use LearningLedger\Lrs\Auth\Token_Repository;
use LearningLedger\Lrs\Auth\Token_Spec;
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

	/**
	 * Child tokens minted; the store's COMMIT keeps them past the test's rollback, so they are removed and committed by hand.
	 *
	 * @var string[]
	 */
	private array $minted = array();

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
		if ( array() !== $this->minted ) {
			foreach ( $this->minted as $key ) {
				$GLOBALS['wpdb']->delete( Tables::name( 'tokens' ), array( 'token_key' => $key ) );
			}
			$GLOBALS['wpdb']->query( 'COMMIT' );
		}
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

	public function test_related_queries_page_in_seq_order_across_ref_kinds() {
		$course = 'https://site.example/course/1';
		$with   = static function ( array $activities, ?array $instructor = null ) {
			$st                                 = self::statement( 'u1', 'https://site.example/lesson/1' );
			$st['context']['contextActivities'] = $activities;
			if ( null !== $instructor ) {
				$st['context']['instructor'] = $instructor;
			}
			return $st;
		};
		[ $s1 ] = $this->store_at( '2026-10-27T08:00:01Z', array( self::statement( 'u1', $course ) ) );
		[ $s2 ] = $this->store_at( '2026-10-27T08:00:02Z', array( $with( array( 'parent' => array( array( 'id' => $course ) ) ) ) ) );
		[ $s3 ] = $this->store_at( '2026-10-27T08:00:03Z', array( $with( array( 'grouping' => array( array( 'id' => $course ) ) ) ) ) );
		[ $s4 ] = $this->store_at(
			'2026-10-27T08:00:04Z',
			array(
				$with(
					array(
						'parent'   => array( array( 'id' => $course ) ),
						'grouping' => array( array( 'id' => $course ) ),
					)
				),
			)
		);
		$this->store_at( '2026-10-27T08:00:05Z', array( self::statement( 'u1', 'https://site.example/other' ) ) );
		[ $s6 ] = $this->store_at( '2026-10-27T08:00:06Z', array( $with( array( 'category' => array( array( 'id' => $course ) ) ), self::agent( 'teacher' ) ) ) );
		[ $v ]  = $this->store_at( '2026-10-27T08:00:07Z', array( self::ref_to( $s3 ) ) );

		$related = array(
			'activity'           => $course,
			'related_activities' => 'true',
			'limit'              => '2',
		);
		$pages   = function ( array $params ): array {
			$out  = array();
			$page = $this->query( $params );
			while ( true ) {
				$out[] = self::ids( $page );
				if ( null === $page['more'] ) {
					return $out;
				}
				$page = $this->query( array(), null, More_Cursor::decode( $page['more'] ) );
			}
		};

		// s3 is voided; its voiding statement matches through it. s4 matches on two kinds, once.
		$this->assertSame( array( array( $v, $s6 ), array( $s4, $s2 ), array( $s1 ) ), $pages( $related ) );
		$this->assertSame( array( array( $s1, $s2 ), array( $s4, $s6 ), array( $v ) ), $pages( array( 'ascending' => 'true' ) + $related ) );
		$this->assertSame( array( array( $v, $s6, $s4, $s2, $s1 ) ), $pages( array( 'limit' => '10' ) + $related ), 'a first page the direct rows do not fill' );

		$both = array(
			'agent'          => Json::encode_exact( self::agent( 'teacher' ) ),
			'related_agents' => 'true',
		) + $related;
		$this->assertSame( array( array( $s6 ) ), $pages( $both ) );
		$this->assertSame( array( array( $v, $s6 ), array( $s4, $s2 ) ), $pages( array( 'since' => '2026-10-27T08:00:01Z' ) + $related ) );
	}

	public function test_related_query_narrowed_by_registration_or_binding() {
		$course = 'https://site.example/course/1';
		$in     = static function ( string $actor, string $registration ) use ( $course ) {
			$st                                 = self::statement( $actor, 'https://site.example/lesson/1', 'completed', $registration );
			$st['context']['contextActivities'] = array( 'parent' => array( array( 'id' => $course ) ) );
			return $st;
		};
		[ $a ]  = $this->store_at( '2026-10-27T08:00:01Z', array( $in( 'u1', self::REG_1 ) ) );
		[ $b ]  = $this->store_at( '2026-10-27T08:00:02Z', array( $in( 'u2', self::REG_2 ) ) );
		[ $c ]  = $this->store_at( '2026-10-27T08:00:03Z', array( $in( 'u1', self::REG_1 ) ) );
		[ $v ]  = $this->store_at( '2026-10-27T08:00:04Z', array( self::ref_to( $a ) ) );
		$query  = array(
			'activity'           => $course,
			'related_activities' => 'true',
		);

		$this->assertSame( array( $v, $c, $b ), self::ids( $this->query( $query ) ) );
		$this->assertSame( array( $v, $c ), self::ids( $this->query( array( 'registration' => self::REG_1 ) + $query ) ) );
		$this->assertSame( array( $b ), self::ids( $this->query( array( 'registration' => self::REG_2 ) + $query ) ) );

		$bound = self::principal( array( 'statements/read' ), array( 'bindings' => array( 'registration' => self::REG_1 ) ) );
		$this->assertSame( array( $c ), self::ids( $this->query( $query, $bound ) ), 'the voiding statement has no registration, so the binding hides it' );
		$this->assertSame( array( $c ), self::ids( $this->query( array( 'limit' => '1' ) + $query, $bound ) ) );
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
	private static function ref_to( string $target, string $actor = 'admin', string $verb = 'voided' ): array {
		$st           = self::statement( $actor, 'unused', $verb );
		$st['object'] = array(
			'objectType' => 'StatementRef',
			'id'         => $target,
		);
		return $st;
	}

	public function test_statement_ref_matches_when_its_target_matches() {
		[ $target ] = $this->store_at( '2026-10-27T08:00:00Z', array( self::statement( 'u1', 'https://site.example/act/1', 'completed', self::REG_1 ) ) );
		[ $voider ] = $this->store_at( '2026-10-27T08:00:01Z', array( self::ref_to( $target ) ) );

		// The voided target is never listed, but the voiding statement matches through it.
		$this->assertSame( array( $voider ), self::ids( $this->query( array( 'verb' => 'http://adlnet.gov/expapi/verbs/completed' ) ) ) );
		$this->assertSame( array( $voider ), self::ids( $this->query( array( 'agent' => Json::encode_exact( self::agent( 'u1' ) ) ) ) ) );
		$this->assertSame( array( $voider ), self::ids( $this->query( array( 'activity' => 'https://site.example/act/1' ) ) ) );
		$this->assertSame( array( $voider ), self::ids( $this->query( array( 'registration' => self::REG_1 ) ) ) );
		$this->assertSame( array( $voider ), self::ids( $this->query( array( 'verb' => 'http://adlnet.gov/expapi/verbs/voided' ) ) ), 'and on its own properties' );
		$this->assertSame( array(), self::ids( $this->query( array( 'verb' => 'http://adlnet.gov/expapi/verbs/attempted' ) ) ) );
	}

	public function test_statement_ref_matching_is_recursive_and_time_filters_apply_to_the_referrer() {
		[ $base ]  = $this->store_at( '2026-10-27T08:00:00Z', array( self::statement( 'u2', 'https://site.example/act/2', 'attempted' ) ) );
		[ $ref_1 ] = $this->store_at( '2026-10-27T08:00:01Z', array( self::ref_to( $base, 'u3', 'commented' ) ) );
		[ $ref_2 ] = $this->store_at( '2026-10-27T08:00:02Z', array( self::ref_to( $ref_1, 'u4', 'liked' ) ) );
		[ $other ] = $this->store_at( '2026-10-27T08:00:03Z', array( self::statement( 'u5' ) ) );
		$attempted = 'http://adlnet.gov/expapi/verbs/attempted';

		$this->assertSame( array( $ref_2, $ref_1, $base ), self::ids( $this->query( array( 'verb' => $attempted ) ) ) );
		$this->assertSame(
			array( $ref_2, $ref_1 ),
			self::ids(
				$this->query(
					array(
						'verb'  => $attempted,
						'since' => '2026-10-27T08:00:00Z',
					)
				)
			)
		);
		$this->assertSame(
			array( $ref_1 ),
			self::ids(
				$this->query(
					array(
						'verb'  => $attempted,
						'since' => '2026-10-27T08:00:00Z',
						'until' => '2026-10-27T08:00:01Z',
					)
				)
			)
		);
		$this->assertSame(
			array( $base, $ref_1, $ref_2 ),
			self::ids(
				$this->query(
					array(
						'verb'      => $attempted,
						'ascending' => 'true',
					)
				)
			)
		);

		// Paging merges direct and referring matches in seq order.
		$page = $this->query(
			array(
				'verb'  => $attempted,
				'limit' => '2',
			)
		);
		$this->assertSame( array( $ref_2, $ref_1 ), self::ids( $page ) );
		$this->assertSame( array( $base ), self::ids( $this->query( array(), null, More_Cursor::decode( $page['more'] ) ) ) );

		// The referring statements must pass the caller's read filter.
		$bound = self::principal( array( 'statements/read' ), array( 'bindings' => array( 'actor_ifi' => 'account:https://site.example|u2' ) ) );
		$this->assertSame( array( $base ), self::ids( $this->query( array( 'verb' => $attempted ), $bound ) ) );
		unset( $other );
	}

	public function test_statement_ref_targets_must_pass_the_read_filter() {
		[ $hidden ]     = $this->store_at( '2026-10-27T08:00:00Z', array( self::statement( 'u2', 'https://site.example/act/1', 'completed', self::REG_1 ) ) );
		[ $to_hidden ]  = $this->store_at( '2026-10-27T08:00:01Z', array( self::ref_to( $hidden, 'u1', 'commented' ) ) );
		[ $own ]        = $this->store_at( '2026-10-27T08:00:02Z', array( self::statement( 'u1', 'https://site.example/act/2', 'completed', self::REG_1 ) ) );
		[ $to_own ]     = $this->store_at( '2026-10-27T08:00:03Z', array( self::ref_to( strtoupper( $own ), 'u1', 'liked' ) ) );
		[ $via_hidden ] = $this->store_at( '2026-10-27T08:00:04Z', array( self::ref_to( $own, 'u2', 'shared' ) ) );
		[ $chained ]    = $this->store_at( '2026-10-27T08:00:05Z', array( self::ref_to( $via_hidden, 'u1', 'liked' ) ) );
		$completed      = 'http://adlnet.gov/expapi/verbs/completed';
		$bound          = self::principal( array( 'statements/read' ), array( 'bindings' => array( 'actor_ifi' => 'account:https://site.example|u1' ) ) );

		// Unbound, every chain matches: by verb (referrer-driven) and by registration (target-driven).
		$all = array( $chained, $via_hidden, $to_own, $own, $to_hidden, $hidden );
		$this->assertSame( $all, self::ids( $this->query( array( 'verb' => $completed ) ) ) );
		$this->assertSame( $all, self::ids( $this->query( array( 'registration' => self::REG_1 ) ) ) );

		// Bound to u1: a statement the caller cannot read never makes a referrer match, at any depth,
		// whether the search starts from the matches or (with since) from the referrers.
		$this->assertSame( array( $to_own, $own ), self::ids( $this->query( array( 'verb' => $completed ), $bound ) ) );
		$this->assertSame(
			array( $to_own, $own ),
			self::ids(
				$this->query(
					array(
						'verb'  => $completed,
						'since' => '2026-10-27T07:00:00Z',
					),
					$bound
				)
			)
		);
		$this->assertSame(
			$all,
			self::ids(
				$this->query(
					array(
						'verb'  => $completed,
						'since' => '2026-10-27T07:00:00Z',
					)
				)
			)
		);
		$this->assertSame( array( $to_own, $own ), self::ids( $this->query( array( 'registration' => self::REG_1 ), $bound ) ) );
		$this->assertSame(
			array( $to_own, $own ),
			self::ids(
				$this->query(
					array(
						'agent' => Json::encode_exact( self::agent( 'u1' ) ),
						'verb'  => $completed,
					),
					$bound
				)
			)
		);
	}

	public function test_child_token_reads_only_its_own_in_its_registration() {
		$c      = Client_Factory::create( array( 'tokens/mint', 'statements/write', 'statements/read/mine' ) );
		$parent = ( new Client_Repository() )->find( $c['id'] );
		Clock::freeze( new DateTimeImmutable( '2026-10-27T07:00:00Z' ) );
		$tokens = new Token_Repository();
		$key    = $tokens->mint( $parent, new Token_Spec( self::REG_1, self::agent( 'u1' ), 'https://site.example/act/1', Uuid::v4(), 'Normal', 28800, array(), false ) )['key'];
		$child  = Principal::for_token( $parent, $tokens->find( $key ) );

		$this->minted[] = $key;

		[ $own ] = $this->store_at( '2026-10-27T08:00:00Z', array( self::statement( 'u1', 'https://site.example/act/1', 'completed', self::REG_1 ) ), $child );
		$this->store_at( '2026-10-27T08:00:01Z', array( self::statement( 'u1', 'https://site.example/act/1', 'completed', self::REG_1 ) ), Principal::for_client( $parent ) );
		$this->store_at( '2026-10-27T08:00:02Z', array( self::statement( 'u1', 'https://site.example/act/1', 'completed', self::REG_2 ) ) );

		$this->assertSame( array( $own ), self::ids( $this->query( array(), $child ) ) );
		$this->assertSame( array(), self::ids( $this->query( array( 'registration' => self::REG_2 ), $child ) ) );
	}
}
