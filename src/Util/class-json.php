<?php
/**
 * Strict JSON helpers.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Util;

/**
 * Size- and depth-limited decoding, exact encoding, deep comparison.
 */
final class Json {

	/**
	 * Decode a JSON document with size and depth limits. Objects become associative arrays.
	 *
	 * @param string  $body      Raw JSON.
	 * @param integer $max_bytes Maximum body length in bytes.
	 * @param integer $max_depth Maximum nesting of arrays/objects.
	 *
	 * @return mixed
	 * @throws Invalid_Input Codes too_large, too_deep, malformed.
	 */
	public static function decode_strict( string $body, int $max_bytes, int $max_depth = 32 ): mixed {
		if ( strlen( $body ) > $max_bytes ) {
			throw new Invalid_Input( 'JSON body is too large.', 'too_large' );
		}
		try {
			$value = json_decode( $body, true, $max_depth + 1, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			if ( JSON_ERROR_DEPTH === $e->getCode() ) {
				throw new Invalid_Input( 'JSON is nested too deeply.', 'too_deep' );
			}
			throw new Invalid_Input( 'JSON is malformed.', 'malformed' );
		}
		if ( self::depth( $value ) > $max_depth ) {
			throw new Invalid_Input( 'JSON is nested too deeply.', 'too_deep' );
		}
		return $value;
	}

	/**
	 * Encode without altering slashes, unicode or zero fractions.
	 *
	 * @param mixed $v Value.
	 *
	 * @return string
	 * @throws Invalid_Input When the value cannot be encoded.
	 */
	public static function encode_exact( mixed $v ): string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure class, no WordPress available.
			return json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			throw new Invalid_Input( 'Value cannot be encoded as JSON.', 'encode_failed' );
		}
	}

	/**
	 * Whether an array is a sequential list (JSON array). Empty arrays are lists.
	 *
	 * @param array $a Array.
	 *
	 * @return boolean
	 */
	public static function is_list( array $a ): bool {
		$i = 0;
		foreach ( $a as $k => $_ ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Deep equality: object key order ignored, list order significant, numbers compared by value.
	 *
	 * @param mixed $a First.
	 * @param mixed $b Second.
	 *
	 * @return boolean
	 */
	public static function equal( mixed $a, mixed $b ): bool {
		if ( is_array( $a ) && is_array( $b ) ) {
			if ( count( $a ) !== count( $b ) ) {
				return false;
			}
			if ( self::is_list( $a ) && self::is_list( $b ) ) {
				foreach ( $a as $i => $v ) {
					if ( ! self::equal( $v, $b[ $i ] ) ) {
						return false;
					}
				}
				return true;
			}
			foreach ( $a as $k => $v ) {
				if ( ! array_key_exists( $k, $b ) || ! self::equal( $v, $b[ $k ] ) ) {
					return false;
				}
			}
			return true;
		}
		if ( is_array( $a ) || is_array( $b ) ) {
			return false;
		}
		if ( ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) ) ) {
			return $a == $b; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- numeric value equality.
		}
		return $a === $b;
	}

	/**
	 * Nesting depth of a decoded value (scalars are 0, a flat array is 1).
	 *
	 * @param mixed $v Value.
	 *
	 * @return integer
	 */
	private static function depth( mixed $v ): int {
		if ( ! is_array( $v ) ) {
			return 0;
		}
		$max = 0;
		foreach ( $v as $child ) {
			$max = max( $max, self::depth( $child ) );
		}
		return $max + 1;
	}
}
