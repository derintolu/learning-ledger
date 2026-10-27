<?php
/**
 * Test client that dispatches xAPI requests through the REST server.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Tests;

use LearningLedger\Lrs\Http\Xapi_Response;

/**
 * Sends requests to the `learning-ledger/xapi` routes and returns the router's Xapi_Response.
 *
 * Usable as `( new Xapi_Test_Client() )->request( ... )`, `Xapi_Test_Client::request( ... )`
 * or `Xapi_Test_Client::as( $key, $secret )->request( ... )`.
 *
 * @method Xapi_Response request( string $method, string $path, array $query = array(), ?string $body = null, array $headers = array() )
 * @method static Xapi_Response request( string $method, string $path, array $query = array(), ?string $body = null, array $headers = array() )
 */
final class Xapi_Test_Client {

	/**
	 * Authorization header value, if any.
	 *
	 * @var string|null
	 */
	private ?string $authorization = null;

	/**
	 * Client sending Basic credentials.
	 *
	 * @param string $key    Client key (username).
	 * @param string $secret Client secret.
	 *
	 * @return self
	 */
	public static function as( string $key, string $secret ): self {
		$client                = new self();
		$client->authorization = 'Basic ' . base64_encode( $key . ':' . $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return $client;
	}

	/**
	 * Instance call of `request`.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 *
	 * @return Xapi_Response
	 * @throws \BadMethodCallException For any other method.
	 */
	public function __call( string $name, array $args ): Xapi_Response {
		if ( 'request' !== $name ) {
			throw new \BadMethodCallException( esc_html( $name ) );
		}
		return $this->send( ...$args );
	}

	/**
	 * Static call of `request`, unauthenticated.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 *
	 * @return Xapi_Response
	 * @throws \BadMethodCallException For any other method.
	 */
	public static function __callStatic( string $name, array $args ): Xapi_Response {
		if ( 'request' !== $name ) {
			throw new \BadMethodCallException( esc_html( $name ) );
		}
		return ( new self() )->send( ...$args );
	}

	/**
	 * Dispatch one request.
	 *
	 * @param string      $method   HTTP method.
	 * @param string      $path     Resource path below the xAPI root.
	 * @param array       $query    Query parameters.
	 * @param string|null $body     Raw body.
	 * @param array       $headers  Headers; a null value omits that header (e.g. the default version).
	 *
	 * @return Xapi_Response
	 */
	private function send( string $method, string $path, array $query = array(), ?string $body = null, array $headers = array() ): Xapi_Response {
		$request = new \WP_REST_Request( strtoupper( $method ), '/learning-ledger/xapi/' . ltrim( $path, '/' ) );
		$request->set_query_params( $query );
		if ( null !== $body ) {
			$request->set_body( $body );
		}

		$given = array_change_key_case( $headers, CASE_LOWER );
		if ( ! array_key_exists( 'x-experience-api-version', $given ) ) {
			$headers['X-Experience-API-Version'] = '2.0.0';
		}
		if ( null !== $this->authorization && ! array_key_exists( 'authorization', $given ) ) {
			$headers['Authorization'] = $this->authorization;
		}
		foreach ( $headers as $name => $value ) {
			if ( null !== $value ) {
				$request->set_header( $name, $value );
			}
		}

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		if ( $data instanceof Xapi_Response ) {
			return $data;
		}
		return Xapi_Response::json( $response->get_status(), $data, $response->get_headers() );
	}
}
