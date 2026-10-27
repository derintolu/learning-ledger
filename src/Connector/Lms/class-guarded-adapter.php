<?php
/**
 * Adapter wrapper that pauses the integration on any Throwable.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * Safe-by-default decorator around an LMS adapter.
 */
final class Guarded_Adapter implements LMS_Adapter, Certificate_Provider {

	/**
	 * Integration id.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Wrapped adapter.
	 *
	 * @var LMS_Adapter
	 */
	private LMS_Adapter $inner;

	/**
	 * Constructor.
	 *
	 * @param LMS_Adapter $inner Adapter to wrap.
	 */
	public function __construct( LMS_Adapter $inner ) {
		$this->inner = $inner;
		$this->id    = $inner->id();
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * LMS version, or an empty string when the adapter fails.
	 *
	 * @return string
	 */
	public function lms_version(): string {
		return $this->guard( fn() => $this->inner->lms_version(), '' );
	}

	/**
	 * Capabilities, or none when the adapter fails.
	 *
	 * @return string[]
	 */
	public function capabilities(): array {
		return $this->guard( fn() => $this->inner->capabilities(), array() );
	}

	/**
	 * Always delegates so events keep queueing while paused.
	 *
	 * @param Event_Sink $sink Sink.
	 *
	 * @return void
	 */
	public function register_event_listeners( Event_Sink $sink ): void {
		try {
			$this->inner->register_event_listeners( $sink );
		} catch ( \Throwable $t ) {
			$this->pause( $t );
		}
	}

	/**
	 * Apply a result; deferred while paused or on failure.
	 *
	 * @param Result $r Result.
	 *
	 * @return Apply_Outcome
	 */
	public function apply( Result $r ): Apply_Outcome {
		if ( $this->paused() ) {
			return new Apply_Outcome( Apply_Outcome::DEFERRED, 'integration paused' );
		}
		return $this->guard( fn() => $this->inner->apply( $r ), new Apply_Outcome( Apply_Outcome::DEFERRED, 'integration paused' ) );
	}

	/**
	 * Resolve an IRI.
	 *
	 * @param Activity_Ref $ref Reference.
	 *
	 * @return Lms_Object|null
	 */
	public function resolve( Activity_Ref $ref ): ?Lms_Object {
		return $this->guard( fn() => $this->inner->resolve( $ref ), null );
	}

	/**
	 * Course tree, empty on failure.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return Course_Tree
	 */
	public function course_tree( int $course_id ): Course_Tree {
		return $this->guard( fn() => $this->inner->course_tree( $course_id ), new Course_Tree( $course_id, '', null, array() ) );
	}

	/**
	 * Upsert a course; 0 on failure.
	 *
	 * @param Course_Spec $spec Spec.
	 *
	 * @return integer
	 */
	public function upsert_course( Course_Spec $spec ): int {
		return $this->guard( fn() => $this->inner->upsert_course( $spec ), 0 );
	}

	/**
	 * Upsert a lesson; 0 on failure.
	 *
	 * @param integer     $course_id Course ID.
	 * @param Lesson_Spec $spec      Spec.
	 *
	 * @return integer
	 */
	public function upsert_lesson( int $course_id, Lesson_Spec $spec ): int {
		return $this->guard( fn() => $this->inner->upsert_lesson( $course_id, $spec ), 0 );
	}

	/**
	 * Enrol; false on failure.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return boolean
	 */
	public function enrol( int $user_id, int $course_id ): bool {
		return $this->guard( fn() => $this->inner->enrol( $user_id, $course_id ), false );
	}

	/**
	 * Progress; empty on failure.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return Progress
	 */
	public function progress( int $user_id, int $course_id ): Progress {
		return $this->guard( fn() => $this->inner->progress( $user_id, $course_id ), new Progress( array(), array(), false, null ) );
	}

	/**
	 * Waived; false on failure.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $lesson_id Lesson ID.
	 *
	 * @return boolean
	 */
	public function is_waived( int $user_id, int $lesson_id ): bool {
		return $this->guard( fn() => $this->inner->is_waived( $user_id, $lesson_id ), false );
	}

	/**
	 * Certificate PDF when the inner adapter provides one.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return string|null
	 */
	public function certificate_pdf( int $user_id, int $course_id ): ?string {
		if ( ! $this->inner instanceof Certificate_Provider ) {
			return null;
		}
		return $this->guard( fn() => $this->inner->certificate_pdf( $user_id, $course_id ), null );
	}

	/**
	 * Whether the integration is paused.
	 *
	 * @return boolean
	 */
	private function paused(): bool {
		return Integration_State::PAUSED === Integration_State::status( $this->id );
	}

	/**
	 * Run a call; on any Throwable pause the integration and return the fallback.
	 *
	 * @param callable $call     Call.
	 * @param mixed    $fallback Safe value.
	 *
	 * @return mixed
	 */
	private function guard( callable $call, mixed $fallback ): mixed {
		try {
			return $call();
		} catch ( \Throwable $t ) {
			$this->pause( $t );
			return $fallback;
		}
	}

	/**
	 * Pause with the exception class and message as the reason.
	 *
	 * @param \Throwable $t Cause.
	 *
	 * @return void
	 */
	private function pause( \Throwable $t ): void {
		Integration_State::pause( $this->id, get_class( $t ) . ': ' . $t->getMessage() );
	}
}
