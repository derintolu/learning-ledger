<?php
/**
 * HTTP client for the paired LRS.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Pairing;

/**
 * Talks to the LRS over HTTP only (wp_remote_*), with Basic `client_id:secret`.
 */
final class Lrs_Client {

	const XAPI_VERSION = '1.0.3';
	const TIMEOUT      = 10;

	/**
	 * Send an xAPI request to the loopback base when set, else the public base.
	 *
	 * @param string      $method   HTTP method.
	 * @param string      $resource Resource relative to the xAPI base, e.g. `statements`.
	 * @param array       $query    Query parameters.
	 * @param string|null $body     Raw body.
	 * @param array       $headers  Extra headers; they cannot replace the credential.
	 *
	 * @return Lrs_Response
	 */
	public function xapi( string $method, string $resource, array $query = array(), ?string $body = null, array $headers = array() ): Lrs_Response { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.resourceFound -- name fixed by the interface.
		$settings = Connector_Settings::get();
		$base     = '' !== $settings['loopback_url'] ? $settings['loopback_url'] : $settings['lrs_url'];
		$headers  = array_merge( array( 'X-Experience-API-Version' => self::XAPI_VERSION ), $headers );
		return $this->send( $method, $base, $resource, $query, $body, $headers );
	}

	/**
	 * Learning Ledger API request against `api_base`, e.g. `actors/erase`.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path relative to the API base.
	 * @param array|null $json   Body, sent as JSON.
	 *
	 * @return Lrs_Response
	 */
	public function api( string $method, string $path, ?array $json = null ): Lrs_Response {
		$headers = array();
		$body    = null;
		if ( null !== $json ) {
			$headers['Content-Type'] = 'application/json';
			$body                    = (string) wp_json_encode( $json );
		}
		return $this->send( $method, Connector_Settings::get()['api_base'], $path, array(), $body, $headers );
	}

	/**
	 * Send an authenticated request.
	 *
	 * @param string      $method   HTTP method.
	 * @param string      $base     Base URL with trailing slash.
	 * @param string      $relative Relative path.
	 * @param array       $query    Query parameters.
	 * @param string|null $body     Raw body.
	 * @param array       $headers  Headers.
	 *
	 * @return Lrs_Response
	 *
	 * @throws \RuntimeException When the connector is not paired.
	 */
	private function send( string $method, string $base, string $relative, array $query, ?string $body, array $headers ): Lrs_Response {
		$client = Connector_Settings::get()['client_id'];
		$secret = Connector_Secret::get();
		if ( '' === $base || '' === $client || null === $secret ) {
			throw new \RuntimeException( 'The connector is not paired with an LRS.' );
		}

		foreach ( array_keys( $headers ) as $name ) {
			if ( 'authorization' === strtolower( (string) $name ) ) {
				unset( $headers[ $name ] );
			}
		}
		$headers['Authorization'] = 'Basic ' . base64_encode( $client . ':' . $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials.

		$url = $base . ltrim( $relative, '/' );
		if ( array() !== $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$args = array(
			'method'      => strtoupper( $method ),
			'headers'     => $headers,
			'timeout'     => self::TIMEOUT,
			// Never forward the credential to another host.
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		return self::to_response( wp_remote_request( $url, $args ) );
	}

	/**
	 * Convert a wp_remote_* result.
	 *
	 * @param array|\WP_Error $res Result.
	 *
	 * @return Lrs_Response
	 */
	public static function to_response( $res ): Lrs_Response {
		if ( is_wp_error( $res ) ) {
			return new Lrs_Response( 0, array(), '', (string) $res->get_error_code(), $res->get_error_message() );
		}
		$headers = wp_remote_retrieve_headers( $res );
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		return new Lrs_Response(
			(int) wp_remote_retrieve_response_code( $res ),
			is_array( $headers ) ? $headers : array(),
			(string) wp_remote_retrieve_body( $res )
		);
	}
}
