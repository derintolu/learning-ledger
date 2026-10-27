<?php
/**
 * Narrative tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Pipeline\Narrative;
use LearningLedger\Util\Invalid_Input;

class Narrative_Test extends \PHPUnit\Framework\TestCase {

	public function test_states_how_many_were_tested_out() {
		$this->assertSame( '4 of 6 lessons completed, 2 tested out by placement', Narrative::for( 6, 4, 2 ) );
		$this->assertSame( '1 of 2 lessons completed, 1 tested out by placement', Narrative::for( 2, 1, 1 ) );
	}

	public function test_omits_the_tested_out_clause_when_none_were() {
		$this->assertSame( '6 of 6 lessons completed', Narrative::for( 6, 6, 0 ) );
		$this->assertSame( '1 of 1 lessons completed', Narrative::for( 1, 1, 0 ) );
	}

	public function test_rejects_counts_that_do_not_add_up() {
		foreach ( array( array( 2, 2, 1 ), array( 1, -1, 0 ), array( 1, 0, -1 ), array( -1, 0, 0 ) ) as $args ) {
			try {
				Narrative::for( ...$args );
				$this->fail( 'Expected Invalid_Input for ' . implode( ',', $args ) );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'invalid_counts', $e->error_code() );
			}
		}
	}
}
