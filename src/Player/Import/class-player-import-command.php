<?php
/**
 * WP-CLI cmi5 package import.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Import;

use LearningLedger\Connector\Packages\Package_Fetcher;
use LearningLedger\Package\Limits;
use LearningLedger\Util\Invalid_Input;

/**
 * Imports a cmi5 package for the player from a URL.
 */
final class Player_Import_Command {

	/**
	 * Imports a cmi5 package (ZIP or cmi5.xml) into Sensei for the player.
	 *
	 * Prints the import report as JSON; the exit status is 0 on success and 1 otherwise.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : https URL of the package ZIP or cmi5.xml.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function import( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$url  = esc_url_raw( (string) ( $args[0] ?? '' ), array( 'http', 'https' ) );
		$path = '';
		try {
			$path   = Package_Fetcher::fetch( $url, new Limits() );
			$report = ( new Player_Importer() )->import( $path, $url );
			$data   = $report->to_array();
			$ok     = $report->ok();
		} catch ( Invalid_Input $e ) {
			$data = array(
				'errors' => array(
					array(
						'code'    => $e->error_code(),
						'path'    => $e->path(),
						'message' => $e->getMessage(),
					),
				),
			);
			$ok   = false;
		} finally {
			if ( '' !== $path ) {
				wp_delete_file( $path );
			}
		}
		\WP_CLI::line( (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) );
		\WP_CLI::halt( $ok ? 0 : 1 );
	}
}
