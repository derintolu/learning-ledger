<?php
/**
 * Lesson JSON to block markup.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

/**
 * Builds beat, checkpoint (with rubric) and planted-bug blocks from a lesson document.
 */
final class Lesson_Blocks_Builder {

	/**
	 * Builds block markup for a lesson.
	 *
	 * @param array<string,mixed> $lesson_json Decoded lesson JSON.
	 *
	 * @return string
	 */
	public static function build( array $lesson_json ): string {
		$blocks = array();

		foreach ( (array) ( $lesson_json['beats'] ?? array() ) as $beat ) {
			if ( ! is_array( $beat ) ) {
				continue;
			}
			$attrs = array(
				'id'   => (string) ( $beat['key'] ?? '' ),
				'goal' => (string) ( $beat['goal'] ?? '' ),
			);
			if ( isset( $beat['question'] ) ) {
				$attrs['question'] = (string) $beat['question'];
			}
			$content = (string) ( $beat['content'] ?? '' );
			// Blocks cannot hold the Markdown's final line break, so the beat remembers it.
			if ( str_ends_with( $content, "\n" ) ) {
				$attrs['trailingNewline'] = true;
			}
			$blocks[] = Markdown_To_Blocks::block( 'learning-ledger/beat', $attrs, Markdown_To_Blocks::convert( $content ) );
		}

		$checkpoint = $lesson_json['checkpoint'] ?? null;
		if ( is_array( $checkpoint ) ) {
			$attrs = array();
			foreach ( array( 'key', 'kind', 'question', 'file', 'maxAttempts', 'checks', 'branches', 'fallbackOptions' ) as $field ) {
				if ( array_key_exists( $field, $checkpoint ) ) {
					$attrs[ $field ] = $checkpoint[ $field ];
				}
			}
			$rubric   = Markdown_To_Blocks::block( 'learning-ledger/rubric', array( 'text' => (string) ( $checkpoint['rubric'] ?? '' ) ), '' );
			$blocks[] = Markdown_To_Blocks::block( 'learning-ledger/checkpoint', $attrs, $rubric );
		}

		$bug = $lesson_json['bug'] ?? null;
		if ( is_array( $bug ) ) {
			$attrs = array();
			foreach ( array( 'key', 'file', 'code', 'hint', 'revealAfter', 'explanation', 'fixChecks' ) as $field ) {
				if ( array_key_exists( $field, $bug ) ) {
					$attrs[ $field ] = $bug[ $field ];
				}
			}
			$blocks[] = Markdown_To_Blocks::block( 'learning-ledger/planted-bug', $attrs, '' );
		}

		return implode( "\n\n", $blocks );
	}
}
