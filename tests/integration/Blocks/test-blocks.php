<?php
/**
 * Beat, checkpoint and rubric block tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Blocks\Blocks_Module;
use LearningLedger\Blocks\Markdown_Allowlist;

class Blocks_Test extends WP_UnitTestCase {

	const FIX = __DIR__ . '/fixtures';

	public function set_up() {
		parent::set_up();
		( new Blocks_Module() )->register_blocks();
	}

	private function fixture(): string {
		return (string) file_get_contents( self::FIX . '/blocks/checkpoint-with-secrets.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
	}

	public function test_registered() {
		foreach ( array( 'beat', 'checkpoint', 'rubric' ) as $b ) {
			$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( "learning-ledger/$b" ) );
		}
	}

	public function test_module_hooks_init() {
		$m = new Blocks_Module();
		$m->register();
		$this->assertNotFalse( has_action( 'init', array( $m, 'register_blocks' ) ) );
	}

	public function test_allowlist_constant() {
		$this->assertSame(
			array( 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/code', 'core/preformatted', 'core/quote', 'core/image' ),
			Markdown_Allowlist::BLOCKS
		);
	}

	public function test_rubric_is_child_of_checkpoint_only() {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'learning-ledger/rubric' );
		$this->assertSame( array( 'learning-ledger/checkpoint' ), $type->parent );
	}

	public function test_rendered_content_hides_secrets() {
		$post = self::factory()->post->create( array( 'post_content' => $this->fixture() ) );
		$res  = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/wp/v2/posts/$post" ) );
		$html = $res->get_data()['content']['rendered'];
		$this->assertStringContainsString( 'What does this print?', $html );
		foreach ( array( 'RUBRIC-SECRET', 'regex-secret', 'BRANCH-MESSAGE-SECRET', 'contains-secret', 'GOAL-SECRET' ) as $s ) {
			$this->assertStringNotContainsString( $s, $html );
		}
		$this->assertStringNotContainsString( 'RUBRIC-SECRET', apply_filters( 'the_content', get_post( $post )->post_content ) );
	}

	public function test_edit_context_needs_edit_post() {
		$post = self::factory()->post->create( array( 'post_content' => $this->fixture() ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$req = new WP_REST_Request( 'GET', "/wp/v2/posts/$post" );
		$req->set_param( 'context', 'edit' );
		$res = rest_get_server()->dispatch( $req );
		$this->assertContains( $res->get_status(), array( 401, 403 ) );
		$this->assertStringNotContainsString( 'RUBRIC-SECRET', wp_json_encode( $res->get_data() ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$res = rest_get_server()->dispatch( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertStringContainsString( 'RUBRIC-SECRET', $res->get_data()['content']['raw'] );
	}

	public function test_beat_hides_goal_shows_question() {
		$html = do_blocks( $this->fixture() );
		$this->assertStringContainsString( 'Why does it work?', $html );
		$this->assertStringContainsString( 'Intro paragraph.', $html );
		$this->assertStringNotContainsString( 'GOAL-SECRET', $html );
	}

	public function test_render_escapes_question() {
		$html = do_blocks( '<!-- wp:learning-ledger/checkpoint {"key":"k","question":"<script>alert(1)</script>"} /-->' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_rubric_renders_empty() {
		$this->assertSame( '', trim( do_blocks( '<!-- wp:learning-ledger/rubric {"text":"RUBRIC-SECRET"} /-->' ) ) );
	}
}
