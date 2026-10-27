<?php
/**
 * Ed25519 tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Crypto\Ed25519;

class Ed25519_Test extends \PHPUnit\Framework\TestCase {
	const SEED   = '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60';
	const PUBLIC = 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a';
	const SIG    = 'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b';

	private static function backends(): array {
		return extension_loaded( 'sodium' ) ? array( 'native', 'compat' ) : array( 'compat' );
	}

	protected function tearDown(): void {
		Ed25519::force_backend( null );
	}

	public function test_rfc8032_vector_1_both_backends() {
		foreach ( self::backends() as $b ) {
			Ed25519::force_backend( $b );
			$this->assertSame( $b, Ed25519::backend() );
			$kp = Ed25519::from_seed( hex2bin( self::SEED ) );
			$this->assertSame( self::PUBLIC, bin2hex( $kp['public'] ) );
			$this->assertSame( 64, strlen( $kp['secret'] ) );
			$this->assertSame( self::SIG, bin2hex( Ed25519::sign( '', $kp['secret'] ) ) );
			$this->assertTrue( Ed25519::verify( hex2bin( self::SIG ), '', $kp['public'] ) );
			$this->assertFalse( Ed25519::verify( hex2bin( self::SIG ), 'x', $kp['public'] ) );
		}
	}

	public function test_default_backend_is_native_when_extension_loaded() {
		$this->assertSame( extension_loaded( 'sodium' ) ? 'native' : 'compat', Ed25519::backend() );
	}

	public function test_backends_interoperate() {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'ext-sodium is not loaded; native backend unavailable.' );
		}
		Ed25519::force_backend( 'native' );
		$kp  = Ed25519::keypair();
		$sig = Ed25519::sign( 'message', $kp['secret'] );
		Ed25519::force_backend( 'compat' );
		$this->assertTrue( Ed25519::verify( $sig, 'message', $kp['public'] ) );
		$kp2  = Ed25519::keypair();
		$sig2 = Ed25519::sign( 'other', $kp2['secret'] );
		Ed25519::force_backend( 'native' );
		$this->assertTrue( Ed25519::verify( $sig2, 'other', $kp2['public'] ) );
	}

	public function test_keypair_shapes() {
		$kp = Ed25519::keypair();
		$this->assertSame( 32, strlen( $kp['public'] ) );
		$this->assertSame( 64, strlen( $kp['secret'] ) );
		$this->assertSame( $kp['public'], substr( $kp['secret'], 32 ) );
	}

	public function test_verify_returns_false_on_malformed_input_both_backends() {
		foreach ( self::backends() as $b ) {
			Ed25519::force_backend( $b );
			$public = hex2bin( self::PUBLIC );
			$this->assertFalse( Ed25519::verify( 'short', '', $public ) );
			$this->assertFalse( Ed25519::verify( hex2bin( self::SIG ), '', 'short' ) );
			$this->assertFalse( Ed25519::verify( str_repeat( "\xff", 64 ), '', $public ) );
		}
	}

	public function test_sign_rejects_bad_secret_length() {
		$this->expectException( \InvalidArgumentException::class );
		Ed25519::sign( 'm', 'too-short' );
	}

	public function test_from_seed_rejects_bad_seed_length() {
		$this->expectException( \InvalidArgumentException::class );
		Ed25519::from_seed( 'too-short' );
	}

	public function test_force_backend_rejects_unknown() {
		$this->expectException( \InvalidArgumentException::class );
		Ed25519::force_backend( 'gmp' );
	}

	public function test_key_id_is_first_16_hex_of_sha256() {
		$public = hex2bin( self::PUBLIC );
		$this->assertSame( substr( hash( 'sha256', $public ), 0, 16 ), Ed25519::key_id( $public ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', Ed25519::key_id( $public ) );
	}
}
