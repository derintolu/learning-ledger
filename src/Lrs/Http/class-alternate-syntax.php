<?php
/**
 * Alternate Request Syntax (xAPI 1.0.3).
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Http;

/**
 * Turns `POST ?method=X` with a form body into the request it stands for.
 */
final class Alternate_Syntax {

	/**
	 * Methods a client may tunnel.
	 *
	 * @var string[]
	 */
	private const METHODS = array( 'GET', 'PUT', 'POST', 'DELETE' );

	/**
	 * Form fields read as headers, keyed by lower-case name.
	 *
	 * @var array<string,string>
	 */
	private const HEADER_FIELDS = array(
		'authorization'            => 'Authorization',
		'x-experience-api-version' => 'X-Experience-API-Version',
		'content-type'             => 'Content-Type',
		'content-length'           => 'Content-Length',
		'if-match'                 => 'If-Match',
		'if-none-match'            => 'If-None-Match',
	);

	/**
	 * Whether a request uses the alternate syntax (POST with a `method` query parameter).
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return boolean
	 */
	public static function is_alternate( \WP_REST_Request $r ): bool {
		return 'POST' === $r->get_method() && array_key_exists( 'method', $r->get_query_params() );
	}

	/**
	 * Rewrite an alternate-syntax request; any other request is returned unchanged.
	 *
	 * @param \WP_REST_Request $r       Request.
	 * @param string           $version Negotiated version.
	 *
	 * @return \WP_REST_Request
	 * @throws Xapi_Error 400 under 2.0.0, for an unknown method, or for other query parameters.
	 */
	public static function apply( \WP_REST_Request $r, string $version ): \WP_REST_Request {
		if ( ! self::is_alternate( $r ) ) {
			return $r;
		}
		if ( '1.0.3' !== $version ) {
			throw new Xapi_Error( 400, 'Alternate Request Syntax is not supported in xAPI 2.0.' );
		}
		$query = $r->get_query_params();
		unset( $query['rest_route'] );
		$method = $query['method'];
		unset( $query['method'] );
		if ( ! is_string( $method ) || ! in_array( $method, self::METHODS, true ) ) {
			throw new Xapi_Error( 400, 'The method parameter must be GET, PUT, POST or DELETE.' );
		}
		if ( array() !== $query ) {
			throw new Xapi_Error( 400, 'Alternate Request Syntax allows no query parameter other than method.' );
		}

		$out = new \WP_REST_Request( $method, $r->get_route() );
		$out->set_url_params( $r->get_url_params() );
		foreach ( $r->get_headers() as $key => $values ) {
			if ( 'content_type' !== $key && 'content_length' !== $key ) {
				$out->set_header( $key, $values );
			}
		}

		$params = array();
		$body   = '';
		foreach ( self::fields( $r ) as $name => $value ) {
			$lower = strtolower( (string) $name );
			if ( isset( self::HEADER_FIELDS[ $lower ] ) ) {
				$out->set_header( self::HEADER_FIELDS[ $lower ], (string) $value );
			} elseif ( 'content' === $lower ) {
				$body = (string) $value;
			} else {
				$params[ $name ] = $value;
			}
		}
		$out->set_query_params( $params );
		$out->set_body( $body );
		return $out;
	}

	/**
	 * One form field, by case-insensitive name.
	 *
	 * @param \WP_REST_Request $r    Request.
	 * @param string           $name Field name.
	 *
	 * @return string|null
	 */
	public static function form_field( \WP_REST_Request $r, string $name ): ?string {
		foreach ( self::fields( $r ) as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, $name ) && is_string( $value ) ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * Form fields: the parsed POST fields, or the raw body parsed as a form.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return array
	 */
	private static function fields( \WP_REST_Request $r ): array {
		$fields = $r->get_body_params();
		if ( array() === $fields && '' !== (string) $r->get_body() ) {
			parse_str( (string) $r->get_body(), $fields );
		}
		return $fields;
	}
}
