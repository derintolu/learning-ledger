<?php
/**
 * Signed_Request tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- base64 is the wire format under test.

use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Memory_Nonce_Store;
use LearningLedger\Crypto\Signature_Error;
use LearningLedger\Crypto\Signed_Request;

class Signed_Request_Test extends \PHPUnit\Framework\TestCase {
	const PATH = '/wp-json/learning-ledger/v1/course-server/learners?x=1';
	const T    = 1761552000;

	private array $kp;
	private string $key_id;

	protected function setUp(): void {
		$this->kp     = Ed25519::keypair();
		$this->key_id = Ed25519::key_id( $this->kp['public'] );
	}

	private function lookup(): callable {
		$keys = array( $this->key_id => $this->kp['public'] );
		return static function ( string $id ) use ( $keys ): ?string {
			return $keys[ $id ] ?? null;
		};
	}

	private function reason_of( callable $call ): string {
		try {
			$call();
		} catch ( Signature_Error $e ) {
			return $e->reason();
		}
		$this->fail( 'Expected Signature_Error' );
	}

	public function test_canonical_string_exact() {
		$this->assertSame( "POST\n/wp-json/learning-ledger/v1/course-server/learners?x=1\n" . hash( 'sha256', '{}' ) . "\n1761552000\nabc", Signed_Request::canonical( 'post', '/wp-json/learning-ledger/v1/course-server/learners?x=1', '{}', '1761552000', 'abc' ) );
	}

	public function test_sign_produces_headers_in_expected_formats() {
		$h = Signed_Request::sign( $this->key_id, $this->kp['secret'], 'POST', self::PATH, '{}', self::T );
		$this->assertSame(
			array( 'X-Learning-Ledger-Key', 'X-Learning-Ledger-Timestamp', 'X-Learning-Ledger-Nonce', 'X-Learning-Ledger-Signature' ),
			array_keys( $h )
		);
		$this->assertSame( $this->key_id, $h['X-Learning-Ledger-Key'] );
		$this->assertSame( '1761552000', $h['X-Learning-Ledger-Timestamp'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{16,64}$/', $h['X-Learning-Ledger-Nonce'] );
		$sig = base64_decode( $h['X-Learning-Ledger-Signature'], true );
		$this->assertSame( 64, strlen( $sig ) );
		$canonical = Signed_Request::canonical( 'POST', self::PATH, '{}', '1761552000', $h['X-Learning-Ledger-Nonce'] );
		$this->assertTrue( Ed25519::verify( $sig, $canonical, $this->kp['public'] ) );
	}

	public function test_sign_defaults_to_current_time() {
		$before = time();
		$h      = Signed_Request::sign( $this->key_id, $this->kp['secret'], 'GET', '/x', '' );
		$this->assertGreaterThanOrEqual( $before, (int) $h['X-Learning-Ledger-Timestamp'] );
		$this->assertLessThanOrEqual( time(), (int) $h['X-Learning-Ledger-Timestamp'] );
	}

	public function test_verify_accepts_valid_request_within_window() {
		$h = Signed_Request::sign( $this->key_id, $this->kp['secret'], 'POST', self::PATH, '{}', self::T );
		$this->assertSame( $this->key_id, Signed_Request::verify( $h, 'post', self::PATH, '{}', $this->lookup(), new Memory_Nonce_Store(), self::T + 300 ) );
		$h = Signed_Request::sign( $this->key_id, $this->kp['secret'], 'POST', self::PATH, '{}', self::T );
		$this->assertSame( $this->key_id, Signed_Request::verify( $h, 'POST', self::PATH, '{}', $this->lookup(), new Memory_Nonce_Store(), self::T - 300 ) );
	}

	public function test_verify_accepts_lowercased_and_wp_style_headers() {
		$h  = Signed_Request::sign( $this->key_id, $this->kp['secret'], 'POST', self::PATH, '{}', self::T );
		$wp = array();
		foreach ( $h as $name => $value ) {
			$wp[ str_replace( '-', '_', strtolower( $name ) ) ] = array( $value );
		}
		$this->assertSame( $this->key_id, Signed_Request::verify( $wp, 'POST', self::PATH, '{}', $this->lookup(), new Memory_Nonce_Store(), self::T ) );
	}

	public function test_verify_rejects_stale_replay_and_tamper() {
		$lookup = $this->lookup();
		$h      = Signed_Request::sign( $this->key_id, $this->kp['secret'], 'POST', self::PATH, '{}', self::T );

		$this->assertSame( 'stale', $this->reason_of( fn() => Signed_Request::verify( $h, 'POST', self::PATH, '{}', $lookup, new Memory_Nonce_Store(), self::T + 301 ) ) );
		$this->assertSame( 'stale', $this->reason_of( fn() => Signed_Request::verify( $h, 'POST', self::PATH, '{}', $lookup, new Memory_Nonce_Store(), self::T - 301 ) ) );

		$nonces = new Memory_Nonce_Store();
		$this->assertSame( $this->key_id, Signed_Request::verify( $h, 'POST', self::PATH, '{}', $lookup, $nonces, self::T ) );
		$this->assertSame( 'replay', $this->reason_of( fn() => Signed_Request::verify( $h, 'POST', self::PATH, '{}', $lookup, $nonces, self::T ) ) );

		$this->assertSame( 'bad_signature', $this->reason_of( fn() => Signed_Request::verify( $h, 'POST', self::PATH, '{"a":1}', $lookup, new Memory_Nonce_Store(), self::T ) ) );
		$this->assertSame( 'bad_signature', $this->reason_of( fn() => Signed_Request::verify( $h, 'PUT', self::PATH, '{}', $lookup, new Memory_Nonce_Store(), self::T ) ) );
		$this->assertSame( 'bad_signature', $this->reason_of( fn() => Signed_Request::verify( $h, 'POST', self::PATH . '&y=2', '{}', $lookup, new Memory_Nonce_Store(), self::T ) ) );

		$unknown                          = $h;
		$unknown['X-Learning-Ledger-Key'] = 'ffffffffffffffff';
		$this->assertSame( 'unknown_key', $this->reason_of( fn() => Signed_Request::verify( $unknown, 'POST', self::PATH, '{}', $lookup, new Memory_Nonce_Store(), self::T ) ) );
	}

	public function test_bad_signature_does_not_consume_nonce() {
		$lookup = $this->lookup();
		$nonces = new Memory_Nonce_Store();
		$h      = Signed_Request::sign( $this->key_id, $this->kp['secret'], 'POST', self::PATH, '{}', self::T );
		$this->assertSame( 'bad_signature', $this->reason_of( fn() => Signed_Request::verify( $h, 'POST', self::PATH, 'tampered', $lookup, $nonces, self::T ) ) );
		$this->assertSame( $this->key_id, Signed_Request::verify( $h, 'POST', self::PATH, '{}', $lookup, $nonces, self::T ) );
	}

	public function test_signature_from_other_key_is_bad_signature() {
		$other = Ed25519::keypair();
		$h     = Signed_Request::sign( $this->key_id, $other['secret'], 'POST', self::PATH, '{}', self::T );
		$this->assertSame( 'bad_signature', $this->reason_of( fn() => Signed_Request::verify( $h, 'POST', self::PATH, '{}', $this->lookup(), new Memory_Nonce_Store(), self::T ) ) );
	}

	public function test_missing_headers() {
		$h = Signed_Request::sign( $this->key_id, $this->kp['secret'], 'POST', self::PATH, '{}', self::T );
		foreach ( array_keys( $h ) as $name ) {
			$partial = $h;
			unset( $partial[ $name ] );
			$this->assertSame( 'missing_header', $this->reason_of( fn() => Signed_Request::verify( $partial, 'POST', self::PATH, '{}', $this->lookup(), new Memory_Nonce_Store(), self::T ) ), $name );
			$partial[ $name ] = '';
			$this->assertSame( 'missing_header', $this->reason_of( fn() => Signed_Request::verify( $partial, 'POST', self::PATH, '{}', $this->lookup(), new Memory_Nonce_Store(), self::T ) ), $name );
		}
	}

	public function test_malformed_values_rejected() {
		$h = Signed_Request::sign( $this->key_id, $this->kp['secret'], 'POST', self::PATH, '{}', self::T );

		$bad                                = $h;
		$bad['X-Learning-Ledger-Timestamp'] = '1761552000.5';
		$this->assertSame( 'stale', $this->reason_of( fn() => Signed_Request::verify( $bad, 'POST', self::PATH, '{}', $this->lookup(), new Memory_Nonce_Store(), self::T ) ) );

		foreach ( array( 'short', str_repeat( 'a', 65 ), 'has space in it ok', 'abc+def/ghijklmnop' ) as $nonce ) {
			$bad                            = $h;
			$bad['X-Learning-Ledger-Nonce'] = $nonce;
			$this->assertSame( 'bad_signature', $this->reason_of( fn() => Signed_Request::verify( $bad, 'POST', self::PATH, '{}', $this->lookup(), new Memory_Nonce_Store(), self::T ) ), $nonce );
		}

		$bad                                = $h;
		$bad['X-Learning-Ledger-Signature'] = base64_encode( 'short' );
		$this->assertSame( 'bad_signature', $this->reason_of( fn() => Signed_Request::verify( $bad, 'POST', self::PATH, '{}', $this->lookup(), new Memory_Nonce_Store(), self::T ) ) );
		$bad['X-Learning-Ledger-Signature'] = '!!not-base64!!';
		$this->assertSame( 'bad_signature', $this->reason_of( fn() => Signed_Request::verify( $bad, 'POST', self::PATH, '{}', $this->lookup(), new Memory_Nonce_Store(), self::T ) ) );
	}

	public function test_signature_error_message_is_reason_only() {
		$e = new Signature_Error( 'replay' );
		$this->assertInstanceOf( \RuntimeException::class, $e );
		$this->assertSame( 'replay', $e->reason() );
		$this->assertSame( 'replay', $e->getMessage() );
	}

	public function test_memory_nonce_store_remembers_per_key() {
		$store = new Memory_Nonce_Store();
		$this->assertTrue( $store->remember( 'k1', 'nonce-aaaaaaaaaaaa', 600 ) );
		$this->assertFalse( $store->remember( 'k1', 'nonce-aaaaaaaaaaaa', 600 ) );
		$this->assertTrue( $store->remember( 'k2', 'nonce-aaaaaaaaaaaa', 600 ) );
	}

	public function test_memory_nonce_store_forgets_after_ttl() {
		$store = new Memory_Nonce_Store();
		$this->assertTrue( $store->remember( 'k1', 'nonce-bbbbbbbbbbbb', 0 ) );
		$this->assertTrue( $store->remember( 'k1', 'nonce-bbbbbbbbbbbb', 600 ) );
	}

	public function test_window_and_ttl_constants() {
		$this->assertSame( 300, Signed_Request::WINDOW );
		$this->assertSame( 600, Signed_Request::NONCE_TTL );
	}
}
