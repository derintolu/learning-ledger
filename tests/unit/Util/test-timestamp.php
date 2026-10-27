<?php
/**
 * Timestamp tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;

class Timestamp_Test extends \PHPUnit\Framework\TestCase {
	public function test_timestamp_rules() {
		$this->assertSame( '2026-10-27 08:00:00.123456', Timestamp::to_db( Timestamp::parse( '2026-10-27T10:00:00.123456+02:00', '2.0.0' ) ) );
		$this->expectException( Invalid_Input::class );
		Timestamp::parse( '2026-10-27T10:00:00', '2.0.0' );
	}

	public function test_2_0_0_rejects_negative_zero_offset() {
		$this->expectException( Invalid_Input::class );
		Timestamp::parse( '2026-10-27T10:00:00-00:00', '2.0.0' );
	}

	public function test_2_0_0_accepts_z_and_plus_zero() {
		$this->assertSame( '2026-10-27 10:00:00.000000', Timestamp::to_db( Timestamp::parse( '2026-10-27T10:00:00Z', '2.0.0' ) ) );
		$this->assertSame( '2026-10-27 10:00:00.500000', Timestamp::to_db( Timestamp::parse( '2026-10-27T10:00:00.5+00:00', '2.0.0' ) ) );
	}

	/**
	 * @dataProvider bad_values
	 */
	public function test_rejects_garbage( string $v ) {
		$this->expectException( Invalid_Input::class );
		Timestamp::parse( $v, '2.0.0' );
	}

	public static function bad_values(): array {
		return array(
			'empty'       => array( '' ),
			'words'       => array( 'yesterday' ),
			'bad month'   => array( '2026-13-01T00:00:00Z' ),
			'bad day'     => array( '2026-02-30T00:00:00Z' ),
			'bad hour'    => array( '2026-10-27T24:00:00Z' ),
			'leap second' => array( '2026-10-27T23:59:60Z' ),
			'trailing'    => array( '2026-10-27T10:00:00Z junk' ),
			'date only'   => array( '2026-10-27' ),
			'bad offset'  => array( '2026-10-27T10:00:00+25:00' ),
		);
	}

	public function test_1_0_3_missing_offset_is_utc() {
		$this->assertSame( '2026-10-27 10:00:00.000000', Timestamp::to_db( Timestamp::parse( '2026-10-27T10:00:00', '1.0.3' ) ) );
		$this->assertSame( '2026-10-27 08:00:00.000000', Timestamp::to_db( Timestamp::parse( '2026-10-27T10:00:00+0200', '1.0.3' ) ) );
		$this->assertSame( '2026-10-27 08:00:00.000000', Timestamp::to_db( Timestamp::parse( '2026-10-27T10:00:00+02', '1.0.3' ) ) );
	}

	public function test_1_0_3_still_rejects_garbage() {
		$this->expectException( Invalid_Input::class );
		Timestamp::parse( 'yesterday', '1.0.3' );
	}

	public function test_unknown_version_rejected() {
		$this->expectException( Invalid_Input::class );
		Timestamp::parse( '2026-10-27T10:00:00Z', '9.9.9' );
	}

	public function test_excess_fraction_digits_truncated_to_micros() {
		$this->assertSame( '2026-10-27 10:00:00.123456', Timestamp::to_db( Timestamp::parse( '2026-10-27T10:00:00.1234567Z', '2.0.0' ) ) );
	}

	public function test_db_round_trip_and_xapi_format() {
		$t = Timestamp::from_db( '2026-10-27 08:00:00.123456' );
		$this->assertSame( 'UTC', $t->getTimezone()->getName() );
		$this->assertSame( '2026-10-27 08:00:00.123456', Timestamp::to_db( $t ) );
		$this->assertSame( '2026-10-27T08:00:00.123456Z', Timestamp::to_xapi( $t ) );
	}

	public function test_to_db_converts_to_utc() {
		$t = new \DateTimeImmutable( '2026-10-27 10:00:00.5', new \DateTimeZone( '+02:00' ) );
		$this->assertSame( '2026-10-27 08:00:00.500000', Timestamp::to_db( $t ) );
		$this->assertSame( '2026-10-27T08:00:00.500000Z', Timestamp::to_xapi( $t ) );
	}

	public function test_from_db_accepts_no_fraction_and_rejects_garbage() {
		$this->assertSame( '2026-10-27 08:00:00.000000', Timestamp::to_db( Timestamp::from_db( '2026-10-27 08:00:00' ) ) );
		$this->expectException( Invalid_Input::class );
		Timestamp::from_db( 'garbage' );
	}
}
