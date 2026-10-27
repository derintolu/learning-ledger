<?php
/**
 * A cmi5 defined statement reduced to what satisfaction needs.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Cmi5;

/**
 * One AU-level fact from a stored statement.
 */
final class Au_Fact {

	/**
	 * Constructor.
	 *
	 * @param string       $au_id        AU publisher id.
	 * @param string       $verb         Verb IRI.
	 * @param string       $statement_id Statement id.
	 * @param string       $stored       Stored timestamp.
	 * @param boolean|null $success      The result.success value.
	 * @param boolean|null $completion   The result.completion value.
	 * @param float|null   $score_scaled The result.score.scaled value.
	 * @param string|null  $reason       The cmi5 reason extension.
	 * @param boolean      $carried_over Whether the carried-over extension is present.
	 */
	public function __construct(
		public readonly string $au_id,
		public readonly string $verb,
		public readonly string $statement_id,
		public readonly string $stored,
		public readonly ?bool $success = null,
		public readonly ?bool $completion = null,
		public readonly ?float $score_scaled = null,
		public readonly ?string $reason = null,
		public readonly bool $carried_over = false
	) {}

	/**
	 * Builds a fact from a cmi5 defined statement about an AU of the structure.
	 *
	 * The AU matches through a grouping activity equal to au@id, or an object id equal to `{au@id}/au`.
	 * Statements without a cmi5 verb or the cmi5 category activity (cmi5 allowed statements) give null.
	 *
	 * @param array            $statement Statement as an associative array.
	 * @param Course_Structure $s         Course structure.
	 *
	 * @return self|null
	 */
	public static function from_statement( array $statement, Course_Structure $s ): ?self {
		$verb = $statement['verb']['id'] ?? null;
		if ( ! is_string( $verb ) || ! in_array( $verb, Vocab::VERBS, true ) ) {
			return null;
		}
		$activities = $statement['context']['contextActivities'] ?? array();
		if ( ! is_array( $activities ) || ! in_array( Vocab::CAT_CMI5, self::ids( $activities['category'] ?? array() ), true ) ) {
			return null;
		}

		$au = null;
		foreach ( self::ids( $activities['grouping'] ?? array() ) as $id ) {
			$au = $s->find_au( $id );
			if ( $au ) {
				break;
			}
		}
		$object_id = $statement['object']['id'] ?? '';
		if ( ! $au && is_string( $object_id ) && str_ends_with( $object_id, '/au' ) ) {
			$au = $s->find_au( substr( $object_id, 0, -3 ) );
		}
		if ( ! $au ) {
			return null;
		}

		$result     = is_array( $statement['result'] ?? null ) ? $statement['result'] : array();
		$extensions = is_array( $result['extensions'] ?? null ) ? $result['extensions'] : array();
		$scaled     = $result['score']['scaled'] ?? null;
		$reason     = $extensions[ Vocab::EXT_REASON ] ?? null;

		return new self(
			$au->id(),
			$verb,
			(string) ( $statement['id'] ?? '' ),
			(string) ( $statement['stored'] ?? '' ),
			is_bool( $result['success'] ?? null ) ? $result['success'] : null,
			is_bool( $result['completion'] ?? null ) ? $result['completion'] : null,
			is_int( $scaled ) || is_float( $scaled ) ? (float) $scaled : null,
			is_string( $reason ) ? $reason : null,
			array_key_exists( Vocab::CXM_EXT_CARRIED_OVER, $extensions )
		);
	}

	/**
	 * Ids of a context activity list (a single activity object is accepted too).
	 *
	 * @param mixed $activities Activity list.
	 *
	 * @return string[]
	 */
	private static function ids( mixed $activities ): array {
		if ( ! is_array( $activities ) ) {
			return array();
		}
		if ( isset( $activities['id'] ) ) {
			$activities = array( $activities );
		}
		$ids = array();
		foreach ( $activities as $activity ) {
			if ( is_array( $activity ) && isset( $activity['id'] ) && is_string( $activity['id'] ) ) {
				$ids[] = $activity['id'];
			}
		}
		return $ids;
	}
}
