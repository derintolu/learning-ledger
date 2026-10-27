<?php
/**
 * Xapi_Response, Xapi_Error and Xapi_Request value object tests (owed by Task 7, F2).
 *
 * @package LearningLedger
 */

use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Http\Xapi_Response;

class Xapi_Value_Objects_Test extends \PHPUnit\Framework\TestCase {

	public function test_response_json_keeps_slashes_and_unicode() {
		$res = Xapi_Response::json( 200, array( 'a' => 'http://x/é' ), array( 'ETag' => '"abc"' ) );
		$this->assertSame( 200, $res->status() );
		$this->assertSame( '{"a":"http://x/é"}', $res->body() );
		$this->assertSame( 'application/json', $res->content_type() );
		$this->assertSame( array( 'ETag' => '"abc"' ), $res->headers() );
	}

	public function test_response_constructor_defaults() {
		$res = new Xapi_Response( 204 );
		$this->assertSame( 204, $res->status() );
		$this->assertSame( '', $res->body() );
		$this->assertSame( 'application/json', $res->content_type() );
		$this->assertSame( array(), $res->headers() );
	}

	public function test_error_status_headers_and_response() {
		$e = new Xapi_Error( 401, 'Bad "credentials"', array( 'WWW-Authenticate' => 'Basic realm="Learning Ledger"' ) );
		$this->assertInstanceOf( \RuntimeException::class, $e );
		$this->assertSame( 401, $e->status() );
		$this->assertSame( array( 'WWW-Authenticate' => 'Basic realm="Learning Ledger"' ), $e->headers() );

		$res = $e->to_response();
		$this->assertSame( 401, $res->status() );
		$this->assertSame( '{"error":true,"message":"Bad \"credentials\""}', $res->body() );
		$this->assertSame( 'application/json', $res->content_type() );
		$this->assertSame( array( 'WWW-Authenticate' => 'Basic realm="Learning Ledger"' ), $res->headers() );
	}

	public function test_request_header_lookup_is_case_insensitive() {
		$r = new Xapi_Request(
			'get',
			'/activities/state/',
			array( 'stateId' => 's1' ),
			array(
				'X-Experience-API-Version' => '1.0.3',
				'content_type'             => array( 'application/json' ),
				'Origin'                   => 'https://au.example',
			),
			'{}',
			'1.0.3',
			'203.0.113.5'
		);
		$this->assertSame( 'GET', $r->method() );
		$this->assertSame( 'activities/state', $r->resource() );
		$this->assertSame( '1.0.3', $r->header( 'x-experience-api-version' ) );
		$this->assertSame( '1.0.3', $r->header( 'X_EXPERIENCE_API_VERSION' ) );
		$this->assertSame( 'application/json', $r->header( 'Content-Type' ) );
		$this->assertSame( 'https://au.example', $r->origin() );
		$this->assertNull( $r->header( 'If-Match' ) );
		$this->assertSame( 's1', $r->param( 'stateId' ) );
		$this->assertNull( $r->param( 'missing' ) );
		$this->assertSame( '{}', $r->body() );
		$this->assertSame( '1.0.3', $r->version() );
		$this->assertSame( '203.0.113.5', $r->ip() );
		$this->assertNull( $r->principal() );
	}
}
