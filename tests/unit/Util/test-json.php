<?php
/**
 * Json tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;

class Json_Test extends \PHPUnit\Framework\TestCase {
	private function code_of( callable $callback ): string {
		try {
			$callback();
		} catch ( Invalid_Input $e ) {
			return $e->error_code();
		}
		return 'none';
	}

	public function test_json_limits() {
		$deep33 = str_repeat( '[', 33 ) . str_repeat( ']', 33 );
		$deep32 = str_repeat( '[', 32 ) . str_repeat( ']', 32 );
		$deep99 = str_repeat( '[', 99 ) . str_repeat( ']', 99 );

		$this->assertSame( 'too_deep', $this->code_of( fn() => Json::decode_strict( $deep33, 100000 ) ) );
		$this->assertSame( 'too_deep', $this->code_of( fn() => Json::decode_strict( $deep99, 100000 ) ) );
		$this->assertSame( 'none', $this->code_of( fn() => Json::decode_strict( $deep32, 100000 ) ) );

		$body = '{"a":"bcdef"}';
		$max  = strlen( $body );
		$this->assertSame( 'none', $this->code_of( fn() => Json::decode_strict( $body, $max ) ) );
		$this->assertSame( 'too_large', $this->code_of( fn() => Json::decode_strict( $body, $max - 1 ) ) );

		$this->assertSame( 'malformed', $this->code_of( fn() => Json::decode_strict( '{"a":', 1000 ) ) );
		$this->assertSame( 'malformed', $this->code_of( fn() => Json::decode_strict( '', 1000 ) ) );
		$this->assertSame( 'malformed', $this->code_of( fn() => Json::decode_strict( "\"\xff\"", 1000 ) ) );
	}

	public function test_custom_max_depth_with_objects() {
		$this->assertSame( 'too_deep', $this->code_of( fn() => Json::decode_strict( '{"a":{"b":1}}', 1000, 1 ) ) );
		$this->assertSame( 'none', $this->code_of( fn() => Json::decode_strict( '{"a":{"b":1}}', 1000, 2 ) ) );
	}

	public function test_decode_returns_values() {
		$this->assertSame( array( 'a' => array( 1, 2.5, null, true ) ), Json::decode_strict( '{"a":[1,2.5,null,true]}', 1000 ) );
		$this->assertSame( 'x', Json::decode_strict( '"x"', 1000 ) );
		$this->assertNull( Json::decode_strict( 'null', 1000 ) );
	}

	public function test_encode_exact_keeps_slashes_and_unicode() {
		$this->assertSame(
			'{"a":"http://x/é","n":1.0}',
			Json::encode_exact(
				array(
					'a' => 'http://x/é',
					'n' => 1.0,
				)
			)
		);
	}

	public function test_encode_exact_throws_on_failure() {
		$this->expectException( Invalid_Input::class );
		Json::encode_exact( "\xff" );
	}

	public function test_is_list() {
		$this->assertTrue( Json::is_list( array() ) );
		$this->assertTrue( Json::is_list( array( 'a', 'b' ) ) );
		$this->assertFalse( Json::is_list( array( 1 => 'a' ) ) );
		$this->assertFalse( Json::is_list( array( 'k' => 'a' ) ) );
	}

	public function test_json_equal_ignores_key_order() {
		$this->assertTrue(
			Json::equal(
				array(
					'a' => 1,
					'b' => array( 1, 2 ),
				),
				array(
					'b' => array( 1, 2 ),
					'a' => 1,
				)
			)
		);
	}

	public function test_json_equal_list_order_matters() {
		$this->assertFalse( Json::equal( array( 1, 2 ), array( 2, 1 ) ) );
		$this->assertFalse( Json::equal( array( 'a' => array( 1, 2 ) ), array( 'a' => array( 2, 1 ) ) ) );
	}

	public function test_json_equal_detects_differences() {
		$this->assertFalse(
			Json::equal(
				array( 'a' => 1 ),
				array(
					'a' => 1,
					'b' => 2,
				)
			)
		);
		$this->assertFalse( Json::equal( array( 'a' => 1 ), array( 'b' => 1 ) ) );
		$this->assertFalse( Json::equal( 'a', 'b' ) );
		$this->assertFalse( Json::equal( '1', 1 ) );
		$this->assertFalse( Json::equal( null, false ) );
		$this->assertTrue( Json::equal( 1, 1.0 ) );
	}
}
