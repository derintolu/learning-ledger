<?php
/**
 * Plugin roles.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Kernel;

use LearningLedger\Db\Db_Requirements;
use LearningLedger\Db\Installer;

/**
 * The LRS and connector roles, stored as a list of enabled role names.
 */
final class Roles {

	public const ROLES  = array( 'lrs', 'connector' );
	public const OPTION = 'lledger_roles';

	/**
	 * Enabled role names, in the order they were enabled.
	 *
	 * @return string[]
	 */
	public static function enabled(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values( array_intersect( array_map( 'strval', $stored ), self::ROLES ) );
	}

	/**
	 * Whether a role is enabled.
	 *
	 * @param string $role Role name.
	 *
	 * @return boolean
	 */
	public static function is_enabled( string $role ): bool {
		return in_array( $role, self::enabled(), true );
	}

	/**
	 * Enable a role. The LRS role checks the database server first; both install the schema
	 * and schedule a rewrite flush.
	 *
	 * @param string $role Role name.
	 *
	 * @return true|\WP_Error
	 */
	public static function enable( string $role ) {
		if ( ! in_array( $role, self::ROLES, true ) ) {
			return self::unknown( $role );
		}
		if ( 'lrs' === $role ) {
			$check = Db_Requirements::check();
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
		Installer::install();

		$enabled = self::enabled();
		if ( ! in_array( $role, $enabled, true ) ) {
			$enabled[] = $role;
			update_option( self::OPTION, $enabled );
		}
		Rewrites::schedule_flush();
		return true;
	}

	/**
	 * Disable a role (its routes and rewrites go away on the next flush). Unknown roles are ignored.
	 *
	 * @param string $role Role name.
	 *
	 * @return void
	 */
	public static function disable( string $role ): void {
		if ( ! self::is_enabled( $role ) ) {
			return;
		}
		update_option( self::OPTION, array_values( array_diff( self::enabled(), array( $role ) ) ) );
		Rewrites::schedule_flush();
	}

	/**
	 * Error for a role name outside ROLES.
	 *
	 * @param string $role Role name.
	 *
	 * @return \WP_Error
	 */
	private static function unknown( string $role ): \WP_Error {
		return new \WP_Error(
			'unknown_role',
			sprintf(
				/* translators: 1: requested role, 2: list of valid roles. */
				__( 'Unknown role "%1$s". Valid roles: %2$s.', 'learning-ledger' ),
				$role,
				implode( ', ', self::ROLES )
			)
		);
	}
}
