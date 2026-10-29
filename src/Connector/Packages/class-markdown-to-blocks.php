<?php
/**
 * Markdown to core block markup.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

use LearningLedger\Vendor\League\CommonMark\Environment\Environment;
use LearningLedger\Vendor\League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use LearningLedger\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use LearningLedger\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use LearningLedger\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use LearningLedger\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use LearningLedger\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use LearningLedger\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use LearningLedger\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use LearningLedger\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use LearningLedger\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use LearningLedger\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use LearningLedger\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use LearningLedger\Vendor\League\CommonMark\Node\Block\Paragraph;
use LearningLedger\Vendor\League\CommonMark\Node\Inline\Newline;
use LearningLedger\Vendor\League\CommonMark\Node\Inline\Text;
use LearningLedger\Vendor\League\CommonMark\Node\Node;
use LearningLedger\Vendor\League\CommonMark\Parser\MarkdownParser;

/**
 * Turns the Markdown of a beat into core blocks. Raw HTML is dropped, text is escaped and only
 * safe link and image protocols are kept, so the result needs no further trust in the package.
 * Uses no WordPress function, so it runs in plain unit tests.
 */
final class Markdown_To_Blocks {

	/**
	 * Converts Markdown to block markup.
	 *
	 * @param string $markdown Markdown.
	 *
	 * @return string Block markup, empty when there is nothing to show.
	 */
	public static function convert( string $markdown ): string {
		$env = new Environment( array() );
		$env->addExtension( new CommonMarkCoreExtension() );
		$document = ( new MarkdownParser( $env ) )->parse( $markdown );
		return implode( "\n\n", self::blocks( $document ) );
	}

	/**
	 * One serialized block: comment delimiters around already-built inner markup.
	 *
	 * @param string              $name  Block name, for example core/paragraph.
	 * @param array<string,mixed> $attrs Attributes.
	 * @param string              $inner Inner markup.
	 *
	 * @return string
	 */
	public static function block( string $name, array $attrs, string $inner ): string {
		$short = 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		$json  = '';
		if ( $attrs ) {
			$json = json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
			$bs   = chr( 92 );
			$json = str_replace(
				array( '--', '<', '>', '&', $bs . '"' ),
				array( $bs . 'u002d' . $bs . 'u002d', $bs . 'u003c', $bs . 'u003e', $bs . 'u0026', $bs . 'u0022' ),
				$json
			);
			$json = ' ' . $json;
		}
		if ( '' === $inner ) {
			return '<!-- wp:' . $short . $json . ' /-->';
		}
		return '<!-- wp:' . $short . $json . " -->\n" . $inner . "\n<!-- /wp:" . $short . ' -->';
	}

	/**
	 * Blocks for the block-level children of a node.
	 *
	 * @param Node $container Container node.
	 *
	 * @return string[]
	 */
	private static function blocks( Node $container ): array {
		$out = array();
		foreach ( $container->children() as $node ) {
			$block = self::one( $node );
			if ( '' !== $block ) {
				$out[] = $block;
			}
		}
		return $out;
	}

	/**
	 * One block-level node.
	 *
	 * @param Node $node Node.
	 *
	 * @return string Empty for dropped nodes (HTML, thematic breaks).
	 */
	private static function one( Node $node ): string {
		if ( $node instanceof Paragraph ) {
			$image = self::sole_image( $node );
			if ( null !== $image ) {
				return self::image_block( $image );
			}
			$html = self::inline( $node );
			return '' === trim( $html ) ? '' : self::block( 'core/paragraph', array(), '<p>' . $html . '</p>' );
		}
		if ( $node instanceof Heading ) {
			$level = $node->getLevel();
			return self::block(
				'core/heading',
				2 === $level ? array() : array( 'level' => $level ),
				'<h' . $level . ' class="wp-block-heading">' . self::inline( $node ) . '</h' . $level . '>'
			);
		}
		if ( $node instanceof ListBlock ) {
			return self::list_block( $node );
		}
		if ( $node instanceof FencedCode || $node instanceof IndentedCode ) {
			// The fence's language is kept as a language-* class, which Block_To_Markdown reads back.
			$info  = $node instanceof FencedCode ? (string) ( $node->getInfoWords()[0] ?? '' ) : '';
			$lang  = 1 === preg_match( '/^[A-Za-z0-9_+#-]+$/', $info ) ? $info : '';
			$class = '' === $lang ? 'wp-block-code' : 'wp-block-code language-' . $lang;
			return self::block( 'core/code', '' === $lang ? array() : array( 'className' => 'language-' . $lang ), '<pre class="' . $class . '"><code>' . self::esc( rtrim( $node->getLiteral(), "\n" ) ) . '</code></pre>' );
		}
		if ( $node instanceof BlockQuote ) {
			$inner = implode( "\n\n", self::blocks( $node ) );
			return '' === $inner ? '' : self::block( 'core/quote', array(), '<blockquote class="wp-block-quote">' . "\n" . $inner . "\n" . '</blockquote>' );
		}
		return '';
	}

	/**
	 * A list and its items.
	 *
	 * @param ListBlock $list_node List node.
	 *
	 * @return string
	 */
	private static function list_block( ListBlock $list_node ): string {
		$data    = $list_node->getListData();
		$ordered = ListBlock::TYPE_ORDERED === $data->type;
		$attrs   = array();
		$tag     = $ordered ? 'ol' : 'ul';
		$start   = '';
		if ( $ordered ) {
			$attrs['ordered'] = true;
			$first            = (int) ( $data->start ?? 1 );
			if ( 1 !== $first ) {
				$attrs['start'] = $first;
				$start          = ' start="' . $first . '"';
			}
		}
		$items = array();
		foreach ( $list_node->children() as $item ) {
			if ( ! $item instanceof ListItem ) {
				continue;
			}
			$text   = '';
			$nested = array();
			foreach ( $item->children() as $child ) {
				if ( $child instanceof Paragraph ) {
					$text .= ( '' === $text ? '' : '<br>' ) . self::inline( $child );
				} elseif ( $child instanceof ListBlock ) {
					$nested[] = self::list_block( $child );
				} else {
					$other = self::one( $child );
					if ( '' !== $other ) {
						$nested[] = $other;
					}
				}
			}
			$items[] = self::block( 'core/list-item', array(), '<li>' . $text . implode( '', $nested ) . '</li>' );
		}
		return self::block( 'core/list', $attrs, '<' . $tag . $start . ' class="wp-block-list">' . "\n" . implode( "\n", $items ) . "\n" . '</' . $tag . '>' );
	}

	/**
	 * The image when a paragraph holds nothing else.
	 *
	 * @param Paragraph $p Paragraph.
	 *
	 * @return Image|null
	 */
	private static function sole_image( Paragraph $p ): ?Image {
		$children = $p->children();
		if ( 1 === count( $children ) && $children[0] instanceof Image && '' !== self::safe_url( $children[0]->getUrl() ) ) {
			return $children[0];
		}
		return null;
	}

	/**
	 * A core/image block.
	 *
	 * @param Image $image Image node.
	 *
	 * @return string
	 */
	private static function image_block( Image $image ): string {
		return self::block( 'core/image', array(), '<figure class="wp-block-image">' . self::img( $image ) . '</figure>' );
	}

	/**
	 * An img tag, empty when the source is not safe.
	 *
	 * @param Image $image Image node.
	 *
	 * @return string
	 */
	private static function img( Image $image ): string {
		$src = self::safe_url( $image->getUrl() );
		if ( '' === $src ) {
			return '';
		}
		return '<img src="' . self::esc( $src ) . '" alt="' . self::esc( self::plain( $image ) ) . '"/>';
	}

	/**
	 * Inline HTML of a node's children.
	 *
	 * @param Node $container Container node.
	 *
	 * @return string
	 */
	private static function inline( Node $container ): string {
		$html = '';
		foreach ( $container->children() as $node ) {
			if ( $node instanceof Text ) {
				$html .= self::esc( $node->getLiteral() );
			} elseif ( $node instanceof Newline ) {
				$html .= Newline::HARDBREAK === $node->getType() ? '<br>' : "\n";
			} elseif ( $node instanceof Code ) {
				$html .= '<code>' . self::esc( $node->getLiteral() ) . '</code>';
			} elseif ( $node instanceof Emphasis ) {
				$html .= '<em>' . self::inline( $node ) . '</em>';
			} elseif ( $node instanceof Strong ) {
				$html .= '<strong>' . self::inline( $node ) . '</strong>';
			} elseif ( $node instanceof Link ) {
				$href  = self::safe_url( $node->getUrl() );
				$inner = self::inline( $node );
				$html .= '' === $href ? $inner : '<a href="' . self::esc( $href ) . '">' . $inner . '</a>';
			} elseif ( $node instanceof Image ) {
				$html .= self::img( $node );
			}
			// Anything else (raw inline HTML) is dropped.
		}
		return $html;
	}

	/**
	 * Plain text of a node's descendants.
	 *
	 * @param Node $container Container node.
	 *
	 * @return string
	 */
	private static function plain( Node $container ): string {
		$text = '';
		foreach ( $container->children() as $node ) {
			if ( $node instanceof Text || $node instanceof Code ) {
				$text .= $node->getLiteral();
			} else {
				$text .= self::plain( $node );
			}
		}
		return $text;
	}

	/**
	 * A URL when it is relative or http, https or mailto; otherwise an empty string.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 */
	private static function safe_url( string $url ): string {
		$url = (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 1 === preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $url, $m ) ) {
			return in_array( strtolower( $m[1] ), array( 'http', 'https', 'mailto' ), true ) ? $url : '';
		}
		return $url;
	}

	/**
	 * HTML-escapes text.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	private static function esc( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
