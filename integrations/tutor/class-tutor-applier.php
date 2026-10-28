<?php
/**
 * Tutor LMS direction 1: apply LRS results.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Tutor;

use LearningLedger\Connector\Ledger\Echo_Guard;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Util\Clock;

/**
 * Applies results as Tutor lesson completion, waivers and course completion,
 * through Tutor's public models only.
 */
final class Tutor_Applier {

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
			return new Apply_Outcome( Apply_Outcome::FAILED, 'not a Tutor object for this result' );
		}
		if ( ! tutor_utils()->is_enrolled( $course, $r->user_id ) ) {
			if ( empty( Connector_Settings::get()['allow_auto_enrol'] ) ) {
				return new Apply_Outcome( Apply_Outcome::DEFERRED, 'learner not enrolled' );
			}
			if ( ! ( new Tutor_Adapter() )->enrol( $r->user_id, $course ) ) {
				return new Apply_Outcome( Apply_Outcome::FAILED, 'enrolment failed' );
			}
		}

		$changed = Echo_Guard::while_applying( fn(): bool => $this->apply_to_tutor( $r, $course ) );
		return new Apply_Outcome( $changed ? Apply_Outcome::APPLIED : Apply_Outcome::SKIPPED );
	}

	/**
	 * The Tutor course a lesson or block result belongs to.
	 *
	 * @param Result $r Result.
	 *
	 * @return integer 0 when the object does not fit the result.
	 */
	private function course_of( Result $r ): int {
		$o = $r->object;
		if ( Result::BLOCK_SATISFIED === $r->type ) {
			return 'course' === $o->kind && Tutor_Adapter::COURSE === get_post_type( $o->id ) ? $o->id : 0;
		}
		if ( 'lesson' !== $o->kind || Tutor_Adapter::LESSON !== get_post_type( $o->id ) ) {
			return 0;
		}
		return Tutor_Adapter::course_of( $o->id );
	}

	/**
	 * Write a lesson or block result to Tutor.
	 *
	 * @param Result  $r      Result.
	 * @param integer $course Course ID.
	 *
	 * @return boolean Whether anything changed.
	 */
	private function apply_to_tutor( Result $r, int $course ): bool {
		$user   = $r->user_id;
		$lesson = $r->object->id;
		switch ( $r->type ) {
			case Result::LESSON_COMPLETED:
				$changed = $this->complete_lesson( $user, $lesson );
				$waivers = new Waiver_Ledger();
				if ( $waivers->is_waived( $user, $lesson ) ) {
					$waivers->clear( $user, $lesson );
					$changed = true;
				}
				return $changed;

			case Result::LESSON_WAIVED:
				$changed = $this->complete_lesson( $user, $lesson );
				$waivers = new Waiver_Ledger();
				$waiver  = $waivers->get( $user, $lesson );
				if ( null === $waiver || $waiver['statement_id'] !== $r->statement_id ) {
					$waivers->record( $user, $lesson, $r->registration, $r->statement_id, (string) $r->reason, $r->carried_over );
					$changed = true;
				}
				return $changed;

			case Result::LESSON_PASSED:
			case Result::LESSON_FAILED:
				// The score lives in the apply ledger, not in Tutor's quiz attempts.
				return true;

			case Result::BLOCK_SATISFIED:
				if ( tutor_utils()->is_completed_course( $course, $user, false ) ) {
					return false;
				}
				return (bool) \Tutor\Models\CourseModel::mark_course_as_completed( $course, $user );
		}
		return false;
	}

	/**
	 * Complete a lesson unless Tutor already has it complete.
	 *
	 * @param integer $user   User ID.
	 * @param integer $lesson Lesson ID.
	 *
	 * @return boolean Whether the lesson was completed now.
	 */
	private function complete_lesson( int $user, int $lesson ): bool {
		if ( tutor_utils()->is_completed_lesson( $lesson, $user ) ) {
			return false;
		}
		\Tutor\Models\LessonModel::mark_lesson_complete( $lesson, $user );
		return true;
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
