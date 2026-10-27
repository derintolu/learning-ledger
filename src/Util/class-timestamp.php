<?php
/**
 * Timestamp helpers.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Util;

/**
 * Timestamp parsing and DB/xAPI formatting. All values are UTC.
 */
final class Timestamp {

	const RFC3339 = '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:\d{2})\z/';

	const ISO8601 = '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:[.,](\d+))?(Z|[+-]\d{2}(?::?\d{2})?)?\z/';

	/**
	 * Parse an xAPI timestamp.
	 *
	 * Version 2.0.0: RFC 3339, offset required, "-00:00" rejected. Version 1.0.3: ISO 8601, a missing offset means UTC.
	 *
	 * @param string $v            Timestamp.
	 * @param string $xapi_version Version of xAPI.
	 *
	 * @return \DateTimeImmutable In UTC.
	 * @throws Invalid_Input When invalid.
	 */
	public static function parse( string $v, string $xapi_version ): \DateTimeImmutable {
		if ( '2.0.0' === $xapi_version ) {
			$pattern = self::RFC3339;
		} elseif ( '1.0.3' === $xapi_version ) {
			$pattern = self::ISO8601;
		} else {
			throw new Invalid_Input( 'Unsupported xAPI version.', 'invalid_version' );
		}
		if ( 1 !== preg_match( $pattern, $v, $m ) ) {
			throw new Invalid_Input( 'Invalid timestamp.', 'invalid_timestamp' );
		}
		$offset = $m[8] ?? 'Z';
		if ( '' === $offset ) {
			$offset = 'Z';
		}
		if ( '-00:00' === $offset && '2.0.0' === $xapi_version ) {
			throw new Invalid_Input( 'Timestamp offset -00:00 is not allowed.', 'invalid_timestamp' );
		}
		if ( 'Z' !== $offset ) {
			if ( 2 === strlen( $offset ) - 1 ) {
				$offset .= ':00';
			} elseif ( 5 === strlen( $offset ) ) {
				$offset = substr( $offset, 0, 3 ) . ':' . substr( $offset, 3 );
			}
			if ( (int) substr( $offset, 1, 2 ) > 23 || (int) substr( $offset, 4, 2 ) > 59 ) {
				throw new Invalid_Input( 'Invalid timestamp offset.', 'invalid_timestamp' );
			}
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59 ) {
			throw new Invalid_Input( 'Invalid timestamp.', 'invalid_timestamp' );
		}
		$micros = str_pad( substr( $m[7] ?? '', 0, 6 ), 6, '0' );
		$text   = sprintf( '%s-%s-%sT%s:%s:%s.%s%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6], $micros, $offset );
		$dt     = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s.uP', $text );
		if ( false === $dt ) {
			throw new Invalid_Input( 'Invalid timestamp.', 'invalid_timestamp' );
		}
		return $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Format for the database (UTC, microseconds).
	 *
	 * @param \DateTimeImmutable $t Time.
	 *
	 * @return string
	 */
	public static function to_db( \DateTimeImmutable $t ): string {
		return $t->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.u' );
	}

	/**
	 * Read a database value as UTC.
	 *
	 * @param string $s Database value.
	 *
	 * @return \DateTimeImmutable
	 * @throws Invalid_Input When invalid.
	 */
	public static function from_db( string $s ): \DateTimeImmutable {
		$utc = new \DateTimeZone( 'UTC' );
		foreach ( array( 'Y-m-d H:i:s.u', 'Y-m-d H:i:s' ) as $format ) {
			$dt = \DateTimeImmutable::createFromFormat( '!' . $format, $s, $utc );
			if ( false !== $dt && $dt->format( $format ) === $s ) {
				return $dt;
			}
		}
		throw new Invalid_Input( 'Invalid database timestamp.', 'invalid_timestamp' );
	}

	/**
	 * Format for xAPI output (UTC, microseconds, Z suffix).
	 *
	 * @param \DateTimeImmutable $t Time.
	 *
	 * @return string
	 */
	public static function to_xapi( \DateTimeImmutable $t ): string {
		return $t->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.u\Z' );
	}
}
