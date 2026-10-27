<?php
/**
 * About resource tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Lrs\Http\Xapi_Router;
use LearningLedger\Tests\Xapi_Test_Client;

class About_Handler_Test extends WP_UnitTestCase {

	const KEY = 'https://github.com/derintolu/learning-ledger';

	public function set_up() {
		parent::set_up();
		( new Xapi_Router() )->register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	public function test_lists_both_versions_without_auth_and_carries_the_extension() {
		$res  = Xapi_Test_Client::request( 'GET', 'about' );
		$body = json_decode( $res->body(), true );

		$this->assertSame( 200, $res->status() );
		$this->assertSame( array( '1.0.3', '2.0.0' ), $body['version'] );
		$this->assertSame( rest_url( 'learning-ledger/v1/' ), $body['extensions'][ self::KEY ]['api_base'] );
		$this->assertSame( LEARNING_LEDGER_VERSION, $body['extensions'][ self::KEY ]['plugin_version'] );
	}

	public function test_filter_adds_fields() {
		add_filter(
			'learning_ledger/about_extension',
			static function ( array $ext ) {
				$ext['badges'] = array( 'issuer' => 'https://x.example' );
				return $ext;
			}
		);
		$body = json_decode( Xapi_Test_Client::request( 'GET', 'about' )->body(), true );

		$this->assertSame( array( 'issuer' => 'https://x.example' ), $body['extensions'][ self::KEY ]['badges'] );
		$this->assertArrayHasKey( 'api_base', $body['extensions'][ self::KEY ] );
	}
}
