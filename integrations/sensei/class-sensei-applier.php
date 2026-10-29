<?php
/**
 * Sensei direction 1: apply LRS results.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Sensei;

use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Ledger\Echo_Guard;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Util\Clock;

/**
 * Applies results as Sensei lesson completion, waivers and course completion,
 * through Sensei's public API only.
 */
final class Sensei_Applier {

	const PROGRAM_SATISFIED = '_lledger_program_satisfied_';

	const LESSON_END = 'sensei_user_lesson_end';

	/**
	 * Lesson statuses Sensei already counts as complete.
	 */
	const COMPLETE_STATUSES = array( 'complete', 'graded', 'passed' );

	/**
	 * Whether a result is being applied; the completion filter then reports
	 * Sensei's own answer.
	 *
	 * @var boolean
	 */
	private static bool $applying = false;

	/**
	 * Teacher-email callbacks removed while applying.
	 *
	 * @var array<int, array{0:callable,1:int,2:int}>
	 */
	private array $muted = array();

	/**
	 * Register the completion filter. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		if ( false !== has_filter( 'sensei_user_completed_lesson', array( self::class, 'completed_lesson' ) ) ) {
			return;
		}
		add_filter( 'sensei_user_completed_lesson', array( self::class, 'completed_lesson' ), 10, 3 );
	}

	/**
	 * Report a pass-required quiz lesson as passed when the apply ledger holds a pass for it.
	 *
	 * @param mixed $status    Sensei's lesson status.
	 * @param mixed $lesson_id Lesson ID.
	 * @param mixed $user_id   User ID.
	 *
	 * @return mixed
	 */
	public static function completed_lesson( mixed $status, mixed $lesson_id = 0, mixed $user_id = 0 ): mixed {
		$lesson = (int) $lesson_id;
		$user   = (int) $user_id;
		if ( self::$applying || $lesson <= 0 || $user <= 0 || in_array( $status, self::COMPLETE_STATUSES, true ) ) {
			return $status;
		}
		// Sensei_Adapter::quiz_of() is Sensei_Lesson::lesson_quizzes() without WP_Query (see Sensei_Adapter).
		$quiz = Sensei_Adapter::quiz_of( $lesson );
		if ( $quiz <= 0 || ! get_post_meta( $quiz, '_pass_required', true ) ) {
			return $status;
		}
		return ( new Apply_Ledger() )->has( $user, new Lms_Object( 'lesson', $lesson ), Result::LESSON_PASSED ) ? 'passed' : $status;
	}

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
			return new Apply_Outcome( Apply_Outcome::FAILED, 'not a Sensei object for this result' );
		}
		if ( ! \Sensei_Course::is_user_enrolled( $course, $r->user_id ) ) {
			if ( empty( Connector_Settings::get()['allow_auto_enrol'] ) ) {
				return new Apply_Outcome( Apply_Outcome::DEFERRED, 'learner not enrolled' );
			}
			if ( ! ( new Sensei_Adapter() )->enrol( $r->user_id, $course ) ) {
				return new Apply_Outcome( Apply_Outcome::FAILED, 'enrolment failed' );
			}
		}

		$changed = Echo_Guard::while_applying(
			function () use ( $r, $course ): bool {
				self::$applying = true;
				$this->mute_teacher_emails();
				add_action( self::LESSON_END, array( $this, 'mute_teacher_emails' ), 9, 0 );
				add_filter( 'sensei_course_get_lessons', array( self::class, 'course_lessons' ), PHP_INT_MAX, 2 );
				add_filter( 'sensei_check_for_activity', array( self::class, 'activity' ), PHP_INT_MAX, 2 );
				try {
					return $this->apply_to_sensei( $r, $course );
				} finally {
					remove_filter( 'sensei_check_for_activity', array( self::class, 'activity' ), PHP_INT_MAX );
					remove_filter( 'sensei_course_get_lessons', array( self::class, 'course_lessons' ), PHP_INT_MAX );
					remove_action( self::LESSON_END, array( $this, 'mute_teacher_emails' ), 9 );
					$this->restore_teacher_emails();
					self::$applying = false;
				}
			}
		);
		return new Apply_Outcome( $changed ? Apply_Outcome::APPLIED : Apply_Outcome::SKIPPED );
	}

	/**
	 * `sensei_course_get_lessons`, only while a result is applied: when Sensei's own query found no
	 * lessons for a course that has published ones, the published lessons, read directly.
	 *
	 * On a host that empties anonymous post queries (the inbox job runs as user 0), Sensei's
	 * Sensei_Utils::user_complete_course() would otherwise count 0 of 0 lessons complete after the
	 * first lesson and complete the whole course. That caller asks for published lessons.
	 *
	 * @param mixed $lessons   Lesson posts Sensei found.
	 * @param mixed $course_id Course ID.
	 *
	 * @return mixed
	 */
	public static function course_lessons( mixed $lessons, mixed $course_id = 0 ): mixed {
		if ( ! is_array( $lessons ) || array() !== $lessons || (int) $course_id <= 0 ) {
			return $lessons;
		}
		return array_values( array_filter( array_map( 'get_post', Sensei_Adapter::course_lesson_ids( (int) $course_id, true ) ) ) );
	}

	/**
	 * `sensei_check_for_activity`, only while a result is applied: when Sensei's comment lookup for
	 * one learner's status on one post came back empty, the same lookup read directly.
	 *
	 * On a host that empties comment queries, Sensei's comment-based progress (or the comment mirror
	 * of its table storage) would otherwise not find the status comment it already has, and every
	 * save would take create(): it rewrites the comment to in-progress, resets its meta and fires
	 * `sensei_*_status_updated` with in-progress before the real status.
	 *
	 * @param mixed $comments What get_comments() returned (a count, a list or one comment).
	 * @param mixed $args     Sensei's lookup arguments.
	 *
	 * @return mixed
	 */
	public static function activity( mixed $comments, mixed $args = array() ): mixed {
		global $wpdb;
		if ( ! empty( $comments ) || ! is_array( $args ) || array() !== array_diff( array_keys( $args ), array( 'post_id', 'user_id', 'type', 'status', 'count' ) ) ) {
			return $comments;
		}
		$post = (int) ( $args['post_id'] ?? 0 );
		$user = (int) ( $args['user_id'] ?? 0 );
		$type = is_string( $args['type'] ?? null ) ? $args['type'] : '';
		if ( $post <= 0 || $user <= 0 || ! in_array( $type, Sensei_Storage::COMMENT_TYPES, true ) ) {
			return $comments;
		}
		$status = is_string( $args['status'] ?? null ) ? $args['status'] : 'any';
		$ids    = array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d AND user_id = %d AND comment_type = %s AND ( %s = 'any' OR comment_approved = %s ) ORDER BY comment_date_gmt DESC, comment_ID DESC",
					$post,
					$user,
					$type,
					$status,
					$status
				)
			)
		);
		if ( ! empty( $args['count'] ) ) {
			return count( $ids );
		}
		return array_values( array_filter( array_map( 'get_comment', $ids ) ) );
	}

	/**
	 * Remove the teacher-email callbacks on lesson end, remembering them.
	 * Also runs at priority 9 of each lesson end, after Sensei's email
	 * generators add their callbacks at priority 1.
	 *
	 * @return void
	 */
	public function mute_teacher_emails(): void {
		global $wp_filter;
		if ( ! isset( $wp_filter[ self::LESSON_END ] ) ) {
			return;
		}
		foreach ( $wp_filter[ self::LESSON_END ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( ! self::is_teacher_email( $function ) ) {
					continue;
				}
				remove_action( self::LESSON_END, $function, $priority );
				$this->muted[] = array( $function, (int) $priority, (int) $callback['accepted_args'] );
			}
		}
	}

	/**
	 * Sensei's teacher "student completed lesson" email callbacks: the legacy
	 * one and the email-customization one.
	 *
	 * @param mixed $callback Callback.
	 *
	 * @return boolean
	 */
	private static function is_teacher_email( mixed $callback ): bool {
		if ( ! is_array( $callback ) || ! isset( $callback[0], $callback[1] ) || ! is_object( $callback[0] ) ) {
			return false;
		}
		if ( $callback[0] instanceof \Sensei_Emails && 'teacher_completed_lesson' === $callback[1] ) {
			return true;
		}
		return 'student_completed_lesson_mail_to_teacher' === $callback[1];
	}

	/**
	 * Put back the callbacks removed while applying.
	 *
	 * @return void
	 */
	private function restore_teacher_emails(): void {
		foreach ( $this->muted as $muted ) {
			add_action( self::LESSON_END, $muted[0], $muted[1], $muted[2] );
		}
		$this->muted = array();
	}

	/**
	 * The Sensei course a lesson or block result belongs to.
	 *
	 * @param Result $r Result.
	 *
	 * @return integer 0 when the object does not fit the result.
	 */
	private function course_of( Result $r ): int {
		$o = $r->object;
		if ( Result::BLOCK_SATISFIED === $r->type ) {
			return 'course' === $o->kind && 'course' === get_post_type( $o->id ) ? $o->id : 0;
		}
		if ( 'lesson' !== $o->kind || 'lesson' !== get_post_type( $o->id ) ) {
			return 0;
		}
		$course = (int) Sensei()->lesson->get_course_id( $o->id );
		return 'course' === get_post_type( $course ) ? $course : 0;
	}

	/**
	 * Write a lesson or block result to Sensei.
	 *
	 * @param Result  $r      Result.
	 * @param integer $course Course ID.
	 *
	 * @return boolean Whether anything changed.
	 */
	private function apply_to_sensei( Result $r, int $course ): bool {
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
				// The score lives in the apply ledger, not in Sensei's quiz store.
				return true;

			case Result::BLOCK_SATISFIED:
				if ( \Sensei_Utils::user_completed_course( $course, $user ) ) {
					return false;
				}
				\Sensei_Utils::user_complete_course( $course, $user );
				return true;
		}
		return false;
	}

	/**
	 * Complete a lesson unless Sensei already has it complete.
	 *
	 * @param integer $user   User ID.
	 * @param integer $lesson Lesson ID.
	 *
	 * @return boolean Whether the lesson was completed now.
	 */
	private function complete_lesson( int $user, int $lesson ): bool {
		if ( \Sensei_Utils::user_completed_lesson( $lesson, $user ) ) {
			return false;
		}
		\Sensei_Utils::sensei_start_lesson( $lesson, $user, true );
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
