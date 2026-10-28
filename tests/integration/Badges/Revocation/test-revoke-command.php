<?php
/**
 * Revoke_Command tests: the `badges remove-key` hook.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Badges\Revocation\Revoke_Command;
use LearningLedger\Badges\Status\Status_List_Repository;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.DuplicateClassName.Found, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- minimal WP_CLI stand-in for this test only (defined once, whichever test loads first).
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {

		public static array $arguments = array();

		public static function error( string $message ): void {
			throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		public static function success( string $message ): void {}

		public static function warning( string $message ): void {}

		public static function log( string $message ): void {
			$GLOBALS['lledger_revoke_cli_log'][] = $message;
		}

		public static function get_runner(): object {
			return (object) array( 'arguments' => self::$arguments );
		}
	}
}

class Revoke_Command_Test extends WP_UnitTestCase {

	private int $list;

	private string $old_vm;

	public function set_up() {
		parent::set_up();
		if ( ! property_exists( 'WP_CLI', 'arguments' ) ) {
			$this->markTestSkipped( 'Another test defined a WP_CLI stand-in without a runner.' );
		}
		Installer::install();
		global $wpdb;
		foreach ( array( 'keys', 'status_lists' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lledger\\_status\\_list\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		wp_cache_flush();
		$this->old_vm = Did_Document::verification_method_for( Key_Store::generate() );
		$this->list   = ( new Status_List_Repository() )->allocate()['list_id'];
		Key_Store::rotate();
	}

	public function tear_down() {
		if ( property_exists( 'WP_CLI', 'arguments' ) ) {
			WP_CLI::$arguments = array();
		}
		parent::tear_down();
	}

	private function list_vm(): string {
		return ( new Status_List_Repository() )->credential( $this->list )['proof']['verificationMethod'];
	}

	public function test_remove_key_without_a_readable_kid_stops_before_anything_is_signed() {
		WP_CLI::$arguments = array( 'learning-ledger', 'badges', 'remove-key' );

		$error = null;
		try {
			( new Revoke_Command() )->before_remove_key();
		} catch ( RuntimeException $e ) {
			$error = $e->getMessage();
		}
		$this->assertSame( $this->old_vm, $this->list_vm(), 'Nothing was re-signed.' );
		$this->assertNotNull( $error, 'A remove-key without a key id was let through.' );
		$this->assertStringContainsString( 'key id', $error );
	}

	public function test_remove_key_with_a_kid_resigns_the_lists_first() {
		$old               = substr( $this->old_vm, strrpos( $this->old_vm, '#' ) + 1 );
		WP_CLI::$arguments = array( 'learning-ledger', 'badges', 'remove-key', $old );

		$GLOBALS['lledger_revoke_cli_log'] = array();
		( new Revoke_Command() )->before_remove_key();

		$this->assertSame( Did_Document::verification_method_for( Key_Store::active() ), $this->list_vm() );
		$this->assertCount( 1, $GLOBALS['lledger_revoke_cli_log'] );
		$this->assertStringContainsString( 'wp learning-ledger badges reissue --kid=' . $old, $GLOBALS['lledger_revoke_cli_log'][0] );
	}
}
