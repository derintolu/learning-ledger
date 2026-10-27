<?php
/**
 * Answers satisfaction questions for one registration.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Cmi5;

/**
 * Satisfaction of AUs, blocks and the course under a waiver policy.
 *
 * Every AU, block and course has a satisfaction index: the position, in fact order, of the
 * fact that made it satisfied (-1 when satisfied without any fact, null when not satisfied).
 */
final class Satisfaction_Result {

	/**
	 * Waiver reason that counts as tested out.
	 */
	const TESTED_OUT = 'Tested Out';

	/**
	 * Course structure.
	 *
	 * @var Course_Structure
	 */
	private Course_Structure $s;

	/**
	 * Per-AU state from Satisfaction_Evaluator, keyed by AU id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $state;

	/**
	 * Statement ids by fact index.
	 *
	 * @var array<int,string>
	 */
	private array $statement_ids;

	/**
	 * Constructor.
	 *
	 * @param Course_Structure                  $s             Course structure.
	 * @param array<string,array<string,mixed>> $state         Per-AU state keyed by AU id.
	 * @param array<int,string>                 $statement_ids Statement ids by fact index.
	 */
	public function __construct( Course_Structure $s, array $state, array $statement_ids ) {
		$this->s             = $s;
		$this->state         = $state;
		$this->statement_ids = $statement_ids;
	}

	/**
	 * Whether the AU's moveOn is met, or it is waived and the policy counts the waiver.
	 *
	 * @param string $au_id  AU id.
	 * @param string $policy Waiver_Policy constant.
	 *
	 * @return boolean
	 */
	public function au_satisfied( string $au_id, string $policy = Waiver_Policy::ALL ): bool {
		$au = $this->s->find_au( $au_id );
		return $au && null !== $this->au_index( $au, $policy );
	}

	/**
	 * The AU's waiver, if it was waived.
	 *
	 * @param string $au_id AU id.
	 *
	 * @return array{reason:string,carried_over:bool,statement_id:string}|null
	 */
	public function au_waived( string $au_id ): ?array {
		return $this->state[ $au_id ]['waiver'] ?? null;
	}

	/**
	 * Id of the statement that satisfied the AU under Waiver_Policy::ALL, or null.
	 *
	 * @param string $au_id AU id.
	 *
	 * @return string|null
	 */
	public function au_satisfying_statement( string $au_id ): ?string {
		$au = $this->s->find_au( $au_id );
		return $au ? $this->statement_at( $this->au_index( $au, Waiver_Policy::ALL ) ) : null;
	}

	/**
	 * Whether every child of the block is satisfied.
	 *
	 * @param string $block_id Block id.
	 * @param string $policy   Waiver_Policy constant.
	 *
	 * @return boolean
	 */
	public function block_satisfied( string $block_id, string $policy = Waiver_Policy::ALL ): bool {
		$block = $this->s->find_block( $block_id );
		return $block && null !== $this->block_index( $block, $policy );
	}

	/**
	 * Whether every top-level child of the course is satisfied.
	 *
	 * @param string $policy Waiver_Policy constant.
	 *
	 * @return boolean
	 */
	public function course_satisfied( string $policy = Waiver_Policy::ALL ): bool {
		return null !== $this->list_index( $this->s->children(), $policy );
	}

	/**
	 * The block (nested ones included) whose satisfaction completed earliest in fact order.
	 * Ties go to the block that comes first in the document.
	 *
	 * @param string $policy Waiver_Policy constant.
	 *
	 * @return string|null
	 */
	public function first_satisfied_block( string $policy = Waiver_Policy::ALL ): ?string {
		$best    = null;
		$best_at = null;
		foreach ( $this->s->blocks() as $block ) {
			$at = $this->block_index( $block, $policy );
			if ( null !== $at && ( null === $best_at || $at < $best_at ) ) {
				$best    = $block->id();
				$best_at = $at;
			}
		}
		return $best;
	}

	/**
	 * Id of the statement that completed the block, or null.
	 *
	 * @param string $block_id Block id.
	 * @param string $policy   Waiver_Policy constant.
	 *
	 * @return string|null
	 */
	public function block_satisfying_statement( string $block_id, string $policy ): ?string {
		$block = $this->s->find_block( $block_id );
		return $block ? $this->statement_at( $this->block_index( $block, $policy ) ) : null;
	}

	/**
	 * Number of waived AUs under a block or the course.
	 *
	 * @param string  $block_or_course_id Block id or the course id.
	 * @param boolean $tested_out_only    Count only "Tested Out" waivers that are not carried over.
	 *
	 * @return integer
	 */
	public function waived_count( string $block_or_course_id, bool $tested_out_only = true ): int {
		if ( $this->s->course_id() === $block_or_course_id ) {
			$aus = $this->s->aus();
		} else {
			$block = $this->s->find_block( $block_or_course_id );
			$aus   = $block ? self::aus_under( $block ) : array();
		}
		$count = 0;
		foreach ( $aus as $au ) {
			$waiver = $this->au_waived( $au->id() );
			if ( null === $waiver ) {
				continue;
			}
			if ( ! $tested_out_only || ( self::TESTED_OUT === $waiver['reason'] && ! $waiver['carried_over'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * The latest scaled score per AU, in fact order.
	 *
	 * @return array<string,float>
	 */
	public function scores(): array {
		$out = array();
		foreach ( $this->s->aus() as $au ) {
			$score = $this->state[ $au->id() ]['score'] ?? null;
			if ( null !== $score ) {
				$out[ $au->id() ] = $score;
			}
		}
		return $out;
	}

	/**
	 * Satisfaction index of an AU.
	 *
	 * @param Au     $au     AU.
	 * @param string $policy Waiver_Policy constant.
	 *
	 * @return integer|null
	 */
	private function au_index( Au $au, string $policy ): ?int {
		$st        = $this->state[ $au->id() ] ?? array();
		$completed = $st['completed'] ?? null;
		$passed    = $st['passed'] ?? null;

		switch ( $au->move_on() ) {
			case 'Completed':
				$move_on = $completed;
				break;
			case 'Passed':
				$move_on = $passed;
				break;
			case 'CompletedAndPassed':
				$move_on = null === $completed || null === $passed ? null : max( $completed, $passed );
				break;
			case 'CompletedOrPassed':
				$move_on = self::earliest( $completed, $passed );
				break;
			default:
				return -1;
		}

		if ( Waiver_Policy::ALL === $policy ) {
			$waived = $st['waived'] ?? null;
		} elseif ( Waiver_Policy::EXCLUDE_CARRIED_OVER === $policy ) {
			$waived = $st['waived_eligible'] ?? null;
		} else {
			$waived = null;
		}
		return self::earliest( $move_on, $waived );
	}

	/**
	 * Satisfaction index of a block.
	 *
	 * @param Block  $block  Block.
	 * @param string $policy Waiver_Policy constant.
	 *
	 * @return integer|null
	 */
	private function block_index( Block $block, string $policy ): ?int {
		return $this->list_index( $block->children(), $policy );
	}

	/**
	 * Satisfaction index of a list of children: the latest child's, or null if any is unsatisfied.
	 *
	 * @param array<Au|Block> $children Children.
	 * @param string          $policy   Waiver_Policy constant.
	 *
	 * @return integer|null
	 */
	private function list_index( array $children, string $policy ): ?int {
		$at = -1;
		foreach ( $children as $child ) {
			$i = $child instanceof Au ? $this->au_index( $child, $policy ) : $this->block_index( $child, $policy );
			if ( null === $i ) {
				return null;
			}
			$at = max( $at, $i );
		}
		return $at;
	}

	/**
	 * Statement id at a fact index; null for null or -1.
	 *
	 * @param integer|null $index Fact index.
	 *
	 * @return string|null
	 */
	private function statement_at( ?int $index ): ?string {
		return null === $index ? null : ( $this->statement_ids[ $index ] ?? null );
	}

	/**
	 * Every AU under a block.
	 *
	 * @param Block $block Block.
	 *
	 * @return Au[]
	 */
	private static function aus_under( Block $block ): array {
		$out = array();
		foreach ( $block->children() as $child ) {
			if ( $child instanceof Au ) {
				$out[] = $child;
			} else {
				array_push( $out, ...self::aus_under( $child ) );
			}
		}
		return $out;
	}

	/**
	 * The smaller of two optional indexes.
	 *
	 * @param integer|null $a Index.
	 * @param integer|null $b Index.
	 *
	 * @return integer|null
	 */
	private static function earliest( ?int $a, ?int $b ): ?int {
		if ( null === $a ) {
			return $b;
		}
		return null === $b ? $a : min( $a, $b );
	}
}
