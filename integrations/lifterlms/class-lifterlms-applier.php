<?php
/**
 * LifterLMS direction 1: apply LRS results.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Lifterlms;

use LearningLedger\Connector\Ledger\Echo_Guard;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Util\Clock;

/**
 * Applies results as LifterLMS lesson and course completion, and waivers,
 * through `llms_mark_complete()` only.
 */
final class Lifterlms_Applier {

	/**
	 * Trigger passed to LifterLMS for every write the connector makes. LifterLMS
	 * stores it with the completion or enrolment and passes it to the
	 * `llms_mark_complete` hooks of the whole cascade, which labels these writes
	 * as the connector's.
	 */
	const TRIGGER = 'learning-ledger';

	const PROGRAM_SATISFIED = '_lledger_program_satisfied_';

	/**
	 * Apply one result.
	 *
	 * @param Result $r Result.
	 *
	 * @return Apply_Outcome
	 */
	public function apply( Result $r ): Apply_Outcome {
		if ( $r->user_id <= 0 ) {
			return new Apply_Outcome( Apply_Outcome::FAILED, 'no user' );
		}
		if ( Result::COURSE_SATISFIED === $r->type ) {
			return $this->program_satisfied( $r );
		}

		$course = $this->course_of( $r );
		if ( $course <= 0 ) {
			return new Apply_Outcome( Apply_Outcome::FAILED, 'not a LifterLMS object for this result' );
		}
		if ( ! llms_is_user_enrolled( $r->user_id, $course ) ) {
			if ( empty( Connector_Settings::get()['allow_auto_enrol'] ) ) {
				return new Apply_Outcome( Apply_Outcome::DEFERRED, 'learner not enrolled' );
			}
			if ( ! ( new Lifterlms_Adapter() )->enrol( $r->user_id, $course ) ) {
				return new Apply_Outcome( Apply_Outcome::FAILED, 'enrolment failed' );
			}
		}

		$changed = Echo_Guard::while_applying( fn(): bool => $this->apply_to_lifterlms( $r, $course ) );
		return new Apply_Outcome( $changed ? Apply_Outcome::APPLIED : Apply_Outcome::SKIPPED );
	}

	/**
	 * The LifterLMS course a lesson or block result belongs to.
	 *
	 * @param Result $r Result.
	 *
	 * @return integer 0 when the object does not fit the result.
	 */
	private function course_of( Result $r ): int {
		$o = $r->object;
		if ( Result::BLOCK_SATISFIED === $r->type ) {
			return 'course' === $o->kind && llms_get_post( $o->id ) instanceof \LLMS_Course ? $o->id : 0;
		}
		return 'lesson' === $o->kind ? Lifterlms_Adapter::course_of_lesson( $o->id ) : 0;
	}

	/**
	 * Write a lesson or block result to LifterLMS.
	 *
	 * @param Result  $r      Result.
	 * @param integer $course Course ID.
	 *
	 * @return boolean Whether anything changed.
	 */
	private function apply_to_lifterlms( Result $r, int $course ): bool {
		$user   = $r->user_id;
		$lesson = $r->object->id;
		switch ( $r->type ) {
			case Result::LESSON_COMPLETED:
				$changed = $this->complete( $user, $lesson, 'lesson' );
				$waivers = new Waiver_Ledger();
				if ( $waivers->is_waived( $user, $lesson ) ) {
					$waivers->clear( $user, $lesson );
					$changed = true;
				}
				return $changed;

			case Result::LESSON_WAIVED:
				// LifterLMS has no waived state: complete the lesson and record the waiver.
				$changed = $this->complete( $user, $lesson, 'lesson' );
				$waivers = new Waiver_Ledger();
				$waiver  = $waivers->get( $user, $lesson );
				if ( null === $waiver || $waiver['statement_id'] !== $r->statement_id ) {
					$waivers->record( $user, $lesson, $r->registration, $r->statement_id, (string) $r->reason, $r->carried_over );
					$changed = true;
				}
				return $changed;

			case Result::LESSON_PASSED:
			case Result::LESSON_FAILED:
				// The score lives in the apply ledger, not in LifterLMS's quiz attempts.
				return true;

			case Result::BLOCK_SATISFIED:
				// LifterLMS completes a course only once all of its lessons are complete.
				return $this->complete( $user, $course, 'course' );
		}
		return false;
	}

	/**
	 * Mark a lesson or course complete unless LifterLMS already has it complete.
	 *
	 * @param integer $user Student ID.
	 * @param integer $id   Lesson or course ID.
	 * @param string  $type Object type, `lesson` or `course`.
	 *
	 * @return boolean Whether it became complete now.
	 */
	private function complete( int $user, int $id, string $type ): bool {
		if ( llms_is_complete( $user, $id, $type ) ) {
			return false;
		}
		llms_mark_complete( $user, $id, $type, self::TRIGGER );
		return llms_is_complete( $user, $id, $type );
	}

	/**
	 * Mark a program satisfied for the user, once.
	 *
	 * @param Result $r Result.
	 *
	 * @return Apply_Outcome
	 */
	private function program_satisfied( Result $r ): Apply_Outcome {
		if ( 'program' !== $r->object->kind || $r->object->id <= 0 ) {
			return new Apply_Outcome( Apply_Outcome::FAILED, 'not a program' );
		}
		$key = self::PROGRAM_SATISFIED . $r->object->id;
		if ( '' !== (string) get_user_meta( $r->user_id, $key, true ) ) {
			return new Apply_Outcome( Apply_Outcome::SKIPPED );
		}
		update_user_meta( $r->user_id, $key, Clock::now()->getTimestamp() );
		return new Apply_Outcome( Apply_Outcome::APPLIED );
	}
}
