<?php
/**
 * Serves AU files from the content host, or from a CSP-sandboxed fallback path.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Origin;

use LearningLedger\Kernel\Module;
use LearningLedger\Player\Store\Au_Store;
use LearningLedger\Player\Store\Content_Host;

/**
 * AU content is untrusted HTML and script, so it never runs on the WordPress
 * cookie origin. On a safe content host, register() answers the request during
 * plugin boot, before any user is determined, and exits; WordPress never
 * renders there. On the site host, `/lledger-au/<id>/<path>` is answered on
 * `parse_request` with a CSP `sandbox` that omits `allow-same-origin`, so the
 * AU runs in an opaque origin.
 */
final class Content_Origin implements Module {

	/**
	 * Fallback rewrite rule (regex).
	 */
	public const RULE = '^lledger-au/(\d+)/(.+)$';

	/**
	 * Sandbox directives for the fallback; `allow-same-origin` is deliberately absent.
	 */
	public const SANDBOX = 'sandbox allow-scripts allow-forms allow-popups allow-modals';

	/**
	 * Register hooks; on the content host, serve the request and exit.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'learning-ledger cmi5 content-host', Content_Host_Command::class );
		} elseif ( isset( $_SERVER['HTTP_HOST'] ) && self::is_content_request( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) ) {
			// The raw path is validated by host_plan(): a numeric id, then Au_Store::normalise() and a manifest lookup.
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			( new File_Responder() )->emit( self::host_plan( $uri, self::range_header() ) );
			exit;
		}
		add_action( 'init', array( $this, 'add_rewrites' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve_fallback' ), 0 );
	}

	/**
	 * Add the fallback rewrite rule. The rule's query is a marker only; the path is
	 * re-read from the request so it is decoded exactly once.
	 *
	 * @return void
	 */
	public function add_rewrites(): void {
		add_rewrite_rule( self::RULE, 'index.php?lledger_au=1', 'top' );
	}

	/**
	 * Serve the sandboxed fallback when the fallback rule matched, then exit.
	 *
	 * @param \WP $wp Current request.
	 *
	 * @return void
	 */
	public function maybe_serve_fallback( \WP $wp ): void {
		if ( self::RULE !== $wp->matched_rule ) {
			return;
		}
		( new File_Responder() )->emit( self::sandbox_plan( (string) $wp->request, self::range_header() ) );
		exit;
	}

	/**
	 * Whether a request Host header names the configured content host, and that host is safe.
	 *
	 * @param string      $host          Request Host header.
	 * @param string|null $cookie_domain Cookie domain for the safety check; null reads `COOKIE_DOMAIN`.
	 *
	 * @return boolean
	 */
	public static function is_content_request( string $host, ?string $cookie_domain = null ): bool {
		$configured = Content_Host::get();
		return null !== $configured
			&& strtolower( trim( $host ) ) === $configured
			&& Content_Host::is_safe( $configured, $cookie_domain );
	}

	/**
	 * Response plan for a content-host request URI (`/<id>/<path>[?query]`).
	 *
	 * @param string      $request_uri Raw request URI.
	 * @param string|null $range       Raw `Range` header.
	 *
	 * @return array{status:int,headers:array<string,string>,file:string|null,offset:int,length:int}
	 */
	public static function host_plan( string $request_uri, ?string $range ): array {
		$path = explode( '?', $request_uri, 2 )[0];
		if ( 1 !== preg_match( '#^/(\d+)/(.+)$#', $path, $m ) ) {
			return File_Responder::not_found();
		}
		return self::plan( (int) $m[1], $m[2], $range, 'frame-ancestors ' . self::site_origin() );
	}

	/**
	 * Response plan for a fallback request path (`lledger-au/<id>/<path>`, as in `WP::$request`).
	 *
	 * @param string      $request_path Raw request path relative to the home path.
	 * @param string|null $range        Raw `Range` header.
	 *
	 * @return array{status:int,headers:array<string,string>,file:string|null,offset:int,length:int}
	 */
	public static function sandbox_plan( string $request_path, ?string $range ): array {
		if ( 1 !== preg_match( '#^lledger-au/(\d+)/(.+)$#', $request_path, $m ) ) {
			return File_Responder::not_found();
		}
		return self::plan( (int) $m[1], $m[2], $range, self::SANDBOX . '; frame-ancestors ' . self::site_origin() );
	}

	/**
	 * Look up a manifest file and plan its response.
	 *
	 * @param integer     $package_id Package id.
	 * @param string      $raw_path   Percent-encoded path inside the package.
	 * @param string|null $range      Raw `Range` header.
	 * @param string      $csp        Content-Security-Policy value.
	 *
	 * @return array{status:int,headers:array<string,string>,file:string|null,offset:int,length:int}
	 */
	private static function plan( int $package_id, string $raw_path, ?string $range, string $csp ): array {
		$file = $package_id > 0 ? Au_Store::lookup( $package_id, rawurldecode( $raw_path ) ) : null;
		if ( null === $file ) {
			return File_Responder::not_found();
		}
		return ( new File_Responder() )->plan(
			$file,
			$range,
			array(
				'Content-Type'            => $file['mime'],
				'X-Content-Type-Options'  => 'nosniff',
				'Content-Security-Policy' => $csp,
				'Cache-Control'           => 'public, max-age=3600',
			)
		);
	}

	/**
	 * The site origin (scheme, host and port of the home URL).
	 *
	 * @return string
	 */
	private static function site_origin(): string {
		$parts  = wp_parse_url( home_url() );
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );
		return isset( $parts['port'] ) ? $origin . ':' . $parts['port'] : $origin;
	}

	/**
	 * The request `Range` header, if any.
	 *
	 * @return string|null
	 */
	private static function range_header(): ?string {
		return isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : null;
	}
}
