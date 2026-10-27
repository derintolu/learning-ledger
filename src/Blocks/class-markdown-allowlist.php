<?php
/**
 * Blocks allowed inside a beat and exported as Markdown.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Blocks;

/**
 * Markdown-exportable core blocks.
 */
final class Markdown_Allowlist {

	public const BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/code',
		'core/preformatted',
		'core/quote',
		'core/image',
	);
}
