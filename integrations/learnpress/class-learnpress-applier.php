<?php
/**
 * LearnPress direction 1: apply LRS results.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Learnpress;

use LearningLedger\Connector\Ledger\Echo_Guard;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Util\Clock;
use LearnPress\Models\UserItems\UserCourseModel;
use LearnPress\Models\UserItems\UserLessonModel;

/**
 * Applies results as LearnPress lesson completion, waivers and course
 * completion, through LP_User::complete_lesson() and LP_User::finish_course().
 */
final class Learnpress_Applier {

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
			return new Apply_Outcome( Apply_Outcome::FAILED, 'not a LearnPress object for this result' );
		}
		$user = learn_press_get_user( $r->user_id );
		if ( ! $user instanceof \LP_User ) {
			return new Apply_Outcome( Apply_Outcome::FAILED, 'no LearnPress user' );
		}
		if ( null === self::enrolment( $r->user_id, $course ) ) {
			if ( empty( Connector_Settings::get()['allow_auto_enrol'] ) ) {
				return new Apply_Outcome( Apply_Outcome::DEFERRED, 'learner not enrolled' );
			}
			if ( ! ( new Learnpress_Adapter() )->enrol( $r->user_id, $course ) ) {
				return new Apply_Outcome( Apply_Outcome::FAILED, 'enrolment failed' );
			}
		}

		try {
			$changed = Echo_Guard::while_applying( fn(): bool => $this->apply_to_learnpress( $r, $user, $course ) );
		} catch ( \RuntimeException $e ) {
			return new Apply_Outcome( Apply_Outcome::FAILED, $e->getMessage() );
		}
		return new Apply_Outcome( $changed ? Apply_Outcome::APPLIED : Apply_Outcome::SKIPPED );
	}

	/**
	 * The LearnPress course a lesson or block result belongs to.
	 *
	 * @param Result $r Result.
	 *
	 * @return integer 0 when the object does not fit the result.
	 */
	private function course_of( Result $r ): int {
		$o = $r->object;
		if ( Result::BLOCK_SATISFIED === $r->type ) {
			return 'course' === $o->kind && Learnpress_Adapter::COURSE === get_post_type( $o->id ) ? $o->id : 0;
		}
		if ( 'lesson' !== $o->kind || Learnpress_Adapter::LESSON !== get_post_type( $o->id ) ) {
			return 0;
		}
		return ( new Learnpress_Adapter() )->course_of_lesson( $o->id );
	}

	/**
	 * Write a lesson or block result to LearnPress.
	 *
	 * @param Result   $r      Result.
	 * @param \LP_User $user   LearnPress user.
	 * @param integer  $course Course ID.
	 *
	 * @return boolean Whether anything changed.
	 */
	private function apply_to_learnpress( Result $r, \LP_User $user, int $course ): bool {
		$lesson = $r->object->id;
		switch ( $r->type ) {
			case Result::LESSON_COMPLETED:
				$changed = $this->complete_lesson( $user, $lesson, $course );
				$waivers = new Waiver_Ledger();
				if ( $waivers->is_waived( $r->user_id, $lesson ) ) {
					$waivers->clear( $r->user_id, $lesson );
					$changed = true;
				}
				return $changed;

			case Result::LESSON_WAIVED:
				$changed = $this->complete_lesson( $user, $lesson, $course );
				$waivers = new Waiver_Ledger();
				$waiver  = $waivers->get( $r->user_id, $lesson );
				if ( null === $waiver || $waiver['statement_id'] !== $r->statement_id ) {
					$waivers->record( $r->user_id, $lesson, $r->registration, $r->statement_id, (string) $r->reason, $r->carried_over );
					$changed = true;
				}
				return $changed;

			case Result::LESSON_PASSED:
			case Result::LESSON_FAILED:
				// The score lives in the apply ledger, not in LearnPress's quiz store.
				return true;

			case Result::BLOCK_SATISFIED:
				if ( self::enrolment( $r->user_id, $course )?->has_finished() ) {
					return false;
				}
				if ( ! $user->finish_course( $course ) ) {
					throw new \RuntimeException( 'LearnPress did not finish the course' );
				}
				return true;
		}
		return false;
	}

	/**
	 * Complete a lesson unless LearnPress already has it complete.
	 * LP_User::complete_lesson() needs the learner's lesson item, which
	 * LearnPress creates when the learner opens the lesson; a result can
	 * arrive for a lesson never opened, so it is started here the way
	 * LearnPress's own lesson route does.
	 *
	 * @param \LP_User $user   LearnPress user.
	 * @param integer  $lesson Lesson ID.
	 * @param integer  $course Course ID.
	 *
	 * @return boolean Whether the lesson was completed now.
	 */
	private function complete_lesson( \LP_User $user, int $lesson, int $course ): bool {
		$item = UserLessonModel::find_user_item( $user->get_id(), $lesson, LP_LESSON_CPT, $course, LP_COURSE_CPT );
		if ( $item instanceof UserLessonModel && UserLessonModel::STATUS_COMPLETED === $item->get_status() ) {
			return false;
		}
		if ( ! $item instanceof UserLessonModel ) {
			$enrolment = self::enrolment( $user->get_id(), $course );
			if ( null === $enrolment ) {
				throw new \RuntimeException( 'learner not enrolled in LearnPress' );
			}
			$item            = new UserLessonModel();
			$item->user_id   = $user->get_id();
			$item->item_id   = $lesson;
			$item->item_type = LP_LESSON_CPT;
			$item->ref_id    = $course;
			$item->ref_type  = LP_COURSE_CPT;
			$item->parent_id = $enrolment->get_user_item_id();
			$item->status    = LP_ITEM_STARTED;
			$item->save();
		}
		$done = $user->complete_lesson( $lesson, $course );
		if ( is_wp_error( $done ) ) {
			throw new \RuntimeException( $done->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught in apply() and returned as the outcome message.
		}
		return true;
	}

	/**
	 * The learner's current enrolment, read from the database. LearnPress's
	 * cached lookups can still hold the state from before this request's
	 * writes; a fresh read also refreshes what they return next.
	 *
	 * @param integer $user   User ID.
	 * @param integer $course Course ID.
	 *
	 * @return UserCourseModel|null Null when not enrolled or finished.
	 */
	private static function enrolment( int $user, int $course ): ?UserCourseModel {
		$enrolment = UserCourseModel::find( $user, $course );
		return $enrolment instanceof UserCourseModel && $enrolment->has_enrolled_or_finished() ? $enrolment : null;
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
