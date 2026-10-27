<?php
/**
 * Autoload mapping tests.
 *
 * @package LearningLedger
 */

class Autoload_Test extends \PHPUnit\Framework\TestCase {
	public function test_maps_class_interface_and_integration_paths() {
		$c = \LearningLedger\autoload_candidates( 'LearningLedger\Lrs\Http\Xapi_Router' );
		$this->assertStringEndsWith( 'src/Lrs/Http/class-xapi-router.php', $c[0] );
		$this->assertStringEndsWith( 'src/Lrs/Http/interface-xapi-router.php', $c[1] );
		$i = \LearningLedger\autoload_candidates( 'LearningLedger\Integrations\Sensei\Sensei_Adapter' );
		$this->assertStringEndsWith( 'integrations/sensei/class-sensei-adapter.php', $i[0] );
	}
}
