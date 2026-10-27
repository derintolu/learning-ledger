<?php
/**
 * CORS tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Lrs\Http\Cors;
use LearningLedger\Lrs\Http\Xapi_Response;

class Cors_Test extends WP_UnitTestCase {

	public function test_preflight_allowed_origin_only() {
		$res = Cors::preflight( 'https://au.example', array( 'https://other.example', 'https://au.example' ) );
		$this->assertSame( 204, $res->status() );
		$this->assertSame( '', $res->body() );
		$h = $res->headers();
		$this->assertSame( 'https://au.example', $h['Access-Control-Allow-Origin'] );
		$this->assertSame( 'Origin', $h['Vary'] );
		$this->assertSame( 'GET, PUT, POST, DELETE, OPTIONS', $h['Access-Control-Allow-Methods'] );
		$this->assertSame( 'Authorization, Content-Type, X-Experience-API-Version, If-Match, If-None-Match', $h['Access-Control-Allow-Headers'] );
		$this->assertSame( '600', $h['Access-Control-Max-Age'] );

		$unknown = Cors::preflight( 'https://evil.example', array( 'https://au.example' ) );
		$this->assertSame( 204, $unknown->status() );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', $unknown->headers() );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Methods', $unknown->headers() );
	}

	public function test_null_origin_matched_literally_and_never_wildcard() {
		$this->assertSame( 'null', Cors::preflight( 'null', array( 'null' ) )->headers()['Access-Control-Allow-Origin'] );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', Cors::preflight( 'null', array( 'https://au.example' ) )->headers() );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', Cors::preflight( 'https://au.example', array( '*' ) )->headers() );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', Cors::preflight( 'https://AU.example', array( 'https://au.example' ) )->headers() );
	}

	public function test_decorate_adds_allow_origin_and_expose_headers() {
		$res = Cors::decorate( new Xapi_Response( 200, '{"x":1}', 'application/json', array( 'ETag' => '"e"' ) ), 'https://au.example', array( 'https://au.example' ) );
		$this->assertSame( 200, $res->status() );
		$this->assertSame( '{"x":1}', $res->body() );
		$this->assertSame( '"e"', $res->headers()['ETag'] );
		$this->assertSame( 'https://au.example', $res->headers()['Access-Control-Allow-Origin'] );
		$this->assertSame( 'ETag, Last-Modified, X-Experience-API-Version, X-Experience-API-Consistent-Through', $res->headers()['Access-Control-Expose-Headers'] );

		foreach ( array( null, 'https://evil.example' ) as $origin ) {
			$plain = new Xapi_Response( 200, '{}' );
			$this->assertSame( $plain, Cors::decorate( $plain, $origin, array( 'https://au.example', '*' ) ) );
		}
	}
}
