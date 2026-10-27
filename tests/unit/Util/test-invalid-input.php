<?php
/**
 * Invalid_Input tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Util\Invalid_Input;

class Invalid_Input_Test extends \PHPUnit\Framework\TestCase {
	public function test_defaults() {
		$e = new Invalid_Input( 'bad' );
		$this->assertInstanceOf( \InvalidArgumentException::class, $e );
		$this->assertSame( 'bad', $e->getMessage() );
		$this->assertSame( 'invalid', $e->error_code() );
		$this->assertSame( '', $e->path() );
	}

	public function test_code_and_path() {
		$e = new Invalid_Input( 'bad', 'missing', 'actor.mbox' );
		$this->assertSame( 'missing', $e->error_code() );
		$this->assertSame( 'actor.mbox', $e->path() );
	}
}
