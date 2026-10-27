<?php
/**
 * Secret_Box, Tokens and Hmac tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- base64 is the wire format under test.

use LearningLedger\Crypto\Hmac;
use LearningLedger\Crypto\Secret_Box;
use LearningLedger\Crypto\Tokens;

class Secret_Box_Test extends \PHPUnit\Framework\TestCase {
	protected function tearDown(): void {
		Hmac::set_key_for_tests( null );
	}

	public function test_round_trip_and_format() {
		$sealed = Secret_Box::seal( 'top secret', 'webhook-secret' );
		$raw    = base64_decode( $sealed, true );
		$this->assertIsString( $raw );
		$this->assertSame( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + strlen( 'top secret' ), strlen( $raw ) );
		$this->assertStringNotContainsString( 'top secret', $raw );
		$this->assertSame( 'top secret', Secret_Box::open( $sealed, 'webhook-secret' ) );
	}

	public function test_nonce_is_random_per_seal() {
		$this->assertNotSame( Secret_Box::seal( 'x', 'c' ), Secret_Box::seal( 'x', 'c' ) );
	}

	public function test_secret_box_context_binding() {
		$sealed = Secret_Box::seal( 'top secret', 'context-a' );
		$this->expectException( \RuntimeException::class );
		Secret_Box::open( $sealed, 'context-b' );
	}

	public function test_open_rejects_tampered_ciphertext() {
		$raw                       = base64_decode( Secret_Box::seal( 'top secret', 'c' ), true );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
		$this->expectException( \RuntimeException::class );
		Secret_Box::open( base64_encode( $raw ), 'c' );
	}

	public function test_open_rejects_garbage_without_leaking_input() {
		foreach ( array( '', 'not base64 !!', base64_encode( 'short' ) ) as $bad ) {
			try {
				Secret_Box::open( $bad, 'c' );
				$this->fail( 'Expected RuntimeException' );
			} catch ( \RuntimeException $e ) {
				if ( '' !== $bad ) {
					$this->assertStringNotContainsString( $bad, $e->getMessage() );
				}
			}
		}
	}

	public function test_key_source_is_salts_without_constant() {
		$this->assertFalse( defined( 'LLEDGER_ENCRYPTION_KEY' ) );
		$this->assertSame( 'salts', Secret_Box::key_source() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_key_source_changes_key() {
		$sealed = Secret_Box::seal( 'v', 'c' );
		define( 'LLEDGER_ENCRYPTION_KEY', 'a-dedicated-encryption-key-for-tests' );
		$this->assertSame( 'constant', Secret_Box::key_source() );
		$this->assertSame( 'v', Secret_Box::open( Secret_Box::seal( 'v', 'c' ), 'c' ) );
		$this->expectException( \RuntimeException::class );
		Secret_Box::open( $sealed, 'c' );
	}

	public function test_tokens_random_is_base64url_without_padding() {
		$t = Tokens::random();
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $t );
		$this->assertSame( 32, strlen( base64_decode( strtr( $t, '-_', '+/' ), true ) ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{22}$/', Tokens::random( 16 ) );
		$this->assertNotSame( Tokens::random(), Tokens::random() );
	}

	public function test_tokens_hash_is_raw_sha256() {
		$this->assertSame( hash( 'sha256', 'abc', true ), Tokens::hash( 'abc' ) );
		$this->assertSame( 32, strlen( Tokens::hash( 'abc' ) ) );
	}

	public function test_hmac_sign_and_verify_with_test_key() {
		Hmac::set_key_for_tests( 'test-key' );
		$mac = Hmac::sign( 'id|123', 'badge-download' );
		$this->assertSame( hash_hmac( 'sha256', 'id|123', 'test-keybadge-download', true ), $mac );
		$this->assertTrue( Hmac::verify( $mac, 'id|123', 'badge-download' ) );
		$this->assertFalse( Hmac::verify( $mac, 'id|124', 'badge-download' ) );
		$this->assertFalse( Hmac::verify( $mac, 'id|123', 'other-purpose' ) );
		$this->assertFalse( Hmac::verify( substr( $mac, 0, 31 ), 'id|123', 'badge-download' ) );
	}

	public function test_hmac_key_change_invalidates_mac() {
		Hmac::set_key_for_tests( 'key-one' );
		$mac = Hmac::sign( 'data', 'p' );
		Hmac::set_key_for_tests( 'key-two' );
		$this->assertFalse( Hmac::verify( $mac, 'data', 'p' ) );
	}
}
