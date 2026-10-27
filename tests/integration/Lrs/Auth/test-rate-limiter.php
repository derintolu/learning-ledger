<?php
/**
 * Rate limiter tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Lrs\Auth\Auth_Fail_Limiter;
use LearningLedger\Lrs\Auth\Rate_Limiter;
use LearningLedger\Util\Clock;

class Rate_Limiter_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:00:00.000000', new DateTimeZone( 'UTC' ) ) );
	}

	public function tear_down() {
		Clock::freeze( null );
		delete_option( 'lledger_rate_classes' );
		parent::tear_down();
	}

	private static function advance( string $by ): void {
		Clock::freeze( Clock::now()->modify( $by ) );
	}

	public function test_bucket_allows_burst_then_refills_at_rate() {
		$l = new Rate_Limiter();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $l->consume( 'b1', 2.0, 5 ), "request $i" );
		}
		$this->assertFalse( $l->consume( 'b1', 2.0, 5 ) );
		$this->assertFalse( $l->consume( 'b1', 2.0, 5 ) );

		// Other buckets are independent.
		$this->assertTrue( $l->consume( 'b2', 2.0, 5 ) );

		self::advance( '+500 milliseconds' );
		$this->assertTrue( $l->consume( 'b1', 2.0, 5 ) );
		$this->assertFalse( $l->consume( 'b1', 2.0, 5 ) );

		// Refill never exceeds the burst.
		self::advance( '+1 hour' );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $l->consume( 'b1', 2.0, 5 ) );
		}
		$this->assertFalse( $l->consume( 'b1', 2.0, 5 ) );
	}

	public function test_bucket_persists_in_the_ratelimit_table_without_an_object_cache() {
		$this->assertFalse( (bool) wp_using_ext_object_cache() );
		( new Rate_Limiter() )->consume( 'persisted', 1.0, 3 );
		$row = $GLOBALS['wpdb']->get_row( "SELECT tokens, updated_at FROM {$GLOBALS['wpdb']->prefix}lledger_ratelimit WHERE bucket = 'persisted'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertEqualsWithDelta( 2.0, (float) $row->tokens, 0.0001 );
		$this->assertSame( '2026-10-27 10:00:00.000000', $row->updated_at );
	}

	public function test_rate_classes_defaults_and_option() {
		$this->assertSame(
			array(
				'default'   => array( 20, 100 ),
				'bulk'      => array( 200, 2000 ),
				'unlimited' => null,
			),
			Rate_Limiter::classes()
		);
		update_option( 'lledger_rate_classes', '{"tiny":[1,3],"default":[5,10]}' );
		$this->assertSame( array( 1, 3 ), Rate_Limiter::classes()['tiny'] );
		$this->assertSame( array( 5, 10 ), Rate_Limiter::classes()['default'] );
		$this->assertNull( Rate_Limiter::classes()['unlimited'] );
		update_option(
			'lledger_rate_classes',
			array(
				'bad' => array( 'x' ),
				'neg' => array( -1, 5 ),
			)
		);
		$this->assertArrayNotHasKey( 'bad', Rate_Limiter::classes() );
		$this->assertArrayNotHasKey( 'neg', Rate_Limiter::classes() );
	}

	public function test_auth_fail_limiter_ten_per_minute() {
		$f = new Auth_Fail_Limiter();
		for ( $i = 0; $i < 9; $i++ ) {
			$f->hit( '192.0.2.1' );
		}
		$this->assertSame( 0, $f->blocked_for( '192.0.2.1' ) );
		self::advance( '+20 seconds' );
		$f->hit( '192.0.2.1' );
		$this->assertSame( 40, $f->blocked_for( '192.0.2.1' ) );
		$this->assertSame( 0, $f->blocked_for( '192.0.2.2' ) );

		self::advance( '+39 seconds' );
		$this->assertSame( 1, $f->blocked_for( '192.0.2.1' ) );
		self::advance( '+1 second' );
		$this->assertSame( 0, $f->blocked_for( '192.0.2.1' ) );

		// A new window starts with the next failure.
		$f->hit( '192.0.2.1' );
		$this->assertSame( 0, $f->blocked_for( '192.0.2.1' ) );
	}
}
