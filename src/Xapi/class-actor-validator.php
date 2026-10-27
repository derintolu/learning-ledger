<?php
/**
 * Agent and Group validation for xAPI.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Xapi;

use LearningLedger\Util\Invalid_Input;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never echoed; HTTP layers JSON-encode them.

/**
 * Validates an Agent or Group object strictly.
 */
final class Actor_Validator {

	/**
	 * Properties allowed on an Agent.
	 *
	 * @var string[]
	 */
	private const AGENT_KEYS = array( 'objectType', 'name', 'mbox', 'mbox_sha1sum', 'openid', 'account' );

	/**
	 * Validate an actor.
	 *
	 * @param mixed  $actor   Decoded actor.
	 * @param string $version Negotiated xAPI version.
	 * @param string $path    Path for errors.
	 *
	 * @throws Invalid_Input On any violation, with the path of the offending value.
	 *
	 * @return void
	 */
	public static function validate( mixed $actor, string $version, string $path = 'actor' ): void {
		if ( ! Primitives::is_object( $actor ) ) {
			throw new Invalid_Input( 'Actor must be an object.', 'type', $path );
		}
		$type = $actor['objectType'] ?? 'Agent';
		if ( 'Agent' !== $type && 'Group' !== $type ) {
			throw new Invalid_Input( 'objectType must be Agent or Group.', 'object_type', $path . '.objectType' );
		}
		if ( 'Agent' === $type ) {
			self::validate_agent( $actor, $path );
			return;
		}

		self::check_keys( $actor, array_merge( self::AGENT_KEYS, array( 'member' ) ), $path );
		self::check_name( $actor, $path );
		$ifi_count = count( array_intersect( Agent_Identity::IFI_KEYS, array_keys( $actor ) ) );
		if ( $ifi_count > 0 ) {
			self::identity( $actor, $path );
		}
		if ( ! array_key_exists( 'member', $actor ) ) {
			if ( 0 === $ifi_count ) {
				throw new Invalid_Input( 'An anonymous Group must list its members.', 'member', $path . '.member' );
			}
			return;
		}
		$members = $actor['member'];
		if ( ! is_array( $members ) || ! array_is_list( $members ) ) {
			throw new Invalid_Input( 'member must be an array.', 'type', $path . '.member' );
		}
		if ( 0 === $ifi_count && array() === $members ) {
			throw new Invalid_Input( 'An anonymous Group must list its members.', 'member', $path . '.member' );
		}
		foreach ( $members as $i => $member ) {
			$member_path = $path . '.member[' . $i . ']';
			if ( ! Primitives::is_object( $member ) ) {
				throw new Invalid_Input( 'Group members must be Agents.', 'type', $member_path );
			}
			if ( array_key_exists( 'objectType', $member ) && 'Agent' !== $member['objectType'] ) {
				throw new Invalid_Input( 'Group members must be Agents.', 'object_type', $member_path . '.objectType' );
			}
			self::validate_agent( $member, $member_path );
		}
	}

	/**
	 * Validate an Agent object.
	 *
	 * @param array  $agent Agent.
	 * @param string $path  Path for errors.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function validate_agent( array $agent, string $path ): void {
		self::check_keys( $agent, self::AGENT_KEYS, $path );
		self::check_name( $agent, $path );
		self::identity( $agent, $path );
	}

	/**
	 * Reject properties outside the allowed list.
	 *
	 * @param array    $obj     Object.
	 * @param string[] $allowed Allowed keys.
	 * @param string   $path    Path for errors.
	 *
	 * @throws Invalid_Input Code `unknown_property`.
	 *
	 * @return void
	 */
	private static function check_keys( array $obj, array $allowed, string $path ): void {
		foreach ( array_keys( $obj ) as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				throw new Invalid_Input( 'Unknown property.', 'unknown_property', $path . '.' . $key );
			}
		}
	}

	/**
	 * `name`, when present, is a string.
	 *
	 * @param array  $obj  Object.
	 * @param string $path Path for errors.
	 *
	 * @throws Invalid_Input Code `type`.
	 *
	 * @return void
	 */
	private static function check_name( array $obj, string $path ): void {
		if ( array_key_exists( 'name', $obj ) && ! is_string( $obj['name'] ) ) {
			throw new Invalid_Input( 'name must be a string.', 'type', $path . '.name' );
		}
	}

	/**
	 * Validate the IFI, rebasing error paths onto $path.
	 *
	 * @param array  $obj  Object.
	 * @param string $path Path for errors.
	 *
	 * @throws Invalid_Input From Agent_Identity.
	 *
	 * @return void
	 */
	private static function identity( array $obj, string $path ): void {
		try {
			Agent_Identity::from_array( $obj );
		} catch ( Invalid_Input $e ) {
			$sub = $e->path();
			throw new Invalid_Input( $e->getMessage(), $e->error_code(), '' === $sub ? $path : $path . '.' . $sub );
		}
	}
}
