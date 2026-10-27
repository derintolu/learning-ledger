<?php
/**
 * Uuid tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Uuid;

class Uuid_Test extends \PHPUnit\Framework\TestCase {
	public function test_uuid_v5_rfc_vector() {
		$this->assertSame( '2ed6657d-e927-568b-95e1-2665a8aea6a2', Uuid::v5( '6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'www.example.com' ) );
	}

	public function test_uuid_bin_round_trip_and_normalize() {
		$u = 'A1B2C3D4-0000-4000-8000-00000000000F';
		$this->assertSame( strtolower( $u ), Uuid::from_bin( Uuid::to_bin( $u ) ) );
		$this->assertSame( strtolower( $u ), Uuid::normalize( $u ) );
		$this->assertSame( 16, strlen( Uuid::to_bin( $u ) ) );
	}

	public function test_v4_is_valid_version_4_and_unique() {
		$a = Uuid::v4();
		$this->assertTrue( Uuid::is_valid( $a ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a );
		$this->assertNotSame( $a, Uuid::v4() );
	}

	public function test_is_valid() {
		$this->assertTrue( Uuid::is_valid( 'A1B2C3D4-0000-4000-8000-00000000000F' ) );
		$this->assertFalse( Uuid::is_valid( 'not-a-uuid' ) );
		$this->assertFalse( Uuid::is_valid( 'a1b2c3d4000040008000-00000000000f' ) );
		$this->assertFalse( Uuid::is_valid( "a1b2c3d4-0000-4000-8000-00000000000f\n" ) );
	}

	public function test_normalize_throws_on_invalid() {
		$this->expectException( Invalid_Input::class );
		Uuid::normalize( 'nope' );
	}

	public function test_to_bin_throws_on_invalid() {
		$this->expectException( Invalid_Input::class );
		Uuid::to_bin( 'nope' );
	}

	public function test_from_bin_throws_on_wrong_length() {
		$this->expectException( Invalid_Input::class );
		Uuid::from_bin( 'short' );
	}

	public function test_nil_bin() {
		$this->assertSame( str_repeat( "\0", 16 ), Uuid::NIL_BIN );
		$this->assertSame( '00000000-0000-0000-0000-000000000000', Uuid::from_bin( Uuid::NIL_BIN ) );
	}
}
