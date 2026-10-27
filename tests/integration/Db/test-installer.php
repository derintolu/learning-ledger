<?php
/**
 * Installer, schema and database requirement tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Db_Requirements;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Schema;
use LearningLedger\Db\Tables;

class Installer_Test extends WP_UnitTestCase {

	/**
	 * The WordPress test case rewrites CREATE TABLE to CREATE TEMPORARY TABLE,
	 * which SHOW TABLES cannot see. Drop any copy of our tables and create real ones.
	 */
	private function install_real_tables(): void {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		foreach ( array_keys( Schema::tables() ) as $short ) {
			$table = Tables::name( $short );
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		delete_option( 'lledger_schema_version' );
		Installer::install();
	}

	/**
	 * Index name => ordered column list for a table.
	 */
	private function indexes( string $short ): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SHOW INDEX FROM ' . Tables::name( $short ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = $row['Column_name'];
		}
		foreach ( $out as $name => $cols ) {
			ksort( $cols );
			$out[ $name ] = array(
				'columns' => array_values( $cols ),
			);
		}
		foreach ( $rows as $row ) {
			$out[ $row['Key_name'] ]['unique'] = ! (int) $row['Non_unique'];
		}
		return $out;
	}

	/**
	 * Column name => DESCRIBE row.
	 */
	private function columns( string $short ): array {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( 'DESCRIBE ' . Tables::name( $short ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[ $row['Field'] ] = (object) $row;
		}
		return $out;
	}

	private function has_index( array $indexes, array $columns, bool $unique = false ): bool {
		foreach ( $indexes as $index ) {
			if ( $index['columns'] === $columns && ( ! $unique || $index['unique'] ) ) {
				return true;
			}
		}
		return false;
	}

	public function test_tables_name_is_prefixed() {
		$this->assertSame( $GLOBALS['wpdb']->prefix . 'lledger_statements', Tables::name( 'statements' ) );
	}

	public function test_install_creates_every_table_and_records_version() {
		$this->install_real_tables();
		foreach ( array_keys( Schema::tables() ) as $short ) {
			$this->assertSame( Tables::name( $short ), $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', Tables::name( $short ) ) ) );
		}
		$this->assertSame( 1, (int) get_option( 'lledger_schema_version' ) );
		$this->assertSame( 1, Schema::VERSION );
		$autoload = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", 'lledger_schema_version' ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_schema_lists_every_table() {
		$expected = array(
			'statements',
			'statement_refs',
			'pending_voids',
			'activities',
			'agents',
			'documents',
			'clients',
			'tokens',
			'course_aus',
			'courses',
			'subscriptions',
			'outbox',
			'badges',
			'status_lists',
			'keys',
			'links',
			'audit',
			'ratelimit',
			'jobs',
			'conn_outbox',
			'conn_inbox',
			'conn_events',
			'conn_cursor',
			'identity_links',
			'waivers',
			'applied',
			'registrations',
			'trusted_keys',
			'nonces',
			'cmi5_packages',
			'cmi5_files',
			'cmi5_sessions',
		);
		$this->assertEqualsCanonicalizing( $expected, array_keys( Schema::tables() ) );
		foreach ( Schema::tables() as $short => $sql ) {
			$this->assertStringStartsWith( 'CREATE TABLE ' . Tables::name( $short ) . ' (', $sql );
			$this->assertStringContainsString( 'ENGINE=InnoDB', $sql );
			$this->assertStringContainsString( $GLOBALS['wpdb']->get_charset_collate(), $sql );
		}
	}

	public function test_install_is_idempotent() {
		global $wpdb;
		$this->install_real_tables();
		$before           = count( $this->columns( 'statements' ) );
		$wpdb->last_error = '';
		Installer::install();
		$this->assertSame( '', $wpdb->last_error );
		Installer::install();
		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( $before, count( $this->columns( 'statements' ) ) );
		$this->assertSame( 17, $before );
		$this->assertSame( 1, (int) get_option( 'lledger_schema_version' ) );
	}

	public function test_statement_indexes_exist() {
		$this->install_real_tables();
		$indexes = $this->indexes( 'statements' );
		$this->assertSame( array( 'seq' ), $indexes['PRIMARY']['columns'] );
		$this->assertTrue( $this->has_index( $indexes, array( 'statement_id' ), true ), 'statement_id unique' );
		foreach ( array( 'actor_hash', 'actor_home_hash', 'verb_hash', 'object_hash', 'registration', 'voided' ) as $col ) {
			$this->assertTrue( $this->has_index( $indexes, array( $col, 'seq' ) ), "($col,seq)" );
		}
		$this->assertTrue( $this->has_index( $indexes, array( 'stored' ) ), '(stored)' );
		$this->assertTrue( $this->has_index( $indexes, array( 'voids_id' ) ), '(voids_id)' );
		$this->assertCount( 10, $indexes );
	}

	public function test_statement_column_types() {
		$this->install_real_tables();
		$cols = $this->columns( 'statements' );
		$this->assertSame( 'auto_increment', $cols['seq']->Extra );
		$this->assertStringStartsWith( 'bigint', $cols['seq']->Type );
		$this->assertStringContainsString( 'unsigned', $cols['seq']->Type );
		foreach ( array( 'statement_id', 'registration', 'voids_id' ) as $col ) {
			$this->assertSame( 'binary(16)', $cols[ $col ]->Type, $col );
		}
		foreach ( array( 'verb_hash', 'actor_hash', 'actor_home_hash', 'object_hash' ) as $col ) {
			$this->assertSame( 'binary(20)', $cols[ $col ]->Type, $col );
		}
		foreach ( array( 'stored', 'timestamp' ) as $col ) {
			$this->assertSame( 'datetime(6)', $cols[ $col ]->Type, $col );
		}
		$this->assertSame( 'binary(32)', $cols['content_sha256']->Type );
		$this->assertSame( 'longtext', $cols['body']->Type );
		foreach ( array( 'actor_home_hash', 'registration', 'voids_id' ) as $col ) {
			$this->assertSame( 'YES', $cols[ $col ]->Null, $col );
		}
		foreach ( array( 'statement_id', 'stored', 'verb_hash', 'actor_hash', 'object_hash', 'body' ) as $col ) {
			$this->assertSame( 'NO', $cols[ $col ]->Null, $col );
		}
	}

	public function test_unique_keys_never_hold_null_for_absent_parts() {
		$this->install_real_tables();
		$docs = $this->columns( 'documents' );
		foreach ( array( 'kind', 'activity_hash', 'agent_hash', 'registration', 'doc_id_hash' ) as $col ) {
			$this->assertSame( 'NO', $docs[ $col ]->Null, $col );
		}
		$this->assertSame( 'binary(16)', $docs['registration']->Type );
		$this->assertTrue( $this->has_index( $this->indexes( 'documents' ), array( 'kind', 'activity_hash', 'agent_hash', 'registration', 'doc_id_hash' ), true ) );

		$badges = $this->columns( 'badges' );
		$this->assertSame( 'NO', $badges['achievement_hash']->Null );
		$this->assertSame( 'NO', $badges['registration']->Null );
		$this->assertTrue( $this->has_index( $this->indexes( 'badges' ), array( 'achievement_hash', 'registration' ), true ) );
	}

	public function test_documents_zero_bytes_registration_is_one_row() {
		global $wpdb;
		$this->install_real_tables();
		$row = array(
			'kind'          => 'agent_profile',
			'activity_hash' => str_repeat( "\0", 20 ),
			'agent_hash'    => str_repeat( "\1", 20 ),
			'registration'  => str_repeat( "\0", 16 ),
			'doc_id_hash'   => str_repeat( "\2", 20 ),
			'doc_id'        => 'p',
			'activity_iri'  => '',
			'agent_ifi'     => '{}',
			'content_type'  => 'application/json',
			'content'       => '{}',
			'etag'          => str_repeat( 'a', 40 ),
			'updated'       => '2026-10-27 10:00:00.123456',
		);
		$this->assertSame( 1, $wpdb->insert( Tables::name( 'documents' ), $row ) );
		$suppress = $wpdb->suppress_errors( true );
		$second   = $wpdb->insert( Tables::name( 'documents' ), $row );
		$wpdb->suppress_errors( $suppress );
		$this->assertFalse( $second );
		$this->assertStringContainsString( 'Duplicate', $wpdb->last_error );
	}

	public function test_key_column_types_follow_rulings() {
		$this->install_real_tables();
		$this->assertSame( 'varbinary(600)', $this->columns( 'keys' )['public_key']->Type );
		$this->assertSame( 'varbinary(32)', $this->columns( 'trusted_keys' )['public_key']->Type );
		$packages = $this->columns( 'cmi5_packages' );
		$this->assertArrayHasKey( 'extract_cursor', $packages );
		$this->assertArrayNotHasKey( 'cursor', $packages );
		$this->assertSame( '1', (string) $this->columns( 'clients' )['guard_enabled']->Default );
		$this->assertSame( 'datetime(6)', $this->columns( 'audit' )['at']->Type );
	}

	public function test_named_indexes_on_other_tables() {
		$this->install_real_tables();
		$expect = array(
			'statement_refs' => array( array( array( 'ref_hash', 'ref_kind', 'seq' ), true ) ),
			'pending_voids'  => array( array( array( 'target_id', 'voiding_seq' ), true ) ),
			'tokens'         => array( array( array( 'parent_client' ), false ), array( array( 'registration' ), false ) ),
			'courses'        => array( array( array( 'course_iri_hash' ), true ) ),
			'outbox'         => array( array( array( 'status', 'next_attempt_at' ), false ) ),
			'badges'         => array( array( array( 'actor_hash' ), false ), array( array( 'registration' ), false ) ),
			'links'          => array( array( array( 'token_hash' ), true ), array( array( 'kind', 'actor_hash', 'subject' ), false ) ),
			'jobs'           => array( array( array( 'dedupe_key' ), true ), array( array( 'done_at', 'run_after' ), false ) ),
			'conn_outbox'    => array( array( array( 'statement_id' ), true ) ),
			'conn_inbox'     => array( array( array( 'statement_id' ), true ), array( array( 'status', 'received_at' ), false ), array( array( 'registration' ), false ), array( array( 'actor_hash' ), false ) ),
			'identity_links' => array( array( array( 'user_id' ), false ) ),
			'waivers'        => array( array( array( 'user_id', 'lesson_id' ), true ) ),
			'applied'        => array( array( array( 'user_id', 'object_kind', 'object_id', 'status' ), true ) ),
			'registrations'  => array( array( array( 'user_id', 'scope_key' ), true ) ),
			'nonces'         => array( array( array( 'key_id', 'nonce' ), true ) ),
			'cmi5_files'     => array( array( array( 'package_id', 'path_hash' ), true ) ),
			'cmi5_sessions'  => array( array( array( 'session_id' ), true ), array( array( 'user_id', 'au_hash', 'status' ), false ) ),
		);
		foreach ( $expect as $short => $wanted ) {
			$indexes = $this->indexes( $short );
			foreach ( $wanted as list( $cols, $unique ) ) {
				$this->assertTrue( $this->has_index( $indexes, $cols, $unique ), $short . ' (' . implode( ',', $cols ) . ')' );
			}
		}
	}

	public function test_maybe_upgrade_installs_only_when_stored_version_is_lower() {
		$this->install_real_tables();
		$queries = 0;
		add_filter(
			'dbdelta_queries',
			function ( $q ) use ( &$queries ) {
				++$queries;
				return $q;
			}
		);
		Installer::maybe_upgrade();
		$this->assertSame( 0, $queries );

		update_option( 'lledger_schema_version', 0, false );
		Installer::maybe_upgrade();
		$this->assertSame( 1, $queries );
		$this->assertSame( 1, (int) get_option( 'lledger_schema_version' ) );
	}

	public function test_db_requirements() {
		add_filter( 'learning_ledger/db_version_string', fn() => '5.7.44' );
		$this->assertWPError( Db_Requirements::check() );
		add_filter( 'learning_ledger/db_version_string', fn() => '10.6.18-MariaDB', 20 );
		$this->assertTrue( Db_Requirements::check() );
	}

	/**
	 * @dataProvider data_db_versions
	 */
	public function test_db_requirements_versions( string $server, bool $ok ) {
		add_filter( 'learning_ledger/db_version_string', fn() => $server );
		$result = Db_Requirements::check();
		if ( $ok ) {
			$this->assertTrue( $result );
		} else {
			$this->assertWPError( $result );
			$this->assertSame( 'lledger_db_version', $result->get_error_code() );
			$this->assertStringContainsString( 'MySQL 8.0', $result->get_error_message() );
			$this->assertStringContainsString( 'MariaDB 10.6', $result->get_error_message() );
		}
	}

	public function data_db_versions(): array {
		return array(
			'mysql 8.0.0'         => array( '8.0.0', true ),
			'mysql 8.4 suffix'    => array( '8.4.3-0ubuntu0.24.04.1', true ),
			'mysql 5.7'           => array( '5.7.44-log', false ),
			'mariadb 10.6 prefix' => array( '5.5.5-10.6.18-MariaDB-1:10.6.18+maria~ubu2004', true ),
			'mariadb 11'          => array( '11.4.2-MariaDB', true ),
			'mariadb 10.5'        => array( '10.5.25-MariaDB', false ),
			'mariadb 10.5 prefix' => array( '5.5.5-10.5.25-MariaDB', false ),
			'unparseable'         => array( 'unknown', false ),
		);
	}
}
