<?php
/**
 * Primitive value checks for xAPI.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Xapi;

use LearningLedger\Util\Invalid_Input;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never echoed; HTTP layers JSON-encode them.

/**
 * Assertions for the primitive value types used in xAPI objects.
 */
final class Primitives {

	/**
	 * BCP 47 (RFC 5646) language tag. Alternatives in order: langtag (language
	 * with extlang, script, region, variants, extensions, private use), a
	 * private-use-only tag, then the grandfathered tags.
	 */
	private const LANG_TAG = '/^(?:'
		. '(?:[a-z]{2,3}(?:-[a-z]{3}){0,3}|[a-z]{4,8})'
		. '(?:-[a-z]{4})?'
		. '(?:-(?:[a-z]{2}|[0-9]{3}))?'
		. '(?:-(?:[a-z0-9]{5,8}|[0-9][a-z0-9]{3}))*'
		. '(?:-[0-9a-wy-z](?:-[a-z0-9]{2,8})+)*'
		. '(?:-x(?:-[a-z0-9]{1,8})+)?'
		. '|x(?:-[a-z0-9]{1,8})+'
		. '|en-gb-oed|i-ami|i-bnn|i-default|i-enochian|i-hak|i-klingon|i-lux|i-mingo|i-navajo|i-pwn|i-tao|i-tay|i-tsu'
		. '|sgn-be-fr|sgn-be-nl|sgn-ch-de|art-lojban|cel-gaulish|no-bok|no-nyn|zh-guoyu|zh-hakka|zh-min|zh-min-nan|zh-xiang'
		. ')$/iD';

	/**
	 * Whether a decoded JSON value is an object (an associative array, or empty).
	 *
	 * @param mixed $v Value.
	 *
	 * @return boolean
	 */
	public static function is_object( mixed $v ): bool {
		return is_array( $v ) && ( array() === $v || ! array_is_list( $v ) );
	}

	/**
	 * Absolute IRI with a scheme.
	 *
	 * @param mixed  $v    Value.
	 * @param string $path Path for errors.
	 *
	 * @throws Invalid_Input Code `iri`.
	 *
	 * @return void
	 */
	public static function assert_iri( mixed $v, string $path ): void {
		if ( ! self::is_iri( $v ) ) {
			throw new Invalid_Input( 'Expected an absolute IRI.', 'iri', $path );
		}
	}

	/**
	 * UUID in 8-4-4-4-12 hex form.
	 *
	 * @param mixed  $v    Value.
	 * @param string $path Path for errors.
	 *
	 * @throws Invalid_Input Code `uuid`.
	 *
	 * @return void
	 */
	public static function assert_uuid( mixed $v, string $path ): void {
		if ( ! is_string( $v ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $v ) ) {
			throw new Invalid_Input( 'Expected a UUID.', 'uuid', $path );
		}
	}

	/**
	 * Language map: object whose keys are BCP 47 tags and values strings.
	 *
	 * @param mixed  $v    Value.
	 * @param string $path Path for errors.
	 *
	 * @throws Invalid_Input Code `lang_map`.
	 *
	 * @return void
	 */
	public static function assert_lang_map( mixed $v, string $path ): void {
		if ( ! self::is_object( $v ) ) {
			throw new Invalid_Input( 'Expected a language map.', 'lang_map', $path );
		}
		foreach ( $v as $tag => $text ) {
			if ( 1 !== preg_match( self::LANG_TAG, (string) $tag ) || ! is_string( $text ) ) {
				throw new Invalid_Input( 'Language map keys must be BCP 47 tags and values strings.', 'lang_map', $path );
			}
		}
	}

	/**
	 * Mailbox IRI: `mailto:` followed by an address.
	 *
	 * @param mixed  $v    Value.
	 * @param string $path Path for errors.
	 *
	 * @throws Invalid_Input Code `mbox`.
	 *
	 * @return void
	 */
	public static function assert_mbox( mixed $v, string $path ): void {
		if ( ! is_string( $v ) || 1 !== preg_match( '/^mailto:[^@\s]+@[^@\s]+$/uD', $v ) ) {
			throw new Invalid_Input( 'Expected mailto: followed by an email address.', 'mbox', $path );
		}
	}

	/**
	 * Hex-encoded SHA-1 (40 hex digits).
	 *
	 * @param mixed  $v    Value.
	 * @param string $path Path for errors.
	 *
	 * @throws Invalid_Input Code `sha1`.
	 *
	 * @return void
	 */
	public static function assert_sha1_hex( mixed $v, string $path ): void {
		if ( ! is_string( $v ) || 1 !== preg_match( '/^[0-9a-f]{40}$/iD', $v ) ) {
			throw new Invalid_Input( 'Expected a hex SHA-1.', 'sha1', $path );
		}
	}

	/**
	 * JSON boolean.
	 *
	 * @param mixed  $v    Value.
	 * @param string $path Path for errors.
	 *
	 * @throws Invalid_Input Code `bool`.
	 *
	 * @return void
	 */
	public static function assert_bool( mixed $v, string $path ): void {
		if ( ! is_bool( $v ) ) {
			throw new Invalid_Input( 'Expected a boolean.', 'bool', $path );
		}
	}

	/**
	 * JSON number (finite).
	 *
	 * @param mixed  $v    Value.
	 * @param string $path Path for errors.
	 *
	 * @throws Invalid_Input Code `number`.
	 *
	 * @return void
	 */
	public static function assert_number( mixed $v, string $path ): void {
		if ( ! is_int( $v ) && ! ( is_float( $v ) && is_finite( $v ) ) ) {
			throw new Invalid_Input( 'Expected a number.', 'number', $path );
		}
	}

	/**
	 * Extensions: object whose keys are IRIs.
	 *
	 * @param mixed  $v    Value.
	 * @param string $path Path for errors.
	 *
	 * @throws Invalid_Input Code `extensions`.
	 *
	 * @return void
	 */
	public static function assert_extensions( mixed $v, string $path ): void {
		if ( ! self::is_object( $v ) ) {
			throw new Invalid_Input( 'Expected an extensions object.', 'extensions', $path );
		}
		foreach ( array_keys( $v ) as $key ) {
			if ( ! self::is_iri( (string) $key ) ) {
				throw new Invalid_Input( 'Extension keys must be IRIs.', 'extensions', $path );
			}
		}
	}

	/**
	 * Absolute IRI test: a scheme, a colon, then non-space characters.
	 *
	 * @param mixed $v Value.
	 *
	 * @return boolean
	 */
	private static function is_iri( mixed $v ): bool {
		return is_string( $v ) && 1 === preg_match( '/^[a-z][a-z0-9+.\-]*:[^\s\x00-\x1f\x7f<>"{}|\\\\^`]+$/iuD', $v );
	}
}
