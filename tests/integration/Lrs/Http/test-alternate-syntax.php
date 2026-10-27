<?php
/**
 * Alternate Request Syntax (xAPI 1.0.3) tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Lrs\Http\Alternate_Syntax;
use LearningLedger\Lrs\Http\Xapi_Error;

class Alternate_Syntax_Test extends WP_UnitTestCase {

	private function form_request( array $query, array $fields ): WP_REST_Request {
		$r = new WP_REST_Request( 'POST', '/learning-ledger/xapi/activities/state' );
		$r->set_url_params( array( 'resource' => 'activities/state' ) );
		$r->set_query_params( $query );
		$r->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$r->set_body( http_build_query( $fields ) );
		return $r;
	}

	public function test_alternate_syntax_get_via_post_under_103() {
		$r   = $this->form_request(
			array( 'method' => 'GET' ),
			array(
				'Authorization'            => 'Basic Zm9vOmJhcg==',
				'X-Experience-API-Version' => '1.0.3',
				'activityId'               => 'http://x/a',
				'agent'                    => '{"mbox":"mailto:a@x"}',
				'stateId'                  => 's1',
			)
		);
		$out = Alternate_Syntax::apply( $r, '1.0.3' );

		$this->assertSame( 'GET', $out->get_method() );
		$this->assertSame( 'Basic Zm9vOmJhcg==', $out->get_header( 'Authorization' ) );
		$this->assertSame( '1.0.3', $out->get_header( 'X-Experience-API-Version' ) );
		$this->assertNull( $out->get_header( 'Content-Type' ) );
		$this->assertSame(
			array(
				'activityId' => 'http://x/a',
				'agent'      => '{"mbox":"mailto:a@x"}',
				'stateId'    => 's1',
			),
			$out->get_query_params()
		);
		$this->assertSame( '', $out->get_body() );
		$this->assertSame( 'activities/state', $out->get_param( 'resource' ) );
	}

	public function test_put_via_post_moves_content_and_headers() {
		$r   = $this->form_request(
			array( 'method' => 'PUT' ),
			array(
				'content-type'   => 'application/json',
				'Content-Length' => '9',
				'If-Match'       => '"abc"',
				'If-None-Match'  => '*',
				'content'        => '{"a":"b"}',
				'stateId'        => 's1',
			)
		);
		$out = Alternate_Syntax::apply( $r, '1.0.3' );

		$this->assertSame( 'PUT', $out->get_method() );
		$this->assertSame( 'application/json', $out->get_header( 'Content-Type' ) );
		$this->assertSame( '9', $out->get_header( 'Content-Length' ) );
		$this->assertSame( '"abc"', $out->get_header( 'If-Match' ) );
		$this->assertSame( '*', $out->get_header( 'If-None-Match' ) );
		$this->assertSame( '{"a":"b"}', $out->get_body() );
		$this->assertSame( array( 'stateId' => 's1' ), $out->get_query_params() );
	}

	public function test_not_alternate_returns_request_unchanged() {
		$get = new WP_REST_Request( 'GET', '/learning-ledger/xapi/statements' );
		$get->set_query_params( array( 'method' => 'GET' ) );
		$this->assertSame( $get, Alternate_Syntax::apply( $get, '1.0.3' ) );

		$post = $this->form_request( array(), array( 'a' => 'b' ) );
		$this->assertSame( $post, Alternate_Syntax::apply( $post, '1.0.3' ) );
	}

	public function test_other_query_parameter_alongside_method_400() {
		$r = $this->form_request(
			array(
				'method'  => 'GET',
				'stateId' => 's1',
			),
			array()
		);
		try {
			Alternate_Syntax::apply( $r, '1.0.3' );
			$this->fail( 'Expected 400' );
		} catch ( Xapi_Error $e ) {
			$this->assertSame( 400, $e->status() );
		}
	}

	public function test_unknown_method_400() {
		$r = $this->form_request( array( 'method' => 'PATCH' ), array() );
		try {
			Alternate_Syntax::apply( $r, '1.0.3' );
			$this->fail( 'Expected 400' );
		} catch ( Xapi_Error $e ) {
			$this->assertSame( 400, $e->status() );
		}
	}

	public function test_alternate_syntax_rejected_under_20() {
		$r = $this->form_request( array( 'method' => 'GET' ), array( 'stateId' => 's1' ) );
		try {
			Alternate_Syntax::apply( $r, '2.0.0' );
			$this->fail( 'Expected 400' );
		} catch ( Xapi_Error $e ) {
			$this->assertSame( 400, $e->status() );
		}
	}

	public function test_form_field_lookup_is_case_insensitive() {
		$r = $this->form_request( array( 'method' => 'GET' ), array( 'x-experience-api-version' => '1.0.1' ) );
		$this->assertSame( '1.0.1', Alternate_Syntax::form_field( $r, 'X-Experience-API-Version' ) );
		$this->assertNull( Alternate_Syntax::form_field( $r, 'Authorization' ) );
	}
}
