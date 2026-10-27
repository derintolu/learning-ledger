<?php
/**
 * Issuer key store tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Secret_Box;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Invalid_Input;

class Key_Store_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'keys' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'audit' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function options_snapshot(): array {
		return $GLOBALS['wpdb']->get_results( "SELECT option_name, option_value FROM {$GLOBALS['wpdb']->options} ORDER BY option_name", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function audit_events(): array {
		return $GLOBALS['wpdb']->get_col( 'SELECT event FROM ' . Tables::name( 'audit' ) . ' ORDER BY id' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function key_row( string $kid ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( 'keys' ) . ' WHERE kid = %s', $kid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function kids( array $keys ): array {
		return array_map( static fn( $k ) => $k->kid, $keys );
	}

	public function test_database_tier_seals_secret() {
		$before = $this->options_snapshot();
		$this->assertSame( 'database', Key_Store::tier() );
		$this->assertNull( Key_Store::active() );

		$key = Key_Store::generate();
		$row = $this->key_row( $key->kid );
		$this->assertNotNull( $row );
		$this->assertSame( 'badge', $row->purpose );
		$this->assertSame( $key->public, $row->public_key );
		$this->assertSame( Ed25519::key_id( $key->public ), $key->kid );
		$this->assertNull( $row->retired_at );
		$this->assertNull( $row->removed_at );

		$seed = Secret_Box::open( $row->secret_sealed, 'badge-key' );
		$this->assertSame( 32, strlen( $seed ) );
		$this->assertSame( $key->public, Ed25519::from_seed( $seed )['public'] );
		$secret = $key->secret();
		$this->assertSame( Ed25519::from_seed( $seed )['secret'], $secret );
		foreach ( (array) $row as $column => $value ) {
			$value = (string) $value;
			foreach ( array( $seed, $secret, base64_encode( $seed ), bin2hex( $seed ), base64_encode( $secret ), bin2hex( $secret ) ) as $plain ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				$this->assertStringNotContainsString( $plain, $value, "Column $column holds the secret in plaintext" );
			}
		}
		$this->assertTrue( Ed25519::verify( Ed25519::sign( 'msg', $key->secret() ), 'msg', $key->public ) );

		$again = Key_Store::generate();
		$this->assertSame( $key->kid, $again->kid );
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'keys' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( $key->kid, Key_Store::active()->kid );
		$this->assertSame( array( 'badge_key_generated' ), $this->audit_events() );
		$this->assertSame( $before, $this->options_snapshot() );
	}

	public function test_rotate_keeps_old_key_published() {
		$old = Key_Store::generate();
		$new = Key_Store::rotate();
		$this->assertNotSame( $old->kid, $new->kid );
		$this->assertSame( $new->kid, Key_Store::active()->kid );
		$this->assertNull( $new->retired_at );
		$this->assertNotNull( Key_Store::find( $old->kid )->retired_at );
		$this->assertNotNull( $this->key_row( $old->kid )->retired_at );

		$published = $this->kids( Key_Store::published() );
		sort( $published );
		$expected = array( $old->kid, $new->kid );
		sort( $expected );
		$this->assertSame( $expected, $published );

		$doc = Did_Document::document();
		$ids = array_column( $doc['verificationMethod'], 'id' );
		$this->assertContains( Did_Document::verification_method_for( $old ), $ids );
		$this->assertContains( Did_Document::verification_method_for( $new ), $ids );
		$this->assertContains( Did_Document::verification_method_for( $old ), $doc['assertionMethod'] );
		$this->assertContains( Did_Document::verification_method_for( $new ), $doc['assertionMethod'] );
		$this->assertSame( array( 'badge_key_generated', 'badge_key_rotated' ), $this->audit_events() );

		$this->assertTrue( Ed25519::verify( Ed25519::sign( 'm', Key_Store::find( $old->kid )->secret() ), 'm', $old->public ) );
	}

	public function test_remove_drops_key_from_did_document() {
		$old = Key_Store::generate();
		$new = Key_Store::rotate();
		Key_Store::remove( $old->kid );

		$this->assertNotNull( $this->key_row( $old->kid )->removed_at );
		$this->assertNull( $this->key_row( $old->kid )->secret_sealed );
		$this->assertNull( Key_Store::find( $old->kid ) );
		$this->assertSame( array( $new->kid ), $this->kids( Key_Store::published() ) );
		$this->assertSame( $new->kid, Key_Store::active()->kid );

		$doc = Did_Document::document();
		$this->assertSame( array( Did_Document::verification_method_for( $new ) ), array_column( $doc['verificationMethod'], 'id' ) );
		$this->assertSame( array( Did_Document::verification_method_for( $new ) ), $doc['assertionMethod'] );
		$this->assertSame( array( 'badge_key_generated', 'badge_key_rotated', 'badge_key_removed' ), $this->audit_events() );

		$this->expectException( Invalid_Input::class );
		Key_Store::remove( $old->kid );
	}

	/**
	 * Make every query against the keys table that starts with $verb fail.
	 */
	private function fail_keys_writes( string $verb ): callable {
		$table = Tables::name( 'keys' );
		$cb    = static function ( $query ) use ( $verb, $table ) {
			return ( str_starts_with( $query, $verb ) && str_contains( $query, $table ) ) ? 'SELECT * FROM lledger_no_such_table_t21' : $query;
		};
		add_filter( 'query', $cb );
		$GLOBALS['wpdb']->suppress_errors( true );
		return $cb;
	}

	private function restore_writes( callable $cb ): void {
		remove_filter( 'query', $cb );
		$GLOBALS['wpdb']->suppress_errors( false );
	}

	public function test_generate_fails_loudly_when_insert_fails() {
		$cb = $this->fail_keys_writes( 'INSERT' );
		try {
			Key_Store::generate();
			$this->fail( 'Expected RuntimeException' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Could not store the new badge key.', $e->getMessage() );
		} finally {
			$this->restore_writes( $cb );
		}
		$this->assertNull( Key_Store::active() );
		$this->assertSame( array(), $this->audit_events() );
	}

	public function test_rotate_rolls_back_when_retire_fails() {
		$old = Key_Store::generate();
		$cb  = $this->fail_keys_writes( 'UPDATE' );
		try {
			Key_Store::rotate();
			$this->fail( 'Expected RuntimeException' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Could not retire the previous badge key.', $e->getMessage() );
		} finally {
			$this->restore_writes( $cb );
		}
		$this->assertSame( array( $old->kid ), $this->kids( Key_Store::published() ) );
		$this->assertSame( $old->kid, Key_Store::active()->kid );
		$this->assertSame( array( 'badge_key_generated' ), $this->audit_events() );
	}

	public function test_remove_fails_loudly_when_update_fails() {
		$key = Key_Store::generate();
		$cb  = $this->fail_keys_writes( 'UPDATE' );
		try {
			Key_Store::remove( $key->kid );
			$this->fail( 'Expected RuntimeException' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Could not remove the badge key.', $e->getMessage() );
		} finally {
			$this->restore_writes( $cb );
		}
		$this->assertSame( array( $key->kid ), $this->kids( Key_Store::published() ) );
		$this->assertSame( array( 'badge_key_generated' ), $this->audit_events() );
	}

	/**
	 * Write a key file and point LLEDGER_BADGE_KEY_FILE at it (separate-process tests only).
	 */
	private function key_file( string $dir, string $json, int $mode ): string {
		wp_mkdir_p( $dir );
		$path = $dir . '/lledger-t21-' . wp_generate_password( 8, false ) . '.json';
		file_put_contents( $path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		chmod( $path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		clearstatcache();
		define( 'LLEDGER_BADGE_KEY_FILE', $path );
		return $path;
	}

	private function file_json( string $seed_old, string $seed_new ): string {
		return wp_json_encode(
			array(
				array(
					'kid'        => 'old-key',
					'seed_b64'   => base64_encode( $seed_old ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'created_at' => '2026-01-01 00:00:00.000000',
					'retired_at' => '2026-06-01 00:00:00.000000',
				),
				array(
					'kid'        => 'new-key',
					'seed_b64'   => base64_encode( $seed_new ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'created_at' => '2026-06-01 00:00:00.000000',
					'retired_at' => null,
				),
			)
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_key_file_0600_outside_abspath_is_used() {
		$old  = random_bytes( 32 );
		$new  = random_bytes( 32 );
		$path = $this->key_file( sys_get_temp_dir(), $this->file_json( $old, $new ), 0600 );
		try {
			$this->assertSame( 'file', Key_Store::tier() );
			$this->assertSame( array( 'old-key', 'new-key' ), $this->kids( Key_Store::published() ) );
			$active = Key_Store::active();
			$this->assertSame( 'new-key', $active->kid );
			$this->assertSame( Ed25519::from_seed( $new )['public'], $active->public );
			$this->assertTrue( Ed25519::verify( Ed25519::sign( 'm', $active->secret() ), 'm', $active->public ) );
			$this->assertSame( '2026-06-01 00:00:00.000000', Key_Store::find( 'old-key' )->retired_at );
			$this->assertSame( 'new-key', Key_Store::generate()->kid );
			$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'keys' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->expectException( RuntimeException::class );
			Key_Store::rotate();
		} finally {
			wp_delete_file( $path );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_key_file_readable_by_others_falls_back_to_database() {
		$path = $this->key_file( sys_get_temp_dir(), $this->file_json( random_bytes( 32 ), random_bytes( 32 ) ), 0644 );
		try {
			$this->assertSame( 'database', Key_Store::tier() );
			$this->assertNull( Key_Store::active() );
		} finally {
			wp_delete_file( $path );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_key_file_inside_abspath_is_rejected() {
		$path = $this->key_file( WP_CONTENT_DIR . '/uploads/lledger-t21', $this->file_json( random_bytes( 32 ), random_bytes( 32 ) ), 0600 );
		try {
			$this->assertStringStartsWith( wp_normalize_path( realpath( ABSPATH ) ), wp_normalize_path( realpath( $path ) ) );
			$this->assertSame( 'database', Key_Store::tier() );
		} finally {
			wp_delete_file( $path );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_malformed_key_file_fails_loudly() {
		$bad  = wp_json_encode(
			array(
				array(
					'kid'      => 'k1',
					'seed_b64' => base64_encode( 'too short' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
			)
		);
		$path = $this->key_file( sys_get_temp_dir(), $bad, 0600 );
		try {
			$this->assertSame( 'file', Key_Store::tier() );
			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( 'LLEDGER_BADGE_KEY_FILE must hold base64 of a 32-byte seed.' );
			Key_Store::published();
		} finally {
			wp_delete_file( $path );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_key_file_that_is_not_a_list_fails_loudly() {
		$path = $this->key_file( sys_get_temp_dir(), '{"kid":"k1"}', 0600 );
		try {
			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( 'LLEDGER_BADGE_KEY_FILE must hold a JSON list of keys.' );
			Key_Store::active();
		} finally {
			wp_delete_file( $path );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_tier() {
		$seed = random_bytes( 32 );
		define( 'LLEDGER_BADGE_KEY', base64_encode( $seed ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertSame( 'constant', Key_Store::tier() );
		$public = Ed25519::from_seed( $seed )['public'];
		$key    = Key_Store::active();
		$this->assertSame( Ed25519::key_id( $public ), $key->kid );
		$this->assertSame( $public, $key->public );
		$this->assertTrue( Ed25519::verify( Ed25519::sign( 'm', $key->secret() ), 'm', $public ) );
		$this->assertSame( $key->kid, Key_Store::generate()->kid );
		$this->assertSame( array( $key->kid ), $this->kids( Key_Store::published() ) );
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'keys' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertStringNotContainsString( base64_encode( $seed ), print_r( $key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.DevelopmentFunctions.error_log_print_r
		$this->expectException( RuntimeException::class );
		Key_Store::remove( $key->kid );
	}

	public function test_removing_active_key_leaves_none_active() {
		$key = Key_Store::generate();
		Key_Store::remove( $key->kid );
		$this->assertNull( Key_Store::active() );
		$this->assertSame( array(), Key_Store::published() );
		$this->assertNotSame( $key->kid, Key_Store::generate()->kid );
	}
}
