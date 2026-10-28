<?php
/**
 * LifterLMS adapter and integration hooks.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Lifterlms;

use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Activity_Ref;
use LearningLedger\Connector\Lms\Apply_Outcome;
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

// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Slash-namespaced hook names are the plugin convention.

/**
 * Talks to LifterLMS only through its public functions (`llms_*`), its post
 * model classes (`LLMS_Course`, `LLMS_Section`, `LLMS_Lesson`, `LLMS_Quiz`)
 * and its documented hooks. Never its `lifterlms_user_postmeta` table.
 */
final class Lifterlms_Adapter implements LMS_Adapter {

	const ID = 'lifterlms';

	const STABLE_KEY = '_lledger_stable_key';

	/**
	 * LifterLMS stores each model property in post meta under this prefix.
	 */
	const PARENT_COURSE = '_llms_parent_course';

	/**
	 * Hook into the connector. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( has_filter( 'learning_ledger/adapter_post_types', array( self::class, 'adapter_post_types' ) ) ) {
			return;
		}
		add_filter( 'learning_ledger/stable_key_post_types', array( self::class, 'stable_key_post_types' ) );
		add_filter( 'learning_ledger/lesson_post_types', array( self::class, 'lesson_post_types' ) );
		add_filter( 'learning_ledger/course_post_types', array( self::class, 'course_post_types' ) );
		add_filter( 'learning_ledger/adapter_post_types', array( self::class, 'adapter_post_types' ) );
		add_filter( 'learning_ledger/status', array( self::class, 'status' ), 10, 2 );
		add_filter( 'learning_ledger/direction2_iris', array( Lifterlms_Iris::class, 'direction2_iris' ), 10, 2 );
	}

	/**
	 * Post types that carry a stable key.
	 *
	 * @param mixed $types Post types.
	 *
	 * @return string[]
	 */
	public static function stable_key_post_types( mixed $types ): array {
		return self::add( $types, array( 'course', 'lesson' ) );
	}

	/**
	 * Lesson post types.
	 *
	 * @param mixed $types Post types.
	 *
	 * @return string[]
	 */
	public static function lesson_post_types( mixed $types ): array {
		return self::add( $types, array( 'lesson' ) );
	}

	/**
	 * Course post types.
	 *
	 * @param mixed $types Post types.
	 *
	 * @return string[]
	 */
	public static function course_post_types( mixed $types ): array {
		return self::add( $types, array( 'course' ) );
	}

	/**
	 * Post types this adapter handles, by integration id.
	 *
	 * @param mixed $map Integration id => post types.
	 *
	 * @return array
	 */
	public static function adapter_post_types( mixed $map ): array {
		$map             = is_array( $map ) ? $map : array();
		$map[ self::ID ] = array( 'course', 'lesson', 'llms_quiz' );
		return $map;
	}

	/**
	 * Add the LifterLMS version to the status payload.
	 *
	 * @param mixed   $payload    Payload.
	 * @param boolean $privileged Unused; versions are public.
	 *
	 * @return array
	 */
	public static function status( mixed $payload, bool $privileged = false ): array {
		unset( $privileged );
		$payload   = is_array( $payload ) ? $payload : array();
		$connector = isset( $payload['connector'] ) && is_array( $payload['connector'] ) ? $payload['connector'] : array();
		$lms       = isset( $connector['lms'] ) && is_array( $connector['lms'] ) ? $connector['lms'] : array();

		$lms[ self::ID ] = function_exists( 'llms' ) ? (string) llms()->version : null;

		$payload['connector'] = array_merge( $connector, array( 'lms' => $lms ) );
		return $payload;
	}

	/**
	 * Integration id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Installed LifterLMS version.
	 *
	 * @return string
	 */
	public function lms_version(): string {
		return (string) llms()->version;
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
	 * Hook the listeners into LifterLMS.
	 *
	 * @param Event_Sink $sink Sink.
	 *
	 * @return void
	 */
	public function register_event_listeners( Event_Sink $sink ): void {
		( new Lifterlms_Listeners() )->register( $sink );
	}

	/**
	 * Apply a result through Lifterlms_Applier.
	 *
	 * @param Result $r Result.
	 *
	 * @return Apply_Outcome
	 */
	public function apply( Result $r ): Apply_Outcome {
		return ( new Lifterlms_Applier() )->apply( $r );
	}

	/**
	 * Map a program IRI or a direction-2 IRI to a LifterLMS object.
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
	 * Course structure in LifterLMS order (sections, then lessons within each).
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return Course_Tree
	 */
	public function course_tree( int $course_id ): Course_Tree {
		$course = $this->course( $course_id );
		if ( null === $course ) {
			return new Course_Tree( $course_id, '', null, array() );
		}
		$nodes = array();
		foreach ( $this->lesson_ids( $course_id ) as $position => $lesson_id ) {
			$lesson  = llms_get_post( $lesson_id );
			$nodes[] = new Lesson_Node(
				$lesson_id,
				get_the_title( $lesson_id ),
				$this->stable_key( $lesson_id ),
				$position + 1,
				$lesson instanceof \LLMS_Lesson && self::has_pass_required_quiz( $lesson )
			);
		}
		return new Course_Tree( $course_id, (string) get_post_field( 'post_title', $course_id ), $this->stable_key( $course_id ), $nodes );
	}

	/**
	 * Create or update a course by stable key, through LLMS_Course.
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
			return $this->insert( new \LLMS_Course( 'new', self::post_fields( $spec->title, $spec->content, $spec->status ) ), $meta );
		}
		$this->update( new \LLMS_Course( $id ), $spec->title, $spec->content, $spec->status, array(), $meta );
		return $id;
	}

	/**
	 * Create or update a lesson by stable key, through LLMS_Lesson, inside
	 * one of the course's sections.
	 *
	 * @param integer     $course_id Course ID.
	 * @param Lesson_Spec $spec      Spec.
	 *
	 * @return integer
	 */
	public function upsert_lesson( int $course_id, Lesson_Spec $spec ): int {
		$key = Program_Module::sanitize_stable_key( $spec->stable_key );
		if ( '' === $key || null === $this->course( $course_id ) ) {
			return 0;
		}
		$meta = array_merge( $spec->meta, array( self::STABLE_KEY => $key ) );
		// Stable keys are scoped to the course: a key new to this course creates a new lesson.
		$id = $this->find_by_key( 'lesson', $key, array( $course_id ) );
		if ( null === $id ) {
			$section = $this->section_for( $course_id );
			if ( 0 === $section ) {
				return 0;
			}
			$lesson = new \LLMS_Lesson( 'new', self::post_fields( $spec->title, $spec->content, $spec->status ) );
			$id     = $this->insert( $lesson, $meta );
			if ( $id > 0 ) {
				$lesson->set(
					array(
						'parent_course'  => $course_id,
						'parent_section' => $section,
						'order'          => $spec->order,
					)
				);
			}
			return $id;
		}
		$lesson  = new \LLMS_Lesson( $id );
		$section = (int) $lesson->get( 'parent_section' );
		$this->update(
			$lesson,
			$spec->title,
			$spec->content,
			$spec->status,
			array(
				'parent_course'  => $course_id,
				'parent_section' => $section > 0 ? $section : $this->section_for( $course_id ),
				'order'          => $spec->order,
			),
			$meta
		);
		return $id;
	}

	/**
	 * Enrol through LifterLMS, labelled with our trigger.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return boolean Whether the user is enrolled afterwards.
	 */
	public function enrol( int $user_id, int $course_id ): bool {
		if ( $user_id <= 0 || null === $this->course( $course_id ) ) {
			return false;
		}
		if ( llms_is_user_enrolled( $user_id, $course_id ) ) {
			return true;
		}
		return (bool) llms_enroll_student( $user_id, $course_id, Lifterlms_Applier::TRIGGER );
	}

	/**
	 * Progress from LifterLMS and the waiver ledger.
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
			if ( llms_is_complete( $user_id, $lesson_id, 'lesson' ) ) {
				$completed[] = $lesson_id;
			}
			if ( $ledger->is_waived( $user_id, $lesson_id ) ) {
				$tested_out[] = $lesson_id;
			}
		}
		$done = $course_id > 0 && llms_is_complete( $user_id, $course_id, 'course' );
		return new Progress( $completed, $tested_out, $done, null );
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
	 * The course a lesson belongs to.
	 *
	 * @param integer $lesson_id Lesson ID.
	 *
	 * @return integer 0 when the post is not a lesson of a course.
	 */
	public static function course_of_lesson( int $lesson_id ): int {
		$lesson = $lesson_id > 0 ? llms_get_post( $lesson_id ) : null;
		if ( ! $lesson instanceof \LLMS_Lesson ) {
			return 0;
		}
		$course = (int) $lesson->get( 'parent_course' );
		return 'course' === get_post_type( $course ) ? $course : 0;
	}

	/**
	 * Whether a lesson has an enabled quiz that must be passed.
	 *
	 * @param \LLMS_Lesson $lesson Lesson.
	 *
	 * @return boolean
	 */
	private static function has_pass_required_quiz( \LLMS_Lesson $lesson ): bool {
		return $lesson->has_quiz() && 'yes' === $lesson->get( 'quiz_enabled' ) && 'yes' === $lesson->get( 'require_passing_grade' );
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
			$id = $this->find_by_key( 'course', $parsed['key'], $courses );
			return null !== $id ? new Lms_Object( 'course', $id ) : null;
		}
		if ( 'lesson' === $parsed['kind'] ) {
			// Lesson keys are unique within a program; a key on two of its lessons resolves to nothing.
			$ids = $this->find_all_by_key( 'lesson', $parsed['key'], $courses );
			return 1 === count( $ids ) ? new Lms_Object( 'lesson', $ids[0] ) : null;
		}
		return null;
	}

	/**
	 * Resolve a direction-2 IRI (Lifterlms_Iris).
	 *
	 * @param string $iri IRI.
	 *
	 * @return Lms_Object|null
	 */
	private function resolve_direction2( string $iri ): ?Lms_Object {
		if ( 1 === preg_match( '#^(.+)/lessons/(\d+)(/quiz)?$#', $iri, $m ) ) {
			$lesson = (int) $m[2];
			$course = self::course_of_lesson( $lesson );
			if ( 0 === $course || Lifterlms_Iris::peek( $course ) !== $m[1] ) {
				return null;
			}
			if ( empty( $m[3] ) ) {
				return new Lms_Object( 'lesson', $lesson );
			}
			$quiz = (int) llms_get_post( $lesson )->get( 'quiz' );
			return $quiz > 0 && 'llms_quiz' === get_post_type( $quiz ) ? new Lms_Object( 'quiz', $quiz ) : null;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'course',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => Lifterlms_Iris::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $iri, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		);
		if ( $ids ) {
			return new Lms_Object( 'course', (int) $ids[0] );
		}
		// A course whose base is not fixed yet still answers to the default form.
		if ( 1 === preg_match( '#/courses/(\d+)$#', $iri, $m ) ) {
			$course = (int) $m[1];
			if ( null !== $this->course( $course ) && Lifterlms_Iris::peek( $course ) === $iri ) {
				return new Lms_Object( 'course', $course );
			}
		}
		return null;
	}

	/**
	 * The course model, or null when the post is not a LifterLMS course.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return \LLMS_Course|null
	 */
	private function course( int $course_id ): ?\LLMS_Course {
		$course = $course_id > 0 ? llms_get_post( $course_id ) : null;
		return $course instanceof \LLMS_Course ? $course : null;
	}

	/**
	 * Lesson IDs of a course in LifterLMS order.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return int[]
	 */
	private function lesson_ids( int $course_id ): array {
		$course = $this->course( $course_id );
		return null === $course ? array() : array_values( array_map( 'intval', (array) $course->get_lessons( 'ids' ) ) );
	}

	/**
	 * The course's first section, created when the course has none, so that
	 * LifterLMS lists the lesson (it finds lessons through their section).
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return integer Section ID, 0 on failure.
	 */
	private function section_for( int $course_id ): int {
		$sections = (array) $this->course( $course_id )->get_sections( 'ids' );
		if ( $sections ) {
			return (int) $sections[0];
		}
		$section = new \LLMS_Section( 'new', self::post_fields( __( 'Lessons', 'learning-ledger' ), '', 'publish' ) );
		$id      = (int) $section->get( 'id' );
		if ( $id <= 0 ) {
			return 0;
		}
		$section->set(
			array(
				'parent_course' => $course_id,
				'order'         => 1,
			)
		);
		return $id;
	}

	/**
	 * Posts of a type with a stable key, oldest first.
	 *
	 * @param string $post_type Post type.
	 * @param string $key       Stable key.
	 * @param int[]  $courses   When not empty: lessons of these courses, or (for courses) these courses only.
	 *
	 * @return int[]
	 */
	private function find_all_by_key( string $post_type, string $key, array $courses = array() ): array {
		if ( '' === $key ) {
			return array();
		}
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 2,
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
		if ( $courses && 'course' === $post_type ) {
			$args['post__in'] = array_map( 'intval', $courses );
		} elseif ( $courses ) {
			$args['meta_query'][] = array(
				'key'     => self::PARENT_COURSE,
				'value'   => array_map( 'strval', $courses ),
				'compare' => 'IN',
			);
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * First post of a type with a stable key.
	 *
	 * @param string $post_type Post type.
	 * @param string $key       Stable key.
	 * @param int[]  $courses   When not empty: lessons of these courses, or (for courses) these courses only.
	 *
	 * @return integer|null
	 */
	private function find_by_key( string $post_type, string $key, array $courses = array() ): ?int {
		$ids = $this->find_all_by_key( $post_type, $key, $courses );
		return $ids ? $ids[0] : null;
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
	 * Post fields for a new model.
	 *
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @param string $status  Status.
	 *
	 * @return array
	 */
	private static function post_fields( string $title, string $content, string $status ): array {
		return array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
		);
	}

	/**
	 * Finish a model created with `new`: add our meta.
	 *
	 * @param \LLMS_Post_Model $model Model created with 'new'.
	 * @param array            $meta  Meta (not LifterLMS properties).
	 *
	 * @return integer Post ID, 0 when LifterLMS could not create it.
	 */
	private function insert( \LLMS_Post_Model $model, array $meta ): int {
		$id = (int) $model->get( 'id' );
		if ( $id <= 0 ) {
			return 0;
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, (string) $key, wp_slash( $value ) );
		}
		return $id;
	}

	/**
	 * Update a model only where title, content, status, properties or meta differ.
	 *
	 * @param \LLMS_Post_Model $model      Model.
	 * @param string           $title      Title.
	 * @param string           $content    Content.
	 * @param string           $status     Status.
	 * @param array            $properties LifterLMS properties.
	 * @param array            $meta       Meta (not LifterLMS properties).
	 *
	 * @return void
	 */
	private function update( \LLMS_Post_Model $model, string $title, string $content, string $status, array $properties, array $meta ): void {
		$id      = (int) $model->get( 'id' );
		$post    = get_post( $id );
		$changes = array();
		$fields  = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
		);
		foreach ( $fields as $field => $value ) {
			// Compare with what wp_update_post() would store, after its save filters.
			$stored = wp_unslash( sanitize_post_field( $field, wp_slash( $value ), $id, 'db' ) );
			if ( (string) $post->$field !== (string) $stored ) {
				$changes[ $field ] = $value;
			}
		}
		foreach ( $properties as $property => $value ) {
			if ( (string) $model->get( $property ) !== (string) $value ) {
				$changes[ $property ] = $value;
			}
		}
		if ( $changes ) {
			$model->set( $changes );
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
	 * Append post types once.
	 *
	 * @param mixed    $types Existing.
	 * @param string[] $add   To add.
	 *
	 * @return string[]
	 */
	private static function add( mixed $types, array $add ): array {
		$types = is_array( $types ) ? $types : array();
		return array_values( array_unique( array_merge( $types, $add ) ) );
	}
}
