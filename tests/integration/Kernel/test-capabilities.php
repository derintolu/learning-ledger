<?php
/**
 * Capability tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Kernel\Capabilities;

class Capabilities_Test extends WP_UnitTestCase {

	private const CAPS = array(
		'lledger_manage',
		'lledger_view_records',
		'lledger_manage_badges',
		'lledger_export',
		'lledger_manage_connector',
	);

	public function test_admin_has_five_caps_subscriber_none() {
		$this->assertSame(
			self::CAPS,
			array( Capabilities::MANAGE, Capabilities::VIEW_RECORDS, Capabilities::MANAGE_BADGES, Capabilities::EXPORT, Capabilities::MANAGE_CONNECTOR )
		);

		Capabilities::install();
		$admin      = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$editor     = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$subscriber = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		foreach ( self::CAPS as $cap ) {
			$this->assertTrue( user_can( $admin, $cap ), "administrator has {$cap}" );
			$this->assertFalse( user_can( $editor, $cap ), "editor lacks {$cap}" );
			$this->assertFalse( user_can( $subscriber, $cap ), "subscriber lacks {$cap}" );
		}

		Capabilities::remove();
		foreach ( self::CAPS as $cap ) {
			$this->assertFalse( get_role( 'administrator' )->has_cap( $cap ), "removed {$cap}" );
		}
		Capabilities::install();
	}
}
