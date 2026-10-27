<?php
/**
 * Sensei LMS adapter.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Sensei;

use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Activity_Ref;
use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Certificate_Provider;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Course_Tree;
use LearningLedger\Connector\Lms\Event_Sink;
use LearningLedger\Connector\Lms\Lesson_Node;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\LMS_Adapter;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Progress;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Programs\Program_Iris;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;

/**
 * Talks to Sensei only through its public PHP API, its post types and its
 * documented post meta. Never Sensei's tables, comments or Sensei\Internal.
 */
final class Sensei_Adapter implements LMS_Adapter, Certificate_Provider {

	const STABLE_KEY = '_lledger_stable_key';

	/**
	 * Integration id.
	 *
	 * @return string
	 */
	public function id(): string {
		return Sensei_Integration::ID;
	}

	/**
	 * Installed Sensei version.
	 *
	 * @return string
	 */
	public function lms_version(): string {
		return (string) Sensei()->version;
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
	 * Delegate to Sensei_Listeners when present.
	 *
	 * @param Event_Sink $sink Sink.
	 *
	 * @return void
	 */
	public function register_event_listeners( Event_Sink $sink ): void {
		if ( class_exists( Sensei_Listeners::class ) ) {
			( new Sensei_Listeners() )->register( $sink );
		}
	}

	/**
	 * Delegate to Sensei_Applier when present.
	 *
	 * @param Result $r Result.
	 *
	 * @return Apply_Outcome
	 */
	public function apply( Result $r ): Apply_Outcome {
		if ( class_exists( Sensei_Applier::class ) ) {
			return ( new Sensei_Applier() )->apply( $r );
		}
		return new Apply_Outcome( Apply_Outcome::DEFERRED, 'Sensei applier not available' );
	}

	/**
	 * Certificate PDF via Sensei_Certificate_Provider when present.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return string|null
	 */
	public function certificate_pdf( int $user_id, int $course_id ): ?string {
		if ( class_exists( Sensei_Certificate_Provider::class ) ) {
			return ( new Sensei_Certificate_Provider() )->certificate_pdf( $user_id, $course_id );
		}
		return null;
	}

	/**
	 * Map a program IRI or a direction-2 IRI to a Sensei object.
	 *
	 * @param Activity_Ref $ref Reference.
	 *
	 * @return Lms_Object|null
	 */
	public function resolve( Activity_Ref $ref ): ?Lms_Object {
		$iri = $ref->iri;
		if ( '' === $iri ) {
			return null;
		}
		return $this->resolve_program( $iri ) ?? $this->resolve_direction2( $iri );
	}

	/**
	 * Course structure in Sensei's lesson order.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return Course_Tree
	 */
	public function course_tree( int $course_id ): Course_Tree {
		$course = get_post( $course_id );
		if ( ! $course || 'course' !== $course->post_type ) {
			return new Course_Tree( $course_id, '', null, array() );
		}
		$nodes = array();
		foreach ( $this->lesson_ids( $course_id ) as $position => $lesson_id ) {
			$order   = (int) get_post_meta( $lesson_id, '_order_' . $course_id, true );
			$nodes[] = new Lesson_Node(
				$lesson_id,
				get_the_title( $lesson_id ),
				$this->stable_key( $lesson_id ),
				$order > 0 ? $order : $position + 1,
				(bool) Sensei()->lesson->lesson_has_quiz_with_questions_and_pass_required( $lesson_id )
			);
		}
		return new Course_Tree( $course_id, $course->post_title, $this->stable_key( $course_id ), $nodes );
	}

	/**
	 * Create or update a course by stable key.
	 *
	 * @param Course_Spec $spec Spec.
	 *
	 * @return integer
	 */
	public function upsert_course( Course_Spec $spec ): int {
		$key = Program_Module::sanitize_stable_key( $spec->stable_key );
		if ( '' === $key ) {
			return 0;
		}
		$meta = array_merge( $spec->meta, array( self::STABLE_KEY => $key ) );
		$id   = $this->find_by_key( 'course', $key );
		if ( null === $id ) {
			return $this->insert( 'course', $spec->title, $spec->content, $spec->status, $meta );
		}
		$this->update( $id, $spec->title, $spec->content, $spec->status, $meta );
		return $id;
	}

	/**
	 * Create or update a lesson by stable key and link it to its course.
	 *
	 * @param integer     $course_id Course ID.
	 * @param Lesson_Spec $spec      Spec.
	 *
	 * @return integer
	 */
	public function upsert_lesson( int $course_id, Lesson_Spec $spec ): int {
		$key = Program_Module::sanitize_stable_key( $spec->stable_key );
		if ( '' === $key || 'course' !== get_post_type( $course_id ) ) {
			return 0;
		}
		$meta = array_merge(
			$spec->meta,
			array(
				self::STABLE_KEY       => $key,
				'_lesson_course'       => (string) $course_id,
				'_order_' . $course_id => (string) $spec->order,
			)
		);
		$id   = $this->find_by_key( 'lesson', $key );
		if ( null === $id ) {
			$id = $this->insert( 'lesson', $spec->title, $spec->content, $spec->status, $meta );
		} else {
			$previous = (int) get_post_meta( $id, '_lesson_course', true );
			$this->update( $id, $spec->title, $spec->content, $spec->status, $meta );
			if ( $previous > 0 && $previous !== $course_id ) {
				delete_post_meta( $id, '_order_' . $previous );
				$this->save_lesson_order( $previous );
			}
		}
		if ( $id > 0 ) {
			$this->save_lesson_order( $course_id );
		}
		return $id;
	}

	/**
	 * Enrol through Sensei's manual enrolment provider.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return boolean
	 */
	public function enrol( int $user_id, int $course_id ): bool {
		if ( $user_id <= 0 || 'course' !== get_post_type( $course_id ) ) {
			return false;
		}
		$provider = \Sensei_Course_Enrolment_Manager::instance()->get_manual_enrolment_provider();
		if ( ! $provider instanceof \Sensei_Course_Manual_Enrolment_Provider ) {
			return false;
		}
		return (bool) $provider->enrol_learner( $user_id, $course_id );
	}

	/**
	 * Progress from Sensei and the waiver ledger.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return Progress
	 */
	public function progress( int $user_id, int $course_id ): Progress {
		$ledger     = new Waiver_Ledger();
		$completed  = array();
		$tested_out = array();
		foreach ( $this->lesson_ids( $course_id ) as $lesson_id ) {
			if ( \Sensei_Utils::user_completed_lesson( $lesson_id, $user_id ) ) {
				$completed[] = $lesson_id;
			}
			if ( $ledger->is_waived( $user_id, $lesson_id ) ) {
				$tested_out[] = $lesson_id;
			}
		}
		return new Progress( $completed, $tested_out, (bool) \Sensei_Utils::user_completed_course( $course_id, $user_id ), null );
	}

	/**
	 * Whether the waiver ledger holds the lesson for the user.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $lesson_id Lesson ID.
	 *
	 * @return boolean
	 */
	public function is_waived( int $user_id, int $lesson_id ): bool {
		return ( new Waiver_Ledger() )->is_waived( $user_id, $lesson_id );
	}

	/**
	 * Resolve an IRI under a program's IRI base.
	 *
	 * @param string $iri IRI.
	 *
	 * @return Lms_Object|null
	 */
	private function resolve_program( string $iri ): ?Lms_Object {
		$repo = new Program_Repository();
		$pid  = $repo->for_activity( $iri ) ?? $repo->for_activity( $iri . '/' );
		if ( null === $pid ) {
			return null;
		}
		$program = $repo->get( $pid );
		if ( ! $program ) {
			return null;
		}
		if ( '' !== $program->course_iri && $iri === $program->course_iri ) {
			return new Lms_Object( 'program', $pid );
		}
		$parsed = Program_Iris::key_from_iri( $program, $iri );
		if ( null === $parsed ) {
			return null;
		}
		$courses = $program->course_ids;
		if ( 'path' === $parsed['kind'] ) {
			$id = $this->find_by_key( 'course', $parsed['key'] );
			return null !== $id && ( ! $courses || in_array( $id, $courses, true ) ) ? new Lms_Object( 'course', $id ) : null;
		}
		if ( 'lesson' === $parsed['kind'] ) {
			$id = $this->find_by_key( 'lesson', $parsed['key'], $courses );
			return null !== $id ? new Lms_Object( 'lesson', $id ) : null;
		}
		return null;
	}

	/**
	 * Resolve a direction-2 IRI (Sensei_Iris).
	 *
	 * @param string $iri IRI.
	 *
	 * @return Lms_Object|null
	 */
	private function resolve_direction2( string $iri ): ?Lms_Object {
		if ( 1 === preg_match( '#^(.+)/lessons/(\d+)(/quiz)?$#', $iri, $m ) ) {
			$lesson = (int) $m[2];
			$course = (int) get_post_meta( $lesson, '_lesson_course', true );
			if ( 'lesson' !== get_post_type( $lesson ) || 'course' !== get_post_type( $course ) ) {
				return null;
			}
			if ( Sensei_Iris::course( $course ) !== $m[1] ) {
				return null;
			}
			if ( empty( $m[3] ) ) {
				return new Lms_Object( 'lesson', $lesson );
			}
			$quiz = (int) Sensei()->lesson->lesson_quizzes( $lesson );
			return $quiz > 0 ? new Lms_Object( 'quiz', $quiz ) : null;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'course',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => Sensei_Iris::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $iri, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		);
		return $ids ? new Lms_Object( 'course', (int) $ids[0] ) : null;
	}

	/**
	 * Lesson IDs of a course in Sensei's order.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return int[]
	 */
	private function lesson_ids( int $course_id ): array {
		if ( $course_id <= 0 ) {
			return array();
		}
		return array_values( array_map( 'intval', (array) Sensei()->course->course_lessons( $course_id, 'any', 'ids' ) ) );
	}

	/**
	 * Post with a stable key.
	 *
	 * @param string $post_type Post type.
	 * @param string $key       Stable key.
	 * @param int[]  $courses   For lessons: limit to these courses when not empty.
	 *
	 * @return integer|null
	 */
	private function find_by_key( string $post_type, string $key, array $courses = array() ): ?int {
		if ( '' === $key ) {
			return null;
		}
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => self::STABLE_KEY,
					'value' => $key,
				),
			),
		);
		if ( $courses ) {
			$args['meta_query'][] = array(
				'key'     => '_lesson_course',
				'value'   => array_map( 'strval', $courses ),
				'compare' => 'IN',
			);
		}
		$ids = get_posts( $args );
		return $ids ? (int) $ids[0] : null;
	}

	/**
	 * Stable key of a post, or null.
	 *
	 * @param integer $id Post ID.
	 *
	 * @return string|null
	 */
	private function stable_key( int $id ): ?string {
		$key = (string) get_post_meta( $id, self::STABLE_KEY, true );
		return '' === $key ? null : $key;
	}

	/**
	 * Create a post with meta.
	 *
	 * @param string $post_type Post type.
	 * @param string $title     Title.
	 * @param string $content   Content.
	 * @param string $status    Status.
	 * @param array  $meta      Meta.
	 *
	 * @return integer
	 */
	private function insert( string $post_type, string $title, string $content, string $status, array $meta ): int {
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $post_type,
					'post_title'   => $title,
					'post_content' => $content,
					'post_status'  => $status,
					'meta_input'   => $meta,
				)
			),
			true
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Update a post only where title, content, status or meta differ.
	 *
	 * @param integer $id      Post ID.
	 * @param string  $title   Title.
	 * @param string  $content Content.
	 * @param string  $status  Status.
	 * @param array   $meta    Meta.
	 *
	 * @return void
	 */
	private function update( int $id, string $title, string $content, string $status, array $meta ): void {
		$post    = get_post( $id );
		$changes = array();
		$fields  = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
		);
		foreach ( $fields as $field => $value ) {
			// Compare with what wp_insert_post() would store, after its save filters.
			$stored = wp_unslash( sanitize_post_field( $field, wp_slash( $value ), $id, 'db' ) );
			if ( (string) $post->$field !== (string) $stored ) {
				$changes[ $field ] = $value;
			}
		}
		if ( $changes ) {
			$changes['ID'] = $id;
			wp_update_post( wp_slash( $changes ) );
		}
		foreach ( $meta as $key => $value ) {
			$key  = (string) $key;
			$want = is_scalar( $value ) ? (string) $value : $value;
			if ( ! metadata_exists( 'post', $id, $key ) || get_post_meta( $id, $key, true ) !== $want ) {
				update_post_meta( $id, $key, wp_slash( $value ) );
			}
		}
	}

	/**
	 * Write the course's lesson order (Sensei's `_lesson_order` course meta)
	 * from the lessons' `_order_<course>` meta.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return void
	 */
	private function save_lesson_order( int $course_id ): void {
		$order = implode( ',', $this->lesson_ids( $course_id ) );
		if ( (string) get_post_meta( $course_id, '_lesson_order', true ) !== $order ) {
			update_post_meta( $course_id, '_lesson_order', $order );
		}
	}
}
