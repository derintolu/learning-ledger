<?php
/**
 * Sensei event listeners (direction 2).
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Ledger\Echo_Guard;
use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Outbox\Outbox_Sink;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
use LearningLedger\Integrations\Sensei\Sensei_Integration;
use LearningLedger\Integrations\Sensei\Sensei_Listeners;
use LearningLedger\Queue\Shutdown_Runner;
use LearningLedger\Tests\Recording_Sink;

/**
 * Sensei listener tests.
 *
 * @group sensei-contract
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Sensei_Listeners_Test extends WP_UnitTestCase {

	private int $user;

	private int $course;

	private int $lesson;

	private int $http_calls = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'conn_outbox', 'applied', 'registrations' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Sensei_Integration::boot();
		$this->user   = self::factory()->user->create();
		$this->course = self::factory()->post->create(
			array(
				'post_type'   => 'course',
				'post_status' => 'publish',
			)
		);
		$this->lesson = $this->lesson_in( $this->course );

		$this->http_calls = 0;
		add_filter( 'pre_http_request', array( $this, 'no_http' ), 10, 3 );
		remove_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'no_http' ), 10 );
		remove_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) );
		$this->assertSame( 0, $this->http_calls, 'Listeners must not make HTTP calls.' );
		parent::tear_down();
	}

	public function no_http( $pre, $args, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		++$this->http_calls;
		return new WP_Error( 'http_not_allowed', 'No HTTP during an LMS action.' );
	}

	private function lesson_in( int $course ): int {
		$lesson = self::factory()->post->create(
			array(
				'post_type'   => 'lesson',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $lesson, '_lesson_course', $course );
		update_post_meta( $lesson, '_order_' . $course, 1 );
		return $lesson;
	}

	private function quiz_for( int $lesson, int $passmark ): int {
		$quiz = self::factory()->post->create(
			array(
				'post_type'   => 'quiz',
				'post_status' => 'publish',
				'post_parent' => $lesson,
			)
		);
		update_post_meta( $quiz, '_quiz_lesson', $lesson );
		update_post_meta( $quiz, '_quiz_passmark', $passmark );
		update_post_meta( $quiz, '_pass_required', 'on' );
		update_post_meta( $lesson, '_lesson_quiz', $quiz );
		return $quiz;
	}

	/**
	 * A quiz submission Sensei can grade: lesson status, one asked question, a submission row.
	 * Test setup only; the listeners never touch Sensei's storage.
	 */
	private function submit( int $quiz ): void {
		Sensei_Utils::sensei_start_lesson( $this->lesson, $this->user );
		$question = self::factory()->post->create( array( 'post_type' => 'question' ) );
		update_comment_meta( Sensei_Utils::user_lesson_status( $this->lesson, $this->user )->comment_ID, 'questions_asked', (string) $question );
		Sensei()->quiz_submission_repository->create( $quiz, $this->user, 0.0 );
	}

	private function listen(): Recording_Sink {
		$sink = new Recording_Sink();
		( new Sensei_Listeners() )->register( $sink );
		return $sink;
	}

	/**
	 * Events of a type.
	 *
	 * @return Neutral_Event[]
	 */
	private function of_type( Recording_Sink $sink, string $type ): array {
		return array_values( array_filter( $sink->events, static fn( Neutral_Event $e ) => $type === $e->type ) );
	}

	private function outbox_rows(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . Tables::name( 'conn_outbox' ) . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_completing_a_lesson_emits_started_and_completed() {
		$sink = $this->listen();

		Sensei_Utils::sensei_start_lesson( $this->lesson, $this->user, true );

		$started   = $this->of_type( $sink, Neutral_Event::LESSON_STARTED );
		$completed = $this->of_type( $sink, Neutral_Event::LESSON_COMPLETED );
		$this->assertCount( 1, $started );
		$this->assertCount( 1, $completed );
		foreach ( array( $started[0], $completed[0] ) as $e ) {
			$this->assertSame( 'sensei', $e->lms );
			$this->assertSame( $this->user, $e->user_id );
			$this->assertSame( $this->course, $e->course_id );
			$this->assertSame( $this->lesson, $e->lesson_id );
		}
		$types = array_map( static fn( Neutral_Event $e ) => $e->type, $sink->events );
		$this->assertLessThan( array_search( Neutral_Event::LESSON_COMPLETED, $types, true ), array_search( Neutral_Event::LESSON_STARTED, $types, true ) );

		$course_started = $this->of_type( $sink, Neutral_Event::COURSE_STARTED );
		$this->assertCount( 1, $course_started );
		$this->assertNull( $course_started[0]->lesson_id );
		$this->assertSame( $this->course, $course_started[0]->course_id );
	}

	public function test_completing_the_last_lesson_emits_course_completed() {
		$sink = $this->listen();

		Sensei_Utils::sensei_start_lesson( $this->lesson, $this->user, true );

		$this->assertTrue( (bool) Sensei_Utils::user_completed_course( $this->course, $this->user ) );
		$done = $this->of_type( $sink, Neutral_Event::COURSE_COMPLETED );
		$this->assertCount( 1, $done );
		$this->assertSame( $this->course, $done[0]->course_id );
		$this->assertSame( $this->user, $done[0]->user_id );
		$this->assertNull( $done[0]->lesson_id );
	}

	public function test_grading_a_quiz_emits_quiz_graded_with_scaled_score_and_success() {
		$quiz = $this->quiz_for( $this->lesson, 70 );
		$this->submit( $quiz );
		$sink = $this->listen();

		$this->assertTrue( Sensei_Utils::sensei_grade_quiz( $quiz, 80, $this->user ) );

		$graded = $this->of_type( $sink, Neutral_Event::QUIZ_GRADED );
		$this->assertCount( 1, $graded );
		$e = $graded[0];
		$this->assertSame( 'sensei', $e->lms );
		$this->assertSame( $this->user, $e->user_id );
		$this->assertSame( $this->course, $e->course_id );
		$this->assertSame( $this->lesson, $e->lesson_id );
		$this->assertSame( $quiz, $e->quiz_id );
		$this->assertSame( 0.8, $e->score_scaled );
		$this->assertTrue( $e->success );
	}

	public function test_grade_below_passmark_is_not_success() {
		$quiz = $this->quiz_for( $this->lesson, 70 );
		$this->submit( $quiz );
		$sink = $this->listen();

		Sensei_Utils::sensei_grade_quiz( $quiz, 50, $this->user );

		$graded = $this->of_type( $sink, Neutral_Event::QUIZ_GRADED );
		$this->assertCount( 1, $graded );
		$this->assertSame( 0.5, $graded[0]->score_scaled );
		$this->assertFalse( $graded[0]->success );
	}

	public function test_quiz_submitted_carries_score_or_none_when_ungraded() {
		$quiz = $this->quiz_for( $this->lesson, 70 );
		$sink = $this->listen();

		do_action( 'sensei_user_quiz_submitted', $this->user, $quiz, 90, 70.0, 'auto' );
		do_action( 'sensei_user_quiz_submitted', $this->user, $quiz, false, 70.0, 'manual' );

		$submitted = $this->of_type( $sink, Neutral_Event::QUIZ_SUBMITTED );
		$this->assertCount( 2, $submitted );
		$this->assertSame( $this->lesson, $submitted[0]->lesson_id );
		$this->assertSame( $this->course, $submitted[0]->course_id );
		$this->assertSame( $quiz, $submitted[0]->quiz_id );
		$this->assertSame( 0.9, $submitted[0]->score_scaled );
		$this->assertNull( $submitted[1]->score_scaled );
	}

	public function test_course_reset_emits_progress_reset_without_lesson() {
		Sensei_Utils::sensei_start_lesson( $this->lesson, $this->user, true );
		$sink = $this->listen();

		Sensei_Utils::sensei_remove_user_from_course( $this->course, $this->user );

		// Sensei also fires sensei_user_lesson_reset per lesson (from Sensei_Quiz::reset_user_lesson_data).
		$reset  = $this->of_type( $sink, Neutral_Event::PROGRESS_RESET );
		$course = array_values( array_filter( $reset, static fn( Neutral_Event $e ) => null === $e->lesson_id ) );
		$this->assertCount( 1, $course );
		$this->assertSame( $this->course, $course[0]->course_id );
		$this->assertSame( $this->user, $course[0]->user_id );
		$this->assertSame( Neutral_Event::PROGRESS_RESET, end( $sink->events )->type );
		$this->assertNull( end( $sink->events )->lesson_id );
	}

	public function test_lesson_reset_emits_progress_reset_for_the_lesson() {
		Sensei_Utils::sensei_start_lesson( $this->lesson, $this->user, true );
		$sink = $this->listen();

		Sensei_Utils::sensei_remove_user_from_lesson( $this->lesson, $this->user );

		// Sensei 4.26.4 fires sensei_user_lesson_reset twice here (quiz data reset, then lesson removal).
		$reset = $this->of_type( $sink, Neutral_Event::PROGRESS_RESET );
		$this->assertNotEmpty( $reset );
		foreach ( $reset as $e ) {
			$this->assertSame( $this->course, $e->course_id );
			$this->assertSame( $this->lesson, $e->lesson_id );
		}
	}

	public function test_enrolment_changes_emit_enrolled_and_unenrolled() {
		$sink = $this->listen();

		$enrolment = Sensei_Course_Enrolment::get_course_instance( $this->course );

		// Sensei defers the enrolment check to shutdown; run it as shutdown would.
		$this->assertTrue( ( new Sensei_Adapter() )->enrol( $this->user, $this->course ) );
		$this->assertTrue( $enrolment->is_enrolled( $this->user, false ) );
		Sensei_Course_Enrolment_Manager::instance()->get_manual_enrolment_provider()->withdraw_learner( $this->user, $this->course );
		$this->assertFalse( $enrolment->is_enrolled( $this->user, false ) );

		$enrolled   = $this->of_type( $sink, Neutral_Event::ENROLLED );
		$unenrolled = $this->of_type( $sink, Neutral_Event::UNENROLLED );
		$this->assertCount( 1, $enrolled );
		$this->assertCount( 1, $unenrolled );
		$this->assertSame( $this->course, $enrolled[0]->course_id );
		$this->assertSame( $this->user, $enrolled[0]->user_id );
		$this->assertNull( $enrolled[0]->lesson_id );
		$this->assertSame( $this->course, $unenrolled[0]->course_id );
	}

	public function test_lesson_outside_a_course_emits_nothing() {
		$orphan = self::factory()->post->create( array( 'post_type' => 'lesson' ) );
		$sink   = $this->listen();

		do_action( 'sensei_user_lesson_end', $this->user, $orphan );

		$this->assertSame( array(), $sink->events );
	}

	public function test_outbox_sink_queues_learner_completion() {
		( new Sensei_Listeners() )->register( new Outbox_Sink() );

		Sensei_Utils::sensei_start_lesson( $this->lesson, $this->user, true );

		$verbs = array_map(
			static fn( array $row ) => json_decode( $row['body'], true )['verb']['id'],
			$this->outbox_rows()
		);
		$this->assertContains( 'http://adlnet.gov/expapi/verbs/completed', $verbs );
	}

	public function test_nothing_reaches_the_outbox_inside_echo_guard() {
		( new Sensei_Listeners() )->register( new Outbox_Sink() );

		Echo_Guard::while_applying(
			function () {
				Sensei_Utils::sensei_start_lesson( $this->lesson, $this->user, true );
			}
		);

		$this->assertCount( 0, $this->outbox_rows() );
	}
}
