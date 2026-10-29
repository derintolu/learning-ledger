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
 *
 * Lessons, quizzes and stable keys are read from the posts and postmeta tables with $wpdb,
 * not WP_Query: some hosts filter post queries on anonymous requests (pre_get_posts), which
 * is what signed course-server requests are. The reads mirror Sensei 4.26.4's own queries.
 */
final class Sensei_Adapter implements LMS_Adapter, Certificate_Provider {

	const STABLE_KEY = '_lledger_stable_key';

	/**
	 * Lesson meta the cmi5 player import writes: the AU id.
	 */
	const AU_META = '_lledger_au_id';

	/**
	 * Course meta the cmi5 player import writes: the top-level block id.
	 */
	const BLOCK_META = '_lledger_block_id';

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
	 * Map a program IRI, a cmi5 player AU or block id, or a direction-2 IRI to a Sensei object.
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
		return $this->resolve_program( $iri ) ?? $this->resolve_player( $iri ) ?? $this->resolve_direction2( $iri );
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
				(string) get_post_field( 'post_title', $lesson_id, 'raw' ),
				$this->stable_key( $lesson_id ),
				$order > 0 ? $order : $position + 1,
				$this->has_pass_required_quiz( $lesson_id )
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
		// Stable keys are scoped to the course: a key new to this course creates a new lesson.
		$id = $this->find_by_key( 'lesson', $key, array( $course_id ) );
		if ( null === $id ) {
			$id = $this->insert( 'lesson', $spec->title, $spec->content, $spec->status, $meta );
		} else {
			$this->update( $id, $spec->title, $spec->content, $spec->status, $meta );
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
			$id = $this->find_by_key( 'course', $parsed['key'], $courses );
			return null !== $id ? new Lms_Object( 'course', $id ) : null;
		}
		if ( 'lesson' === $parsed['kind'] ) {
			// Lesson keys are unique within a program; a key on two of its lessons resolves to nothing.
			$id = $this->find_by_key( 'lesson', $parsed['key'], $courses, true );
			return null !== $id ? new Lms_Object( 'lesson', $id ) : null;
		}
		return null;
	}

	/**
	 * Resolve a cmi5 player id: an AU id, or its AU activity id `{au}/au`, to the lesson of the
	 * player program's courses carrying it in `_lledger_au_id` (the exact id first); a top-level
	 * block id to the player program course carrying it in `_lledger_block_id`.
	 *
	 * @param string $iri IRI.
	 *
	 * @return Lms_Object|null
	 */
	private function resolve_player( string $iri ): ?Lms_Object {
		$repo = new Program_Repository();
		$pid  = $repo->for_activity( $iri );
		if ( null !== $pid ) {
			$program = $repo->get( $pid );
			$courses = $program ? $program->course_ids : array();
			$ids     = str_ends_with( $iri, '/au' ) ? array( $iri, substr( $iri, 0, -3 ) ) : array( $iri );
			foreach ( $courses ? $ids : array() as $id ) {
				$lesson = $this->find_unique( 'lesson', self::AU_META, $id, $courses );
				if ( null !== $lesson ) {
					return new Lms_Object( 'lesson', $lesson );
				}
			}
		}

		$course = $this->find_unique( 'course', self::BLOCK_META, $iri );
		if ( null === $course ) {
			return null;
		}
		$owner = $repo->for_lms_course( $course );
		return null !== $owner && (int) get_post_meta( $owner, '_lledger_player', true ) ? new Lms_Object( 'course', $course ) : null;
	}

	/**
	 * The one post with a meta value, or null when none or more than one has it.
	 *
	 * @param string $post_type Post type.
	 * @param string $meta_key  Meta key.
	 * @param string $value     Meta value.
	 * @param int[]  $courses   For lessons: limit to these courses when not empty.
	 *
	 * @return integer|null
	 */
	private function find_unique( string $post_type, string $meta_key, string $value, array $courses = array() ): ?int {
		$query = array(
			array(
				'key'   => $meta_key,
				'value' => $value,
			),
		);
		if ( $courses ) {
			$query[] = array(
				'key'     => '_lesson_course',
				'value'   => array_map( 'strval', $courses ),
				'compare' => 'IN',
			);
		}
		$ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 2,
				'no_found_rows'  => true,
				'meta_query'     => $query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		return 1 === count( $ids ) ? (int) $ids[0] : null;
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
			if ( Sensei_Iris::peek( $course ) !== $m[1] ) {
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
		if ( $ids ) {
			return new Lms_Object( 'course', (int) $ids[0] );
		}
		// A course whose base is not fixed yet still answers to the default form.
		if ( 1 === preg_match( '#/courses/(\d+)$#', $iri, $m ) ) {
			$course = (int) $m[1];
			if ( 'course' === get_post_type( $course ) && Sensei_Iris::peek( $course ) === $iri ) {
				return new Lms_Object( 'course', $course );
			}
		}
		return null;
	}

	/**
	 * Lesson IDs of a course in Sensei's order.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return int[]
	 */
	private function lesson_ids( int $course_id ): array {
		global $wpdb;
		if ( $course_id <= 0 ) {
			return array();
		}
		// Sensei_Course::course_lessons( $course_id, 'any', 'ids' ): lessons whose `_lesson_course`
		// is the course, by date, then stably by `_order_<course>` (unset or 0 sorts last).
		$excluded = self::excluded_statuses();
		$ids      = array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT DISTINCT p.ID, p.post_date FROM %i p INNER JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s'
					. ' WHERE p.post_type = %s AND p.post_status NOT IN (' . implode( ',', array_fill( 0, count( $excluded ), '%s' ) ) . ') ORDER BY p.post_date ASC, p.ID ASC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
					array_merge( array( $wpdb->posts, $wpdb->postmeta, '_lesson_course', (string) $course_id, 'lesson' ), $excluded )
				)
			)
		);
		if ( count( $ids ) > 1 ) {
			update_postmeta_cache( $ids );
			$order = array();
			foreach ( $ids as $id ) {
				$order[ $id ] = (int) get_post_meta( $id, '_order_' . $course_id, true );
				$order[ $id ] = $order[ $id ] ? $order[ $id ] : 100000;
			}
			usort( $ids, static fn( int $a, int $b ): int => $order[ $a ] <=> $order[ $b ] );
		}
		$lessons = apply_filters( 'sensei_course_get_lessons', array_values( array_filter( array_map( 'get_post', $ids ) ) ), $course_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Sensei's own filter, as course_lessons() applies it.
		return array_values( array_map( static fn( $lesson ): int => (int) $lesson->ID, (array) $lessons ) );
	}

	/**
	 * Whether the lesson has a quiz with questions that must be passed, as
	 * Sensei_Lesson::lesson_has_quiz_with_questions_and_pass_required() answers it.
	 *
	 * @param integer $lesson_id Lesson ID.
	 *
	 * @return boolean
	 */
	private function has_pass_required_quiz( int $lesson_id ): bool {
		$quiz = $this->lesson_quiz( $lesson_id );
		if ( 0 === $quiz || ! (bool) get_post_meta( $lesson_id, '_quiz_has_questions', true ) ) {
			return false;
		}
		return (bool) get_post_meta( $quiz, '_pass_required', true );
	}

	/**
	 * The lesson's quiz, as Sensei_Lesson::lesson_quizzes( $lesson_id, 'any', 'ids' ) finds it: the
	 * quiz whose parent is the lesson (by title, descending), else the quiz in `_lesson_quiz`.
	 *
	 * @param integer $lesson_id Lesson ID.
	 *
	 * @return integer Quiz ID, 0 when none.
	 */
	private function lesson_quiz( int $lesson_id ): int {
		global $wpdb;
		$excluded = self::excluded_statuses();
		$quiz     = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT ID FROM %i WHERE post_type = %s AND post_parent = %d AND post_status NOT IN (' . implode( ',', array_fill( 0, count( $excluded ), '%s' ) ) . ') ORDER BY post_title DESC, ID DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
				array_merge( array( $wpdb->posts, 'quiz', $lesson_id ), $excluded )
			)
		);
		if ( $quiz > 0 ) {
			return $quiz;
		}
		$meta = (int) get_post_meta( $lesson_id, '_lesson_quiz', true );
		if ( $meta <= 0 ) {
			return 0;
		}
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT ID FROM %i WHERE ID = %d AND post_type = %s AND post_status NOT IN (' . implode( ',', array_fill( 0, count( $excluded ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
				array_merge( array( $wpdb->posts, $meta, 'quiz' ), $excluded )
			)
		);
	}

	/**
	 * Statuses WP_Query's `post_status => 'any'` leaves out (trash, auto-draft, ...).
	 *
	 * @return string[]
	 */
	private static function excluded_statuses(): array {
		$statuses = array_values( get_post_stati( array( 'exclude_from_search' => true ) ) );
		return $statuses ? $statuses : array( 'trash' );
	}

	/**
	 * Post with a stable key.
	 *
	 * @param string  $post_type Post type.
	 * @param string  $key       Stable key.
	 * @param int[]   $courses   When not empty: lessons of these courses, or (for courses) these courses only.
	 * @param boolean $unique    Null unless exactly one post matches.
	 *
	 * @return integer|null
	 */
	private function find_by_key( string $post_type, string $key, array $courses = array(), bool $unique = false ): ?int {
		global $wpdb;
		if ( '' === $key ) {
			return null;
		}
		$excluded = self::excluded_statuses();
		$sql      = 'SELECT DISTINCT p.ID FROM %i p INNER JOIN %i k ON k.post_id = p.ID AND k.meta_key = %s AND k.meta_value = %s';
		$args     = array( $wpdb->posts, $wpdb->postmeta, self::STABLE_KEY, $key );
		$where    = '';
		if ( $courses && 'course' === $post_type ) {
			$where .= ' AND p.ID IN (' . implode( ',', array_fill( 0, count( $courses ), '%d' ) ) . ')';
		} elseif ( $courses ) {
			$sql   .= ' INNER JOIN %i c ON c.post_id = p.ID AND c.meta_key = %s AND c.meta_value IN (' . implode( ',', array_fill( 0, count( $courses ), '%s' ) ) . ')';
			$args[] = $wpdb->postmeta;
			$args[] = '_lesson_course';
			$args   = array_merge( $args, array_map( 'strval', $courses ) );
		}
		$sql .= ' WHERE p.post_type = %s AND p.post_status NOT IN (' . implode( ',', array_fill( 0, count( $excluded ), '%s' ) ) . ')' . $where . ' ORDER BY p.ID ASC LIMIT %d';
		$args = array_merge( $args, array( $post_type ), $excluded, $courses && 'course' === $post_type ? array_map( 'intval', $courses ) : array(), array( $unique ? 2 : 1 ) );
		$ids  = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql holds placeholders only.
		if ( ! $ids || ( $unique && count( $ids ) > 1 ) ) {
			return null;
		}
		return (int) $ids[0];
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
