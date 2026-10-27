<?php
/**
 * Writes shared by the package importers.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Block;
use LearningLedger\Connector\Programs\Program_Repository;

/**
 * Program upsert, content-hash bookkeeping and text helpers used by Package_Importer
 * and the cmi5 player importer.
 */
final class Import_Writer {

	/**
	 * Program meta holding the content hash and LMS id of each written course and lesson.
	 */
	public const HASH_META = '_lledger_import_hash';

	/**
	 * Programs.
	 *
	 * @var Program_Repository
	 */
	private Program_Repository $repo;

	/**
	 * Constructor.
	 *
	 * @param Program_Repository $repo Repository.
	 */
	public function __construct( Program_Repository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Finds the program by stable key or creates it, and keeps its title in step.
	 *
	 * @param string  $key     Stable key.
	 * @param string  $title   Title; sanitized here.
	 * @param boolean $created Set to whether the program was created.
	 *
	 * @return integer Post id, 0 on failure.
	 */
	public function program( string $key, string $title, bool &$created = false ): int {
		$title   = sanitize_text_field( $title );
		$created = false;
		$id      = $this->repo->find_by_key( $key );
		if ( null === $id ) {
			$new = wp_insert_post(
				wp_slash(
					array(
						'post_type'   => Program_Repository::POST_TYPE,
						'post_status' => 'publish',
						'post_title'  => $title,
					)
				),
				true
			);
			if ( is_wp_error( $new ) || 0 === (int) $new ) {
				return 0;
			}
			$id      = (int) $new;
			$created = true;
			update_post_meta( $id, '_lledger_stable_key', $key );
		} elseif ( get_post_field( 'post_title', $id, 'raw' ) !== $title ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'         => $id,
						'post_title' => $title,
					)
				)
			);
		}
		return $id;
	}

	/**
	 * Stored hashes.
	 *
	 * @param integer $program_id Program id.
	 *
	 * @return array<string,array{h:string,id:int}>
	 */
	public function hashes( int $program_id ): array {
		$decoded = json_decode( (string) get_post_meta( $program_id, self::HASH_META, true ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Stores hashes.
	 *
	 * @param integer $program_id Program id.
	 * @param array   $hashes     Hashes.
	 *
	 * @return void
	 */
	public function save_hashes( int $program_id, array $hashes ): void {
		update_post_meta( $program_id, self::HASH_META, wp_slash( (string) wp_json_encode( $hashes ) ) );
	}

	/**
	 * Runs the write for an item unless the stored hash matches, then records the new hash.
	 *
	 * @param integer  $program_id Program id.
	 * @param array    $hashes     Hashes, updated in place.
	 * @param string   $ref        Item ref.
	 * @param string   $hash       Content hash.
	 * @param string[] $created    Created refs, appended to.
	 * @param string[] $updated    Updated refs, appended to.
	 * @param callable $write      Performs the upsert and returns the LMS id.
	 *
	 * @return integer LMS id.
	 */
	public function write_item( int $program_id, array &$hashes, string $ref, string $hash, array &$created, array &$updated, callable $write ): int {
		$known = $hashes[ $ref ] ?? null;
		if ( is_array( $known ) && ( $known['h'] ?? '' ) === $hash && (int) ( $known['id'] ?? 0 ) > 0 ) {
			return (int) $known['id'];
		}
		$id = (int) $write();
		if ( null === $known ) {
			$created[] = $ref;
		} else {
			$updated[] = $ref;
		}
		$hashes[ $ref ] = array(
			'h'  => $hash,
			'id' => $id,
		);
		$this->save_hashes( $program_id, $hashes );
		return $id;
	}

	/**
	 * Every AU under a block, nested blocks flattened, in document order.
	 *
	 * @param Block $block Block.
	 *
	 * @return Au[]
	 */
	public static function flatten( Block $block ): array {
		$aus = array();
		foreach ( $block->children() as $child ) {
			if ( $child instanceof Block ) {
				$aus = array_merge( $aus, self::flatten( $child ) );
			} elseif ( $child instanceof Au ) {
				$aus[] = $child;
			}
		}
		return $aus;
	}

	/**
	 * Description map of a block.
	 *
	 * @param Block $block Block.
	 *
	 * @return array<string,string>
	 */
	public static function description_of( Block $block ): array {
		$data = $block->to_array();
		return (array) ( $data['description'] ?? array() );
	}

	/**
	 * A paragraph block, or nothing for empty text.
	 *
	 * @param string $text Plain text.
	 *
	 * @return string
	 */
	public static function paragraph( string $text ): string {
		return '' === trim( $text ) ? '' : Markdown_To_Blocks::block( 'core/paragraph', array(), '<p>' . esc_html( $text ) . '</p>' );
	}

	/**
	 * English text, else the first language, else the fallback.
	 *
	 * @param array<string,string> $langstrings Language to text.
	 * @param string               $fallback    Fallback.
	 *
	 * @return string
	 */
	public static function pick( array $langstrings, string $fallback ): string {
		$text = $langstrings['en'] ?? ( $langstrings ? reset( $langstrings ) : '' );
		return '' !== trim( (string) $text ) ? (string) $text : $fallback;
	}

	/**
	 * Last path segment of an IRI.
	 *
	 * @param string $iri IRI.
	 *
	 * @return string
	 */
	public static function last_segment( string $iri ): string {
		$path = (string) wp_parse_url( $iri, PHP_URL_PATH );
		return basename( rtrim( $path, '/' ) );
	}

	/**
	 * A validator-shaped error.
	 *
	 * @param string $code    Code.
	 * @param string $path    Path.
	 * @param string $message Message.
	 *
	 * @return array{code:string,path:string,message:string}
	 */
	public static function error( string $code, string $path, string $message ): array {
		return array(
			'code'    => $code,
			'path'    => $path,
			'message' => $message,
		);
	}
}
