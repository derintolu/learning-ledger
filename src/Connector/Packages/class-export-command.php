<?php
/**
 * WP-CLI package export.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

use LearningLedger\Util\Invalid_Input;

/**
 * Writes a program's course package to a file.
 */
final class Export_Command {

	/**
	 * Exports a program as a course package ZIP.
	 *
	 * The full flavour carries the rubrics and other secrets, so the file is created readable by its owner only.
	 *
	 * ## OPTIONS
	 *
	 * <program>
	 * : Program post id or stable key.
	 *
	 * --file=<path>
	 * : Where to write the ZIP.
	 *
	 * [--flavour=<flavour>]
	 * : full (default) or public.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void {
		$file    = (string) ( $assoc_args['file'] ?? '' );
		$flavour = (string) ( $assoc_args['flavour'] ?? 'full' );
		if ( '' === $file ) {
			\WP_CLI::error( 'Pass --file=<path>.' );
		}
		$id = Export_Controller::program_id( (string) ( $args[0] ?? '' ) );
		if ( null === $id ) {
			\WP_CLI::error( 'No such program.' );
		}
		try {
			$built = ( new Package_Builder() )->build( $id, $flavour );
		} catch ( Invalid_Input $e ) {
			\WP_CLI::error( $e->error_code() . ': ' . $e->getMessage() );
		}

		// Create the file private before any bytes go in.
		if ( false === touch( $file ) || ! chmod( $file, 0600 ) || false === file_put_contents( $file, $built->zip ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			\WP_CLI::error( 'Could not write the file.' );
		}
		\WP_CLI::success( sprintf( 'Wrote the %s package, version %d, ETag %s.', $built->flavour, $built->version, $built->etag ) );
	}
}
