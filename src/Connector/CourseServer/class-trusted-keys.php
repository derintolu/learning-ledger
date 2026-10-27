<?php
/**
 * Course-server public keys trusted by the connector.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\CourseServer;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin-owned table, read once per signed request.

use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;

/**
 * Rows of `lledger_trusted_keys`: an Ed25519 public key per key id, with its capabilities and limits.
 * Only public keys are stored.
 */
final class Trusted_Keys {

	const CREATE_LEARNERS   = 'create_learners';
	const ENROL             = 'enrol';
	const READ_PROGRESS     = 'read_progress';
	const DELETE_LEARNERS   = 'delete_learners';
	const READ_PACKAGES     = 'read_packages';
	const IMPORT_PACKAGES   = 'import_packages';
	const READ_CERTIFICATES = 'read_certificates';

	/**
	 * Every capability a key can hold.
	 */
	const ALL_CAPABILITIES = array(
		self::CREATE_LEARNERS,
		self::ENROL,
		self::READ_PROGRESS,
		self::DELETE_LEARNERS,
		self::READ_PACKAGES,
		self::IMPORT_PACKAGES,
		self::READ_CERTIFICATES,
	);

	/**
	 * Capabilities granted when none are listed: all but `create_learners`, which is off unless listed.
	 */
	const DEFAULT_CAPABILITIES = array(
		self::ENROL,
		self::READ_PROGRESS,
		self::DELETE_LEARNERS,
		self::READ_PACKAGES,
		self::IMPORT_PACKAGES,
		self::READ_CERTIFICATES,
	);

	/**
	 * Learner-creation limits per key.
	 */
	const DEFAULT_LIMITS = array(
		'per_minute' => 30,
		'per_day'    => 2000,
	);

	/**
	 * Key id pattern.
	 */
	const ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

	/**
	 * Trust a key, or replace an existing key id (which also lifts a revocation).
	 *
	 * @param string   $key_id       Key id.
	 * @param string   $public_b64   Base64 of the raw 32-byte Ed25519 public key.
	 * @param string[] $capabilities Capabilities from ALL_CAPABILITIES.
	 * @param array    $limits       Limits `per_minute` and `per_day`, positive integers; missing ones take the defaults.
	 *
	 * @return void
	 *
	 * @throws Invalid_Input When an argument is invalid.
	 */
	public function add( string $key_id, string $public_b64, array $capabilities, array $limits ): void {
		global $wpdb;
		if ( 1 !== preg_match( self::ID_PATTERN, $key_id ) ) {
			throw new Invalid_Input( 'The key id must be 1 to 64 letters, digits, dots, dashes or underscores.', 'invalid', 'id' );
		}
		$public = base64_decode( trim( $public_b64 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- key transport encoding.
		if ( false === $public || 32 !== strlen( $public ) ) {
			throw new Invalid_Input( 'The public key must be the base64 of a 32-byte Ed25519 public key.', 'invalid', 'public-key' );
		}
		$caps = array();
		foreach ( $capabilities as $cap ) {
			if ( ! is_string( $cap ) || ! in_array( $cap, self::ALL_CAPABILITIES, true ) ) {
				throw new Invalid_Input( 'Unknown capability. Use any of: ' . esc_html( implode( ', ', self::ALL_CAPABILITIES ) ) . '.', 'invalid', 'capabilities' );
			}
			$caps[ $cap ] = true;
		}
		$caps = array_values( array_intersect( self::ALL_CAPABILITIES, array_keys( $caps ) ) );

		$clean = array();
		foreach ( self::DEFAULT_LIMITS as $name => $default ) {
			$value = $limits[ $name ] ?? $default;
			if ( ! is_int( $value ) || $value < 1 ) {
				throw new Invalid_Input( 'Limits must be positive integers.', 'invalid', esc_html( $name ) );
			}
			$clean[ $name ] = $value;
		}

		$wpdb->query(
			$wpdb->prepare(
				'REPLACE INTO %i (key_id, public_key, capabilities, limits, created_at, revoked_at) VALUES (%s, UNHEX(%s), %s, %s, %s, NULL)',
				Tables::name( 'trusted_keys' ),
				$key_id,
				bin2hex( $public ),
				wp_json_encode( $caps ),
				wp_json_encode( $clean ),
				Timestamp::to_db( Clock::now() )
			)
		);
	}

	/**
	 * A trusted key that has not been revoked.
	 *
	 * @param string $key_id Key id.
	 *
	 * @return array{key_id:string,public:string,capabilities:string[],limits:array{per_minute:int,per_day:int}}|null
	 */
	public function get( string $key_id ): ?array {
		global $wpdb;
		if ( 1 !== preg_match( self::ID_PATTERN, $key_id ) ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT key_id, public_key, capabilities, limits FROM %i WHERE key_id = %s AND revoked_at IS NULL', Tables::name( 'trusted_keys' ), $key_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$caps   = json_decode( (string) $row['capabilities'], true );
		$limits = json_decode( (string) $row['limits'], true );
		$out    = array();
		foreach ( self::DEFAULT_LIMITS as $name => $default ) {
			$out[ $name ] = is_array( $limits ) && is_int( $limits[ $name ] ?? null ) ? $limits[ $name ] : $default;
		}
		return array(
			'key_id'       => (string) $row['key_id'],
			'public'       => (string) $row['public_key'],
			'capabilities' => is_array( $caps ) ? array_values( array_intersect( self::ALL_CAPABILITIES, $caps ) ) : array(),
			'limits'       => $out,
		);
	}

	/**
	 * Revoke a key. Its requests fail from now on.
	 *
	 * @param string $key_id Key id.
	 *
	 * @return void
	 */
	public function revoke( string $key_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked_at = %s WHERE key_id = %s AND revoked_at IS NULL',
				Tables::name( 'trusted_keys' ),
				Timestamp::to_db( Clock::now() ),
				$key_id
			)
		);
	}
}
