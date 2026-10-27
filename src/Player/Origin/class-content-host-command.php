<?php
/**
 * WP-CLI: AU content host.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Origin;

use LearningLedger\Player\Store\Content_Host;

/**
 * Set the separate hostname AU files are served from.
 */
final class Content_Host_Command {

	/**
	 * Set the AU content host. Without one, AU files use the sandboxed fallback.
	 *
	 * The host must differ from the site and home hostnames and must not be
	 * covered by COOKIE_DOMAIN, so WordPress cookies never reach AU content.
	 * Point it at this install; it answers only manifest paths.
	 *
	 * ## OPTIONS
	 *
	 * <host>
	 * : Hostname, optionally with a port, such as au.example.org.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learning-ledger cmi5 content-host au.example.org
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$host = strtolower( trim( (string) ( $args[0] ?? '' ) ) );
		if ( ! Content_Host::is_safe( $host ) ) {
			\WP_CLI::error( 'That host is not safe for AU content: it must be a valid hostname that differs from the site host and is not covered by COOKIE_DOMAIN.' );
		}
		update_option( Content_Host::OPTION, $host );
		if ( defined( 'LLEDGER_CMI5_CONTENT_HOST' ) ) {
			\WP_CLI::warning( 'LLEDGER_CMI5_CONTENT_HOST is defined and takes precedence over this setting.' );
		}
		\WP_CLI::success( 'AU content host set to ' . $host . '.' );
	}
}
