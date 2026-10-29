<?php
/**
 * The secret fields of a lesson JSON document.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Package;

/**
 * Strips or detects fields that must not reach a distribution package.
 */
final class Secrets {

	/**
	 * Lesson JSON pointers of secret fields (design 6.2).
	 */
	const FIELDS = array(
		'/bug/explanation',
		'/bug/fixChecks',
		'/referenceSolution',
	);

	/**
	 * Removes every secret field.
	 *
	 * @param array $lesson Decoded lesson JSON.
	 *
	 * @return array
	 */
	public static function strip( array $lesson ): array {
		foreach ( self::FIELDS as $pointer ) {
			$keys = explode( '/', substr( $pointer, 1 ) );
			$last = array_pop( $keys );
			$ref  = &$lesson;
			foreach ( $keys as $key ) {
				if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
					unset( $ref );
					continue 2;
				}
				$ref = &$ref[ $key ];
			}
			unset( $ref[ $last ] );
			unset( $ref );
		}
		return $lesson;
	}

	/**
	 * The secret pointers present in a lesson.
	 *
	 * @param array $lesson Decoded lesson JSON.
	 *
	 * @return string[]
	 */
	public static function present( array $lesson ): array {
		$found = array();
		foreach ( self::FIELDS as $pointer ) {
			$node = $lesson;
			foreach ( explode( '/', substr( $pointer, 1 ) ) as $key ) {
				if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
					continue 2;
				}
				$node = $node[ $key ];
			}
			$found[] = $pointer;
		}
		return $found;
	}

	/**
	 * Whether any secret field is present.
	 *
	 * @param array $lesson Decoded lesson JSON.
	 *
	 * @return boolean
	 */
	public static function has_secrets( array $lesson ): bool {
		return array() !== self::present( $lesson );
	}
}
