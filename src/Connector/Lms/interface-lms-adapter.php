<?php
/**
 * The shared LMS adapter interface.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * One adapter per LMS. Adapters call only public LMS functions and hooks.
 */
interface LMS_Adapter {

	/**
	 * Integration id, for example sensei.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Installed LMS version.
	 *
	 * @return string
	 */
	public function lms_version(): string;

	/**
	 * Subset of: emit_events, apply_completion, apply_score, mark_waived, enrol, export_course, import_course.
	 *
	 * @return string[]
	 */
	public function capabilities(): array;

	/**
	 * Translate LMS hooks into neutral events.
	 *
	 * @param Event_Sink $sink Sink.
	 *
	 * @return void
	 */
	public function register_event_listeners( Event_Sink $sink ): void;

	/**
	 * Apply a neutral result to LMS progress.
	 *
	 * @param Result $r Result.
	 *
	 * @return Apply_Outcome
	 */
	public function apply( Result $r ): Apply_Outcome;

	/**
	 * Map an activity IRI to a course, lesson or quiz.
	 *
	 * @param Activity_Ref $ref Reference.
	 *
	 * @return Lms_Object|null
	 */
	public function resolve( Activity_Ref $ref ): ?Lms_Object;

	/**
	 * Course structure, for export and IRIs.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return Course_Tree
	 */
	public function course_tree( int $course_id ): Course_Tree;

	/**
	 * Create or update a course by stable key.
	 *
	 * @param Course_Spec $spec Spec.
	 *
	 * @return integer Course ID.
	 */
	public function upsert_course( Course_Spec $spec ): int;

	/**
	 * Create or update a lesson by stable key.
	 *
	 * @param integer     $course_id Course ID.
	 * @param Lesson_Spec $spec      Spec.
	 *
	 * @return integer Lesson ID.
	 */
	public function upsert_lesson( int $course_id, Lesson_Spec $spec ): int;

	/**
	 * Enrol a user.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return boolean
	 */
	public function enrol( int $user_id, int $course_id ): bool;

	/**
	 * A user's progress in a course.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return Progress
	 */
	public function progress( int $user_id, int $course_id ): Progress;

	/**
	 * Whether the lesson was waived for the user (shared waiver ledger).
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $lesson_id Lesson ID.
	 *
	 * @return boolean
	 */
	public function is_waived( int $user_id, int $lesson_id ): bool;
}
