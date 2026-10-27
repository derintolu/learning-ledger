<?php
/**
 * ISO 8601 duration helpers.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Util;

/**
 * Converts between ISO 8601 durations and seconds.
 */
final class Duration {

	/**
	 * Parse a duration to seconds. Years count as 365 days and months as 30 days.
	 *
	 * @param string $iso Duration such as PT1H30M3.5S.
	 *
	 * @return float
	 * @throws Invalid_Input When invalid.
	 */
	public static function parse( string $iso ): float {
		$pattern = '/\AP(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?\z/';
		if ( 1 !== preg_match( $pattern, $iso, $m ) || 'P' === $iso || ! preg_match( '/\d/', $iso ) || preg_match( '/T\z/', $iso ) ) {
			throw new Invalid_Input( 'Invalid duration.', 'invalid_duration' );
		}
		return (float) ( ( (int) ( $m[1] ?? 0 ) ) * 31536000
			+ ( (int) ( $m[2] ?? 0 ) ) * 2592000
			+ ( (int) ( $m[3] ?? 0 ) ) * 604800
			+ ( (int) ( $m[4] ?? 0 ) ) * 86400
			+ ( (int) ( $m[5] ?? 0 ) ) * 3600
			+ ( (int) ( $m[6] ?? 0 ) ) * 60 )
			+ (float) ( $m[7] ?? 0 );
	}

	/**
	 * Format seconds as an ISO 8601 duration with hundredths of a second at most.
	 *
	 * @param float $seconds Non-negative seconds.
	 *
	 * @return string
	 * @throws Invalid_Input When negative or not finite.
	 */
	public static function format( float $seconds ): string {
		if ( $seconds < 0 || ! is_finite( $seconds ) ) {
			throw new Invalid_Input( 'Duration must be a non-negative number.', 'invalid_duration' );
		}
		$cs = (int) round( $seconds * 100 );
		$h  = intdiv( $cs, 360000 );
		$m  = intdiv( $cs % 360000, 6000 );
		$cs = $cs % 6000;
		$s  = intdiv( $cs, 100 );
		$f  = $cs % 100;

		$out = '';
		if ( $h > 0 ) {
			$out .= $h . 'H';
		}
		if ( $m > 0 ) {
			$out .= $m . 'M';
		}
		if ( $cs > 0 ) {
			$out .= $s . ( $f > 0 ? rtrim( sprintf( '.%02d', $f ), '0' ) : '' ) . 'S';
		}
		return '' === $out ? 'PT0S' : 'PT' . $out;
	}
}
