<?php
/**
 * Request value object for the xAPI endpoint.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Http;

use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Xapi\Version;

/**
 * An xAPI request: method, resource, query params, headers, raw body, version, caller.
 */
final class Xapi_Request {

	/**
	 * HTTP method, upper case.
	 *
	 * @var string
	 */
	private string $method;

	/**
	 * Resource path below the xAPI root, e.g. `statements` or `activities/state`.
	 *
	 * @var string
	 */
	private string $resource;

	/**
	 * Query parameters.
	 *
	 * @var array
	 */
	private array $params;

	/**
	 * Headers keyed by lower-case name with `-` replaced by `_`.
	 *
	 * @var array<string,string>
	 */
	private array $headers = array();

	/**
	 * Raw body.
	 *
	 * @var string
	 */
	private string $body;

	/**
	 * Negotiated version, or '' when the header was missing or unsupported.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Client IP.
	 *
	 * @var string
	 */
	private string $ip;

	/**
	 * Authenticated caller.
	 *
	 * @var Principal|null
	 */
	private ?Principal $principal = null;

	/**
	 * Constructor.
	 *
	 * @param string $method        HTTP method.
	 * @param string $resource_path Resource path.
	 * @param array  $params        Query parameters.
	 * @param array  $headers       Headers, name => string or list of strings (any case).
	 * @param string $body          Raw body.
	 * @param string $version       Negotiated version, or '' when none.
	 * @param string $ip            Client IP.
	 */
	public function __construct( string $method, string $resource_path, array $params = array(), array $headers = array(), string $body = '', string $version = '', string $ip = '' ) {
		$this->method   = strtoupper( $method );
		$this->resource = trim( $resource_path, '/' );
		$this->params   = $params;
		$this->body     = $body;
		$this->version  = $version;
		$this->ip       = $ip;
		foreach ( $headers as $name => $value ) {
			$this->headers[ self::header_key( (string) $name ) ] = is_array( $value ) ? implode( ',', $value ) : (string) $value;
		}
	}

	/**
	 * Build from a REST request routed as `/(?P<resource>...)`.
	 *
	 * @param \WP_REST_Request $r REST request.
	 *
	 * @return self
	 */
	public static function from_rest( \WP_REST_Request $r ): self {
		$params = $r->get_query_params();
		unset( $params['rest_route'] );

		try {
			$version = Version::negotiate( $r->get_header( 'X-Experience-API-Version' ) );
		} catch ( Invalid_Input $e ) {
			$version = '';
		}

		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an IP.
		}

		return new self(
			$r->get_method(),
			(string) $r->get_param( 'resource' ),
			$params,
			$r->get_headers(),
			(string) $r->get_body(),
			$version,
			$ip
		);
	}

	/**
	 * HTTP method.
	 *
	 * @return string
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * Resource path.
	 *
	 * @return string
	 */
	public function resource(): string {
		return $this->resource;
	}

	/**
	 * Query parameters.
	 *
	 * @return array
	 */
	public function params(): array {
		return $this->params;
	}

	/**
	 * One query parameter.
	 *
	 * @param string $k Name.
	 *
	 * @return mixed Null when absent.
	 */
	public function param( string $k ): mixed {
		return $this->params[ $k ] ?? null;
	}

	/**
	 * Header value, case-insensitive.
	 *
	 * @param string $k Header name.
	 *
	 * @return string|null
	 */
	public function header( string $k ): ?string {
		return $this->headers[ self::header_key( $k ) ] ?? null;
	}

	/**
	 * Raw body.
	 *
	 * @return string
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Negotiated version, or '' when the header was missing or unsupported.
	 *
	 * @return string
	 */
	public function version(): string {
		return $this->version;
	}

	/**
	 * Origin header.
	 *
	 * @return string|null
	 */
	public function origin(): ?string {
		return $this->header( 'Origin' );
	}

	/**
	 * Client IP (REMOTE_ADDR; forwarded headers are not trusted).
	 *
	 * @return string
	 */
	public function ip(): string {
		return $this->ip;
	}

	/**
	 * Authenticated caller, if any.
	 *
	 * @return Principal|null
	 */
	public function principal(): ?Principal {
		return $this->principal;
	}

	/**
	 * Copy with the authenticated caller set.
	 *
	 * @param Principal $p Caller.
	 *
	 * @return self
	 */
	public function with_principal( Principal $p ): self {
		$copy            = clone $this;
		$copy->principal = $p;
		return $copy;
	}

	/**
	 * Normalised header key, as WP_REST_Request stores them.
	 *
	 * @param string $name Header name.
	 *
	 * @return string
	 */
	private static function header_key( string $name ): string {
		return str_replace( '-', '_', strtolower( $name ) );
	}
}
