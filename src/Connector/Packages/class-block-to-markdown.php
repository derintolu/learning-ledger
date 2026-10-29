<?php
/**
 * Converts allowlisted core blocks to CommonMark.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API properties are camelCase.

use LearningLedger\Blocks\Markdown_Allowlist;

/**
 * Turns parsed blocks into Markdown. Anything outside the allowlist is skipped and named.
 */
final class Block_To_Markdown {

	/**
	 * Converts blocks.
	 *
	 * @param array $blocks Blocks as parse_blocks() returns them.
	 *
	 * @return array{markdown:string,skipped:string[]}
	 */
	public static function convert( array $blocks ): array {
		$skipped = array();
		$parts   = self::blocks( $blocks, $skipped );
		return array(
			'markdown' => implode( "\n\n", $parts ),
			'skipped'  => $skipped,
		);
	}

	/**
	 * Converts a list of blocks to Markdown chunks.
	 *
	 * @param array    $blocks  Blocks.
	 * @param string[] $skipped Names of skipped blocks, appended to.
	 *
	 * @return string[]
	 */
	private static function blocks( array $blocks, array &$skipped ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( null === $name || '' === $name ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					$skipped[] = 'core/freeform';
				}
				continue;
			}
			if ( ! in_array( $name, Markdown_Allowlist::BLOCKS, true ) ) {
				$skipped[] = $name;
				continue;
			}
			$md = self::block( $block, $skipped );
			if ( '' !== $md ) {
				$out[] = $md;
			}
		}
		return $out;
	}

	/**
	 * Converts one allowlisted block.
	 *
	 * @param array    $block   Block.
	 * @param string[] $skipped Skipped names, appended to.
	 *
	 * @return string
	 */
	private static function block( array $block, array &$skipped ): string {
		$html  = (string) ( $block['innerHTML'] ?? '' );
		$attrs = (array) ( $block['attrs'] ?? array() );
		switch ( $block['blockName'] ) {
			case 'core/paragraph':
				return self::inline( $html );
			case 'core/heading':
				$level = max( 1, min( 6, (int) ( $attrs['level'] ?? 2 ) ) );
				return str_repeat( '#', $level ) . ' ' . self::inline( $html );
			case 'core/list':
				return self::list_block( $block, $skipped, 0 );
			case 'core/list-item':
				return '- ' . self::item_text( $block );
			case 'core/code':
				return self::fence( self::code_text( $html ), self::language( $attrs ) );
			case 'core/preformatted':
				return self::fence( self::code_text( $html ), '' );
			case 'core/quote':
				return self::quote( $block, $skipped );
			default:
				return self::image( $html, $attrs );
		}
	}

	/**
	 * A list with its items, indented by depth.
	 *
	 * @param array    $block   List block.
	 * @param string[] $skipped Skipped names, appended to.
	 * @param integer  $depth   Nesting depth.
	 *
	 * @return string
	 */
	private static function list_block( array $block, array &$skipped, int $depth ): string {
		$attrs   = (array) ( $block['attrs'] ?? array() );
		$ordered = ! empty( $attrs['ordered'] );
		$items   = (array) ( $block['innerBlocks'] ?? array() );
		$pad     = str_repeat( $ordered ? '   ' : '  ', $depth );
		$lines   = array();
		$n       = (int) ( $attrs['start'] ?? 1 );

		if ( ! $items ) {
			// Pre-6.0 markup keeps the items in the HTML.
			$dom = self::dom( (string) ( $block['innerHTML'] ?? '' ) );
			foreach ( $dom->getElementsByTagName( 'li' ) as $li ) {
				$lines[] = $pad . ( $ordered ? ( $n++ ) . '. ' : '- ' ) . self::inline_node( $li );
			}
			return implode( "\n", $lines );
		}

		foreach ( $items as $item ) {
			$name = $item['blockName'] ?? '';
			if ( 'core/list-item' !== $name ) {
				$skipped[] = (string) $name;
				continue;
			}
			$lines[] = $pad . ( $ordered ? ( $n++ ) . '. ' : '- ' ) . self::item_text( $item );
			foreach ( (array) ( $item['innerBlocks'] ?? array() ) as $nested ) {
				if ( 'core/list' === ( $nested['blockName'] ?? '' ) ) {
					$lines[] = self::list_block( $nested, $skipped, $depth + 1 );
				} else {
					$skipped[] = (string) ( $nested['blockName'] ?? '' );
				}
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Text of a list item, without its nested list.
	 *
	 * @param array $item List item block.
	 *
	 * @return string
	 */
	private static function item_text( array $item ): string {
		$content = (array) ( $item['innerContent'] ?? array( $item['innerHTML'] ?? '' ) );
		$html    = implode( '', array_filter( $content, 'is_string' ) );
		return self::inline( $html );
	}

	/**
	 * A fenced code block whose fence is longer than any backtick run inside.
	 *
	 * @param string $code Code.
	 * @param string $lang Language.
	 *
	 * @return string
	 */
	private static function fence( string $code, string $lang ): string {
		$longest = 0;
		if ( preg_match_all( '/`+/', $code, $runs ) ) {
			foreach ( $runs[0] as $run ) {
				$longest = max( $longest, strlen( $run ) );
			}
		}
		$fence = str_repeat( '`', max( 3, $longest + 1 ) );
		return $fence . $lang . "\n" . $code . "\n" . $fence;
	}

	/**
	 * Code language from the attribute or a language-* class.
	 *
	 * @param array $attrs Attributes.
	 *
	 * @return string
	 */
	private static function language( array $attrs ): string {
		$lang = (string) ( $attrs['language'] ?? '' );
		if ( '' === $lang && 1 === preg_match( '/(?:^|\s)language-([A-Za-z0-9_+#-]+)/', (string) ( $attrs['className'] ?? '' ), $m ) ) {
			$lang = $m[1];
		}
		return 1 === preg_match( '/^[A-Za-z0-9_+#-]+$/', $lang ) ? $lang : '';
	}

	/**
	 * Plain text of a pre/code element, line breaks kept.
	 *
	 * @param string $html HTML.
	 *
	 * @return string
	 */
	private static function code_text( string $html ): string {
		// Only what is inside the pre element: the block's own line breaks around it are not code.
		if ( 1 === preg_match( '#<pre\b[^>]*>(.*)</pre>#is', $html, $m ) ) {
			$html = $m[1];
		}
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$text = strip_tags( (string) $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Pure class, no WordPress available; the output is Markdown text, not HTML.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return rtrim( $text, "\n" );
	}

	/**
	 * A quote: inner blocks prefixed with "> ", then an optional citation.
	 *
	 * @param array    $block   Quote block.
	 * @param string[] $skipped Skipped names, appended to.
	 *
	 * @return string
	 */
	private static function quote( array $block, array &$skipped ): string {
		$parts = self::blocks( (array) ( $block['innerBlocks'] ?? array() ), $skipped );
		$html  = implode( '', array_filter( (array) ( $block['innerContent'] ?? array() ), 'is_string' ) );
		if ( ! $parts ) {
			$dom = self::dom( (string) ( $block['innerHTML'] ?? '' ) );
			foreach ( $dom->getElementsByTagName( 'p' ) as $p ) {
				$parts[] = self::inline_node( $p );
			}
		}
		if ( 1 === preg_match( '#<cite>(.*?)</cite>#s', $html, $m ) ) {
			$cite = self::inline( $m[1] );
			if ( '' !== $cite ) {
				$parts[] = '- ' . $cite;
			}
		}
		$lines = explode( "\n", implode( "\n\n", $parts ) );
		return implode(
			"\n",
			array_map( static fn( string $l ): string => '' === $l ? '>' : '> ' . $l, $lines )
		);
	}

	/**
	 * An image as Markdown.
	 *
	 * @param string $html  Inner HTML.
	 * @param array  $attrs Attributes.
	 *
	 * @return string
	 */
	private static function image( string $html, array $attrs ): string {
		$src = (string) ( $attrs['url'] ?? '' );
		$alt = isset( $attrs['alt'] ) ? (string) $attrs['alt'] : null;
		$dom = self::dom( $html );
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			if ( '' === $src ) {
				$src = $img->getAttribute( 'src' );
			}
			if ( null === $alt ) {
				$alt = $img->getAttribute( 'alt' );
			}
			break;
		}
		if ( '' === $src ) {
			return '';
		}
		return '![' . str_replace( array( '[', ']' ), array( '\\[', '\\]' ), (string) $alt ) . '](' . $src . ')';
	}

	/**
	 * Inline Markdown of an HTML fragment's text content.
	 *
	 * @param string $html HTML.
	 *
	 * @return string
	 */
	private static function inline( string $html ): string {
		$dom  = self::dom( $html );
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		return $body ? trim( self::inline_children( $body ) ) : '';
	}

	/**
	 * Inline Markdown of an element's children.
	 *
	 * @param \DOMNode $node Element.
	 *
	 * @return string
	 */
	private static function inline_node( \DOMNode $node ): string {
		return trim( self::inline_children( $node ) );
	}

	/**
	 * Inline Markdown of a node's children.
	 *
	 * @param \DOMNode $node Node.
	 *
	 * @return string
	 */
	private static function inline_children( \DOMNode $node ): string {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				$out .= preg_replace( '/\s+/', ' ', $child->data );
				continue;
			}
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$inner = self::inline_children( $child );
			switch ( strtolower( $child->tagName ) ) {
				case 'strong':
				case 'b':
					$out .= '' === trim( $inner ) ? '' : '**' . trim( $inner ) . '**';
					break;
				case 'em':
				case 'i':
					$out .= '' === trim( $inner ) ? '' : '*' . trim( $inner ) . '*';
					break;
				case 'code':
					$out .= '' === $inner ? '' : '`' . $inner . '`';
					break;
				case 'a':
					$href = $child->getAttribute( 'href' );
					$out .= '' === $href ? $inner : '[' . $inner . '](' . $href . ')';
					break;
				case 'br':
					$out .= "  \n";
					break;
				case 'ul':
				case 'ol':
					break;
				default:
					$out .= $inner;
			}
		}
		return $out;
	}

	/**
	 * Parses an HTML fragment as UTF-8 without touching the network.
	 *
	 * @param string $html HTML fragment.
	 *
	 * @return \DOMDocument
	 */
	private static function dom( string $html ): \DOMDocument {
		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $dom;
	}
}
