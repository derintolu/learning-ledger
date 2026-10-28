<?php
/**
 * LearnPress adapter.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Learnpress;

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
use LearnPress\Models\CourseModel;
use LearnPress\Models\CourseSectionItemModel;
use LearnPress\Models\UserItems\UserCourseModel;
use LearnPress\Models\UserItems\UserItemModel;
use LearnPress\Models\UserItems\UserLessonModel;

// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Slash-namespaced hook names are the plugin convention.

/**
 * Talks to LearnPress only through its public PHP classes (LP_User, its
 * models and LP_Section_CURD) and its post types. Never LearnPress's tables.
 */
final class Learnpress_Adapter implements LMS_Adapter {

	const ID = 'learnpress';

	const COURSE = 'lp_course';

	const LESSON = 'lp_lesson';

	const QUIZ = 'lp_quiz';

	const STABLE_KEY = '_lledger_stable_key';

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
		add_filter( 'learning_ledger/direction2_iris', array( Learnpress_Iris::class, 'direction2_iris' ), 10, 2 );
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
	 * Add the LearnPress version to the status payload.
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

		$lms[ self::ID ] = defined( 'LEARNPRESS_VERSION' ) ? (string) LEARNPRESS_VERSION : null;

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
	 * Installed LearnPress version.
	 *
	 * @return string
	 */
	public function lms_version(): string {
		return (string) LEARNPRESS_VERSION;
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
	 * Delegate to Learnpress_Listeners.
	 *
	 * @param Event_Sink $sink Sink.
	 *
	 * @return void
	 */
	public function register_event_listeners( Event_Sink $sink ): void {
		( new Learnpress_Listeners() )->register( $sink );
	}

	/**
	 * Delegate to Learnpress_Applier.
	 *
	 * @param Result $r Result.
	 *
	 * @return Apply_Outcome
	 */
	public function apply( Result $r ): Apply_Outcome {
		return ( new Learnpress_Applier() )->apply( $r );
	}

	/**
	 * Map a program IRI or a direction-2 IRI to a LearnPress object.
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
	 * Course structure: the lessons of the LearnPress curriculum, in order.
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
			// LearnPress quizzes are course items of their own, never part of a lesson.
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
		$id   = $this->find_by_key( self::COURSE, $key );
		if ( null === $id ) {
			$id = $this->insert( self::COURSE, $spec->title, $spec->content, $spec->status, $meta );
		} else {
			$this->update( $id, $spec->title, $spec->content, $spec->status, $meta );
		}
		if ( $id > 0 ) {
			$this->write_course_record( $id );
		}
		return $id;
	}

	/**
	 * Write LearnPress's course record (its learnpress_courses row) from the
	 * post. LearnPress writes it in its own save_post hook, but only when the
	 * current user may edit the course; an import from WP-CLI or cron has no
	 * such user, so the record is written here the same way, forced.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return void
	 */
	private function write_course_record( int $course_id ): void {
		if ( current_user_can( 'edit_' . self::COURSE, $course_id ) ) {
			return;
		}
		$post  = get_post( $course_id );
		$model = CourseModel::find( $course_id );
		if ( ! $post || ! $model instanceof CourseModel ) {
			return;
		}
		$model                 = new CourseModel( array_merge( get_object_vars( $model ), (array) $post ) );
		$model->meta_data      = $model->get_post_model()->get_all_metadata();
		$model->sections_items = null;
		$model->total_items    = null;
		$model->first_item_id  = null;
		$model->save( true );
	}

	/**
	 * Create or update a lesson by stable key and place it in the course's curriculum.
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
		// Stable keys are scoped to the course: a key new to this course creates a new lesson.
		$id = $this->find_by_key( self::LESSON, $key, array( $course_id ) );
		if ( null === $id ) {
			$id = $this->insert( self::LESSON, $spec->title, $spec->content, $spec->status, $meta );
		} else {
			$this->update( $id, $spec->title, $spec->content, $spec->status, $meta );
		}
		if ( $id > 0 ) {
			$this->place( $course_id, $id, $spec->order );
		}
		return $id;
	}

	/**
	 * Enrol through LearnPress's user-course model, as LearnPress's own
	 * enrol ability does.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return boolean
	 */
	public function enrol( int $user_id, int $course_id ): bool {
		if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) || self::COURSE !== get_post_type( $course_id ) ) {
			return false;
		}
		$enrolment = UserCourseModel::find( $user_id, $course_id );
		if ( $enrolment instanceof UserCourseModel && $enrolment->has_enrolled_or_finished() ) {
			return true;
		}
		if ( ! $enrolment instanceof UserCourseModel ) {
			$enrolment            = new UserCourseModel();
			$enrolment->user_id   = $user_id;
			$enrolment->item_id   = $course_id;
			$enrolment->item_type = LP_COURSE_CPT;
			$enrolment->ref_type  = '';
		}
		$enrolment->status     = UserItemModel::STATUS_ENROLLED;
		$enrolment->graduation = UserItemModel::GRADUATION_IN_PROGRESS;
		$enrolment->start_time = gmdate( 'Y-m-d H:i:s' );
		$enrolment->save();
		$saved = UserCourseModel::find( $user_id, $course_id );
		return $saved instanceof UserCourseModel && $saved->has_enrolled_or_finished();
	}

	/**
	 * Progress from LearnPress and the waiver ledger.
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
			$item = UserLessonModel::find_user_item( $user_id, $lesson_id, LP_LESSON_CPT, $course_id, LP_COURSE_CPT );
			if ( $item instanceof UserLessonModel && UserItemModel::STATUS_COMPLETED === $item->get_status() ) {
				$completed[] = $lesson_id;
			}
			if ( $ledger->is_waived( $user_id, $lesson_id ) ) {
				$tested_out[] = $lesson_id;
			}
		}
		$enrolment = $user_id > 0 && $course_id > 0 ? UserCourseModel::find( $user_id, $course_id ) : false;
		return new Progress( $completed, $tested_out, $enrolment instanceof UserCourseModel && $enrolment->has_finished(), null );
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
	 * The course whose curriculum holds a lesson.
	 *
	 * @param integer $lesson_id Lesson ID.
	 *
	 * @return integer 0 when none.
	 */
	public function course_of_lesson( int $lesson_id ): int {
		foreach ( $this->courses_of( $lesson_id, self::LESSON ) as $course ) {
			return $course;
		}
		return 0;
	}

	/**
	 * Courses whose curriculum holds an item.
	 *
	 * @param integer $item_id   Item ID.
	 * @param string  $item_type Item post type, lp_lesson or lp_quiz.
	 *
	 * @return int[]
	 */
	private function courses_of( int $item_id, string $item_type ): array {
		if ( $item_id <= 0 || get_post_type( $item_id ) !== $item_type ) {
			return array();
		}
		$courses = array();
		foreach ( CourseSectionItemModel::get_courses_from_item_id( $item_id, $item_type ) as $row ) {
			$course = (int) ( is_object( $row ) ? ( $row->section_course_id ?? 0 ) : $row );
			if ( self::COURSE === get_post_type( $course ) ) {
				$courses[] = $course;
			}
		}
		return array_values( array_unique( $courses ) );
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
			$id = $this->find_by_key( self::COURSE, $parsed['key'], $courses );
			return null !== $id ? new Lms_Object( 'course', $id ) : null;
		}
		if ( 'lesson' === $parsed['kind'] ) {
			// Lesson keys are unique per course only: a key on lessons of two of the program's courses names neither.
			$id = $this->find_by_key( self::LESSON, $parsed['key'], $courses, true );
			return null !== $id ? new Lms_Object( 'lesson', $id ) : null;
		}
		return null;
	}

	/**
	 * Resolve a direction-2 IRI (Learnpress_Iris).
	 *
	 * @param string $iri IRI.
	 *
	 * @return Lms_Object|null
	 */
	private function resolve_direction2( string $iri ): ?Lms_Object {
		if ( 1 === preg_match( '#^(.+)/(lessons|quizzes)/(\d+)$#', $iri, $m ) ) {
			$kind = 'lessons' === $m[2] ? 'lesson' : 'quiz';
			$id   = (int) $m[3];
			foreach ( $this->courses_of( $id, 'lesson' === $kind ? self::LESSON : self::QUIZ ) as $course ) {
				if ( Learnpress_Iris::peek( $course ) === $m[1] ) {
					return new Lms_Object( $kind, $id );
				}
			}
			return null;
		}

		$ids = get_posts(
			array(
				'post_type'      => self::COURSE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => Learnpress_Iris::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
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
			if ( self::COURSE === get_post_type( $course ) && Learnpress_Iris::peek( $course ) === $iri ) {
				return new Lms_Object( 'course', $course );
			}
		}
		return null;
	}

	/**
	 * The course's sections and their items, in LearnPress's order. Read
	 * without LearnPress's per-request cache, which outlives writes made here.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return array<int, object> Sections with section_id and items (item_id, item_type, item_order).
	 */
	private function sections( int $course_id ): array {
		if ( $course_id <= 0 ) {
			return array();
		}
		$model = CourseModel::find( $course_id );
		return $model instanceof CourseModel ? array_values( (array) $model->get_section_items() ) : array();
	}

	/**
	 * Lesson IDs of a course in curriculum order.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return int[]
	 */
	private function lesson_ids( int $course_id ): array {
		$ids = array();
		foreach ( $this->sections( $course_id ) as $section ) {
			foreach ( (array) ( $section->items ?? array() ) as $item ) {
				if ( self::LESSON === ( $item->item_type ?? '' ) ) {
					$ids[] = (int) $item->item_id;
				}
			}
		}
		return $ids;
	}

	/**
	 * Put a lesson in the course's curriculum at the given order: in the
	 * section that already holds it, else in the first section (created when
	 * the course has none). LP_Section_CURD rather than the section models,
	 * whose saves require the current user to edit the course; imports also
	 * run from cron and WP-CLI.
	 *
	 * @param integer $course_id Course ID.
	 * @param integer $lesson_id Lesson ID.
	 * @param integer $order     Order.
	 *
	 * @return void
	 */
	private function place( int $course_id, int $lesson_id, int $order ): void {
		$sections = $this->sections( $course_id );
		$target   = 0;
		foreach ( $sections as $section ) {
			foreach ( (array) ( $section->items ?? array() ) as $item ) {
				if ( (int) $item->item_id === $lesson_id && self::LESSON === ( $item->item_type ?? '' ) ) {
					if ( (int) $item->item_order === $order ) {
						return;
					}
					$target = (int) $section->section_id;
					break 2;
				}
			}
		}

		$curd = new \LP_Section_CURD( $course_id );
		if ( 0 === $target && $sections ) {
			$target = (int) $sections[0]->section_id;
		}
		if ( 0 === $target ) {
			$args    = array(
				'section_course_id' => $course_id,
				'section_name'      => get_the_title( $course_id ),
			);
			$created = $curd->create( $args );
			$target  = (int) ( $created['section_id'] ?? 0 );
			if ( $target <= 0 ) {
				return;
			}
		}
		$curd->assign_item_section(
			$target,
			array(
				'item_id'    => $lesson_id,
				'item_type'  => self::LESSON,
				'item_order' => $order,
			)
		);
		$this->forget_curriculum( $course_id );
	}

	/**
	 * Make LearnPress read the curriculum again: clear the section list it
	 * keeps in its course record, as its own section models do after a change.
	 *
	 * @param integer $course_id Course ID.
	 *
	 * @return void
	 */
	private function forget_curriculum( int $course_id ): void {
		$filter     = new \LP_Course_JSON_Filter();
		$filter->ID = $course_id;
		$stored     = CourseModel::get_item_model_from_db( $filter );
		if ( $stored instanceof CourseModel ) {
			$stored->sections_items = null;
			$stored->total_items    = null;
			$stored->first_item_id  = null;
			// Forced: the course is the connector's to write, whoever the current user is.
			$stored->save( true );
			return;
		}
		$model = CourseModel::find( $course_id );
		if ( $model instanceof CourseModel ) {
			$model->clean_caches();
		}
	}

	/**
	 * Post with a stable key.
	 *
	 * @param string  $post_type Post type.
	 * @param string  $key       Stable key.
	 * @param int[]   $courses   When not empty: lessons of these courses' curricula, or (for courses) these courses only.
	 * @param boolean $unique    Null unless exactly one post matches.
	 *
	 * @return integer|null
	 */
	private function find_by_key( string $post_type, string $key, array $courses = array(), bool $unique = false ): ?int {
		if ( '' === $key ) {
			return null;
		}
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => $unique ? 2 : 1,
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
		if ( $courses && self::COURSE === $post_type ) {
			$args['post__in'] = array_map( 'intval', $courses );
		} elseif ( $courses ) {
			$in = array();
			foreach ( $courses as $course ) {
				$in = array_merge( $in, $this->lesson_ids( (int) $course ) );
			}
			if ( ! $in ) {
				return null;
			}
			$args['post__in'] = $in;
		}
		$ids = get_posts( $args );
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
