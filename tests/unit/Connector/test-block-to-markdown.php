<?php
/**
 * Block_To_Markdown tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Packages\Block_To_Markdown;

class Block_To_Markdown_Test extends \PHPUnit\Framework\TestCase {

	/**
	 * A parsed-block array as parse_blocks() returns it.
	 *
	 * @param string $name   Block name.
	 * @param string $html   Inner HTML.
	 * @param array  $attrs  Attributes.
	 * @param array  $inner  Inner blocks.
	 * @param array  $chunks Inner content (strings and nulls); defaults to the HTML.
	 *
	 * @return array
	 */
	private function block( string $name, string $html, array $attrs = array(), array $inner = array(), ?array $chunks = null ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $html,
			'innerContent' => $chunks ?? array( $html ),
		);
	}

	private function md( array $blocks ): string {
		$r = Block_To_Markdown::convert( $blocks );
		$this->assertSame( array(), $r['skipped'] );
		return $r['markdown'];
	}

	public function test_paragraph_with_inline_formatting() {
		$p = $this->block( 'core/paragraph', '<p>Use <strong>double</strong> quotes, <em>always</em>, in <code>"key"</code> and <a href="https://example.org/x">the spec</a>.</p>' );
		$this->assertSame( 'Use **double** quotes, *always*, in `"key"` and [the spec](https://example.org/x).', $this->md( array( $p ) ) );
	}

	public function test_paragraphs_are_separated_by_a_blank_line() {
		$a = $this->block( 'core/paragraph', '<p>One &amp; two.</p>' );
		$b = $this->block( 'core/paragraph', '<p>Three.</p>' );
		$this->assertSame( "One & two.\n\nThree.", $this->md( array( $a, $b ) ) );
	}

	public function test_heading_levels() {
		$h2 = $this->block( 'core/heading', '<h2 class="wp-block-heading">Two</h2>' );
		$h4 = $this->block( 'core/heading', '<h4 class="wp-block-heading">Four</h4>', array( 'level' => 4 ) );
		$this->assertSame( "## Two\n\n#### Four", $this->md( array( $h2, $h4 ) ) );
	}

	public function test_nested_list() {
		$child = $this->block(
			'core/list',
			'<ul></ul>',
			array(),
			array(
				$this->block( 'core/list-item', '<li>Nested</li>' ),
			),
			array( '<ul>', null, '</ul>' )
		);
		$one   = $this->block( 'core/list-item', '<li>One</li>', array(), array( $child ), array( '<li>One', null, '</li>' ) );
		$two   = $this->block( 'core/list-item', '<li>Two</li>' );
		$list  = $this->block( 'core/list', '<ul></ul>', array(), array( $one, $two ), array( '<ul>', null, null, '</ul>' ) );
		$this->assertSame( "- One\n  - Nested\n- Two", $this->md( array( $list ) ) );
	}

	public function test_ordered_list() {
		$a    = $this->block( 'core/list-item', '<li>First</li>' );
		$b    = $this->block( 'core/list-item', '<li>Second</li>' );
		$list = $this->block( 'core/list', '<ol></ol>', array( 'ordered' => true ), array( $a, $b ), array( '<ol>', null, null, '</ol>' ) );
		$this->assertSame( "1. First\n2. Second", $this->md( array( $list ) ) );
	}

	public function test_code_with_language() {
		$code = $this->block( 'core/code', '<pre class="wp-block-code"><code>{"port": 8080}' . "\n" . '&lt;b&gt;</code></pre>', array( 'language' => 'json' ) );
		$this->assertSame( "```json\n{\"port\": 8080}\n<b>\n```", $this->md( array( $code ) ) );
	}

	public function test_code_language_from_class_name_and_longer_fence() {
		$code = $this->block( 'core/code', '<pre class="wp-block-code"><code>a ``` b</code></pre>', array( 'className' => 'language-js' ) );
		$this->assertSame( "````js\na ``` b\n````", $this->md( array( $code ) ) );
	}

	public function test_code_ignores_the_line_breaks_around_its_pre() {
		$code = $this->block( 'core/code', "\n" . '<pre class="wp-block-code language-php"><code>echo 1;</code></pre>' . "\n", array( 'className' => 'language-php' ) );
		$this->assertSame( "```php\necho 1;\n```", $this->md( array( $code ) ) );
	}

	public function test_preformatted_becomes_a_fence() {
		$pre = $this->block( 'core/preformatted', '<pre class="wp-block-preformatted">line one<br>line two</pre>' );
		$this->assertSame( "```\nline one\nline two\n```", $this->md( array( $pre ) ) );
	}

	public function test_quote_with_citation() {
		$inner = $this->block( 'core/paragraph', '<p>Be liberal.</p>' );
		$quote = $this->block( 'core/quote', '<blockquote class="wp-block-quote"><cite>Postel</cite></blockquote>', array(), array( $inner ), array( '<blockquote class="wp-block-quote">', null, '<cite>Postel</cite></blockquote>' ) );
		$this->assertSame( "> Be liberal.\n>\n> - Postel", $this->md( array( $quote ) ) );
	}

	public function test_image_with_alt() {
		$img = $this->block(
			'core/image',
			'<figure class="wp-block-image"><img src="https://example.org/a.png" alt="A diagram"/><figcaption>Cap</figcaption></figure>',
			array(
				'url' => 'https://example.org/a.png',
				'alt' => 'A diagram',
			)
		);
		$this->assertSame( '![A diagram](https://example.org/a.png)', $this->md( array( $img ) ) );
	}

	public function test_image_alt_read_from_markup_when_not_in_attributes() {
		$img = $this->block( 'core/image', '<figure class="wp-block-image"><img src="https://example.org/b.png" alt="From markup"/></figure>' );
		$this->assertSame( '![From markup](https://example.org/b.png)', $this->md( array( $img ) ) );
	}

	public function test_blocks_outside_the_allowlist_are_skipped_by_name() {
		$p     = $this->block( 'core/paragraph', '<p>Keep.</p>' );
		$table = $this->block( 'core/table', '<figure><table></table></figure>' );
		$r     = Block_To_Markdown::convert( array( $p, $table, $this->block( 'core/video', '<figure></figure>' ) ) );
		$this->assertSame( 'Keep.', $r['markdown'] );
		$this->assertSame( array( 'core/table', 'core/video' ), $r['skipped'] );
	}

	public function test_whitespace_only_freeform_blocks_are_ignored() {
		$blank = array(
			'blockName'    => null,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => "\n\n",
			'innerContent' => array( "\n\n" ),
		);
		$this->assertSame( 'Hi.', $this->md( array( $blank, $this->block( 'core/paragraph', '<p>Hi.</p>' ) ) ) );
	}
}
