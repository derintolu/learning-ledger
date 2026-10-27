<?php
/**
 * `wp learning-ledger statements ...`.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Export;

use LearningLedger\Util\Invalid_Input;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages go to WP-CLI only, never into HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming a file the administrator names, not a WordPress upload.

/**
 * Exports statements to an NDJSON file and imports such a file.
 */
final class Statements_Command {

	const ACTOR = 'cli';

	/**
	 * Export statements as NDJSON, oldest first. The file is created private (mode 0600), must not exist,
	 * and may not be inside the web root, the content directory or the uploads directory.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : File to create.
	 *
	 * [--since=<time>]
	 * : Only statements stored at or after this RFC 3339 time.
	 *
	 * [--until=<time>]
	 * : Only statements stored at or before this RFC 3339 time.
	 *
	 * [--registration=<uuid>]
	 * : Only this registration.
	 *
	 * [--agent=<json>]
	 * : Only statements whose actor is this Agent, as JSON.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		try {
			$count = self::export_to_file( (string) ( $assoc_args['file'] ?? '' ), array_intersect_key( $assoc_args, array_flip( Export_Controller::FILTERS ) ) );
		} catch ( Invalid_Input | \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( 'Exported ' . $count . ' statements.' );
	}

	/**
	 * Import statements from an NDJSON file made by `statements export`. Keeps each statement's stored time
	 * and authority, checks content_sha256, skips statements already present with the same content and
	 * rejects the rest. Imported statements do not trigger badges, notifications or connector delivery.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : File to read.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function import( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		$importer = new Migration_Importer();
		try {
			$result = $importer->import( (string) ( $assoc_args['file'] ?? '' ), self::ACTOR );
		} catch ( Invalid_Input $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		foreach ( $importer->problems() as $problem ) {
			\WP_CLI::warning( 'Line ' . $problem['line'] . ': ' . $problem['reason'] );
		}
		\WP_CLI::success( sprintf( 'Imported %d, skipped %d, rejected %d.', $result['imported'], $result['skipped'], $result['rejected'] ) );
	}

	/**
	 * Write an export file and audit it.
	 *
	 * @param string $path    File to create.
	 * @param array  $filters As for Ndjson_Exporter::normalize_filters().
	 *
	 * @return integer Statements written.
	 * @throws Invalid_Input Codes `invalid_path`, `unsafe_path`, `file_exists`, `write_failed` or a filter error.
	 */
	public static function export_to_file( string $path, array $filters ): int {
		$described = Ndjson_Exporter::describe( $filters );
		self::assert_safe_target( $path );

		$old    = umask( 0177 );
		$handle = fopen( $path, 'xb' );
		umask( $old );
		if ( false === $handle ) {
			throw new Invalid_Input( 'The export file could not be created.', 'write_failed' );
		}
		chmod( $path, 0600 );

		Export_Module::audit_export_start( 'cli', $described, self::ACTOR );
		$count    = 0;
		$complete = false;
		try {
			( new Ndjson_Exporter() )->stream(
				$filters,
				static function ( string $line ) use ( $handle, &$count ): void {
					if ( strlen( $line ) !== fwrite( $handle, $line ) ) {
						throw new Invalid_Input( 'The export file could not be written.', 'write_failed' );
					}
					++$count;
				}
			);
			if ( ! fclose( $handle ) ) {
				$handle = null;
				throw new Invalid_Input( 'The export file could not be written.', 'write_failed' );
			}
			$handle   = null;
			$complete = true;
		} catch ( \Throwable $e ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			unlink( $path );
			throw $e;
		} finally {
			Export_Module::audit_export_end( 'cli', $count, $complete, self::ACTOR );
		}
		return $count;
	}

	/**
	 * Refuse a target that exists or whose directory is web-readable.
	 *
	 * @param string $path File path.
	 *
	 * @return void
	 * @throws Invalid_Input Codes `invalid_path`, `unsafe_path`, `file_exists`.
	 */
	private static function assert_safe_target( string $path ): void {
		if ( '' === $path || str_contains( $path, "\0" ) || str_ends_with( $path, '/' ) ) {
			throw new Invalid_Input( 'Give --file a file path.', 'invalid_path' );
		}
		$dir = realpath( dirname( $path ) );
		if ( false === $dir ) {
			throw new Invalid_Input( 'The directory of --file does not exist.', 'invalid_path' );
		}
		if ( file_exists( $path ) || is_link( $path ) ) {
			throw new Invalid_Input( 'The file already exists; it is not overwritten.', 'file_exists' );
		}
		$uploads = wp_upload_dir( null, false );
		// Compared case-insensitively: on a case-insensitive file system a differently cased path is the same directory.
		foreach ( array( ABSPATH, WP_CONTENT_DIR, (string) ( $uploads['basedir'] ?? '' ) ) as $root ) {
			$root = '' === $root ? false : realpath( $root );
			if ( false !== $root && str_starts_with( strtolower( $dir ) . '/', strtolower( rtrim( $root, '/' ) ) . '/' ) ) {
				throw new Invalid_Input( 'The file would be inside the web root; choose a directory outside WordPress.', 'unsafe_path' );
			}
		}
	}
}
