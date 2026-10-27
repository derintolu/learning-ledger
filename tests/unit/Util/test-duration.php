<?php
/**
 * Duration and Clock tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Util\Clock;
use LearningLedger\Util\Duration;
use LearningLedger\Util\Invalid_Input;

class Duration_Test extends \PHPUnit\Framework\TestCase {
	protected function tearDown(): void {
		Clock::freeze( null );
	}

	public function test_duration() {
		$this->assertSame( 5403.5, Duration::parse( 'PT1H30M3.5S' ) );
		$this->assertSame( 'PT1H30M3.5S', Duration::format( 5403.5 ) );
	}

	public function test_parse_variants() {
		$this->assertSame( 0.0, Duration::parse( 'PT0S' ) );
		$this->assertSame( 90.0, Duration::parse( 'PT1M30S' ) );
		$this->assertSame( 3600.0, Duration::parse( 'PT1H' ) );
		$this->assertSame( 0.01, Duration::parse( 'PT0.01S' ) );
		$this->assertSame( 86400.0 + 3600.0, Duration::parse( 'P1DT1H' ) );
		$this->assertSame( 604800.0, Duration::parse( 'P1W' ) );
	}

	/**
	 * @dataProvider bad_values
	 */
	public function test_parse_rejects( string $v ) {
		$this->expectException( Invalid_Input::class );
		Duration::parse( $v );
	}

	public static function bad_values(): array {
		return array(
			'empty'       => array( '' ),
			'no P'        => array( 'T1H' ),
			'bare P'      => array( 'P' ),
			'bare PT'     => array( 'PT' ),
			'words'       => array( 'an hour' ),
			'lowercase'   => array( 'pt1h' ),
			'wrong order' => array( 'PT1S1H' ),
			'negative'    => array( 'PT-1S' ),
			'trailing'    => array( 'PT1Sx' ),
		);
	}

	public function test_format() {
		$this->assertSame( 'PT0S', Duration::format( 0.0 ) );
		$this->assertSame( 'PT1S', Duration::format( 1.0 ) );
		$this->assertSame( 'PT1M', Duration::format( 60.0 ) );
		$this->assertSame( 'PT1H2M3.5S', Duration::format( 3723.5 ) );
		$this->assertSame( 'PT0.01S', Duration::format( 0.01 ) );
		$this->assertSame( 'PT0.12S', Duration::format( 0.123 ) );
		$this->assertSame( 'PT25H', Duration::format( 90000.0 ) );
	}

	public function test_format_negative_throws() {
		$this->expectException( Invalid_Input::class );
		Duration::format( -1.0 );
	}

	public function test_format_parse_round_trip() {
		$this->assertSame( 3723.25, Duration::parse( Duration::format( 3723.25 ) ) );
	}

	public function test_clock_now_is_utc_and_freezable() {
		$this->assertSame( 'UTC', Clock::now()->getTimezone()->getName() );
		$t = new \DateTimeImmutable( '2026-10-27 08:00:00.123456', new \DateTimeZone( 'UTC' ) );
		Clock::freeze( $t );
		$this->assertEquals( $t, Clock::now() );
		Clock::freeze( null );
		$this->assertNotEquals( $t, Clock::now() );
	}
}
