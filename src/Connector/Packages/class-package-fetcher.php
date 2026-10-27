<?php
/**
 * Downloads a package ZIP to a temp file.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

use LearningLedger\Package\Limits;
use LearningLedger\Util\Invalid_Input;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never echoed; HTTP layers JSON-encode them.

/**
 * Streams a remote ZIP to disk with a size cap.
 */
final class Package_Fetcher {

	/**
	 * Fetches a package.
	 *
	 * @param string $url URL, https only unless LLEDGER_TESTING.
	 * @param Limits $l   Limits (the size cap is max_bytes).
	 *
	 * @throws Invalid_Input Codes fetch_url, fetch_failed, fetch_status, fetch_too_large.
	 *
	 * @return string Path of a temp file; the caller deletes it.
	 */
	public static function fetch( string $url, Limits $l ): string {
		$scheme  = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$allowed = defined( 'LLEDGER_TESTING' ) && LLEDGER_TESTING ? array( 'https', 'http' ) : array( 'https' );
		if ( ! in_array( $scheme, $allowed, true ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			throw new Invalid_Input( 'The package URL must be an https URL.', 'fetch_url' );
		}

		$path = wp_tempnam( 'llpkg' );
		if ( ! $path ) {
			throw new Invalid_Input( 'Could not create a temporary file.', 'fetch_failed' );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 60,
				'redirection'         => 3,
				'stream'              => true,
				'filename'            => $path,
				'limit_response_size' => $l->max_bytes + 1,
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $path );
			throw new Invalid_Input( 'The package could not be downloaded.', 'fetch_failed' );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $path );
			throw new Invalid_Input( 'The package server did not return the file.', 'fetch_status' );
		}
		clearstatcache( true, $path );
		if ( (int) filesize( $path ) > $l->max_bytes ) {
			wp_delete_file( $path );
			throw new Invalid_Input( 'The package is larger than the allowed size.', 'fetch_too_large' );
		}
		return $path;
	}
}
