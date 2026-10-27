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

	public function test_removing_active_key_leaves_none_active() {
		$key = Key_Store::generate();
		Key_Store::remove( $key->kid );
		$this->assertNull( Key_Store::active() );
		$this->assertSame( array(), Key_Store::published() );
		$this->assertNotSame( $key->kid, Key_Store::generate()->kid );
	}
}
