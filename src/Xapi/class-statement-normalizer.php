<?php
/**
 * Server-side statement normalisation.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Xapi;

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

/**
 * Fills in the properties the LRS owns and builds the PUT identity form.
 *
 * Input is a statement that already passed Statement_Validator.
 */
final class Statement_Normalizer {

	/**
	 * Properties the LRS sets, ignored when comparing statements.
	 *
	 * @var string[]
	 */
	private const SERVER_KEYS = array( 'id', 'stored', 'authority', 'version' );

	/**
	 * Set id, stored, authority, version and timestamp; turn single
	 * contextActivities objects into arrays.
	 *
	 * @param array              $st        Validated statement.
	 * @param string             $version   Negotiated xAPI version.
	 * @param array              $authority Authority derived from the credentials.
	 * @param \DateTimeImmutable $stored    Time of storage.
	 *
	 * @return array
	 */
	public static function normalize( array $st, string $version, array $authority, \DateTimeImmutable $stored ): array {
		$stored_text = Timestamp::to_xapi( $stored );

		$st['id']        = isset( $st['id'] ) ? strtolower( $st['id'] ) : Uuid::v4();
		$st['stored']    = $stored_text;
		$st['authority'] = $authority;
		if ( ! isset( $st['version'] ) ) {
			$st['version'] = '2.0.0' === $version ? '2.0.0' : '1.0.0';
		}
		if ( ! isset( $st['timestamp'] ) ) {
			$st['timestamp'] = $stored_text;
		}
		return self::context_activities_as_arrays( $st );
	}

	/**
	 * The form two statements are compared in for the PUT identity check:
	 * server-owned properties removed, timestamp in UTC, contextActivities as
	 * arrays, object keys sorted (list order kept).
	 *
	 * @param array $st Statement.
	 *
	 * @return array
	 */
	public static function comparable( array $st ): array {
		foreach ( self::SERVER_KEYS as $key ) {
			unset( $st[ $key ] );
		}
		if ( isset( $st['timestamp'] ) && is_string( $st['timestamp'] ) ) {
			try {
				$st['timestamp'] = Timestamp::to_xapi( Timestamp::parse( $st['timestamp'], '1.0.3' ) );
			} catch ( Invalid_Input $e ) {
				// Keep an unparsable value as given; validation rejects it before storage.
				unset( $e );
			}
		}
		return self::sort_keys( self::context_activities_as_arrays( $st ) );
	}

	/**
	 * Wrap single contextActivities values in arrays, here and in a SubStatement.
	 *
	 * @param array $st Statement or SubStatement.
	 *
	 * @return array
	 */
	private static function context_activities_as_arrays( array $st ): array {
		if ( isset( $st['context']['contextActivities'] ) && is_array( $st['context']['contextActivities'] ) ) {
			foreach ( $st['context']['contextActivities'] as $key => $value ) {
				if ( is_array( $value ) && ! Json::is_list( $value ) ) {
					$st['context']['contextActivities'][ $key ] = array( $value );
				}
			}
		}
		if ( isset( $st['object']['objectType'] ) && 'SubStatement' === $st['object']['objectType'] ) {
			$st['object'] = self::context_activities_as_arrays( $st['object'] );
		}
		return $st;
	}

	/**
	 * Recursively sort object keys; lists keep their order.
	 *
	 * @param array $v Value.
	 *
	 * @return array
	 */
	private static function sort_keys( array $v ): array {
		foreach ( $v as $k => $child ) {
			if ( is_array( $child ) ) {
				$v[ $k ] = self::sort_keys( $child );
			}
		}
		if ( ! Json::is_list( $v ) ) {
			ksort( $v, SORT_STRING );
		}
		return $v;
	}
}
