<?php
/**
 * A cmi5 block.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Cmi5;

/**
 * Immutable block from a course structure.
 */
final class Block {

	/**
	 * Publisher id.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * The cxm:key attribute.
	 *
	 * @var string|null
	 */
	private ?string $key;

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
	 * Enclosing block id.
	 *
	 * @var string|null
	 */
	private ?string $parent_block_id;

	/**
	 * Child AUs and blocks in document order.
	 *
	 * @var array<Au|Block>
	 */
	private array $children;

	/**
	 * Constructor.
	 *
	 * @param string               $id              Publisher id.
	 * @param string|null          $key             The cxm:key attribute.
	 * @param array<string,string> $title           Title by language.
	 * @param array<string,string> $description     Description by language.
	 * @param string|null          $parent_block_id Enclosing block id.
	 * @param array<Au|Block>      $children        Children in document order.
	 */
	public function __construct( string $id, ?string $key, array $title, array $description, ?string $parent_block_id, array $children ) {
		$this->id              = $id;
		$this->key             = '' === $key ? null : $key;
		$this->title           = $title;
		$this->description     = $description;
		$this->parent_block_id = '' === $parent_block_id ? null : $parent_block_id;
		$this->children        = array_values( $children );
	}

	/**
	 * Builds a block from its to_array() form.
	 *
	 * @param array<string,mixed> $data Data.
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) $data['id'],
			isset( $data['key'] ) ? (string) $data['key'] : null,
			(array) ( $data['title'] ?? array() ),
			(array) ( $data['description'] ?? array() ),
			isset( $data['parent_block_id'] ) ? (string) $data['parent_block_id'] : null,
			Course_Structure::children_from_array( (array) ( $data['children'] ?? array() ) )
		);
	}

	/**
	 * Publisher id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The cxm:key attribute.
	 *
	 * @return string|null
	 */
	public function key(): ?string {
		return $this->key;
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
	 * Child AUs and blocks in document order.
	 *
	 * @return array<Au|Block>
	 */
	public function children(): array {
		return $this->children;
	}

	/**
	 * Id of the enclosing block, or null for a top-level block.
	 *
	 * @return string|null
	 */
	public function parent_block_id(): ?string {
		return $this->parent_block_id;
	}

	/**
	 * Plain array form.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'type'            => 'block',
			'id'              => $this->id,
			'key'             => $this->key,
			'title'           => $this->title,
			'description'     => $this->description,
			'parent_block_id' => $this->parent_block_id,
			'children'        => array_map( static fn( $c ) => $c->to_array(), $this->children ),
		);
	}
}
