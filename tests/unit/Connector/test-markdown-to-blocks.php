<?php
/**
 * Markdown_To_Blocks tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Packages\Markdown_To_Blocks;

class Markdown_To_Blocks_Test extends \PHPUnit\Framework\TestCase {

	public function test_paragraph_with_inline_marks() {
		$out = Markdown_To_Blocks::convert( 'Use *double* and **strong** and `code`.' );
		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>Use <em>double</em> and <strong>strong</strong> and <code>code</code>.</p>\n<!-- /wp:paragraph -->",
			$out
		);
	}

	public function test_heading_levels() {
		$out = Markdown_To_Blocks::convert( "## Two\n\n### Three" );
		$this->assertStringContainsString( "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Two</h2>\n<!-- /wp:heading -->", $out );
		$this->assertStringContainsString( "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Three</h3>\n<!-- /wp:heading -->", $out );
	}

	public function test_nested_list_uses_list_item_blocks() {
		$out = Markdown_To_Blocks::convert( "- one\n  - inner\n- two" );
		$this->assertSame( 2, substr_count( $out, '<!-- wp:list -->' ) );
		$this->assertSame( 3, substr_count( $out, '<!-- wp:list-item -->' ) );
		$this->assertStringContainsString( '<li>one<!-- wp:list -->', $out );
		$this->assertStringContainsString( '<li>two</li>', $out );
	}

	public function test_ordered_list() {
		$out = Markdown_To_Blocks::convert( "1. a\n2. b" );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true} -->', $out );
		$this->assertStringContainsString( '<ol class="wp-block-list">', $out );
	}

	public function test_code_with_language_is_escaped() {
		$out = Markdown_To_Blocks::convert( "```json\n{\"a\": \"<b>\"}\n```" );
		$this->assertStringContainsString( '<!-- wp:code -->', $out );
		$this->assertStringContainsString( '<pre class="wp-block-code"><code>{&quot;a&quot;: &quot;&lt;b&gt;&quot;}</code></pre>', $out );
	}

	public function test_quote_wraps_inner_blocks() {
		$out = Markdown_To_Blocks::convert( '> Quoted text' );
		$this->assertStringContainsString( '<!-- wp:quote -->', $out );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote">', $out );
		$this->assertStringContainsString( "<!-- wp:paragraph -->\n<p>Quoted text</p>", $out );
	}

	public function test_image_with_alt() {
		$out = Markdown_To_Blocks::convert( '![A diagram](https://example.org/a.png)' );
		$this->assertStringContainsString( '<!-- wp:image -->', $out );
		$this->assertStringContainsString( '<img src="https://example.org/a.png" alt="A diagram"/>', $out );
	}

	public function test_raw_html_is_dropped() {
		$out = Markdown_To_Blocks::convert( "<script>alert(1)</script>\n\nHello <b onclick=\"x()\">there</b>\n\n<div>block</div>" );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringNotContainsString( 'onclick', $out );
		$this->assertStringNotContainsString( '<div', $out );
		$this->assertStringNotContainsString( '<b', $out );
		$this->assertStringContainsString( 'Hello there', $out );
	}

	public function test_unsafe_link_protocols_are_removed() {
		$out = Markdown_To_Blocks::convert( '[x](javascript:alert(1)) [y](https://example.org/?a=1&b=2) [z](mailto:a@example.org)' );
		$this->assertStringNotContainsString( 'javascript', $out );
		$this->assertStringContainsString( '<a href="https://example.org/?a=1&amp;b=2">y</a>', $out );
		$this->assertStringContainsString( '<a href="mailto:a@example.org">z</a>', $out );
	}

	public function test_unsafe_image_source_is_removed() {
		$out = Markdown_To_Blocks::convert( '![x](data:text/html;base64,AAAA)' );
		$this->assertStringNotContainsString( 'data:', $out );
	}

	public function test_empty_markdown_gives_empty_string() {
		$this->assertSame( '', Markdown_To_Blocks::convert( "  \n" ) );
	}

	public function test_block_serializer_escapes_attribute_json() {
		$out = Markdown_To_Blocks::block( 'learning-ledger/beat', array( 'goal' => 'a --> <b> & "q"' ), '' );
		$this->assertSame( 1, substr_count( $out, '-->' ) );
		$this->assertStringContainsString( chr( 92 ) . 'u003e', $out );
		$this->assertStringContainsString( chr( 92 ) . 'u0026', $out );
	}
}
