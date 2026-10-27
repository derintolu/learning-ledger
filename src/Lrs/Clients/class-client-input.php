<?php
/**
 * Validated client input.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Clients;

use LearningLedger\Lrs\Auth\Bindings;
use LearningLedger\Lrs\Auth\Client;
use LearningLedger\Lrs\Auth\Client_Spec;
use LearningLedger\Lrs\Auth\Rate_Limiter;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed raw; HTTP layers JSON-encode them and WP-CLI prints text.

/**
 * Turns the array shape shared by WP-CLI and the admin REST routes into a Client_Spec or a change set.
 * Only a SHA-256 of the secret ever enters here.
 */
final class Client_Input {

	const ID_PATTERN = '/^[a-z0-9][a-z0-9._-]{1,63}$/';

	const ORIGIN_PATTERN = '#^https?://(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[0-9a-f:]+\])(?::[0-9]{1,5})?$#';

	const BINDING_KEYS = array( 'actor_home_page', 'actor_ifi', 'registration', 'activity_prefixes', 'write_activity_prefixes' );

	const KEYS = array(
		'name',
		'secret_sha256',
		'scopes',
		'bindings',
		'cors_origins',
		'trusted',
		'rate_class',
		'guard_enabled',
		'reject_non_account',
		'expires_at',
	);

	/**
	 * Build a spec to create.
	 *
	 * @param array $in Keys `id`, `secret_sha256` (64 hex), `scopes` and optionally `name`, `bindings`,
	 *                  `cors_origins`, `trusted`, `rate_class`, `guard_enabled`, `reject_non_account`, `expires_at`.
	 *
	 * @return Client_Spec
	 * @throws Invalid_Input On any invalid or missing value.
	 */
	public static function from_array( array $in ): Client_Spec {
		$id = $in['id'] ?? null;
		if ( ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new Invalid_Input( 'The id must be 2 to 64 characters: lowercase letters, digits, dot, underscore, hyphen, starting with a letter or digit.', 'invalid_id', 'id' );
		}
		if ( str_starts_with( $id, 'tok_' ) ) {
			throw new Invalid_Input( 'This client id is reserved.', 'reserved_id', 'id' );
		}
		if ( ! isset( $in['secret_sha256'] ) || ! isset( $in['scopes'] ) ) {
			throw new Invalid_Input( 'secret_sha256 and scopes are required.', 'missing', isset( $in['secret_sha256'] ) ? 'scopes' : 'secret_sha256' );
		}
		$changes = self::changes( array_diff_key( $in, array( 'id' => true ) ) );
		if ( array() === $changes['scopes'] ) {
			throw new Invalid_Input( 'At least one scope is required.', 'missing', 'scopes' );
		}

		return new Client_Spec(
			$id,
			$changes['name'] ?? $id,
			$changes['secret_sha256_raw'],
			$changes['scopes'],
			$changes['bindings'] ?? array(),
			$changes['cors_origins'] ?? array(),
			$changes['trusted'] ?? false,
			$changes['rate_class'] ?? 'default',
			$changes['guard_enabled'] ?? true,
			$changes['reject_non_account'] ?? false,
			$changes['expires_at'] ?? null
		);
	}

	/**
	 * Build the change set for Client_Repository::update from the keys that are present.
	 *
	 * @param array       $in      Any of KEYS. `bindings` keys that are present replace the current ones; an empty value clears one.
	 * @param Client|null $current Client being changed, so partial bindings merge into its own.
	 *
	 * @return array<string,mixed> Keys as Client_Repository::COLUMNS.
	 * @throws Invalid_Input On an unknown key or an invalid value.
	 */
	public static function changes( array $in, ?Client $current = null ): array {
		$out = array();
		foreach ( $in as $key => $value ) {
			switch ( $key ) {
				case 'name':
					$name = is_string( $value ) ? sanitize_text_field( $value ) : '';
					if ( '' === $name || strlen( $name ) > 191 ) {
						throw new Invalid_Input( 'The name must be 1 to 191 characters.', 'invalid', 'name' );
					}
					$out['name'] = $name;
					break;
				case 'secret_sha256':
					$out['secret_sha256_raw'] = self::secret_hash( $value );
					break;
				case 'scopes':
					$scopes = self::list_of( $value, 'scopes' );
					Scopes::validate( $scopes );
					$out['scopes'] = array_values( array_unique( $scopes ) );
					break;
				case 'bindings':
					$out['bindings'] = self::bindings( $value, $current );
					break;
				case 'cors_origins':
					$out['cors_origins'] = self::origins( self::list_of( $value, 'cors_origins' ) );
					break;
				case 'trusted':
				case 'guard_enabled':
				case 'reject_non_account':
					$out[ $key ] = self::bool( $value, $key );
					break;
				case 'rate_class':
					if ( ! is_string( $value ) || ! array_key_exists( $value, Rate_Limiter::classes() ) ) {
						throw new Invalid_Input( 'Unknown rate class.', 'invalid', 'rate_class' );
					}
					$out['rate_class'] = $value;
					break;
				case 'expires_at':
					$out['expires_at'] = self::expiry( $value );
					break;
				default:
					throw new Invalid_Input( 'Unknown client field.', 'unknown_property', (string) $key );
			}
		}
		return $out;
	}

	/**
	 * Raw SHA-256 from its hex form.
	 *
	 * @param mixed $hex Hex text.
	 *
	 * @return string 32 raw bytes.
	 * @throws Invalid_Input When it is not 64 hex characters.
	 */
	public static function secret_hash( mixed $hex ): string {
		if ( ! is_string( $hex ) || 1 !== preg_match( '/^[0-9a-fA-F]{64}$/', $hex ) ) {
			throw new Invalid_Input( 'secret_sha256 must be 64 hexadecimal characters.', 'invalid', 'secret_sha256' );
		}
		return (string) hex2bin( $hex );
	}

	/**
	 * A list from an array or a comma-separated string.
	 *
	 * @param mixed  $value Value.
	 * @param string $path  Field for errors.
	 *
	 * @return string[]
	 * @throws Invalid_Input When it is neither.
	 */
	private static function list_of( mixed $value, string $path ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			throw new Invalid_Input( 'Expected a list.', 'type', $path );
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				throw new Invalid_Input( 'Expected a list of strings.', 'type', $path );
			}
			$item = trim( $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Bindings merged over the current ones, validated by Bindings.
	 *
	 * @param mixed       $value   Bindings keys.
	 * @param Client|null $current Current client.
	 *
	 * @return array
	 * @throws Invalid_Input On unknown keys or invalid values.
	 */
	private static function bindings( mixed $value, ?Client $current ): array {
		if ( ! is_array( $value ) ) {
			throw new Invalid_Input( 'Expected bindings.', 'type', 'bindings' );
		}
		$merged = null === $current ? array() : $current->bindings->to_array();
		foreach ( $value as $key => $v ) {
			if ( ! in_array( $key, self::BINDING_KEYS, true ) ) {
				throw new Invalid_Input( 'Invalid binding.', 'invalid_binding', 'bindings.' . $key );
			}
			if ( in_array( $key, array( 'activity_prefixes', 'write_activity_prefixes' ), true ) ) {
				$v = self::list_of( $v, 'bindings.' . $key );
			}
			if ( null === $v || '' === $v || array() === $v ) {
				unset( $merged[ $key ] );
			} else {
				$merged[ $key ] = $v;
			}
		}
		return Bindings::from_array( $merged )->to_array();
	}

	/**
	 * Origins as `scheme://host[:port]` or the literal `null`.
	 *
	 * @param string[] $origins Origins.
	 *
	 * @return string[]
	 * @throws Invalid_Input On an invalid origin.
	 */
	private static function origins( array $origins ): array {
		$out = array();
		foreach ( $origins as $origin ) {
			$lower = strtolower( $origin );
			if ( 'null' !== $lower && 1 !== preg_match( self::ORIGIN_PATTERN, $lower ) ) {
				throw new Invalid_Input( 'An origin must be scheme://host[:port] or the literal null.', 'invalid', 'cors_origins' );
			}
			$out[] = $lower;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A boolean from a bool or its text form.
	 *
	 * @param mixed  $value Value.
	 * @param string $path  Field for errors.
	 *
	 * @return boolean
	 * @throws Invalid_Input When it is not a boolean.
	 */
	private static function bool( mixed $value, string $path ): bool {
		$parsed = is_bool( $value ) ? $value : filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( null === $parsed ) {
			throw new Invalid_Input( 'Expected true or false.', 'type', $path );
		}
		return $parsed;
	}

	/**
	 * An RFC 3339 time, or null for no expiry.
	 *
	 * @param mixed $value Value.
	 *
	 * @return \DateTimeImmutable|null
	 * @throws Invalid_Input On an invalid time.
	 */
	private static function expiry( mixed $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			throw new Invalid_Input( 'Expected an RFC 3339 time.', 'type', 'expires_at' );
		}
		try {
			return Timestamp::parse( $value, '1.0.3' );
		} catch ( Invalid_Input $e ) {
			throw new Invalid_Input( 'Expected an RFC 3339 time.', 'invalid', 'expires_at' );
		}
	}
}
