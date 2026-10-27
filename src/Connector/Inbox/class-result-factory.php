<?php
/**
 * Turns a registration's satisfaction into LMS results.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Inbox;

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Au_Fact;
use LearningLedger\Cmi5\Block;
use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Cmi5\Satisfaction_Result;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Cmi5\Waiver_Policy;
use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Programs\Program;
use LearningLedger\Util\Uuid;

/**
 * Yields only results moveOn supports and the apply ledger has not seen yet.
 */
final class Result_Factory {

	/**
	 * Apply ledger.
	 *
	 * @var Apply_Ledger
	 */
	private Apply_Ledger $ledger;

	/**
	 * Constructor.
	 *
	 * @param Apply_Ledger|null $ledger Apply ledger.
	 */
	public function __construct( ?Apply_Ledger $ledger = null ) {
		$this->ledger = $ledger ?? new Apply_Ledger();
	}

	/**
	 * Results for one registration, in document order: per AU its pass or fail, then its
	 * completion or waiver; then satisfied blocks; then the course.
	 *
	 * @param Program             $p            Program.
	 * @param Course_Structure    $s            Course structure.
	 * @param Satisfaction_Result $e            Evaluation under Waiver_Policy::ALL.
	 * @param integer             $user         User id.
	 * @param string              $registration Registration UUID.
	 * @param callable            $resolve      Maps an AU or block id to ?Lms_Object.
	 * @param Au_Fact[]           $facts        The facts $e was evaluated from, in stored order (for pass and fail scores).
	 *
	 * @return Result[]
	 */
	public function results( Program $p, Course_Structure $s, Satisfaction_Result $e, int $user, string $registration, callable $resolve, array $facts = array() ): array {
		$out   = array();
		$order = array();
		foreach ( array_values( $facts ) as $i => $fact ) {
			$order[ $fact->statement_id ] = $i;
		}

		foreach ( $s->aus() as $au ) {
			$target = $resolve( $au->id() );
			if ( ! $target instanceof Lms_Object ) {
				continue;
			}
			$score = $this->score_result( $au, $facts, $user, $target, $registration );
			if ( $score ) {
				$out[] = $score;
			}
			$done = $this->completion_result( $au, $e, $user, $target, $registration );
			if ( $done ) {
				$out[] = $done;
			}
		}

		foreach ( $s->blocks() as $block ) {
			if ( ! $e->block_satisfied( $block->id(), Waiver_Policy::ALL ) ) {
				continue;
			}
			$target = $resolve( $block->id() );
			if ( ! $target instanceof Lms_Object || $this->ledger->has( $user, $target, Result::BLOCK_SATISFIED ) ) {
				continue;
			}
			$sid   = $e->block_satisfying_statement( $block->id(), Waiver_Policy::ALL ) ?? self::synthetic_id( $registration, 'block:' . $block->id() );
			$out[] = new Result( Result::BLOCK_SATISFIED, $user, $target, $registration, $sid );
		}

		if ( $e->course_satisfied( Waiver_Policy::ALL ) ) {
			$target = new Lms_Object( 'program', $p->id );
			if ( ! $this->ledger->has( $user, $target, Result::COURSE_SATISFIED ) ) {
				$sid   = self::latest( $this->child_statements( $s->children(), $e ), $order ) ?? self::synthetic_id( $registration, 'course:' . $s->course_id() );
				$out[] = new Result( Result::COURSE_SATISFIED, $user, $target, $registration, $sid );
			}
		}

		return $out;
	}

	/**
	 * LESSON_PASSED from the first pass, else LESSON_FAILED from the latest fail.
	 *
	 * @param Au         $au           AU.
	 * @param Au_Fact[]  $facts        Facts.
	 * @param integer    $user         User id.
	 * @param Lms_Object $target       Lesson.
	 * @param string     $registration Registration UUID.
	 *
	 * @return Result|null
	 */
	private function score_result( Au $au, array $facts, int $user, Lms_Object $target, string $registration ): ?Result {
		$passed = null;
		$failed = null;
		foreach ( $facts as $fact ) {
			if ( ! $fact instanceof Au_Fact || $fact->au_id !== $au->id() ) {
				continue;
			}
			if ( Vocab::PASSED === $fact->verb ) {
				$passed ??= $fact;
			} elseif ( Vocab::FAILED === $fact->verb ) {
				$failed = $fact;
			}
		}

		if ( $passed ) {
			if ( $this->ledger->has( $user, $target, Result::LESSON_PASSED ) ) {
				return null;
			}
			return new Result( Result::LESSON_PASSED, $user, $target, $registration, $passed->statement_id, $passed->score_scaled );
		}
		if ( $failed && ! $this->ledger->has( $user, $target, Result::LESSON_PASSED ) && ! $this->ledger->has( $user, $target, Result::LESSON_FAILED ) ) {
			return new Result( Result::LESSON_FAILED, $user, $target, $registration, $failed->statement_id, $failed->score_scaled );
		}
		return null;
	}

	/**
	 * LESSON_WAIVED when the waiver satisfied the AU, else LESSON_COMPLETED; nothing while moveOn is unmet.
	 *
	 * @param Au                  $au           AU.
	 * @param Satisfaction_Result $e            Evaluation.
	 * @param integer             $user         User id.
	 * @param Lms_Object          $target       Lesson.
	 * @param string              $registration Registration UUID.
	 *
	 * @return Result|null
	 */
	private function completion_result( Au $au, Satisfaction_Result $e, int $user, Lms_Object $target, string $registration ): ?Result {
		if ( ! $e->au_satisfied( $au->id(), Waiver_Policy::ALL ) ) {
			return null;
		}
		if ( $this->ledger->has( $user, $target, Result::LESSON_COMPLETED ) || $this->ledger->has( $user, $target, Result::LESSON_WAIVED ) ) {
			return null;
		}
		$sid    = $e->au_satisfying_statement( $au->id() );
		$waiver = $e->au_waived( $au->id() );
		if ( null !== $waiver && null !== $sid && $sid === $waiver['statement_id'] ) {
			return new Result( Result::LESSON_WAIVED, $user, $target, $registration, $sid, null, $waiver['reason'], $waiver['carried_over'] );
		}
		return new Result( Result::LESSON_COMPLETED, $user, $target, $registration, $sid ?? self::synthetic_id( $registration, 'au:' . $au->id() ) );
	}

	/**
	 * Satisfying statement ids of a list of children.
	 *
	 * @param array<Au|Block>     $children Children.
	 * @param Satisfaction_Result $e        Evaluation.
	 *
	 * @return string[]
	 */
	private function child_statements( array $children, Satisfaction_Result $e ): array {
		$ids = array();
		foreach ( $children as $child ) {
			$sid = $child instanceof Au ? $e->au_satisfying_statement( $child->id() ) : $e->block_satisfying_statement( $child->id(), Waiver_Policy::ALL );
			if ( null !== $sid ) {
				$ids[] = $sid;
			}
		}
		return $ids;
	}

	/**
	 * The id that comes last in fact order.
	 *
	 * @param string[]          $ids   Statement ids.
	 * @param array<string,int> $order Fact index by statement id.
	 *
	 * @return string|null
	 */
	private static function latest( array $ids, array $order ): ?string {
		$best    = null;
		$best_at = -1;
		foreach ( $ids as $id ) {
			$at = $order[ $id ] ?? -1;
			if ( null === $best || $at > $best_at ) {
				$best    = $id;
				$best_at = $at;
			}
		}
		return $best;
	}

	/**
	 * A stable id for a result no statement caused (an AU or block satisfied without facts).
	 *
	 * @param string $registration Registration UUID.
	 * @param string $name         What was satisfied.
	 *
	 * @return string
	 */
	private static function synthetic_id( string $registration, string $name ): string {
		return Uuid::v5( Uuid::is_valid( $registration ) ? $registration : Uuid::from_bin( Uuid::NIL_BIN ), $name );
	}
}
