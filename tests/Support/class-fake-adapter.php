<?php
/**
 * In-memory LMS adapter for tests.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Tests;

use LearningLedger\Connector\Lms\Activity_Ref;
use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Course_Tree;
use LearningLedger\Connector\Lms\Event_Sink;
use LearningLedger\Connector\Lms\Lesson_Node;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\LMS_Adapter;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Lms\Progress;
use LearningLedger\Connector\Lms\Result;

/**
 * Keeps courses, lessons and progress in memory. Lesson IRIs are
 * https://example.org/ledger-test/lessons/{id}.
 */
class Fake_Adapter implements LMS_Adapter {

	const IRI_PREFIX = 'https://example.org/ledger-test/lessons/';

	/**
	 * When true every method except id() and register_event_listeners() throws.
	 *
	 * @var bool
	 */
	public bool $throw = false;

	/**
	 * Results passed to apply(), in order.
	 *
	 * @var Result[]
	 */
	public array $applied = array();

	/**
	 * Courses: id => array{title, key, lessons: int[]}.
	 *
	 * @var array
	 */
	private array $courses = array();

	/**
	 * Lessons: id => array{title, key, order, course}.
	 *
	 * @var array
	 */
	private array $lessons = array();

	/**
	 * Enrolments: "user:course" => true.
	 *
	 * @var array
	 */
	private array $enrolled = array();

	/**
	 * Completed lessons: "user:lesson" => true.
	 *
	 * @var array
	 */
	private array $completed = array();

	/**
	 * Waived lessons: "user:lesson" => true.
	 *
	 * @var array
	 */
	private array $waived = array();

	/**
	 * Next ID.
	 *
	 * @var int
	 */
	private int $next_id = 1000;

	/**
	 * Sink.
	 *
	 * @var Event_Sink|null
	 */
	private ?Event_Sink $sink = null;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'fake';
	}

	/**
	 * Version.
	 *
	 * @return string
	 */
	public function lms_version(): string {
		$this->maybe_throw();
		return '1.0.0';
	}

	/**
	 * Capabilities.
	 *
	 * @return string[]
	 */
	public function capabilities(): array {
		return array( 'emit_events', 'apply_completion', 'apply_score', 'mark_waived', 'enrol', 'export_course', 'import_course' );
	}

	/**
	 * Remember the sink.
	 *
	 * @param Event_Sink $sink Sink.
	 *
	 * @return void
	 */
	public function register_event_listeners( Event_Sink $sink ): void {
		$this->sink = $sink;
	}

	/**
	 * Apply a result.
	 *
	 * @param Result $r Result.
	 *
	 * @return Apply_Outcome
	 */
	public function apply( Result $r ): Apply_Outcome {
		$this->maybe_throw();
		$this->applied[] = $r;
		if ( 'lesson' !== $r->object->kind || ! isset( $this->lessons[ $r->object->id ] ) ) {
			return new Apply_Outcome( Apply_Outcome::SKIPPED, 'unsupported object' );
		}
		$lesson_id = $r->object->id;
		$course_id = $this->lessons[ $lesson_id ]['course'];
		$key       = $r->user_id . ':' . $lesson_id;
		if ( Result::LESSON_FAILED === $r->type ) {
			return new Apply_Outcome( Apply_Outcome::SKIPPED, 'failed result' );
		}
		if ( isset( $this->completed[ $key ] ) ) {
			return new Apply_Outcome( Apply_Outcome::SKIPPED, 'already complete' );
		}
		$this->completed[ $key ] = true;
		if ( Result::LESSON_WAIVED === $r->type ) {
			$this->waived[ $key ] = true;
		}
		$this->emit( new Neutral_Event( Neutral_Event::LESSON_COMPLETED, 'fake', $r->user_id, $course_id, $lesson_id ) );
		return new Apply_Outcome( Apply_Outcome::APPLIED );
	}

	/**
	 * Resolve a lesson IRI.
	 *
	 * @param Activity_Ref $ref Reference.
	 *
	 * @return Lms_Object|null
	 */
	public function resolve( Activity_Ref $ref ): ?Lms_Object {
		$this->maybe_throw();
		if ( 0 !== strpos( $ref->iri, self::IRI_PREFIX ) ) {
			return null;
		}
		$id = (int) substr( $ref->iri, strlen( self::IRI_PREFIX ) );
		return isset( $this->lessons[ $id ] ) ? new Lms_Object( 'lesson', $id ) : null;
	}

	/**
	 * Course tree.
	 *
	 * @param int $course_id Course ID.
	 *
	 * @return Course_Tree
	 */
	public function course_tree( int $course_id ): Course_Tree {
		$this->maybe_throw();
		$course = $this->courses[ $course_id ] ?? array(
			'title'   => '',
			'key'     => null,
			'lessons' => array(),
		);
		$nodes  = array();
		foreach ( $course['lessons'] as $lesson_id ) {
			$l       = $this->lessons[ $lesson_id ];
			$nodes[] = new Lesson_Node( $lesson_id, $l['title'], $l['key'], $l['order'], false );
		}
		usort( $nodes, static fn( Lesson_Node $a, Lesson_Node $b ): int => $a->order <=> $b->order );
		return new Course_Tree( $course_id, $course['title'], $course['key'], $nodes );
	}

	/**
	 * Create or update a course by stable key.
	 *
	 * @param Course_Spec $spec Spec.
	 *
	 * @return int
	 */
	public function upsert_course( Course_Spec $spec ): int {
		$this->maybe_throw();
		foreach ( $this->courses as $id => $c ) {
			if ( $c['key'] === $spec->stable_key ) {
				$this->courses[ $id ]['title'] = $spec->title;
				return $id;
			}
		}
		$id                   = $this->next_id++;
		$this->courses[ $id ] = array(
			'title'   => $spec->title,
			'key'     => $spec->stable_key,
			'lessons' => array(),
		);
		return $id;
	}

	/**
	 * Create or update a lesson by stable key. Like the real adapters, 0 for an id that is not one of its courses.
	 *
	 * @param int         $course_id Course ID.
	 * @param Lesson_Spec $spec      Spec.
	 *
	 * @return int
	 */
	public function upsert_lesson( int $course_id, Lesson_Spec $spec ): int {
		$this->maybe_throw();
		if ( ! isset( $this->courses[ $course_id ] ) ) {
			return 0;
		}
		foreach ( $this->courses[ $course_id ]['lessons'] ?? array() as $id ) {
			if ( $this->lessons[ $id ]['key'] === $spec->stable_key ) {
				$this->lessons[ $id ]['title'] = $spec->title;
				$this->lessons[ $id ]['order'] = $spec->order;
				return $id;
			}
		}
		$id                                       = $this->next_id++;
		$this->lessons[ $id ]                     = array(
			'title'  => $spec->title,
			'key'    => $spec->stable_key,
			'order'  => $spec->order,
			'course' => $course_id,
		);
		$this->courses[ $course_id ]['lessons'][] = $id;
		return $id;
	}

	/**
	 * Enrol a user.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 *
	 * @return bool
	 */
	public function enrol( int $user_id, int $course_id ): bool {
		$this->maybe_throw();
		if ( ! isset( $this->courses[ $course_id ] ) ) {
			return false;
		}
		$this->enrolled[ $user_id . ':' . $course_id ] = true;
		$this->emit( new Neutral_Event( Neutral_Event::ENROLLED, 'fake', $user_id, $course_id ) );
		return true;
	}

	/**
	 * Progress.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 *
	 * @return Progress
	 */
	public function progress( int $user_id, int $course_id ): Progress {
		$this->maybe_throw();
		$done       = array();
		$tested     = array();
		$lesson_ids = $this->courses[ $course_id ]['lessons'] ?? array();
		foreach ( $lesson_ids as $lesson_id ) {
			if ( isset( $this->completed[ $user_id . ':' . $lesson_id ] ) ) {
				$done[] = $lesson_id;
			}
			if ( isset( $this->waived[ $user_id . ':' . $lesson_id ] ) ) {
				$tested[] = $lesson_id;
			}
		}
		return new Progress( $done, $tested, $lesson_ids && count( $done ) === count( $lesson_ids ), null );
	}

	/**
	 * Waived?
	 *
	 * @param int $user_id   User ID.
	 * @param int $lesson_id Lesson ID.
	 *
	 * @return bool
	 */
	public function is_waived( int $user_id, int $lesson_id ): bool {
		$this->maybe_throw();
		return isset( $this->waived[ $user_id . ':' . $lesson_id ] );
	}

	/**
	 * Emit to the sink if one is registered.
	 *
	 * @param Neutral_Event $e Event.
	 *
	 * @return void
	 */
	private function emit( Neutral_Event $e ): void {
		if ( $this->sink ) {
			$this->sink->emit( $e );
		}
	}

	/**
	 * Throw when configured to.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When $throw is set.
	 */
	private function maybe_throw(): void {
		if ( $this->throw ) {
			throw new \RuntimeException( 'fake adapter failure' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}
}
