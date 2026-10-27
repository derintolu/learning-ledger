<?php
/**
 * The separate hostname AU files are served from.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Store;

/**
 * Chooses between a separate content origin and the sandboxed same-host fallback.
 */
final class Content_Host {

	/**
	 * Option holding the content host when the constant is not set.
	 */
	public const OPTION = 'lledger_cmi5_content_host';

	/**
	 * A hostname of letters, digits, dots and hyphens, with an optional port.
	 */
	private const HOST_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?::[0-9]{1,5})?$/';

	/**
	 * Configured content host (`LLEDGER_CMI5_CONTENT_HOST`, else the option), lowercased.
	 *
	 * @return string|null Null when none is configured.
	 */
	public static function get(): ?string {
		$value = defined( 'LLEDGER_CMI5_CONTENT_HOST' ) ? constant( 'LLEDGER_CMI5_CONTENT_HOST' ) : get_option( self::OPTION, '' );
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return '' === $value ? null : $value;
	}

	/**
	 * Whether a host can serve AU files without seeing WordPress cookies.
	 *
	 * Cookies ignore the port, so only the hostname is compared. The host must
	 * differ from the site and home hostnames and must not be covered by the
	 * cookie domain.
	 *
	 * @param string      $host          Host, optionally with a port.
	 * @param string|null $cookie_domain Cookie domain to check against; null reads `COOKIE_DOMAIN`.
	 *
	 * @return boolean
	 */
	public static function is_safe( string $host, ?string $cookie_domain = null ): bool {
		$host = strtolower( trim( $host ) );
		if ( 1 !== preg_match( self::HOST_PATTERN, $host ) ) {
			return false;
		}
		$name = (string) preg_replace( '/:[0-9]+$/', '', $host );

		foreach ( array( home_url(), site_url() ) as $url ) {
			$site = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $site ) && strtolower( $site ) === $name ) {
				return false;
			}
		}

		if ( null === $cookie_domain ) {
			$cookie_domain = defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '';
		}
		$domain = ltrim( strtolower( trim( $cookie_domain ) ), '.' );
		if ( '' !== $domain && ( $name === $domain || str_ends_with( $name, '.' . $domain ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Base URL under which a package's files are served, with a trailing slash.
	 *
	 * @param integer $package_id Package id.
	 *
	 * @return string
	 */
	public static function base_url( int $package_id ): string {
		if ( 'host' === self::mode() ) {
			return 'https://' . self::get() . '/' . $package_id . '/';
		}
		return home_url( '/lledger-au/' . $package_id . '/' );
	}

	/**
	 * Either `host` (a safe content host is configured) or `sandbox`.
	 *
	 * @return string
	 */
	public static function mode(): string {
		$host = self::get();
		return null !== $host && self::is_safe( $host ) ? 'host' : 'sandbox';
	}
}
