<?php
/**
 * Planted-bug block tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Blocks\Blocks_Module;

class Planted_Bug_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		( new Blocks_Module() )->register_blocks();
	}

	private function markup(): string {
		$attrs = array(
			'key'         => 'bug-1',
			'file'        => 'app/main.js',
			'code'        => 'let total = a - b; // <b>visible</b>',
			'hint'        => 'HINT-SECRET',
			'revealAfter' => 3,
			'explanation' => 'EXPLANATION-SECRET',
			'fixChecks'   => array(
				array(
					'kind'  => 'contains',
					'value' => 'FIXCHECK-SECRET',
				),
			),
		);
		return '<!-- wp:learning-ledger/planted-bug ' . wp_json_encode( $attrs ) . ' /-->';
	}

	public function test_registered() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'learning-ledger/planted-bug' ) );
	}

	public function test_attributes_declared() {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'learning-ledger/planted-bug' );
		foreach ( array( 'key', 'file', 'code', 'hint', 'revealAfter', 'explanation', 'fixChecks' ) as $a ) {
			$this->assertArrayHasKey( $a, $type->attributes );
		}
	}

	public function test_render_shows_file_and_escaped_code_only() {
		$html = do_blocks( $this->markup() );
		$this->assertStringContainsString( 'app/main.js', $html );
		$this->assertStringContainsString( 'let total = a - b;', $html );
		$this->assertStringContainsString( '&lt;b&gt;visible&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>visible</b>', $html );
		foreach ( array( 'EXPLANATION-SECRET', 'FIXCHECK-SECRET', 'HINT-SECRET' ) as $s ) {
			$this->assertStringNotContainsString( $s, $html );
		}
	}

	public function test_public_rest_content_hides_secrets() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = self::factory()->post->create( array( 'post_content' => $this->markup() ) );
		wp_set_current_user( 0 );
		$res  = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/wp/v2/posts/$post" ) );
		$html = $res->get_data()['content']['rendered'];
		$this->assertStringContainsString( 'let total = a - b;', $html );
		$this->assertStringNotContainsString( 'EXPLANATION-SECRET', $html );
		$this->assertStringNotContainsString( 'FIXCHECK-SECRET', $html );
	}
}
