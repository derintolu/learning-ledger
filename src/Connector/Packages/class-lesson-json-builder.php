<?php
/**
 * Builds a lesson's cxm/lessons/<key>.json document from its blocks.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;

/**
 * Beats, checkpoint, planted bug and lesson meta, in the standard's field names.
 */
final class Lesson_Json_Builder {

	/**
	 * Key under the lesson's `extensions` for data the schema has no field for.
	 */
	public const EXTENSION = 'https://github.com/derintolu/learning-ledger/extensions/lesson';

	private const MAX_META_BYTES = 1048576;

	/**
	 * Builds the lesson document.
	 *
	 * @param \WP_Post $lesson Lesson post.
	 *
	 * @return array{json:array,warnings:string[]}
	 */
	public static function build( \WP_Post $lesson ): array {
		$warnings = array();
		$beats    = array();
		$found    = array(
			'checkpoint' => null,
			'bug'        => null,
		);
		self::walk( parse_blocks( $lesson->post_content ), $beats, $found, $warnings, $lesson->ID );

		$json = array();
		$min  = (int) get_post_meta( $lesson->ID, '_lledger_minutes', true );
		if ( $min > 0 ) {
			$json['minutes'] = $min;
		}
		$sources = self::meta_rows( $lesson->ID, '_lledger_sources' );
		if ( $sources ) {
			$json['sources'] = $sources;
		}
		$starter = array();
		foreach ( self::meta_rows( $lesson->ID, '_lledger_starter_files' ) as $row ) {
			if ( isset( $row['path'], $row['content'] ) ) {
				$starter[ (string) $row['path'] ] = (string) $row['content'];
			}
		}
		if ( $starter ) {
			$json['starterFiles'] = $starter;
		}
		$json['beats'] = $beats;
		if ( null !== $found['bug'] ) {
			$json['bug'] = $found['bug'];
		}
		if ( null !== $found['checkpoint'] ) {
			$json['checkpoint'] = $found['checkpoint']['json'];
		}

		$extension = array();
		$licence   = (string) get_post_meta( $lesson->ID, '_lledger_licence', true );
		if ( '' !== $licence ) {
			$extension['license'] = $licence;
		}
		if ( null !== $found['checkpoint'] && $found['checkpoint']['fallback'] ) {
			$extension['fallbackOptions'] = $found['checkpoint']['fallback'];
		}
		if ( $extension ) {
			$json['extensions'] = array( self::EXTENSION => $extension );
		}

		return array(
			'json'     => $json,
			'warnings' => $warnings,
		);
	}

	/**
	 * Collects beats, the first checkpoint and the first planted bug, looking inside wrapper blocks.
	 *
	 * @param array    $blocks   Blocks.
	 * @param array    $beats    Beats, appended to.
	 * @param array    $found    Checkpoint and bug, set once.
	 * @param string[] $warnings Warnings, appended to.
	 * @param integer  $post_id  Lesson id.
	 *
	 * @return void
	 */
	private static function walk( array $blocks, array &$beats, array &$found, array &$warnings, int $post_id ): void {
		foreach ( $blocks as $block ) {
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = (array) ( $block['attrs'] ?? array() );
			switch ( $name ) {
				case 'learning-ledger/beat':
					$beats[] = self::beat( $block, count( $beats ) + 1, $warnings );
					break;
				case 'learning-ledger/checkpoint':
					if ( null === $found['checkpoint'] ) {
						$found['checkpoint'] = self::checkpoint( $block );
					} else {
						$warnings[] = sprintf( 'Lesson %d has more than one checkpoint; "%s" was left out.', $post_id, (string) ( $attrs['key'] ?? '' ) );
					}
					break;
				case 'learning-ledger/planted-bug':
					if ( null === $found['bug'] ) {
						$found['bug'] = self::bug( $attrs );
					} else {
						$warnings[] = sprintf( 'Lesson %d has more than one planted bug; "%s" was left out.', $post_id, (string) ( $attrs['key'] ?? '' ) );
					}
					break;
				case '':
					if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
						$warnings[] = sprintf( 'Skipped text outside any beat in lesson %d.', $post_id );
					}
					break;
				default:
					if ( 0 === strpos( $name, 'learning-ledger/' ) ) {
						break;
					}
					if ( ! empty( $block['innerBlocks'] ) ) {
						self::walk( $block['innerBlocks'], $beats, $found, $warnings, $post_id );
					} else {
						$warnings[] = sprintf( 'Skipped block %s outside any beat in lesson %d.', $name, $post_id );
					}
			}
		}
	}

	/**
	 * A beat.
	 *
	 * @param array    $block    Beat block.
	 * @param integer  $position One-based position.
	 * @param string[] $warnings Warnings, appended to.
	 *
	 * @return array
	 */
	private static function beat( array $block, int $position, array &$warnings ): array {
		$attrs = (array) ( $block['attrs'] ?? array() );
		$id    = (string) ( $attrs['id'] ?? '' );
		$key   = '' !== $id ? $id : 'beat-' . $position;
		$md    = Block_To_Markdown::convert( (array) ( $block['innerBlocks'] ?? array() ) );
		foreach ( $md['skipped'] as $name ) {
			$warnings[] = sprintf( 'Skipped block %s in beat "%s".', $name, $key );
		}
		$beat = array(
			'key'     => $key,
			'goal'    => (string) ( $attrs['goal'] ?? '' ),
			'content' => $md['markdown'],
		);
		if ( '' !== (string) ( $attrs['question'] ?? '' ) ) {
			$beat['question'] = (string) $attrs['question'];
		}
		return $beat;
	}

	/**
	 * A checkpoint.
	 *
	 * @param array $block Checkpoint block.
	 *
	 * @return array{json:array,fallback:array}
	 */
	private static function checkpoint( array $block ): array {
		$attrs  = (array) ( $block['attrs'] ?? array() );
		$rubric = '';
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $inner ) {
			if ( 'learning-ledger/rubric' === ( $inner['blockName'] ?? '' ) ) {
				$rubric = (string) ( $inner['attrs']['text'] ?? '' );
				break;
			}
		}
		$file = is_string( $attrs['file'] ?? null ) ? $attrs['file'] : '';
		$json = array(
			'key'      => (string) ( $attrs['key'] ?? '' ),
			'kind'     => (string) ( $attrs['kind'] ?? 'free-text' ),
			'question' => (string) ( $attrs['question'] ?? '' ),
			'rubric'   => $rubric,
		);
		if ( '' !== $file ) {
			$json['file'] = $file;
		}
		$checks = self::checks( (array) ( $attrs['checks'] ?? array() ), $file );
		if ( $checks ) {
			$json['checks'] = $checks;
		}
		$json['branches'] = self::branches( (array) ( $attrs['branches'] ?? array() ) );
		if ( is_int( $attrs['maxAttempts'] ?? null ) ) {
			$json['maxAttempts'] = $attrs['maxAttempts'];
		}
		return array(
			'json'     => $json,
			'fallback' => array_values( array_filter( (array) ( $attrs['fallbackOptions'] ?? array() ), 'is_array' ) ),
		);
	}

	/**
	 * A planted bug.
	 *
	 * @param array $attrs Block attributes.
	 *
	 * @return array
	 */
	private static function bug( array $attrs ): array {
		$file = (string) ( $attrs['file'] ?? '' );
		$bug  = array(
			'key'  => (string) ( $attrs['key'] ?? '' ),
			'file' => $file,
			'code' => (string) ( $attrs['code'] ?? '' ),
		);
		if ( '' !== (string) ( $attrs['hint'] ?? '' ) ) {
			$bug['hint'] = (string) $attrs['hint'];
		}
		if ( is_int( $attrs['revealAfter'] ?? null ) ) {
			$bug['revealAfter'] = $attrs['revealAfter'];
		}
		$bug['explanation'] = (string) ( $attrs['explanation'] ?? '' );
		$bug['fixChecks']   = self::checks( (array) ( $attrs['fixChecks'] ?? array() ), $file );
		return $bug;
	}

	/**
	 * Maps the editor's check rows (kind, value, pointer) to the schema's check objects.
	 *
	 * Rows already in the schema's shape pass through. A missing file is the owning block's file
	 * and a missing message is a generic one.
	 *
	 * @param array  $rows         Rows.
	 * @param string $default_file File of the owning block.
	 *
	 * @return array
	 */
	private static function checks( array $rows, string $default_file ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type  = (string) ( $row['type'] ?? $row['kind'] ?? '' );
			$check = array( 'type' => $type );
			if ( '' !== (string) ( $row['file'] ?? '' ) ) {
				$check['file'] = (string) $row['file'];
			} elseif ( '' !== $default_file ) {
				$check['file'] = $default_file;
			}
			switch ( $type ) {
				case 'regex':
					$check['pattern'] = (string) ( $row['pattern'] ?? $row['value'] ?? '' );
					if ( '' !== (string) ( $row['flags'] ?? '' ) ) {
						$check['flags'] = (string) $row['flags'];
					}
					break;
				case 'json':
					$check['pointer'] = (string) ( $row['pointer'] ?? '' );
					$check['equals']  = array_key_exists( 'equals', $row ) ? $row['equals'] : ( $row['value'] ?? '' );
					break;
				default:
					$check['value'] = (string) ( $row['value'] ?? '' );
			}
			$check['message'] = '' !== (string) ( $row['message'] ?? '' ) ? (string) $row['message'] : 'The ' . $type . ' check did not pass.';
			$out[]            = $check;
		}
		return $out;
	}

	/**
	 * Branch rows; an empty target is null.
	 *
	 * @param array $rows Rows.
	 *
	 * @return array
	 */
	private static function branches( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$to    = $row['to'] ?? null;
			$out[] = array(
				'key'     => (string) ( $row['key'] ?? '' ),
				'when'    => (string) ( $row['when'] ?? '' ),
				'success' => (bool) ( $row['success'] ?? false ),
				'to'      => is_string( $to ) && '' !== $to ? $to : null,
				'message' => (string) ( $row['message'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * A JSON list stored in post meta.
	 *
	 * @param integer $post_id Post id.
	 * @param string  $key     Meta key.
	 *
	 * @return array[]
	 */
	private static function meta_rows( int $post_id, string $key ): array {
		$raw = (string) get_post_meta( $post_id, $key, true );
		if ( '' === $raw ) {
			return array();
		}
		try {
			$data = Json::decode_strict( $raw, self::MAX_META_BYTES );
		} catch ( Invalid_Input $e ) {
			return array();
		}
		return is_array( $data ) && Json::is_list( $data ) ? array_values( array_filter( $data, 'is_array' ) ) : array();
	}
}
