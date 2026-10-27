<?php
/**
 * Module interface test.
 *
 * @package LearningLedger
 */

class Module_Test extends \PHPUnit\Framework\TestCase {
	public function test_module_interface_autoloads() {
		$this->assertTrue( interface_exists( '\LearningLedger\Kernel\Module' ) );
		$this->assertTrue( method_exists( '\LearningLedger\Kernel\Module', 'register' ) );
	}

	public function test_auth_key_constants_defined_for_unit_tests() {
		$this->assertTrue( defined( 'AUTH_KEY' ) );
		$this->assertTrue( defined( 'SECURE_AUTH_KEY' ) );
	}
}
