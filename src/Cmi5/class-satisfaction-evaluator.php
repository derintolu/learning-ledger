<?php
/**
 * Evaluates cmi5 moveOn over a course structure.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Cmi5;

/**
 * Reduces AU facts to the per-AU state a Satisfaction_Result answers from.
 *
 * Only completed, passed, failed and waived facts are inputs. Block and course `satisfied`
 * statements are informational and are recomputed, never trusted.
 */
final class Satisfaction_Evaluator {

	/**
	 * Evaluates facts, which must be in stored order.
	 *
	 * @param Course_Structure $s     Course structure.
	 * @param iterable         $facts Au_Fact objects in stored order.
	 *
	 * @return Satisfaction_Result
	 */
	public static function evaluate( Course_Structure $s, iterable $facts ): Satisfaction_Result {
		$state         = array();
		$statement_ids = array();
		$i             = 0;
		foreach ( $facts as $fact ) {
			if ( ! $fact instanceof Au_Fact || ! $s->find_au( $fact->au_id ) ) {
				continue;
			}
			$statement_ids[ $i ] = $fact->statement_id;
			$au                  = $state[ $fact->au_id ] ?? array(
				'completed'       => null,
				'passed'          => null,
				'waived'          => null,
				'waived_eligible' => null,
				'waiver'          => null,
				'score'           => null,
			);

			if ( Vocab::COMPLETED === $fact->verb ) {
				$au['completed'] ??= $i;
			} elseif ( Vocab::PASSED === $fact->verb ) {
				$au['passed'] ??= $i;
			} elseif ( Vocab::WAIVED === $fact->verb ) {
				if ( null === $au['waived'] ) {
					$au['waived'] = $i;
					$au['waiver'] = array(
						'reason'       => (string) $fact->reason,
						'carried_over' => $fact->carried_over,
						'statement_id' => $fact->statement_id,
					);
				}
				if ( ! $fact->carried_over ) {
					$au['waived_eligible'] ??= $i;
				}
			}
			if ( null !== $fact->score_scaled ) {
				$au['score'] = $fact->score_scaled;
			}

			$state[ $fact->au_id ] = $au;
			++$i;
		}

		return new Satisfaction_Result( $s, $state, $statement_ids );
	}
}
