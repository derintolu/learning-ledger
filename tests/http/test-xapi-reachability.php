<?php
/**
 * xAPI endpoints answer from our router, even with a Basic header core would reject.
 *
 * @package LearningLedger
 */

class Xapi_Reachability_Test extends \PHPUnit\Framework\TestCase {

	/**
	 * GET a URL and return status, lower-cased headers and body.
	 *
	 * @param string $url URL.
	 *
	 * @return array{0:int,1:array<string,string>,2:string}
	 */
	private function get( string $url ): array {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'          => 'GET',
					'header'          => "Authorization: Basic Zm9vOmJhcg==\r\nX-Experience-API-Version: 2.0.0\r\n",
					'ignore_errors'   => true,
					'follow_location' => 0,
					'timeout'         => 20,
				),
			)
		);
		$body    = file_get_contents( $url, false, $context ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertIsString( $body, 'No response from ' . $url );
		$headers = array();
		$status  = 0;
		foreach ( $http_response_header as $line ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
				$status = (int) $m[1];
				continue;
			}
			$parts = explode( ':', $line, 2 );
			if ( 2 === count( $parts ) ) {
				$headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
			}
		}
		return array( $status, $headers, $body );
	}

	public function test_about_answers_from_router_despite_basic_header() {
		foreach ( array( '/xapi/about', '/wp-json/learning-ledger/xapi/about' ) as $path ) {
			list( $status, $headers, $body ) = $this->get( LLEDGER_TEST_BASE_URL . $path );
			$this->assertNotSame( 401, $status, $path . ': ' . $body );
			$this->assertStringNotContainsString( 'invalid_username', $body, $path );
			$this->assertSame( '2.0.0', $headers['x-experience-api-version'] ?? null, $path . ': ' . $body );
			$this->assertStringNotContainsString( '_links', $body, $path );
		}
	}
}
