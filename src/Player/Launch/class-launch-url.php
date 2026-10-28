<?php
/**
 * Launch URLs for cmi5 AUs.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Launch;

/**
 * Adds the cmi5 launch parameters to an AU URL [cmi5 8.1] and works out the AU's origin.
 */
final class Launch_Url {

	/**
	 * The AU URL with the launch parameters appended to its own query string; a fragment stays last.
	 *
	 * @param string                $au_url AU URL (absolute).
	 * @param array<string, string> $params The endpoint, fetch, actor, registration and activityId.
	 *
	 * @return string
	 */
	public static function build( string $au_url, array $params ): string {
		$fragment = '';
		$hash     = strpos( $au_url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $au_url, $hash );
			$au_url   = substr( $au_url, 0, $hash );
		}
		$added = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		if ( '' === $added ) {
			return $au_url . $fragment;
		}
		$glue = str_contains( $au_url, '?' ) ? ( str_ends_with( $au_url, '?' ) || str_ends_with( $au_url, '&' ) ? '' : '&' ) : '?';
		return $au_url . $glue . $added . $fragment;
	}

	/**
	 * The origin the AU runs in: `null` for the CSP-sandboxed fallback under the site's
	 * `/lledger-au/`, else the scheme, host and port of its URL.
	 *
	 * @param string $au_url AU URL (absolute).
	 *
	 * @return string `null`, an origin, or '' when the URL has none.
	 */
	public static function origin( string $au_url ): string {
		if ( str_starts_with( $au_url, home_url( '/lledger-au/' ) ) ) {
			return 'null';
		}
		$parts = wp_parse_url( $au_url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme  = strtolower( $parts['scheme'] );
		$origin  = $scheme . '://' . strtolower( $parts['host'] );
		$default = 'https' === $scheme ? 443 : 80;
		return isset( $parts['port'] ) && (int) $parts['port'] !== $default ? $origin . ':' . $parts['port'] : $origin;
	}
}
