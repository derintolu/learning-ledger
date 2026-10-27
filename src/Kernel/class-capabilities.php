<?php
/**
 * Plugin capabilities.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Kernel;

/**
 * Capabilities granted to administrators.
 */
final class Capabilities {

	public const MANAGE           = 'lledger_manage';
	public const VIEW_RECORDS     = 'lledger_view_records';
	public const MANAGE_BADGES    = 'lledger_manage_badges';
	public const EXPORT           = 'lledger_export';
	public const MANAGE_CONNECTOR = 'lledger_manage_connector';

	/**
	 * Every capability.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::MANAGE, self::VIEW_RECORDS, self::MANAGE_BADGES, self::EXPORT, self::MANAGE_CONNECTOR );
	}

	/**
	 * Grant every capability to the administrator role only.
	 *
	 * @return void
	 */
	public static function install(): void {
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}
		foreach ( self::all() as $cap ) {
			$admin->add_cap( $cap );
		}
	}

	/**
	 * Remove every capability from every role.
	 *
	 * @return void
	 */
	public static function remove(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all() as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}
}
