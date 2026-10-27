<?php
/**
 * A cmi5 Assignable Unit.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Cmi5;

/**
 * Immutable AU from a course structure.
 */
final class Au {

	/**
	 * Field values, in to_array() shape.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Fields as produced by to_array().
	 */
	public function __construct( array $data ) {
		$this->data = array(
			'type'              => 'au',
			'id'                => (string) ( $data['id'] ?? '' ),
			'key'               => self::nullable_string( $data['key'] ?? null ),
			'role'              => self::nullable_string( $data['role'] ?? null ),
			'title'             => (array) ( $data['title'] ?? array() ),
			'description'       => (array) ( $data['description'] ?? array() ),
			'move_on'           => (string) ( $data['move_on'] ?? 'NotApplicable' ),
			'mastery_score'     => isset( $data['mastery_score'] ) ? (float) $data['mastery_score'] : null,
			'launch_method'     => (string) ( $data['launch_method'] ?? 'AnyWindow' ),
			'url'               => (string) ( $data['url'] ?? '' ),
			'launch_parameters' => self::nullable_string( $data['launch_parameters'] ?? null ),
			'entitlement_key'   => self::nullable_string( $data['entitlement_key'] ?? null ),
			'activity_type'     => self::nullable_string( $data['activity_type'] ?? null ),
			'parent_block_id'   => self::nullable_string( $data['parent_block_id'] ?? null ),
			'lesson_src'        => self::nullable_string( $data['lesson_src'] ?? null ),
		);
	}

	/**
	 * The activity id statements use for an AU: `{au@id}/au`.
	 *
	 * @param string $au_id AU publisher id.
	 *
	 * @return string
	 */
	public static function activity_id( string $au_id ): string {
		return $au_id . '/au';
	}

	/**
	 * Publisher id (au@id).
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->data['id'];
	}

	/**
	 * The cxm:key attribute.
	 *
	 * @return string|null
	 */
	public function key(): ?string {
		return $this->data['key'];
	}

	/**
	 * The cxm:role attribute.
	 *
	 * @return string|null
	 */
	public function role(): ?string {
		return $this->data['role'];
	}

	/**
	 * Title, keyed by language.
	 *
	 * @return array<string,string>
	 */
	public function title(): array {
		return $this->data['title'];
	}

	/**
	 * Description, keyed by language.
	 *
	 * @return array<string,string>
	 */
	public function description(): array {
		return $this->data['description'];
	}

	/**
	 * The moveOn criterion.
	 *
	 * @return string
	 */
	public function move_on(): string {
		return $this->data['move_on'];
	}

	/**
	 * The masteryScore, if any.
	 *
	 * @return float|null
	 */
	public function mastery_score(): ?float {
		return $this->data['mastery_score'];
	}

	/**
	 * AnyWindow or OwnWindow.
	 *
	 * @return string
	 */
	public function launch_method(): string {
		return $this->data['launch_method'];
	}

	/**
	 * Launch URL as written in the course structure.
	 *
	 * @return string
	 */
	public function url(): string {
		return $this->data['url'];
	}

	/**
	 * The launchParameters text, if any.
	 *
	 * @return string|null
	 */
	public function launch_parameters(): ?string {
		return $this->data['launch_parameters'];
	}

	/**
	 * The entitlementKey text, if any.
	 *
	 * @return string|null
	 */
	public function entitlement_key(): ?string {
		return $this->data['entitlement_key'];
	}

	/**
	 * The activityType attribute, if any.
	 *
	 * @return string|null
	 */
	public function activity_type(): ?string {
		return $this->data['activity_type'];
	}

	/**
	 * Id of the enclosing block, or null for an AU directly under the course.
	 *
	 * @return string|null
	 */
	public function parent_block_id(): ?string {
		return $this->data['parent_block_id'];
	}

	/**
	 * The src of the cxm:lesson element, if any.
	 *
	 * @return string|null
	 */
	public function lesson_src(): ?string {
		return $this->data['lesson_src'];
	}

	/**
	 * Plain array form.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Null for null or empty, otherwise the string.
	 *
	 * @param mixed $value Value.
	 *
	 * @return string|null
	 */
	private static function nullable_string( mixed $value ): ?string {
		return null === $value || '' === $value ? null : (string) $value;
	}
}
