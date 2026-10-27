<?php
/**
 * Migration import tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Resources\Activity_Repository;
use LearningLedger\Lrs\Export\Migration_Importer;
use LearningLedger\Lrs\Export\Ndjson_Exporter;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Xapi\Agent_Identity;

class Migration_Importer_Test extends WP_UnitTestCase {

	private ?string $file = null;

	public function set_up() {
		parent::set_up();
		Installer::install();
		$this->wipe();
		add_action( 'learning_ledger/statements_stored', array( $this, 'count_stored_hook' ) );
	}

	public function tear_down() {
		remove_action( 'learning_ledger/statements_stored', array( $this, 'count_stored_hook' ) );
		if ( null !== $this->file && file_exists( $this->file ) ) {
			wp_delete_file( $this->file );
		}
		parent::tear_down();
	}

	private int $stored_hook_calls = 0;

	private string $client_id = '';

	public function count_stored_hook(): void {
		++$this->stored_hook_calls;
	}

	private function wipe(): void {
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'audit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	private static function statement( string $name, string $activity = 'one' ): array {
		return array(
			'actor'   => array(
				'objectType' => 'Agent',
				'account'    => array(
					'homePage' => 'https://site.example',
					'name'     => $name,
				),
			),
			'verb'    => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object'  => array(
				'id'         => 'https://site.example/act/' . $activity,
				'definition' => array( 'name' => array( 'en-US' => 'Activity ' . $activity ) ),
			),
			'result'  => new stdClass(),
			'context' => array( 'registration' => '8b1a4c7e-2f3d-4a5b-9c6d-7e8f90a1b2c3' ),
		);
	}

	/**
	 * Store three statements and a voider through the real store.
	 *
	 * @return string[] Statement ids: a, b, c, void-of-b.
	 */
	private function seed(): array {
		$c               = Client_Factory::create( array( 'statements/write', 'void' ) );
		$this->client_id = $c['id'];
		$principal       = Principal::for_client( ( new Client_Repository() )->find( $c['id'] ) );
		$store           = new Statement_Store();
		$ids             = $store->store( array( self::statement( 'u1', 'a' ), self::statement( 'u2', 'b' ), self::statement( 'u1', 'c' ) ), $principal, '2.0.0' );
		$void            = self::statement( 'admin' );
		$void['verb']    = array( 'id' => 'http://adlnet.gov/expapi/verbs/voided' );
		$void['object']  = array(
			'objectType' => 'StatementRef',
			'id'         => $ids[1],
		);
		unset( $void['context'], $void['result'] );
		$ids[] = $store->store( array( $void ), $principal, '2.0.0' )[0];
		return $ids;
	}

	private function export_to_file(): string {
		$this->file = sys_get_temp_dir() . '/ll-import-' . bin2hex( random_bytes( 4 ) ) . '.ndjson';
		$handle     = fopen( $this->file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		( new Ndjson_Exporter() )->stream(
			array(),
			static function ( string $line ) use ( $handle ) {
				fwrite( $handle, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			}
		);
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $this->file;
	}

	private function rows(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT seq, LOWER(HEX(statement_id)) AS id, `stored`, voided, is_voiding, LOWER(HEX(content_sha256)) AS sha, body FROM ' . Tables::name( 'statements' ) . ' ORDER BY seq', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public function test_import_into_a_clean_install_keeps_stored_authority_and_hashes() {
		$ids    = $this->seed();
		$before = $this->rows();
		$path   = $this->export_to_file();
		$this->assertCount( 4, $before );
		$this->assertSame( '1', $before[1]['voided'] );

		$this->wipe();
		$this->stored_hook_calls = 0;
		$result                  = ( new Migration_Importer() )->import( $path );
		$this->assertSame(
			array(
				'imported' => 4,
				'skipped'  => 0,
				'rejected' => 0,
			),
			$result
		);

		$after = $this->rows();
		$this->assertCount( 4, $after );
		foreach ( $before as $i => $row ) {
			$this->assertSame( $row['id'], $after[ $i ]['id'] );
			$this->assertSame( $row['stored'], $after[ $i ]['stored'] );
			$this->assertSame( $row['sha'], $after[ $i ]['sha'] );
			$this->assertSame( $row['body'], $after[ $i ]['body'], 'The body is kept byte for byte.' );
			$this->assertSame( $row['voided'], $after[ $i ]['voided'], 'The voided flag follows the voiding statement.' );
			$this->assertSame( $row['is_voiding'], $after[ $i ]['is_voiding'] );
		}
		$statement = ( new Statement_Repository() )->find( $ids[0] )->statement();
		$this->assertSame( $this->client_id, $statement['authority']['account']['name'] );
		$this->assertSame( $before[0]['body'], $after[0]['body'] );
		$this->assertStringContainsString( '"result":{}', $after[0]['body'] );
		$this->assertSame( 0, $this->stored_hook_calls, 'Imported statements do not trigger the live pipeline.' );
	}

	public function test_importing_again_skips_identical_statements() {
		$this->seed();
		$path     = $this->export_to_file();
		$importer = new Migration_Importer();
		$this->wipe();
		$importer->import( $path );
		$again = $importer->import( $path );
		$this->assertSame(
			array(
				'imported' => 0,
				'skipped'  => 4,
				'rejected' => 0,
			),
			$again
		);
		$this->assertCount( 4, $this->rows() );
	}

	public function test_a_line_with_a_wrong_hash_is_rejected_and_the_rest_imported() {
		$this->seed();
		$path  = $this->export_to_file();
		$lines = file( $path );
		$this->wipe();
		$lines[2] = preg_replace( '/[0-9a-f]{64}"\}\n$/', str_repeat( 'ab', 32 ) . "\"}\n", $lines[2] );
		file_put_contents( $path, implode( '', $lines ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$importer = new Migration_Importer();
		$result   = $importer->import( $path );
		$this->assertSame(
			array(
				'imported' => 3,
				'skipped'  => 0,
				'rejected' => 1,
			),
			$result
		);
		$this->assertCount( 3, $this->rows() );
		$this->assertCount( 1, $importer->problems() );
		$this->assertSame( 3, $importer->problems()[0]['line'] );
	}

	public function test_malformed_lines_are_rejected() {
		$this->seed();
		$path  = $this->export_to_file();
		$lines = file( $path );
		$this->wipe();
		$first = json_decode( $lines[0], true );
		$bad   = array(
			"not json\n",
			'{"statement":[1,2],"stored":"2024-01-01T00:00:00.000000Z","content_sha256":"' . str_repeat( '0', 64 ) . "\"}\n",
			wp_json_encode( array( 'statement' => $first['statement'] ) ) . "\n",
			"\n",
		);
		file_put_contents( $path, implode( '', $bad ) . $lines[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = ( new Migration_Importer() )->import( $path );
		$this->assertSame(
			array(
				'imported' => 1,
				'skipped'  => 0,
				'rejected' => 3,
			),
			$result
		);
	}

	public function test_same_id_with_different_content_is_rejected_not_overwritten() {
		$this->seed();
		$path = $this->export_to_file();
		$rows = $this->rows();
		( new Migration_Importer() )->import( $path );
		$lines            = file( $path );
		$line             = json_decode( $lines[0], true );
		$st               = $line['statement'];
		$st['verb']['id'] = 'http://adlnet.gov/expapi/verbs/failed';
		$body             = wp_json_encode( $st, JSON_UNESCAPED_SLASHES );
		file_put_contents( $path, '{"statement":' . $body . ',"stored":"' . $line['stored'] . '","content_sha256":"' . hash( 'sha256', $body ) . "\"}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = ( new Migration_Importer() )->import( $path );
		$this->assertSame( 1, $result['rejected'] );
		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( $rows[0]['body'], $this->rows()[0]['body'] );
	}

	public function test_every_run_is_audited_with_counts_only() {
		$this->seed();
		$path = $this->export_to_file();
		$this->wipe();
		( new Migration_Importer() )->import( $path, 'cli' );
		( new Migration_Importer() )->import( $path, 'cli' );

		$rows = $GLOBALS['wpdb']->get_results( 'SELECT actor, data FROM ' . Tables::name( 'audit' ) . " WHERE event = 'statements.import' ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->assertCount( 2, $rows );
		$this->assertSame( 'cli', $rows[0]['actor'] );
		$first = json_decode( $rows[0]['data'], true );
		$this->assertSame( 4, $first['imported'] );
		$this->assertSame( 0, $first['rejected'] );
		$this->assertSame( basename( $path ), $first['file'] );
		$second = json_decode( $rows[1]['data'], true );
		$this->assertSame( 4, $second['skipped'] );
		$this->assertStringNotContainsString( 'u1', $rows[0]['data'] );
	}

	public function test_a_missing_file_is_an_error_and_not_audited() {
		try {
			( new Migration_Importer() )->import( sys_get_temp_dir() . '/ll-does-not-exist.ndjson' );
			$this->fail( 'Expected Invalid_Input.' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'unreadable_file', $e->error_code() );
		}
	}

	/**
	 * An export line for a hand-made statement.
	 *
	 * @param array $st      Statement parts (verb, actor, object, ...).
	 * @param string $id     Statement id.
	 * @param integer $n     Stored second offset.
	 */
	private static function make_line( array $st, string $id, int $n = 1 ): string {
		$stored = gmdate( 'Y-m-d\TH:i:s', 1700000000 + $n ) . '.000000Z';
		$body   = Json::encode_exact(
			array_merge(
				array(
					'id'        => $id,
					'timestamp' => $stored,
					'stored'    => $stored,
					'authority' => array(
						'objectType' => 'Agent',
						'account'    => array(
							'homePage' => 'https://site.example',
							'name'     => 'old-lrs',
						),
					),
					'version'   => '2.0.0',
				),
				$st
			)
		);
		return '{"statement":' . $body . ',"stored":"' . $stored . '","content_sha256":"' . hash( 'sha256', $body ) . "\"}\n";
	}

	private function import_lines( array $lines ): array {
		$this->file = sys_get_temp_dir() . '/ll-import-' . bin2hex( random_bytes( 4 ) ) . '.ndjson';
		file_put_contents( $this->file, implode( '', $lines ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return ( new Migration_Importer() )->import( $this->file );
	}

	private static function agent_row_count( array $agent ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::name( 'agents' ) . ' WHERE ifi_hash = UNHEX(%s)', bin2hex( Agent_Identity::from_array( $agent )->hash() ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public function test_substatement_context_and_group_resources_are_recorded() {
		$account = static fn( string $name ) => array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => 'https://site.example',
				'name'     => $name,
			),
		);
		$st      = array(
			'actor'   => $account( 'outer' ),
			'verb'    => array( 'id' => 'http://adlnet.gov/expapi/verbs/attempted' ),
			'object'  => array(
				'objectType' => 'SubStatement',
				'actor'      => $account( 'inner-actor' ),
				'verb'       => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
				'object'     => array(
					'id'         => 'https://site.example/act/inner',
					'definition' => array( 'name' => array( 'en-US' => 'Inner activity' ) ),
				),
				'context'    => array(
					'instructor' => $account( 'inner-instructor' ),
				),
			),
			'context' => array(
				'contextAgents' => array(
					array(
						'objectType' => 'contextAgent',
						'agent'      => $account( 'ctx-agent' ),
					),
				),
				'team'          => array(
					'objectType' => 'Group',
					'name'       => 'Team',
					'account'    => array(
						'homePage' => 'https://site.example',
						'name'     => 'team-group',
					),
					'member'     => array( $account( 'team-member' ) ),
				),
			),
		);
		$result  = $this->import_lines( array( self::make_line( $st, '11111111-1111-4111-8111-111111111111' ) ) );
		$this->assertSame( 1, $result['imported'] );

		$this->assertSame( 'Inner activity', ( new Activity_Repository() )->get( 'https://site.example/act/inner' )['definition']['name']['en-US'] );
		foreach ( array( 'outer', 'old-lrs', 'inner-actor', 'inner-instructor', 'ctx-agent', 'team-member' ) as $name ) {
			$this->assertSame( 1, self::agent_row_count( $account( $name ) ), $name );
		}
		$row = $this->rows()[0];
		$this->assertSame( 'SubStatement', $GLOBALS['wpdb']->get_var( 'SELECT object_kind FROM ' . Tables::name( 'statements' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '11111111-1111-4111-8111-111111111111', preg_replace( '/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', $row['id'] ) );
	}

	public function test_a_line_over_the_cap_is_rejected_and_the_next_line_still_imported() {
		$actor      = array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => 'https://site.example',
				'name'     => 'u',
			),
		);
		$base       = array(
			'actor'  => $actor,
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object' => array( 'id' => 'https://site.example/act/x' ),
		);
		$big        = self::make_line( array_merge( $base, array( 'pad' => str_repeat( 'x', Migration_Importer::MAX_LINE_BYTES ) ) ), '22222222-2222-4222-8222-222222222222' );
		$ok         = self::make_line( $base, '33333333-3333-4333-8333-333333333333', 2 );
		$this->file = sys_get_temp_dir() . '/ll-import-' . bin2hex( random_bytes( 4 ) ) . '.ndjson';
		file_put_contents( $this->file, $big . $ok ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$importer = new Migration_Importer();
		$result   = $importer->import( $this->file );
		$this->assertSame(
			array(
				'imported' => 1,
				'skipped'  => 0,
				'rejected' => 1,
			),
			$result
		);
		$this->assertSame( 1, $importer->problems()[0]['line'] );
		$this->assertCount( 1, $this->rows() );
	}

	public function test_a_voider_that_precedes_its_target_in_the_file_still_voids_it() {
		$actor  = array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => 'https://site.example',
				'name'     => 'u',
			),
		);
		$target = '44444444-4444-4444-8444-444444444444';
		$void   = self::make_line(
			array(
				'actor'  => $actor,
				'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/voided' ),
				'object' => array(
					'objectType' => 'StatementRef',
					'id'         => $target,
				),
			),
			'55555555-5555-4555-8555-555555555555',
			1
		);
		$orig   = self::make_line(
			array(
				'actor'  => $actor,
				'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
				'object' => array( 'id' => 'https://site.example/act/x' ),
			),
			$target,
			2
		);
		$this->assertSame( 2, $this->import_lines( array( $void, $orig ) )['imported'] );
		$voided = $GLOBALS['wpdb']->get_col( 'SELECT voided FROM ' . Tables::name( 'statements' ) . ' ORDER BY seq' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array( '0', '1' ), $voided );
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'pending_voids' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public function test_a_void_left_pending_by_a_live_write_applies_only_while_its_writer_may_void() {
		$target = '66666666-6666-4666-8666-666666666666';
		$line   = self::make_line(
			array(
				'actor'  => self::statement( 'u' )['actor'],
				'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
				'object' => array( 'id' => 'https://site.example/act/x' ),
			),
			$target
		);
		$log    = ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- the store logs the dropped void on purpose.
		foreach ( array( true, false ) as $active ) {
			$this->wipe();
			$c              = Client_Factory::create( array( 'statements/write', 'void' ) );
			$principal      = Principal::for_client( ( new Client_Repository() )->find( $c['id'] ) );
			$void           = self::statement( 'admin' );
			$void['verb']   = array( 'id' => 'http://adlnet.gov/expapi/verbs/voided' );
			$void['object'] = array(
				'objectType' => 'StatementRef',
				'id'         => $target,
			);
			unset( $void['context'], $void['result'] );
			( new Statement_Store() )->store( array( $void ), $principal, '2.0.0' );
			if ( ! $active ) {
				( new Client_Repository() )->revoke( $c['id'] );
			}
			$this->import_lines( array( $line ) );
			$this->assertSame( $active ? '1' : '0', $GLOBALS['wpdb']->get_var( 'SELECT voided FROM ' . Tables::name( 'statements' ) . ' WHERE seq = (SELECT MAX(seq) FROM ' . Tables::name( 'statements' ) . ' WHERE is_voiding = 0)' ), $active ? 'active writer' : 'revoked writer' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		ini_set( 'error_log', (string) $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
	}
}
