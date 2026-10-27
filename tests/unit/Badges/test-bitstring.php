<?php
/**
 * Bitstring Status List bitstring tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Status\Bitstring;
use LearningLedger\Crypto\Multibase;
use LearningLedger\Util\Invalid_Input;

class Bitstring_Test extends \PHPUnit\Framework\TestCase {

	private static function empty_list(): string {
		return str_repeat( "\0", Bitstring::SIZE / 8 );
	}

	public function test_size_is_131072_entries() {
		$this->assertSame( 131072, Bitstring::SIZE );
	}

	public function test_index_zero_is_most_significant_bit_of_first_byte() {
		$bits = Bitstring::set( self::empty_list(), 0, true );
		$this->assertSame( "\x80", $bits[0] );
		$this->assertSame( str_repeat( "\0", Bitstring::SIZE / 8 - 1 ), substr( $bits, 1 ) );
		$this->assertTrue( Bitstring::get( $bits, 0 ) );
		$this->assertFalse( Bitstring::get( $bits, 1 ) );
	}

	public function test_last_index_is_least_significant_bit_of_last_byte() {
		$bits = Bitstring::set( self::empty_list(), 131071, true );
		$this->assertSame( "\x01", substr( $bits, -1 ) );
		$this->assertSame( str_repeat( "\0", Bitstring::SIZE / 8 - 1 ), substr( $bits, 0, -1 ) );
		$this->assertTrue( Bitstring::get( $bits, 131071 ) );
		$this->assertFalse( Bitstring::get( $bits, 131070 ) );
	}

	public function test_set_and_clear_leave_other_bits_alone() {
		$bits = Bitstring::set( self::empty_list(), 9, true );
		$bits = Bitstring::set( $bits, 10, true );
		$this->assertSame( "\x00\x60", substr( $bits, 0, 2 ) );
		$bits = Bitstring::set( $bits, 9, false );
		$this->assertSame( "\x00\x20", substr( $bits, 0, 2 ) );
		$this->assertFalse( Bitstring::get( $bits, 9 ) );
		$this->assertTrue( Bitstring::get( $bits, 10 ) );
		$this->assertSame( Bitstring::SIZE / 8, strlen( $bits ) );
	}

	public function test_out_of_range_index_is_rejected() {
		foreach ( array( -1, 131072 ) as $index ) {
			try {
				Bitstring::set( self::empty_list(), $index, true );
				$this->fail( 'Expected Invalid_Input for set ' . $index );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'index', $e->path() );
			}
			try {
				Bitstring::get( self::empty_list(), $index );
				$this->fail( 'Expected Invalid_Input for get ' . $index );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'index', $e->path() );
			}
		}
	}

	public function test_encode_is_multibase_base64url_of_gzip() {
		$bits    = Bitstring::set( self::empty_list(), 7, true );
		$encoded = Bitstring::encode( $bits );
		$this->assertSame( 'u', $encoded[0] );
		$this->assertMatchesRegularExpression( '/^u[A-Za-z0-9_-]+$/D', $encoded );
		$this->assertSame( $bits, gzdecode( Multibase::base64url_decode( $encoded ) ) );
	}

	public function test_encode_decode_round_trip() {
		$bits = self::empty_list();
		foreach ( array( 0, 1, 7, 8, 4242, 65536, 131071 ) as $index ) {
			$bits = Bitstring::set( $bits, $index, true );
		}
		$decoded = Bitstring::decode( Bitstring::encode( $bits ) );
		$this->assertSame( $bits, $decoded );
		$this->assertTrue( Bitstring::get( $decoded, 4242 ) );
		$this->assertFalse( Bitstring::get( $decoded, 4243 ) );
		$this->assertSame( self::empty_list(), Bitstring::decode( Bitstring::encode( self::empty_list() ) ) );
	}

	public function test_decode_rejects_garbage_and_oversized_lists() {
		$bad = array(
			'not multibase',
			'u!!!',
			Multibase::base64url_encode( 'not gzip' ),
			Multibase::base64url_encode( gzencode( str_repeat( "\0", Bitstring::SIZE / 8 + 1 ) ) ),
		);
		foreach ( $bad as $encoded ) {
			try {
				Bitstring::decode( $encoded );
				$this->fail( 'Expected Invalid_Input for ' . substr( $encoded, 0, 20 ) );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'encodedList', $e->path() );
			}
		}
	}
}
