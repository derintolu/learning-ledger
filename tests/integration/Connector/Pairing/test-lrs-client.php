<?php
/**
 * LRS client tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixtures.

use LearningLedger\Connector\Pairing\Connector_Keys;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Pairing\Lrs_Client;
use LearningLedger\Connector\Pairing\Lrs_Response;

class Lrs_Client_Test extends WP_UnitTestCase {

	/** @var array[] */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();
		delete_option( Connector_Settings::OPTION );
		delete_option( Connector_Secret::OPTION );
		delete_option( Connector_Keys::OPTION );
		$this->requests = array();
		Connector_Settings::update(
			array(
				'lrs_url'   => 'https://lrs.example.test/xapi/',
				'api_base'  => 'https://lrs.example.test/wp-json/learning-ledger/v1/',
				'client_id' => 'connector-site',
			)
		);
		Connector_Secret::generate();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		parent::tear_down();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return array(
			'headers'  => array(
				'content-type'             => 'application/json',
				'x-experience-api-version' => '1.0.3',
			),
			'body'     => '{"statements":[],"more":""}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function test_lrs_client_sends_basic_and_version() {
		$res = ( new Lrs_Client() )->xapi(
			'GET',
			'statements',
			array(
				'agent' => '{"mbox":"mailto:a@b.test"}',
				'limit' => 5,
			)
		);

		$this->assertInstanceOf( Lrs_Response::class, $res );
		$this->assertSame( 200, $res->status );
		$this->assertSame(
			array(
				'statements' => array(),
				'more'       => '',
			),
			$res->json()
		);
		$this->assertSame( '1.0.3', $res->headers['x-experience-api-version'] );

		$this->assertCount( 1, $this->requests );
		$r = $this->requests[0];
		$this->assertStringStartsWith( 'https://lrs.example.test/xapi/statements?', $r['url'] );
		$query = array();
		parse_str( (string) wp_parse_url( $r['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( '{"mbox":"mailto:a@b.test"}', $query['agent'] );
		$this->assertSame( '5', $query['limit'] );
		$this->assertSame( 'GET', $r['args']['method'] );
		$this->assertSame( 10, $r['args']['timeout'] );
		$this->assertSame( 'Basic ' . base64_encode( 'connector-site:' . Connector_Secret::get() ), $r['args']['headers']['Authorization'] );
		$this->assertSame( '1.0.3', $r['args']['headers']['X-Experience-API-Version'] );

		$body = '[{"id":"6690e6c9-3ef0-4ed3-8b37-7f3964730bee"}]';
		( new Lrs_Client() )->xapi( 'POST', 'statements', array(), $body, array( 'Content-Type' => 'application/json' ) );
		$r = $this->requests[1];
		$this->assertSame( 'https://lrs.example.test/xapi/statements', $r['url'] );
		$this->assertSame( 'POST', $r['args']['method'] );
		$this->assertSame( $body, $r['args']['body'] );
		$this->assertSame( 'application/json', $r['args']['headers']['Content-Type'] );

		( new Lrs_Client() )->xapi( 'GET', 'statements', array(), null, array( 'Authorization' => 'Basic forged' ) );
		$this->assertSame( 'Basic ' . base64_encode( 'connector-site:' . Connector_Secret::get() ), $this->requests[2]['args']['headers']['Authorization'], 'callers cannot replace the credential' );
	}

	public function test_api_uses_api_base_with_json() {
		$res = ( new Lrs_Client() )->api( 'POST', 'actors/erase', array( 'actor' => array( 'mbox' => 'mailto:a@b.test' ) ) );
		$this->assertSame( 200, $res->status );
		$r = $this->requests[0];
		$this->assertSame( 'https://lrs.example.test/wp-json/learning-ledger/v1/actors/erase', $r['url'] );
		$this->assertSame( 'POST', $r['args']['method'] );
		$this->assertSame( '{"actor":{"mbox":"mailto:a@b.test"}}', $r['args']['body'] );
		$this->assertSame( 'application/json', $r['args']['headers']['Content-Type'] );
		$this->assertSame( 'Basic ' . base64_encode( 'connector-site:' . Connector_Secret::get() ), $r['args']['headers']['Authorization'] );
		$this->assertSame( 10, $r['args']['timeout'] );
	}

	public function test_public_base_never_loopback() {
		Connector_Settings::update( array( 'loopback_url' => 'http://127.0.0.1:8080/xapi' ) );

		$this->assertSame( 'https://lrs.example.test/xapi/', Connector_Settings::public_xapi_base() );
		$this->assertSame( 'http://127.0.0.1:8080/xapi/', Connector_Settings::get()['loopback_url'] );

		( new Lrs_Client() )->xapi( 'GET', 'about' );
		$this->assertSame( 'http://127.0.0.1:8080/xapi/about', $this->requests[0]['url'], 'server-to-server calls use the loopback' );

		Connector_Settings::update( array( 'loopback_url' => '' ) );
		( new Lrs_Client() )->xapi( 'GET', 'about' );
		$this->assertSame( 'https://lrs.example.test/xapi/about', $this->requests[1]['url'] );
	}

	public function test_transport_error_is_status_zero() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_request_failed', 'cURL error 7: refused' );
			}
		);
		$res = ( new Lrs_Client() )->xapi( 'GET', 'statements' );
		$this->assertSame( 0, $res->status );
		$this->assertNull( $res->json() );
		$this->assertSame( 'http_request_failed', $res->error_code );
		$this->assertSame( 'cURL error 7: refused', $res->error_message );
	}

	public function test_unpaired_client_refuses_to_send() {
		delete_option( Connector_Secret::OPTION );
		$this->expectException( \RuntimeException::class );
		( new Lrs_Client() )->xapi( 'GET', 'statements' );
	}
}
