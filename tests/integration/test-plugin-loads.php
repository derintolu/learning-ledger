<?php
/**
 * Plugin load test.
 *
 * @package LearningLedger
 */

class Plugin_Loads_Test extends WP_UnitTestCase {
	public function test_plugin_constants() {
		$this->assertSame( '0.1.4', LEARNING_LEDGER_VERSION );
		$this->assertTrue( function_exists( 'LearningLedger\autoload_candidates' ) );
	}
}
