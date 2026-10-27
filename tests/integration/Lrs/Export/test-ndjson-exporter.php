<?php
/**
 * NDJSON export tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Kernel\Capabilities;
use LearningLedger\Lrs\Export\Export_Controller;
use LearningLedger\Lrs\Export\Export_Module;
use LearningLedger\Lrs\Export\Ndjson_Exporter;
use LearningLedger\Lrs\Export\Statements_Command;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;

class Ndjson_Exporter_Test extends WP_UnitTestCase {

	const REGISTRATION = '8b1a4c7e-2f3d-4a5b-9c6d-7e8f90a1b2c3';

	private ?string $file = null;

	public function set_up() {
		parent::set_up();
		Installer::install();
		Capabilities::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'audit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
	}

	public function tear_down() {
		if ( null !== $this->file && file_exists( $this->file ) ) {
			wp_delete_file( $this->file );
		}
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * Insert one statement row directly.
	 *
	 * @param integer     $n            Number (makes the id, time and actor).
	 * @param string|null $registration Registration.
	 * @param string      $padding      Extra bytes inside the body.
	 * @param string      $actor_name   Actor account name.
	 */
	private function add( int $n, ?string $registration = null, string $padding = '', string $actor_name = 'learner' ): array {
		$id     = sprintf( '00000000-0000-4000-8000-%012d', $n );
		$stored = gmdate( 'Y-m-d\TH:i:s', 1700000000 + $n ) . '.000000Z';
		$actor  = array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => 'https://site.example',
				'name'     => $actor_name,
			),
		);
		$body   = Json::encode_exact(
			array(
				'id'        => $id,
				'actor'     => $actor,
				'verb'      => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
				'object'    => array( 'id' => 'https://site.example/act/' . $n ),
				'result'    => new stdClass(),
				'context'   => null === $registration ? new stdClass() : array( 'registration' => $registration ),
				'timestamp' => $stored,
				'stored'    => $stored,
				'authority' => array(
					'objectType' => 'Agent',
					'account'    => array(
						'homePage' => 'https://site.example',
						'name'     => 'writer',
					),
				),
				'version'   => '2.0.0',
				'pad'       => $padding,
			)
		);
		$hash   = sha1( 'account:https://site.example|' . $actor_name, true );
		( new Statement_Repository() )->insert(
			array(
				'statement_id'     => $id,
				'stored'           => gmdate( 'Y-m-d H:i:s', 1700000000 + $n ) . '.000000',
				'timestamp'        => gmdate( 'Y-m-d H:i:s', 1700000000 + $n ) . '.000000',
				'version'          => '2.0.0',
				'verb_hash'        => sha1( 'http://adlnet.gov/expapi/verbs/completed', true ),
				'actor_hash'       => $hash,
				'actor_home_hash'  => null,
				'object_kind'      => 'Activity',
				'object_hash'      => sha1( 'https://site.example/act/' . $n, true ),
				'registration'     => $registration,
				'authority_client' => 'writer',
				'is_voiding'       => false,
				'voids_id'         => null,
				'voided'           => false,
				'content_sha256'   => hash( 'sha256', $body, true ),
				'body'             => $body,
			)
		);
		return array(
			'id'   => $id,
			'body' => $body,
		);
	}

	private function export( array $filters = array() ): array {
		$lines = array();
		$count = ( new Ndjson_Exporter() )->stream(
			$filters,
			static function ( string $line ) use ( &$lines ) {
				$lines[] = $line;
			}
		);
		$this->assertSame( count( $lines ), $count );
		return $lines;
	}

	private function ids( array $lines ): array {
		return array_map( static fn( $l ) => json_decode( $l, true )['statement']['id'], $lines );
	}

	public function test_line_is_the_stored_body_verbatim_with_stored_and_hash() {
		$row   = $this->add( 1 );
		$lines = $this->export();
		$this->assertCount( 1, $lines );
		$line = $lines[0];
		$this->assertStringEndsWith( "\n", $line );
		$this->assertSame( 1, substr_count( $line, "\n" ) );
		$this->assertSame(
			'{"statement":' . $row['body'] . ',"stored":"' . gmdate( 'Y-m-d\TH:i:s', 1700000001 ) . '.000000Z","content_sha256":"' . hash( 'sha256', $row['body'] ) . '"}' . "\n",
			$line
		);
		$this->assertStringContainsString( '"result":{}', $line );
	}

	public function test_exports_1200_rows_in_seq_order_in_chunks_with_bounded_memory() {
		$padding = str_repeat( 'x', 20000 );
		for ( $i = 1; $i <= 1200; $i++ ) {
			$this->add( $i, null, $padding );
		}
		$chunk_queries = 0;
		$spy           = static function ( $query ) use ( &$chunk_queries ) {
			if ( str_contains( $query, 'ORDER BY seq' ) && str_contains( $query, 'LIMIT 500' ) ) {
				++$chunk_queries;
			}
			return $query;
		};
		add_filter( 'query', $spy );

		gc_collect_cycles();
		$baseline = memory_get_usage();
		$peak     = 0;
		$ids      = array();
		$count    = ( new Ndjson_Exporter() )->stream(
			array(),
			static function ( string $line ) use ( &$peak, &$ids, $baseline ) {
				$peak  = max( $peak, memory_get_usage() - $baseline );
				$ids[] = substr( $line, strpos( $line, '"id":"' ) + 6, 36 );
			}
		);
		remove_filter( 'query', $spy );

		$this->assertSame( 1200, $count );
		$this->assertCount( 1200, $ids );
		$expected = array();
		for ( $i = 1; $i <= 1200; $i++ ) {
			$expected[] = sprintf( '00000000-0000-4000-8000-%012d', $i );
		}
		$this->assertSame( $expected, $ids );
		$this->assertSame( 3, $chunk_queries, 'Three chunk queries of at most 500 rows.' );
		// The bodies alone are 24 MB; holding them all would exceed this, one chunk (about 10 MB) does not.
		$this->assertLessThan( 18 * 1024 * 1024, $peak );
	}

	public function test_filters_by_stored_time_registration_and_agent() {
		$this->add( 1 );
		$this->add( 2, self::REGISTRATION );
		$this->add( 3, self::REGISTRATION, '', 'other' );
		$this->add( 4 );
		$id = static fn( int $n ) => sprintf( '00000000-0000-4000-8000-%012d', $n );

		$this->assertSame( array( $id( 3 ), $id( 4 ) ), $this->ids( $this->export( array( 'since' => gmdate( 'Y-m-d\TH:i:s\Z', 1700000003 ) ) ) ) );
		$this->assertSame( array( $id( 1 ), $id( 2 ) ), $this->ids( $this->export( array( 'until' => gmdate( 'Y-m-d\TH:i:s\Z', 1700000002 ) ) ) ) );
		$this->assertSame(
			array( $id( 2 ) ),
			$this->ids(
				$this->export(
					array(
						'since' => gmdate( 'Y-m-d\TH:i:s\Z', 1700000002 ),
						'until' => gmdate( 'Y-m-d\TH:i:s\Z', 1700000002 ),
					)
				)
			)
		);
		$this->assertSame( array( $id( 2 ), $id( 3 ) ), $this->ids( $this->export( array( 'registration' => strtoupper( self::REGISTRATION ) ) ) ) );
		$agent = wp_json_encode(
			array(
				'account' => array(
					'homePage' => 'https://site.example',
					'name'     => 'other',
				),
			)
		);
		$this->assertSame( array( $id( 3 ) ), $this->ids( $this->export( array( 'agent' => $agent ) ) ) );
		$this->assertSame(
			array( $id( 3 ) ),
			$this->ids(
				$this->export(
					array(
						'agent'        => $agent,
						'registration' => self::REGISTRATION,
					)
				)
			)
		);
		$this->assertSame( array(), $this->export( array( 'registration' => '11111111-1111-4111-8111-111111111111' ) ) );
	}

	public function test_invalid_filters_are_rejected() {
		foreach ( array(
			array( 'since' => 'yesterday' ),
			array( 'until' => '2024-13-45T00:00:00Z' ),
			array( 'registration' => 'not-a-uuid' ),
			array( 'agent' => '{not json' ),
			array( 'agent' => '{"name":"no ifi"}' ),
		) as $filters ) {
			try {
				$this->export( $filters );
				$this->fail( 'Expected Invalid_Input for ' . wp_json_encode( $filters ) );
			} catch ( Invalid_Input $e ) {
				$this->assertNotSame( '', $e->error_code() );
			}
		}
	}

	public function test_filter_values_cannot_inject_sql() {
		$this->add( 1 );
		try {
			$this->export( array( 'registration' => "' OR 1=1 -- " ) );
		} catch ( Invalid_Input $e ) {
			unset( $e );
		}
		$this->assertCount( 1, $this->export() );
	}

	// REST route.

	private function boot_rest(): void {
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		add_action( 'rest_api_init', array( new Export_Controller(), 'routes' ) );
		do_action( 'rest_api_init', $wp_rest_server );
	}

	private function call( array $params = array(), bool $nonce = true ): WP_REST_Response {
		$this->boot_rest();
		$request = new WP_REST_Request( 'GET', '/learning-ledger/v1/export' );
		$request->set_query_params( $params );
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_user_without_the_export_capability_gets_403() {
		$this->add( 1 );
		foreach ( array( 'subscriber', 'editor' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$this->assertSame( 403, $this->call()->get_status(), $role );
		}
		$manager = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $manager )->add_cap( Capabilities::MANAGE );
		wp_set_current_user( $manager );
		$this->assertSame( 403, $this->call()->get_status(), 'lledger_manage alone is not enough.' );
	}

	public function test_signed_out_gets_401_and_a_missing_nonce_gets_403() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call()->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 403, $this->call( array(), false )->get_status() );
	}

	public function test_an_exporter_gets_an_ndjson_attachment_stream() {
		$this->add( 1 );
		$this->add( 2 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$response = $this->call();
		$this->assertSame( 200, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertStringStartsWith( 'application/x-ndjson', $headers['Content-Type'] );
		$this->assertMatchesRegularExpression( '/^attachment; filename="learning-ledger-statements-[0-9TZ-]+\.ndjson"$/', $headers['Content-Disposition'] );
		$this->assertSame( 'no-store', $headers['Cache-Control'] );

		$request = new WP_REST_Request( 'GET', '/learning-ledger/v1/export' );
		ob_start();
		$served = ( new Export_Module() )->serve( false, $response, $request, rest_get_server() );
		$output = ob_get_clean();
		$this->assertTrue( $served );
		$lines = explode( "\n", rtrim( $output, "\n" ) );
		$this->assertCount( 2, $lines );
		$this->assertSame( sprintf( '00000000-0000-4000-8000-%012d', 2 ), json_decode( $lines[1], true )['statement']['id'] );
	}

	public function test_serve_ignores_other_routes() {
		$request = new WP_REST_Request( 'GET', '/learning-ledger/v1/clients' );
		$this->assertFalse( ( new Export_Module() )->serve( false, new WP_REST_Response( array( 'a' => 1 ), 200 ), $request, rest_get_server() ) );
	}

	public function test_a_bad_filter_is_a_400() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 400, $this->call( array( 'registration' => 'nope' ) )->get_status() );
		$this->assertSame( 400, $this->call( array( 'since' => 'nope' ) )->get_status() );
	}

	public function test_the_streamed_response_filters_and_audits() {
		$this->add( 1 );
		$this->add( 2, self::REGISTRATION );
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$response = $this->call( array( 'registration' => self::REGISTRATION ) );
		$request  = new WP_REST_Request( 'GET', '/learning-ledger/v1/export' );
		$request->set_query_params( array( 'registration' => self::REGISTRATION ) );
		ob_start();
		( new Export_Module() )->serve( false, $response, $request, rest_get_server() );
		$output = ob_get_clean();
		$this->assertCount( 1, explode( "\n", rtrim( $output, "\n" ) ) );

		$rows = $GLOBALS['wpdb']->get_results( 'SELECT event, actor, data FROM ' . Tables::name( 'audit' ) . " WHERE event LIKE 'statements.export%' ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array( 'statements.export', 'statements.export_done' ), array_column( $rows, 'event' ) );
		$start = json_decode( $rows[0]['data'], true );
		$end   = json_decode( $rows[1]['data'], true );
		$this->assertSame( 'user:' . $user, $rows[0]['actor'] );
		$this->assertSame( self::REGISTRATION, $start['filters']['registration'] );
		$this->assertSame( 1, $end['count'] );
		$this->assertTrue( $end['complete'] );
		$this->assertStringNotContainsString( 'learner', $rows[0]['data'] . $rows[1]['data'] );
	}

	public function test_a_head_request_streams_nothing_and_audits_nothing() {
		$this->add( 1 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$response = $this->call();
		$request  = new WP_REST_Request( 'HEAD', '/learning-ledger/v1/export' );
		ob_start();
		$served = ( new Export_Module() )->serve( false, $response, $request, rest_get_server() );
		$output = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( '', $output );
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'audit' ) . " WHERE event LIKE 'statements.export%'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	// WP-CLI.

	public function test_cli_export_writes_a_private_file_outside_the_web_root() {
		$this->add( 1 );
		$this->add( 2 );
		$this->file = sys_get_temp_dir() . '/ll-export-' . bin2hex( random_bytes( 4 ) ) . '.ndjson';
		$count      = Statements_Command::export_to_file( $this->file, array() );
		$this->assertSame( 2, $count );
		$this->assertSame( 2, count( file( $this->file ) ) );
		$this->assertSame( '0600', substr( sprintf( '%o', fileperms( $this->file ) ), -4 ) );
		$rows = $GLOBALS['wpdb']->get_col( 'SELECT event FROM ' . Tables::name( 'audit' ) . " WHERE actor = 'cli' AND event LIKE 'statements.export%' ORDER BY id" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array( 'statements.export', 'statements.export_done' ), $rows );
	}

	public function test_cli_export_refuses_the_web_root_and_existing_files() {
		$this->add( 1 );
		foreach ( array( ABSPATH . 'leak.ndjson', wp_upload_dir()['basedir'] . '/leak.ndjson', WP_CONTENT_DIR . '/leak.ndjson' ) as $path ) {
			try {
				Statements_Command::export_to_file( $path, array() );
				$this->fail( 'Expected a refusal for ' . $path );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'unsafe_path', $e->error_code() );
			}
			$this->assertFileDoesNotExist( $path );
		}
		$this->file = sys_get_temp_dir() . '/ll-export-' . bin2hex( random_bytes( 4 ) ) . '.ndjson';
		file_put_contents( $this->file, 'keep' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		try {
			Statements_Command::export_to_file( $this->file, array() );
			$this->fail( 'Expected a refusal to overwrite.' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'file_exists', $e->error_code() );
		}
		$this->assertSame( 'keep', file_get_contents( $this->file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
