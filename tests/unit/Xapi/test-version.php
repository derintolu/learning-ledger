<?php
/**
 * Version tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Xapi\Version;

class Version_Test extends \PHPUnit\Framework\TestCase {
	public function test_supported() {
		$this->assertSame( array( '1.0.3', '2.0.0' ), Version::SUPPORTED );
	}

	/**
	 * @dataProvider accepted
	 */
	public function test_negotiate_accepted( $header, $expected ) {
		$this->assertSame( $expected, Version::negotiate( $header ) );
	}

	public function accepted() {
		return array(
			array( '1.0', '1.0.3' ),
			array( '1.0.0', '1.0.3' ),
			array( '1.0.1', '1.0.3' ),
			array( '1.0.2', '1.0.3' ),
			array( '1.0.3', '1.0.3' ),
			array( '2.0', '2.0.0' ),
			array( '2.0.0', '2.0.0' ),
			array( '2.0.7', '2.0.0' ),
		);
	}

	/**
	 * @dataProvider rejected
	 */
	public function test_negotiate_rejected( $header ) {
		try {
			Version::negotiate( $header );
			$this->fail( 'Expected Invalid_Input for ' . ( $header ?? 'null' ) );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'version', $e->error_code() );
		}
	}

	public function rejected() {
		return array(
			array( null ),
			array( '' ),
			array( '0.95' ),
			array( '0.9' ),
			array( '1' ),
			array( '1.0.4' ),
			array( '1.1.0' ),
			array( '2' ),
			array( '2.1.0' ),
			array( '2.0.x' ),
			array( '3.0.0' ),
			array( '1.0.3-beta' ),
		);
	}
}
