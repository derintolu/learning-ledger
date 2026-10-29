<?php
/**
 * WP-CLI package import.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Util\Invalid_Input;

/**
 * Imports a course package from a URL.
 */
final class Package_Command {

	/**
	 * Imports a course package. Prints nothing; the exit status is 0 on success and 1 otherwise.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : https URL of the package ZIP.
	 *
	 * [--adapter=<id>]
	 * : LMS integration id. Default sensei.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function import( array $args, array $assoc_args ): void {
		$adapter = sanitize_key( (string) ( $assoc_args['adapter'] ?? 'sensei' ) );
		$path    = '';
		try {
			$limits = new Limits();
			$path   = Package_Fetcher::fetch( (string) ( $args[0] ?? '' ), $limits );
			// WP-CLI is a trusted import path: it runs as the site operator, often as user 0.
			$report = ( new Package_Importer() )->import( Package::from_zip( $path, $limits ), $adapter, true );
		} catch ( Invalid_Input $e ) {
			$report = null;
		} finally {
			if ( '' !== $path ) {
				wp_delete_file( $path );
			}
		}
		\WP_CLI::halt( null !== $report && $report->ok() ? 0 : 1 );
	}
}
