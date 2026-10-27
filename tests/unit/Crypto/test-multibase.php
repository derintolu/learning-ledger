<?php
/**
 * Multibase tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Crypto\Multibase;

class Multibase_Test extends \PHPUnit\Framework\TestCase {
	public function test_base58btc_vector() {
		$this->assertSame( 'z2NEpo7TZRRrLZSi2U', Multibase::base58btc_encode( 'Hello World!' ) );
		$this->assertSame( 'Hello World!', Multibase::base58btc_decode( 'z2NEpo7TZRRrLZSi2U' ) );
	}

	public function test_base58btc_leading_zero_bytes_and_empty() {
		$this->assertSame( 'z111', Multibase::base58btc_encode( "\0\0\0" ) );
		$this->assertSame( "\0\0\x01", Multibase::base58btc_decode( 'z112' ) );
		$this->assertSame( 'z', Multibase::base58btc_encode( '' ) );
		$this->assertSame( '', Multibase::base58btc_decode( 'z' ) );
		$raw = "\0" . random_bytes( 40 );
		$this->assertSame( $raw, Multibase::base58btc_decode( Multibase::base58btc_encode( $raw ) ) );
	}

	public function test_base58btc_decode_rejects_bad_prefix_and_alphabet() {
		foreach ( array( '2NEpo7TZRRrLZSi2U', 'z0OIl', 'u2NEpo' ) as $bad ) {
			try {
				Multibase::base58btc_decode( $bad );
				$this->fail( 'Expected rejection of ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_base64url_round_trip() {
		$this->assertSame( 'uaGk_Pw', Multibase::base64url_encode( 'hi??' ) );
		$this->assertSame( 'hi??', Multibase::base64url_decode( 'uaGk_Pw' ) );
		$raw = random_bytes( 33 );
		$enc = Multibase::base64url_encode( $raw );
		$this->assertStringNotContainsString( '=', $enc );
		$this->assertSame( $raw, Multibase::base64url_decode( $enc ) );
	}

	public function test_base64url_decode_rejects_bad_input() {
		foreach ( array( 'aGk', 'uaG+k', 'ua=', 'zabc' ) as $bad ) {
			try {
				Multibase::base64url_decode( $bad );
				$this->fail( 'Expected rejection of ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_multikey_round_trip_starts_z6mk() {
		$public = hex2bin( 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a' );
		$mk     = Multibase::ed25519_multikey( $public );
		$this->assertStringStartsWith( 'z6Mk', $mk );
		$this->assertSame( "\xed\x01" . $public, Multibase::base58btc_decode( $mk ) );
		$this->assertSame( $public, Multibase::from_ed25519_multikey( $mk ) );
	}

	public function test_multikey_rejects_wrong_codec_or_length() {
		$this->expectException( \InvalidArgumentException::class );
		Multibase::from_ed25519_multikey( Multibase::base58btc_encode( "\xec\x01" . str_repeat( 'a', 32 ) ) );
	}

	public function test_multikey_rejects_short_key() {
		$this->expectException( \InvalidArgumentException::class );
		Multibase::from_ed25519_multikey( Multibase::base58btc_encode( "\xed\x01" . str_repeat( 'a', 31 ) ) );
	}

	public function test_multikey_encode_rejects_wrong_length() {
		$this->expectException( \InvalidArgumentException::class );
		Multibase::ed25519_multikey( 'short' );
	}
}
