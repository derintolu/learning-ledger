<?php
/**
 * Primitives tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Xapi\Primitives;

class Primitives_Test extends \PHPUnit\Framework\TestCase {
	private function assert_invalid( string $method, $value, string $code ) {
		try {
			Primitives::$method( $value, 'x.y' );
			$this->fail( $method . ' accepted a ' . gettype( $value ) );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( $code, $e->error_code() );
			$this->assertSame( 'x.y', $e->path() );
		}
	}

	private function assert_ok( string $method, $value ) {
		Primitives::$method( $value, 'x.y' );
		$this->addToAssertionCount( 1 );
	}

	public function test_iri() {
		foreach ( array( 'http://adlnet.gov/expapi/verbs/completed', 'https://x/é', 'urn:uuid:1', 'mailto:a@b.org', 'tag:example.com,2026:x' ) as $ok ) {
			$this->assert_ok( 'assert_iri', $ok );
		}
		foreach ( array( 'not an iri', 'relative/path', '/abs/path', 'http://x y', '', ':nope', 'http:', 5, null ) as $bad ) {
			$this->assert_invalid( 'assert_iri', $bad, 'iri' );
		}
	}

	public function test_uuid() {
		$this->assert_ok( 'assert_uuid', 'fd41c918-b88b-4b20-a0a5-a4c32391aaa0' );
		$this->assert_ok( 'assert_uuid', 'FD41C918-B88B-4B20-A0A5-A4C32391AAA0' );
		foreach ( array( 'fd41c918b88b4b20a0a5a4c32391aaa0', 'fd41c918-b88b-4b20-a0a5-a4c32391aaa', 'zd41c918-b88b-4b20-a0a5-a4c32391aaa0', '{fd41c918-b88b-4b20-a0a5-a4c32391aaa0}', 7 ) as $bad ) {
			$this->assert_invalid( 'assert_uuid', $bad, 'uuid' );
		}
	}

	public function test_lang_map() {
		$this->assert_ok(
			'assert_lang_map',
			array(
				'en-US'      => 'completed',
				'zh-Hant-TW' => 'x',
				'de'         => 'y',
				'und'        => 'z',
				'es-419'     => 'w',
				'sr-Latn-RS' => 'v',
				'en-x-priv'  => 'u',
			)
		);
		$this->assert_ok( 'assert_lang_map', array() );
		$this->assert_invalid( 'assert_lang_map', array( 'not a tag' => 'x' ), 'lang_map' );
		$this->assert_invalid( 'assert_lang_map', array( 'en_US' => 'x' ), 'lang_map' );
		$this->assert_invalid( 'assert_lang_map', array( 'e' => 'x' ), 'lang_map' );
		$this->assert_invalid( 'assert_lang_map', array( 'en-US' => 5 ), 'lang_map' );
		$this->assert_invalid( 'assert_lang_map', array( 'x', 'y' ), 'lang_map' );
		$this->assert_invalid( 'assert_lang_map', 'en-US', 'lang_map' );
	}

	public function test_mbox() {
		$this->assert_ok( 'assert_mbox', 'mailto:a@b.org' );
		foreach ( array( 'a@b.org', 'mailto:', 'mailto:ab.org', 'mailto:a@b@c', 'MAILTO:a@b.org', 'mailto:a b@c.org', 3 ) as $bad ) {
			$this->assert_invalid( 'assert_mbox', $bad, 'mbox' );
		}
	}

	public function test_sha1_hex() {
		$this->assert_ok( 'assert_sha1_hex', sha1( 'x' ) );
		$this->assert_ok( 'assert_sha1_hex', strtoupper( sha1( 'x' ) ) );
		foreach ( array( substr( sha1( 'x' ), 1 ), sha1( 'x' ) . 'a', str_repeat( 'g', 40 ), sha1( 'x', true ), null ) as $bad ) {
			$this->assert_invalid( 'assert_sha1_hex', $bad, 'sha1' );
		}
	}

	public function test_bool() {
		$this->assert_ok( 'assert_bool', true );
		$this->assert_ok( 'assert_bool', false );
		foreach ( array( 'true', 1, 0, null ) as $bad ) {
			$this->assert_invalid( 'assert_bool', $bad, 'bool' );
		}
	}

	public function test_number() {
		foreach ( array( 0, -3, 1.5, 1.0e10 ) as $ok ) {
			$this->assert_ok( 'assert_number', $ok );
		}
		foreach ( array( '1', true, null, NAN, INF ) as $bad ) {
			$this->assert_invalid( 'assert_number', $bad, 'number' );
		}
	}

	public function test_extensions() {
		$this->assert_ok(
			'assert_extensions',
			array(
				'http://example.com/ext/a' => array( 'any' => 'value' ),
				'urn:x:b'                  => 5,
			)
		);
		$this->assert_ok( 'assert_extensions', array() );
		$this->assert_invalid( 'assert_extensions', array( 'notiri' => 1 ), 'extensions' );
		$this->assert_invalid( 'assert_extensions', array( 1, 2 ), 'extensions' );
		$this->assert_invalid( 'assert_extensions', 'x', 'extensions' );
	}
}
