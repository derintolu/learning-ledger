<?php
/**
 * A parsed cmi5 course structure.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Cmi5;

use LearningLedger\Util\Invalid_Input;

/**
 * Immutable course tree with lookups by id and cxm:key.
 */
final class Course_Structure {

	/**
	 * Course publisher id.
	 *
	 * @var string
	 */
	private string $course_id;

	/**
	 * Title, keyed by language.
	 *
	 * @var array<string,string>
	 */
	private array $title;

	/**
	 * Description, keyed by language.
	 *
	 * @var array<string,string>
	 */
	private array $description;

	/**
	 * Top-level AUs and blocks.
	 *
	 * @var array<Au|Block>
	 */
	private array $children;

	/**
	 * The whitespace-trimmed XML.
	 *
	 * @var string
	 */
	private string $xml;

	/**
	 * AUs by id, in document order.
	 *
	 * @var array<string,Au>
	 */
	private array $aus = array();

	/**
	 * Blocks by id, in document order (a block before its descendants).
	 *
	 * @var array<string,Block>
	 */
	private array $blocks = array();

	/**
	 * Constructor.
	 *
	 * @param string               $course_id   Course publisher id.
	 * @param array<string,string> $title       Title by language.
	 * @param array<string,string> $description Description by language.
	 * @param array<Au|Block>      $children    Top-level children in document order.
	 * @param string               $xml         The whitespace-trimmed XML.
	 */
	public function __construct( string $course_id, array $title, array $description, array $children, string $xml ) {
		$this->course_id   = $course_id;
		$this->title       = $title;
		$this->description = $description;
		$this->children    = array_values( $children );
		$this->xml         = $xml;
		$this->index( $this->children );
	}

	/**
	 * Course publisher id.
	 *
	 * @return string
	 */
	public function course_id(): string {
		return $this->course_id;
	}

	/**
	 * Title, keyed by language.
	 *
	 * @return array<string,string>
	 */
	public function title(): array {
		return $this->title;
	}

	/**
	 * Top-level AUs and blocks in document order.
	 *
	 * @return array<Au|Block>
	 */
	public function children(): array {
		return $this->children;
	}

	/**
	 * Every AU in document order.
	 *
	 * @return Au[]
	 */
	public function aus(): array {
		return array_values( $this->aus );
	}

	/**
	 * Every block, nested ones included, in document order.
	 *
	 * @return Block[]
	 */
	public function blocks(): array {
		return array_values( $this->blocks );
	}

	/**
	 * AU by publisher id.
	 *
	 * @param string $id AU id.
	 *
	 * @return Au|null
	 */
	public function find_au( string $id ): ?Au {
		return $this->aus[ $id ] ?? null;
	}

	/**
	 * Block by publisher id.
	 *
	 * @param string $id Block id.
	 *
	 * @return Block|null
	 */
	public function find_block( string $id ): ?Block {
		return $this->blocks[ $id ] ?? null;
	}

	/**
	 * AU by cxm:key.
	 *
	 * @param string $key Key.
	 *
	 * @return Au|null
	 */
	public function au_by_key( string $key ): ?Au {
		foreach ( $this->aus as $au ) {
			if ( $au->key() === $key ) {
				return $au;
			}
		}
		return null;
	}

	/**
	 * The whitespace-trimmed XML, extension elements included.
	 *
	 * @return string
	 */
	public function xml(): string {
		return $this->xml;
	}

	/**
	 * Plain array form, suitable for JSON storage.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'course_id'   => $this->course_id,
			'title'       => $this->title,
			'description' => $this->description,
			'children'    => array_map( static fn( $c ) => $c->to_array(), $this->children ),
			'xml'         => $this->xml,
		);
	}

	/**
	 * Rebuilds a structure from to_array().
	 *
	 * @param array<string,mixed> $data Data.
	 *
	 * @throws Invalid_Input When a required field is missing.
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		if ( ! isset( $data['course_id'], $data['children'] ) || ! is_array( $data['children'] ) ) {
			throw new Invalid_Input( 'Course structure array needs course_id and children.', 'invalid', 'course_id' );
		}
		return new self(
			(string) $data['course_id'],
			(array) ( $data['title'] ?? array() ),
			(array) ( $data['description'] ?? array() ),
			self::children_from_array( $data['children'] ),
			(string) ( $data['xml'] ?? '' )
		);
	}

	/**
	 * Rebuilds a child list from its array form.
	 *
	 * @param array<int,array<string,mixed>> $children Children.
	 *
	 * @return array<Au|Block>
	 *
	 * @throws Invalid_Input On an unknown child type.
	 */
	public static function children_from_array( array $children ): array {
		$out = array();
		foreach ( $children as $child ) {
			$type = is_array( $child ) ? ( $child['type'] ?? '' ) : '';
			if ( 'au' === $type ) {
				$out[] = new Au( $child );
			} elseif ( 'block' === $type ) {
				$out[] = Block::from_array( $child );
			} else {
				throw new Invalid_Input( 'Course structure child must be an au or a block.', 'invalid', 'children' );
			}
		}
		return $out;
	}

	/**
	 * Indexes AUs and blocks in document order.
	 *
	 * @param array<Au|Block> $children Children.
	 *
	 * @return void
	 */
	private function index( array $children ): void {
		foreach ( $children as $child ) {
			if ( $child instanceof Au ) {
				$this->aus[ $child->id() ] = $child;
			} else {
				$this->blocks[ $child->id() ] = $child;
				$this->index( $child->children() );
			}
		}
	}
}
