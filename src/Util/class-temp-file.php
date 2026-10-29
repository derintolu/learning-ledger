<?php
/**
 * Temporary file names.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Util;

/**
 * Wraps wp_tempnam(), which lives in wp-admin/includes/file.php: WordPress loads that file
 * for WP-CLI and admin screens but not for REST or cron requests.
 */
final class Temp_File {

	/**
	 * Creates an empty temporary file.
	 *
	 * @param string $prefix File name prefix.
	 *
	 * @return string Path of the file; the caller deletes it. Empty when none could be made.
	 */
	public static function create( string $prefix ): string {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return (string) wp_tempnam( $prefix );
	}
}
