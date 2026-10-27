<?php
/**
 * Resolution of an xAPI `more` link.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Inbox;

/**
 * Turns a `more` IRL into a resource and query on the paired LRS, or refuses it.
 */
final class More_Link {

	/**
	 * Resource and query for a `more` IRL, or null when it is empty or leaves the LRS.
	 *
	 * @param string $more     The `more` value.
	 * @param array  $settings Connector settings.
	 *
	 * @return array{0:string,1:array}|null
	 */
	public static function resolve( string $more, array $settings ): ?array {
		if ( '' === $more ) {
			return null;
		}
		$base  = '' !== $settings['loopback_url'] ? $settings['loopback_url'] : $settings['lrs_url'];
		$parts = wp_parse_url( $more );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$path = (string) ( $parts['path'] ?? '' );

		if ( isset( $parts['host'] ) || isset( $parts['scheme'] ) ) {
			// An absolute IRL must point at the LRS this connector is paired with.
			$allowed = false;
			foreach ( array( $settings['lrs_url'], $settings['loopback_url'] ) as $candidate ) {
				if ( '' !== $candidate && self::origin( $candidate ) === self::origin( $more ) ) {
					$allowed = true;
				}
			}
			if ( ! $allowed ) {
				return null;
			}
		}

		if ( '' !== $path && '/' === $path[0] ) {
			// Relative to the server root: strip the xAPI base path (public or loopback).
			$resource = null;
			foreach ( array( $base, $settings['lrs_url'] ) as $candidate ) {
				$prefix = trailingslashit( (string) wp_parse_url( $candidate, PHP_URL_PATH ) );
				if ( 0 === strpos( $path, $prefix ) ) {
					$resource = substr( $path, strlen( $prefix ) );
					break;
				}
			}
			if ( null === $resource ) {
				return null;
			}
		} else {
			$resource = $path;
		}

		if ( '' === $resource || false !== strpos( $resource, '..' ) ) {
			return null;
		}
		$query = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		return array( $resource, $query );
	}

	/**
	 * Scheme, host and port of a URL.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 */
	private static function origin( string $url ): string {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || ! isset( $p['host'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $p['scheme'] ?? '' ) );
		$port   = $p['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . strtolower( $p['host'] ) . ':' . $port;
	}
}
