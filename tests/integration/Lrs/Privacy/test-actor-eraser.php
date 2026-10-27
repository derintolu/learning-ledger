<?php
/**
 * Actor erasure tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Auth\Token_Repository;
use LearningLedger\Lrs\Auth\Token_Spec;
use LearningLedger\Lrs\Documents\Doc_Key;
use LearningLedger\Lrs\Documents\Document_Store;
use LearningLedger\Lrs\Privacy\Actor_Eraser;
use LearningLedger\Lrs\Privacy\Erase_Module;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Xapi\Agent_Identity;

class Actor_Eraser_Test extends WP_UnitTestCase {

	const ACT  = 'https://site.example/act/1';
	const REG  = '6f1c3c1e-9a5e-4c1b-8d4e-2b7f0c9a1d11';
	const SESS = '0b6c7a52-5d0e-4c3a-9f1e-6a2b3c4d5e6f';

	/**
	 * Active failure-injection query filter.
	 *
	 * @var Closure|null
	 */
	private static ?Closure $failer = null;

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'documents', 'tokens', 'links', 'audit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		( new Erase_Module() )->register();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_actions( 'learning_ledger/actor_erased' );
		self::stop_failing();
		$GLOBALS['wpdb']->suppress_errors( false );
		parent::tear_down();
	}

	private static function agent( string $name, string $home = 'https://site.example' ): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => $home,
				'name'     => $name,
			),
		);
	}

	private static function statement( array $actor, array $context = array() ): array {
		$st = array(
			'actor'  => $actor,
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object' => array(
				'objectType' => 'Activity',
				'id'         => self::ACT,
			),
		);
		if ( array() !== $context ) {
			$st['context'] = $context;
		}
		return $st;
	}

	private static function store( array $statements ): array {
		$c = Client_Factory::create( array( Scopes::STATEMENTS_WRITE ) );
		$p = Principal::for_client( ( new Client_Repository() )->find( $c['id'] ) );
		return ( new Statement_Store() )->store( $statements, $p, '2.0.0' );
	}

	private static function rows( string $table, string $where = '1=1', array $args = array() ): int {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . Tables::name( $table ) . ' WHERE ' . $where;
		return (int) $wpdb->get_var( array() === $args ? $sql : $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function seq_of( string $id ): int {
		return ( new Statement_Repository() )->find( $id )->seq;
	}

	private static function refs_of_seqs( array $seqs ): int {
		global $wpdb;
		$in = implode( ',', array_map( 'intval', $seqs ) );
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'statement_refs' ) . " WHERE seq IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function put_documents( Agent_Identity $a ): void {
		$docs = new Document_Store();
		$docs->put( new Doc_Key( 'state', self::ACT, $a, self::REG, 'progress' ), '{"page":3}', 'application/json', null, null );
		$docs->put( new Doc_Key( 'agent_profile', null, $a, null, 'prefs' ), '{"lang":"en"}', 'application/json', null, null );
	}

	private static function basic( string $user, string $pass ): string {
		return 'Basic ' . base64_encode( $user . ':' . $pass ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private function post_erase( array $client, mixed $body ): WP_REST_Response {
		$r = new WP_REST_Request( 'POST', '/learning-ledger/v1/actors/erase' );
		if ( array() !== $client ) {
			$r->set_header( 'Authorization', self::basic( $client['id'], $client['secret'] ) );
		}
		$r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $r );
	}

	public function test_erase_removes_statements_refs_state_agent_profile_and_agent_row() {
		$a   = Agent_Identity::from_array( self::agent( 'erased' ) );
		$b   = Agent_Identity::from_array( self::agent( 'kept' ) );
		$ids = self::store( array( self::statement( self::agent( 'erased' ) ), self::statement( self::agent( 'erased' ) ), self::statement( self::agent( 'kept' ) ) ) );
		self::put_documents( $a );
		self::put_documents( $b );
		$own_refs = self::refs_of_seqs( array( self::seq_of( $ids[0] ), self::seq_of( $ids[1] ) ) );
		$kept_seq = self::seq_of( $ids[2] );
		$this->assertGreaterThan( 0, $own_refs );
		$this->assertSame( 1, self::rows( 'agents', 'ifi_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );

		$counts = ( new Actor_Eraser() )->erase( $a, 'client:test' );

		$this->assertSame( 2, $counts['statements'] );
		$this->assertSame( $own_refs, $counts['refs'] );
		$this->assertSame( 2, $counts['documents'] );
		$this->assertSame( 1, $counts['agents'] );
		$this->assertSame( 0, self::rows( 'statements', 'actor_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
		$this->assertNull( ( new Statement_Repository() )->find( $ids[0] ) );
		$this->assertNull( ( new Statement_Repository() )->find( $ids[1] ) );
		$this->assertSame( 0, self::rows( 'statement_refs', 'ref_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
		$this->assertSame( 0, self::rows( 'statement_refs', 'seq NOT IN (' . $kept_seq . ')' ) );
		$this->assertSame( 0, self::rows( 'documents', 'agent_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
		$this->assertSame( 0, self::rows( 'agents', 'ifi_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );

		$this->assertNotNull( ( new Statement_Repository() )->find( $ids[2] ) );
		$this->assertGreaterThan( 0, self::rows( 'statement_refs', 'seq = %d', array( $kept_seq ) ) );
		$this->assertSame( 2, self::rows( 'documents', 'agent_hash = UNHEX(%s)', array( bin2hex( $b->hash() ) ) ) );
		$this->assertSame( 1, self::rows( 'agents', 'ifi_hash = UNHEX(%s)', array( bin2hex( $b->hash() ) ) ) );
	}

	public function test_other_statement_naming_actor_as_instructor_keeps_body_and_ref_becomes_tombstone() {
		$a    = Agent_Identity::from_array( self::agent( 'erased' ) );
		$ids  = self::store( array( self::statement( self::agent( 'kept' ), array( 'instructor' => self::agent( 'erased' ) ) ) ) );
		$repo = new Statement_Repository();
		$body = $repo->find( $ids[0] )->body;
		$seq  = self::seq_of( $ids[0] );
		$this->assertSame( 1, self::rows( 'statement_refs', 'ref_hash = UNHEX(%s) AND ref_kind = %s AND seq = %d', array( bin2hex( $a->hash() ), 'instructor', $seq ) ) );

		$counts = ( new Actor_Eraser() )->erase( $a, 'client:test' );

		$this->assertSame( 0, $counts['statements'] );
		$this->assertSame( 1, $counts['refs'] );
		$after = $repo->find( $ids[0] );
		$this->assertNotNull( $after );
		$this->assertSame( $body, $after->body );
		$this->assertSame( 0, self::rows( 'statement_refs', 'ref_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
		$this->assertSame( 1, self::rows( 'statement_refs', 'ref_hash = UNHEX(%s) AND ref_kind = %s AND seq = %d', array( sha1( 'tombstone' ), 'instructor', $seq ) ) );
	}

	public function test_two_erased_agents_in_one_list_leave_one_tombstone_row() {
		$x   = self::agent( 'x' );
		$y   = self::agent( 'y' );
		$ids = self::store(
			array(
				self::statement(
					self::agent( 'kept' ),
					array(
						'contextAgents' => array(
							array(
								'objectType' => 'contextAgent',
								'agent'      => $x,
							),
							array(
								'objectType' => 'contextAgent',
								'agent'      => $y,
							),
						),
					)
				),
			)
		);
		$seq = self::seq_of( $ids[0] );

		( new Actor_Eraser() )->erase( Agent_Identity::from_array( $x ), 'client:test' );
		$counts = ( new Actor_Eraser() )->erase( Agent_Identity::from_array( $y ), 'client:test' );

		$this->assertSame( 1, $counts['refs'] );
		$this->assertSame( 1, self::rows( 'statement_refs', 'ref_hash = UNHEX(%s) AND ref_kind = %s AND seq = %d', array( sha1( 'tombstone' ), 'context-agent', $seq ) ) );
		$this->assertSame( 0, self::rows( 'statement_refs', 'ref_hash IN (UNHEX(%s), UNHEX(%s))', array( bin2hex( Agent_Identity::from_array( $x )->hash() ), bin2hex( Agent_Identity::from_array( $y )->hash() ) ) ) );
	}

	public function test_agent_object_hash_of_other_statement_becomes_tombstone() {
		$a            = Agent_Identity::from_array( self::agent( 'erased' ) );
		$st           = self::statement( self::agent( 'kept' ) );
		$st['object'] = self::agent( 'erased' );
		$ids          = self::store( array( $st ) );

		( new Actor_Eraser() )->erase( $a, 'client:test' );

		$this->assertNotNull( ( new Statement_Repository() )->find( $ids[0] ) );
		$this->assertSame( 0, self::rows( 'statements', 'object_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
		$this->assertSame( 1, self::rows( 'statements', 'object_hash = UNHEX(%s)', array( sha1( 'tombstone' ) ) ) );
	}

	public function test_erase_removes_the_actors_tokens_and_links() {
		$a = Agent_Identity::from_array( self::agent( 'erased' ) );
		$c = Client_Factory::create( array( Scopes::TOKENS_MINT, Scopes::STATEMENTS_WRITE ) );
		( new Token_Repository() )->mint( ( new Client_Repository() )->find( $c['id'] ), new Token_Spec( self::REG, self::agent( 'erased' ), self::ACT, self::SESS, 'Normal' ) );
		( new Token_Repository() )->mint( ( new Client_Repository() )->find( $c['id'] ), new Token_Spec( self::REG, self::agent( 'kept' ), self::ACT, self::SESS, 'Normal' ) );
		global $wpdb;
		foreach ( array( $a->hash(), Agent_Identity::from_array( self::agent( 'kept' ) )->hash() ) as $hash ) {
			$wpdb->insert(
				Tables::name( 'links' ),
				array(
					'token_hash' => random_bytes( 32 ),
					'kind'       => 'view',
					'actor_hash' => $hash,
					'subject'    => 'record',
					'expires_at' => '2026-10-27 10:15:00.000000',
					'created_by' => 'client:test',
				)
			);
		}

		$counts = ( new Actor_Eraser() )->erase( $a, 'client:test' );

		$this->assertSame( 1, $counts['tokens'] );
		$this->assertSame( 1, $counts['links'] );
		$this->assertSame( 0, self::rows( 'tokens', 'actor_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
		$this->assertSame( 0, self::rows( 'links', 'actor_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
		$this->assertSame( 1, self::rows( 'tokens' ) );
		$this->assertSame( 1, self::rows( 'links' ) );
	}

	public function test_actor_erased_fired_with_hash_and_counts() {
		$a = Agent_Identity::from_array( self::agent( 'erased' ) );
		self::store( array( self::statement( self::agent( 'erased' ) ) ) );
		$seen = array();
		add_action(
			'learning_ledger/actor_erased',
			static function ( string $hash, array $counts ) use ( &$seen ) {
				$seen[] = array( $hash, $counts );
			},
			10,
			2
		);

		$counts = ( new Actor_Eraser() )->erase( $a, 'client:test' );

		$this->assertCount( 1, $seen );
		$this->assertSame( $a->hash(), $seen[0][0] );
		$this->assertSame( $counts, $seen[0][1] );
		$this->assertSame( 1, $seen[0][1]['statements'] );
	}

	public function test_erase_is_idempotent() {
		$a = Agent_Identity::from_array( self::agent( 'erased' ) );
		self::store( array( self::statement( self::agent( 'erased' ) ) ) );
		self::put_documents( $a );
		( new Actor_Eraser() )->erase( $a, 'client:test' );

		$again = ( new Actor_Eraser() )->erase( $a, 'client:test' );

		$this->assertSame(
			array(
				'statements' => 0,
				'refs'       => 0,
				'documents'  => 0,
				'agents'     => 0,
				'tokens'     => 0,
				'links'      => 0,
			),
			$again
		);
	}

	public function test_audit_row_has_counts_only() {
		$a = Agent_Identity::from_array( self::agent( 'erased-learner-name' ) );
		self::store( array( self::statement( self::agent( 'erased-learner-name' ) ) ) );

		$counts = ( new Actor_Eraser() )->erase( $a, 'client:course-server' );

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT actor, data FROM ' . Tables::name( 'audit' ) . ' WHERE event = %s', 'actor_erased' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertCount( 1, $rows );
		$this->assertSame( 'client:course-server', $rows[0]['actor'] );
		$this->assertSame( $counts, json_decode( $rows[0]['data'], true ) );
		$this->assertStringNotContainsString( 'erased-learner-name', $rows[0]['data'] );
		$this->assertStringNotContainsString( bin2hex( $a->hash() ), $rows[0]['data'] );
	}

	public function test_route_erases_actor_within_binding_and_returns_counts() {
		$a = Agent_Identity::from_array( self::agent( 'erased' ) );
		self::store( array( self::statement( self::agent( 'erased' ) ) ) );
		$c = Client_Factory::create( array( Scopes::ACTORS_ERASE ), array( 'bindings' => array( 'actor_home_page' => 'https://site.example' ) ) );

		$res = $this->post_erase( $c, array( 'actor' => self::agent( 'erased' ) ) );

		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 1, $data['statements'] );
		$this->assertSame( 1, $data['agents'] );
		$this->assertSame( 0, self::rows( 'statements', 'actor_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
		$this->assertSame( 'client:' . $c['id'], $GLOBALS['wpdb']->get_var( 'SELECT actor FROM ' . Tables::name( 'audit' ) . " WHERE event = 'actor_erased'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_route_actor_outside_binding_403_and_nothing_erased() {
		$a = Agent_Identity::from_array( self::agent( 'erased' ) );
		self::store( array( self::statement( self::agent( 'erased' ) ) ) );
		$c = Client_Factory::create( array( Scopes::ACTORS_ERASE ), array( 'bindings' => array( 'actor_home_page' => 'https://other.example' ) ) );

		$res = $this->post_erase( $c, array( 'actor' => self::agent( 'erased' ) ) );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 1, self::rows( 'statements', 'actor_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
		$this->assertSame( 0, self::rows( 'audit', 'event = %s', array( 'actor_erased' ) ) );
	}

	public function test_route_without_the_scope_403() {
		self::store( array( self::statement( self::agent( 'erased' ) ) ) );
		$c = Client_Factory::create( array( Scopes::ALL ) );

		$res = $this->post_erase( $c, array( 'actor' => self::agent( 'erased' ) ) );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 1, self::rows( 'statements' ) );
	}

	public function test_route_without_credentials_401_with_www_authenticate() {
		$res = $this->post_erase( array(), array( 'actor' => self::agent( 'erased' ) ) );

		$this->assertSame( 401, $res->get_status() );
		$this->assertArrayHasKey( 'WWW-Authenticate', $res->get_headers() );
	}

	public function test_route_invalid_actor_400() {
		$c = Client_Factory::create( array( Scopes::ACTORS_ERASE ) );

		$this->assertSame( 400, $this->post_erase( $c, array( 'actor' => array( 'name' => 'no ifi' ) ) )->get_status() );
		$this->assertSame( 400, $this->post_erase( $c, array( 'nope' => true ) )->get_status() );
		$this->assertSame( 400, $this->post_erase( $c, 'not an object' )->get_status() );
	}

	/**
	 * Make the first query starting with a prefix fail, as a lock-wait timeout would.
	 *
	 * @param string $prefix Query prefix (after the table prefix is filled in).
	 */
	private static function fail_query( string $prefix ): void {
		$GLOBALS['wpdb']->suppress_errors( true );
		self::$failer = static function ( string $q ) use ( $prefix ) {
			return str_starts_with( $q, $prefix ) ? 'DELETE FROM lledger_no_such_table_t44' : $q;
		};
		add_filter( 'query', self::$failer );
	}

	/**
	 * Remove only our query filter (the test framework has its own).
	 */
	private static function stop_failing(): void {
		if ( null !== self::$failer ) {
			remove_filter( 'query', self::$failer );
			self::$failer = null;
		}
	}

	private function assert_nothing_erased( Agent_Identity $a, int $statements, int $refs ): void {
		$this->assertSame( $statements, self::rows( 'statements', 'actor_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
		$this->assertSame( $refs, self::rows( 'statement_refs' ) );
		$this->assertSame( 2, self::rows( 'documents', 'agent_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
		$this->assertSame( 1, self::rows( 'agents', 'ifi_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
		$this->assertSame( 0, self::rows( 'audit', 'event = %s', array( 'actor_erased' ) ) );
	}

	public function test_failed_statements_delete_rolls_back_and_route_answers_500() {
		$a = Agent_Identity::from_array( self::agent( 'erased' ) );
		self::store( array( self::statement( self::agent( 'erased' ) ), self::statement( self::agent( 'kept' ), array( 'instructor' => self::agent( 'erased' ) ) ) ) );
		self::put_documents( $a );
		$refs  = self::rows( 'statement_refs' );
		$fired = 0;
		add_action(
			'learning_ledger/actor_erased',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$c = Client_Factory::create( array( Scopes::ACTORS_ERASE ) );
		self::fail_query( 'DELETE FROM ' . Tables::name( 'statements' ) . ' WHERE actor_hash' );

		$res = $this->post_erase( $c, array( 'actor' => self::agent( 'erased' ) ) );

		$this->assertSame( 500, $res->get_status() );
		self::stop_failing();
		$this->assert_nothing_erased( $a, 1, $refs );
		$this->assertSame( 1, self::rows( 'statement_refs', 'ref_hash = UNHEX(%s) AND ref_kind = %s', array( bin2hex( $a->hash() ), 'instructor' ) ) );
		$this->assertSame( 0, $fired );
	}

	public function test_failed_refs_delete_inside_delete_for_actor_rolls_back() {
		$a = Agent_Identity::from_array( self::agent( 'erased' ) );
		self::store( array( self::statement( self::agent( 'erased' ) ) ) );
		self::put_documents( $a );
		$refs = self::rows( 'statement_refs' );
		self::fail_query( 'DELETE r FROM ' . Tables::name( 'statement_refs' ) );

		try {
			( new Actor_Eraser() )->erase( $a, 'client:test' );
			$this->fail( 'Expected the erasure to fail.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Could not delete the actor\'s statements.', $e->getMessage() );
		}

		self::stop_failing();
		$this->assert_nothing_erased( $a, 1, $refs );
	}

	public function test_failed_agent_delete_rolls_back() {
		$a = Agent_Identity::from_array( self::agent( 'erased' ) );
		self::store( array( self::statement( self::agent( 'erased' ) ) ) );
		self::put_documents( $a );
		$refs = self::rows( 'statement_refs' );
		self::fail_query( 'DELETE FROM ' . Tables::name( 'agents' ) );

		$this->expectException( RuntimeException::class );
		try {
			( new Actor_Eraser() )->erase( $a, 'client:test' );
		} finally {
			self::stop_failing();
			$this->assert_nothing_erased( $a, 1, $refs );
		}
	}

	public function test_audit_row_is_written_before_listeners_run() {
		$a     = Agent_Identity::from_array( self::agent( 'erased' ) );
		$audit = null;
		add_action(
			'learning_ledger/actor_erased',
			static function () use ( &$audit ) {
				$audit = self::rows( 'audit', 'event = %s', array( 'actor_erased' ) );
			}
		);

		( new Actor_Eraser() )->erase( $a, 'client:test' );

		$this->assertSame( 1, $audit );
	}

	public function test_failing_listener_does_not_fail_the_committed_erasure() {
		$a = Agent_Identity::from_array( self::agent( 'erased' ) );
		self::store( array( self::statement( self::agent( 'erased' ) ) ) );
		add_action(
			'learning_ledger/actor_erased',
			static function () {
				throw new RuntimeException( 'listener broke' );
			}
		);
		$c   = Client_Factory::create( array( Scopes::ACTORS_ERASE ) );
		$log = ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keeps the expected log line out of the test output.

		try {
			$res = $this->post_erase( $c, array( 'actor' => self::agent( 'erased' ) ) );
		} finally {
			ini_set( 'error_log', (string) $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 1, $res->get_data()['statements'] );
		$this->assertSame( 0, self::rows( 'statements', 'actor_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
		$this->assertSame( 1, self::rows( 'audit', 'event = %s', array( 'actor_erased' ) ) );
	}

	public function test_route_with_actor_ifi_binding_erases_only_that_actor() {
		$a     = Agent_Identity::from_array( self::agent( 'bound' ) );
		$other = Agent_Identity::from_array( self::agent( 'other' ) );
		self::store( array( self::statement( self::agent( 'bound' ) ), self::statement( self::agent( 'other' ) ) ) );
		$c = Client_Factory::create( array( Scopes::ACTORS_ERASE ), array( 'bindings' => array( 'actor_ifi' => $a->ifi_key() ) ) );

		$denied  = $this->post_erase( $c, array( 'actor' => self::agent( 'other' ) ) );
		$allowed = $this->post_erase( $c, array( 'actor' => self::agent( 'bound' ) ) );

		$this->assertSame( 403, $denied->get_status() );
		$this->assertSame( 1, self::rows( 'statements', 'actor_hash = UNHEX(%s)', array( bin2hex( $other->hash() ) ) ) );
		$this->assertSame( 200, $allowed->get_status() );
		$this->assertSame( 1, $allowed->get_data()['statements'] );
		$this->assertSame( 0, self::rows( 'statements', 'actor_hash = UNHEX(%s)', array( bin2hex( $a->hash() ) ) ) );
	}

	public function test_route_erases_an_identified_group_actor() {
		$group = array(
			'objectType' => 'Group',
			'name'       => 'Team',
			'account'    => array(
				'homePage' => 'https://site.example',
				'name'     => 'team-1',
			),
			'member'     => array( self::agent( 'member' ) ),
		);
		$g     = Agent_Identity::from_array( $group );
		self::store( array( self::statement( $group ), self::statement( self::agent( 'member' ) ) ) );
		$c = Client_Factory::create( array( Scopes::ACTORS_ERASE ), array( 'bindings' => array( 'actor_home_page' => 'https://site.example' ) ) );

		$res = $this->post_erase( $c, array( 'actor' => $group ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 1, $res->get_data()['statements'] );
		$this->assertSame( 1, $res->get_data()['agents'] );
		$this->assertSame( 0, self::rows( 'statements', 'actor_hash = UNHEX(%s)', array( bin2hex( $g->hash() ) ) ) );
		$this->assertSame( 1, self::rows( 'statements' ) );
		$this->assertSame( 1, self::rows( 'agents', 'ifi_hash = UNHEX(%s)', array( bin2hex( Agent_Identity::from_array( self::agent( 'member' ) )->hash() ) ) ) );
	}
}
