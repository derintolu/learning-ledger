<?php
/**
 * Tutor LMS adapter.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Tutor;

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
 * Talks to Tutor only through its public functions and models, its post types
 * and its documented user meta. A Tutor course holds topics, and each topic
 * holds lessons and quizzes, ordered by menu_order.
 */
final class Tutor_Adapter implements LMS_Adapter {

	const ID         = 'tutor';
	const COURSE     = 'courses';
	const LESSON     = 'lesson';
	const TOPIC      = 'topics';
	const QUIZ       = 'tutor_quiz';
	const STABLE_KEY = '_lledger_stable_key';

	/**
	 * Marks the topic the importer puts new lessons in.
	 */
	const TOPIC_META = '_lledger_topic';

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
		add_filter( 'learning_ledger/direction2_iris', array( Tutor_Iris::class, 'direction2' ), 10, 2 );
	}

	/**
	 * Post types that carry a stable key.
	 *
	 * @param mixed $types Post types.
	 *
	 * @return string[]
	 */
	public static function stable_key_post_types( mixed $types ): array {
		return self::add( $types, array( self::COURSE, self::LESSON ) );
	}

	/**
	 * Lesson post types.
	 *
	 * @param mixed $types Post types.
	 *
	 * @return string[]
	 */
	public static function lesson_post_types( mixed $types ): array {
		return self::add( $types, array( self::LESSON ) );
	}

	/**
	 * Course post types.
	 *
	 * @param mixed $types Post types.
	 *
	 * @return string[]
	 */
	public static function course_post_types( mixed $types ): array {
		return self::add( $types, array( self::COURSE ) );
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
		$map[ self::ID ] = array( self::COURSE, self::LESSON, self::QUIZ );
		return $map;
	}

	/**
	 * Add the Tutor LMS version to the status payload.
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

		$lms[ self::ID ] = defined( 'TUTOR_VERSION' ) ? (string) TUTOR_VERSION : null;

		$payload['connector'] = array_merge( $connector, array( 'lms' => $lms ) );
		return $payload;
	}

	/**
	 * Course of a lesson or quiz: its topic's course, or, for content left
	 * without a topic, Tutor's `_tutor_course_id_for_lesson` meta.
	 *
	 * @param integer $id Lesson or quiz ID.
	 *
	 * @return integer 0 when none.
	 */
	public static function course_of( int $id ): int {
		if ( $id <= 0 ) {
			return 0;
		}
		$topic  = (int) wp_get_post_parent_id( $id );
		$course = self::TOPIC === get_post_type( $topic ) ? (int) wp_get_post_parent_id( $topic ) : (int) get_post_meta( $id, '_tutor_course_id_for_lesson', true );
		return self::COURSE === get_post_type( $course ) ? $course : 0;
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
	 * Installed Tutor version.
	 *
	 * @return string
	 */
	public function lms_version(): string {
		return (string) TUTOR_VERSION;
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
	 * Delegate to Tutor_Listeners.
	 *
	 * @param Event_Sink $sink Sink.
	 *
	 * @return void
	 */
	public function register_event_listeners( Event_Sink $sink ): void {
		( new Tutor_Listeners() )->register( $sink );
	}

	/**
	 * Delegate to Tutor_Applier.
	 *
	 * @param Result $r Result.
	 *
	 * @return Apply_Outcome
	 */
	public function apply( Result $r ): Apply_Outcome {
		return ( new Tutor_Applier() )->apply( $r );
	}

	/**
	 * Map a program IRI or a direction-2 IRI to a Tutor object.
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
	 * Course structure: lessons in topic order, then lesson order.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return Course_Tree
	 */
	public function course_tree( int $course_id ): Course_Tree {
		$course = get_post( $course_id );
		if ( ! $course || self::COURSE !== $course->post_type ) {
			return new Course_Tree( $course_id, '', null, array() );
		}
		$nodes = array();
		foreach ( $this->lesson_ids( $course_id ) as $position => $lesson_id ) {
			// A Tutor quiz is course content of its own, never part of a lesson.
			$nodes[] = new Lesson_Node( $lesson_id, get_the_title( $lesson_id ), $this->stable_key( $lesson_id ), $position + 1, false );
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
		$post = array(
			'post_title'   => $spec->title,
			'post_content' => $spec->content,
			'post_status'  => $spec->status,
		);
		$id   = $this->find_by_key( self::COURSE, $key );
		if ( null === $id ) {
			return $this->insert( self::COURSE, $post, $meta );
		}
		$this->update( $id, $post, $meta );
		return $id;
	}

	/**
	 * Create or update a lesson by stable key. A new lesson goes into the
	 * course's importer topic; an existing one stays in its topic.
	 *
	 * @param integer     $course_id Course ID.
	 * @param Lesson_Spec $spec      Spec.
	 *
	 * @return integer
	 */
	public function upsert_lesson( int $course_id, Lesson_Spec $spec ): int {
		$key = Program_Module::sanitize_stable_key( $spec->stable_key );
		if ( '' === $key || self::COURSE !== get_post_type( $course_id ) ) {
			return 0;
		}
		$meta = array_merge( $spec->meta, array( self::STABLE_KEY => $key ) );
		$post = array(
			'post_title'   => $spec->title,
			'post_content' => $spec->content,
			'post_status'  => $spec->status,
			'menu_order'   => $spec->order,
		);
		// Stable keys are scoped to the course: a key new to this course creates a new lesson.
		$id = $this->find_by_key( self::LESSON, $key, array( $course_id ) );
		if ( null !== $id ) {
			$this->update( $id, $post, $meta );
			return $id;
		}
		$topic = $this->import_topic( $course_id );
		if ( $topic <= 0 ) {
			return 0;
		}
		return $this->insert( self::LESSON, array_merge( $post, array( 'post_parent' => $topic ) ), $meta );
	}

	/**
	 * Enrol through Tutor's own enrolment. A paid course gets a pending
	 * enrolment, which does not count.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return boolean
	 */
	public function enrol( int $user_id, int $course_id ): bool {
		if ( $user_id <= 0 || self::COURSE !== get_post_type( $course_id ) ) {
			return false;
		}
		if ( ! tutor_utils()->is_enrolled( $course_id, $user_id ) ) {
			tutor_utils()->do_enroll( $course_id, 0, $user_id );
		}
		return (bool) tutor_utils()->is_enrolled( $course_id, $user_id );
	}

	/**
	 * Progress from Tutor and the waiver ledger.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return Progress
	 */
	public function progress( int $user_id, int $course_id ): Progress {
		if ( $user_id <= 0 ) {
			return new Progress( array(), array(), false, null );
		}
		$ledger     = new Waiver_Ledger();
		$completed  = array();
		$tested_out = array();
		foreach ( $this->lesson_ids( $course_id ) as $lesson_id ) {
			if ( tutor_utils()->is_completed_lesson( $lesson_id, $user_id ) ) {
				$completed[] = $lesson_id;
			}
			if ( $ledger->is_waived( $user_id, $lesson_id ) ) {
				$tested_out[] = $lesson_id;
			}
		}
		// Tutor caches a course-completion miss for the request, so ask it uncached.
		return new Progress( $completed, $tested_out, (bool) tutor_utils()->is_completed_course( $course_id, $user_id, false ), null );
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
			$id = $this->find_by_key( self::COURSE, $parsed['key'] );
			return null !== $id && ( ! $courses || in_array( $id, $courses, true ) ) ? new Lms_Object( 'course', $id ) : null;
		}
		if ( 'lesson' === $parsed['kind'] ) {
			$id = $this->find_by_key( self::LESSON, $parsed['key'], $courses );
			return null !== $id ? new Lms_Object( 'lesson', $id ) : null;
		}
		return null;
	}

	/**
	 * Resolve a direction-2 IRI (Tutor_Iris).
	 *
	 * @param string $iri IRI.
	 *
	 * @return Lms_Object|null
	 */
	private function resolve_direction2( string $iri ): ?Lms_Object {
		if ( 1 === preg_match( '#^(.+)/(lessons|quizzes)/(\d+)$#', $iri, $m ) ) {
			$id     = (int) $m[3];
			$type   = 'lessons' === $m[2] ? self::LESSON : self::QUIZ;
			$course = self::course_of( $id );
			if ( get_post_type( $id ) !== $type || $course <= 0 || Tutor_Iris::peek( $course ) !== $m[1] ) {
				return null;
			}
			return new Lms_Object( self::LESSON === $type ? 'lesson' : 'quiz', $id );
		}

		$ids = get_posts(
			array(
				'post_type'      => self::COURSE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => Tutor_Iris::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
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
			if ( self::COURSE === get_post_type( $course ) && Tutor_Iris::peek( $course ) === $iri ) {
				return new Lms_Object( 'course', $course );
			}
		}
		return null;
	}

	/**
	 * Lesson IDs of a course: topics by menu_order, lessons in each by menu_order.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return int[]
	 */
	private function lesson_ids( int $course_id ): array {
		if ( $course_id <= 0 ) {
			return array();
		}
		$ids = array();
		foreach ( $this->children( self::TOPIC, $course_id ) as $topic ) {
			$ids = array_merge( $ids, $this->children( self::LESSON, $topic ) );
		}
		return $ids;
	}

	/**
	 * Child posts of one type, in menu order.
	 *
	 * @param string  $post_type Post type.
	 * @param integer $parent_id Parent post ID.
	 *
	 * @return int[]
	 */
	private function children( string $post_type, int $parent_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_parent'    => $parent_id,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'no_found_rows'  => true,
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * The topic of a course that new imported lessons go into, created on first use.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return integer 0 on failure.
	 */
	private function import_topic( int $course_id ): int {
		$ids = get_posts(
			array(
				'post_type'      => self::TOPIC,
				'post_parent'    => $course_id,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_key'       => self::TOPIC_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( $ids ) {
			return (int) $ids[0];
		}
		$topics = $this->children( self::TOPIC, $course_id );
		$last   = $topics ? (int) get_post_field( 'menu_order', end( $topics ) ) : 0;
		return $this->insert(
			self::TOPIC,
			array(
				'post_title'  => __( 'Lessons', 'learning-ledger' ),
				'post_status' => 'publish',
				'post_parent' => $course_id,
				'menu_order'  => $last + 1,
			),
			array( self::TOPIC_META => '1' )
		);
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
		$ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => $courses ? -1 : 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_key'       => self::STABLE_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $ids as $id ) {
			// A lesson's course is its topic's parent, which a meta query cannot reach.
			if ( ! $courses || in_array( self::course_of( (int) $id ), array_map( 'intval', $courses ), true ) ) {
				return (int) $id;
			}
		}
		return null;
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
	 * @param array  $post      Post fields.
	 * @param array  $meta      Meta.
	 *
	 * @return integer
	 */
	private function insert( string $post_type, array $post, array $meta ): int {
		$id = wp_insert_post(
			wp_slash(
				array_merge(
					$post,
					array(
						'post_type'  => $post_type,
						'meta_input' => $meta,
					)
				)
			),
			true
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Update a post only where its fields or meta differ.
	 *
	 * @param integer $id     Post ID.
	 * @param array   $fields Post fields.
	 * @param array   $meta   Meta.
	 *
	 * @return void
	 */
	private function update( int $id, array $fields, array $meta ): void {
		$post    = get_post( $id );
		$changes = array();
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
