<?php
/**
 * Plugin boot and module manifest tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Kernel\Plugin;
use LearningLedger\Kernel\Rewrites;
use LearningLedger\Kernel\Roles;

/**
 * Records register() calls (test module for the lrs role).
 */
class Lledger_Recording_Lrs_Module implements \LearningLedger\Kernel\Module {
	public static $calls = 0;

	public function register(): void {
		++self::$calls;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test module lives beside its test.
class Plugin_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Lledger_Recording_Lrs_Module::$calls = 0;
		delete_option( Roles::OPTION );
		add_filter( 'learning_ledger/modules', array( $this, 'add_test_module' ) );
	}

	public function add_test_module( array $modules ): array {
		$modules[ Lledger_Recording_Lrs_Module::class ] = 'lrs';
		return $modules;
	}

	public function test_boot_registers_only_enabled_role_modules() {
		( new Plugin() )->boot();
		$this->assertSame( 0, Lledger_Recording_Lrs_Module::$calls, 'lrs off: not registered' );

		$this->assertTrue( Roles::enable( 'lrs' ) );
		( new Plugin() )->boot();
		$this->assertSame( 1, Lledger_Recording_Lrs_Module::$calls, 'lrs on: registered once' );
	}

	public function test_boot_is_once_per_instance_and_hooks_upgrade_and_rewrites() {
		Roles::enable( 'lrs' );
		$plugin = new Plugin();
		$plugin->boot();
		$plugin->boot();
		$this->assertSame( 1, Lledger_Recording_Lrs_Module::$calls );
		$this->assertSame( 10, has_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) ) );
		$this->assertSame( 99, has_action( 'init', array( Rewrites::class, 'maybe_flush' ) ) );
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_manifest_rulings() {
		$this->assertSame( 'core', Plugin::MODULES['LearningLedger\Kernel\Status_Route'] );
		$this->assertSame( 'core', Plugin::MODULES['LearningLedger\Lrs\Admin\Admin_Module'] );
		$this->assertSame( 'lrs', Plugin::MODULES['LearningLedger\Badges\Jwt\Jwt_Module'] );
		$this->assertSame( 'connector', Plugin::MODULES['LearningLedger\Connector\Lms\Integration_Loader'] );
		$this->assertSame( 'core', Plugin::MODULES['LearningLedger\Kernel\No_Page_Cache'] );
		$this->assertCount( 41, Plugin::MODULES );
		foreach ( Plugin::MODULES as $role ) {
			$this->assertContains( $role, array( 'core', 'lrs', 'connector' ) );
		}
	}

	public function test_rewrite_flush_scheduled_once() {
		delete_option( Rewrites::OPTION );
		Rewrites::schedule_flush();
		$this->assertNotFalse( get_option( Rewrites::OPTION ) );
		Rewrites::maybe_flush();
		$this->assertFalse( get_option( Rewrites::OPTION ) );
	}
}
