<?php
/**
 * Sensei direction 1: applying LRS results.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Db\Installer;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
use LearningLedger\Integrations\Sensei\Sensei_Applier;
use LearningLedger\Integrations\Sensei\Sensei_Integration;
use LearningLedger\Util\Clock;

/**
 * Sensei applier tests.
 *
 * @group sensei-contract
 * @requires function Sensei
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Sensei_Applier_Test extends WP_UnitTestCase {

	const REGISTRATION = '11111111-1111-4111-8111-111111111111';
	const STATEMENT    = '22222222-2222-4222-8222-222222222222';

	private Sensei_Adapter $adapter;

	private Sensei_Applier $applier;

	private ?array $sensei_settings = null;

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		$this->adapter = new Sensei_Adapter();
		$this->applier = new Sensei_Applier();
	}

	public function tear_down(): void {
		Clock::freeze( null );
		if ( null !== $this->sensei_settings ) {
			Sensei()->settings->settings = $this->sensei_settings;
		}
		parent::tear_down();
	}

	/**
	 * A published course with lessons, made through the adapter.
	 *
	 * @param int $lessons Lesson count.
	 * @param int $author  Author of the course and lessons (Sensei's teacher).
	 *
	 * @return array{course:int,lessons:int[]}
	 */
	private function course( int $lessons, int $author = 0 ): array {
		$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
		$before = get_current_user_id();
		// New posts take the current user as author.
		wp_set_current_user( $author );
		$course = $this->adapter->upsert_course( new Course_Spec( 'c-' . $suffix, 'Course', '', 'publish', array() ) );
		$ids    = array();
		for ( $i = 1; $i <= $lessons; $i++ ) {
			$ids[] = $this->adapter->upsert_lesson( $course, new Lesson_Spec( 'l-' . $suffix . '-' . $i, 'Lesson ' . $i, '', $i, 'publish', array() ) );
		}
		wp_set_current_user( $before );
		return array(
			'course'  => $course,
			'lessons' => $ids,
		);
	}

	private function enrolled_user( int $course ): int {
		$user = self::factory()->user->create();
		$this->assertTrue( $this->adapter->enrol( $user, $course ) );
		return $user;
	}

	private function result( string $type, int $user, string $kind, int $id, ?string $reason = null ): Result {
		return new Result( $type, $user, new Lms_Object( $kind, $id ), self::REGISTRATION, self::STATEMENT, null, $reason );
	}

	/**
	 * Give a lesson a quiz that must be passed.
	 *
	 * @param int $lesson Lesson ID.
	 *
	 * @return int Quiz ID.
	 */
	private function pass_required_quiz( int $lesson ): int {
		$quiz = self::factory()->post->create(
			array(
				'post_type'   => 'quiz',
				'post_status' => 'publish',
				'post_parent' => $lesson,
			)
		);
		update_post_meta( $quiz, '_pass_required', 'on' );
		update_post_meta( $quiz, '_quiz_passmark', 80 );
		update_post_meta( $lesson, '_lesson_quiz', $quiz );
		return $quiz;
	}

	public function test_completed_result_completes_lesson_and_sensei_completes_course_once() {
		$made  = $this->course( 2 );
		$user  = $this->enrolled_user( $made['course'] );
		$ended = 0;
		add_action(
			'sensei_user_course_end',
			static function () use ( &$ended ) {
				++$ended;
			}
		);

		$first = $this->applier->apply( $this->result( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][0] ) );
		$this->assertSame( Apply_Outcome::APPLIED, $first->status );
		$this->assertTrue( Sensei_Utils::user_completed_lesson( $made['lessons'][0], $user ) );
		$this->assertFalse( Sensei_Utils::user_completed_course( $made['course'], $user ) );
		$this->assertSame( 0, $ended );

		$this->applier->apply( $this->result( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][1] ) );
		$this->assertTrue( Sensei_Utils::user_completed_course( $made['course'], $user ), 'Sensei completes the course after the last lesson' );
		$this->assertSame( 1, $ended );

		$block = $this->applier->apply( $this->result( Result::BLOCK_SATISFIED, $user, 'course', $made['course'] ) );
		$this->assertContains( $block->status, array( Apply_Outcome::APPLIED, Apply_Outcome::SKIPPED ) );
		$this->assertSame( 1, $ended, 'the certificate trigger fires once' );
	}

	public function test_completed_result_clears_an_earlier_waiver() {
		$made = $this->course( 1 );
		$user = $this->enrolled_user( $made['course'] );
		$this->applier->apply( $this->result( Result::LESSON_WAIVED, $user, 'lesson', $made['lessons'][0], 'prior learning' ) );
		$this->assertTrue( ( new Waiver_Ledger() )->is_waived( $user, $made['lessons'][0] ) );

		$this->applier->apply( $this->result( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][0] ) );
		$this->assertFalse( ( new Waiver_Ledger() )->is_waived( $user, $made['lessons'][0] ) );
		$this->assertTrue( Sensei_Utils::user_completed_lesson( $made['lessons'][0], $user ) );
	}

	public function test_waived_result_completes_and_is_waived() {
		$made    = $this->course( 2 );
		$user    = $this->enrolled_user( $made['course'] );
		$outcome = $this->applier->apply( $this->result( Result::LESSON_WAIVED, $user, 'lesson', $made['lessons'][1], 'prior learning' ) );

		$this->assertSame( Apply_Outcome::APPLIED, $outcome->status );
		$this->assertTrue( Sensei_Utils::user_completed_lesson( $made['lessons'][1], $user ) );
		$this->assertFalse( Sensei_Utils::user_completed_lesson( $made['lessons'][0], $user ) );
		$waiver = ( new Waiver_Ledger() )->get( $user, $made['lessons'][1] );
		$this->assertNotNull( $waiver );
		$this->assertSame( 'prior learning', $waiver['reason'] );
		$this->assertSame( self::REGISTRATION, $waiver['registration'] );
		$this->assertSame( self::STATEMENT, $waiver['statement_id'] );
		$this->assertFalse( $waiver['carried_over'] );
	}

	public function test_six_waivers_send_zero_teacher_emails() {
		$teacher = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'teacher@example.org',
			)
		);
		$made    = $this->course( 7, $teacher );
		$user    = $this->enrolled_user( $made['course'] );
		// The legacy teacher email, turned on, as on a site without Sensei's email customization.
		add_action( 'sensei_user_lesson_end', array( Sensei()->emails, 'teacher_completed_lesson' ), 10, 2 );
		$this->sensei_settings                         = Sensei()->settings->settings;
		Sensei()->settings->settings['email_teachers'] = array( 'teacher-completed-lesson' );
		$sent = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$sent ) {
				++$sent;
				return true;
			}
		);

		for ( $i = 0; $i < 6; $i++ ) {
			$this->applier->apply( $this->result( Result::LESSON_WAIVED, $user, 'lesson', $made['lessons'][ $i ], 'prior learning' ) );
		}
		$this->assertSame( 0, $sent );
		$this->assertSame( 10, has_action( 'sensei_user_lesson_end', array( Sensei()->emails, 'teacher_completed_lesson' ) ), 'restored after applying' );

		// Control: Sensei's own completion still emails the teacher.
		Sensei_Utils::sensei_start_lesson( $made['lessons'][6], $user, true );
		$this->assertGreaterThan( 0, $sent );
	}

	public function test_pass_required_quiz_lesson_with_ledger_pass_reports_completed() {
		Sensei_Integration::boot();
		Sensei_Applier::hooks();
		$this->assertSame( 10, has_filter( 'sensei_user_completed_lesson', array( Sensei_Applier::class, 'completed_lesson' ) ) );
		$made = $this->course( 2 );
		$user = $this->enrolled_user( $made['course'] );
		$this->pass_required_quiz( $made['lessons'][0] );
		$this->pass_required_quiz( $made['lessons'][1] );
		Sensei_Utils::sensei_start_lesson( $made['lessons'][0], $user );
		Sensei_Utils::sensei_start_lesson( $made['lessons'][1], $user );
		$this->assertFalse( Sensei_Utils::user_completed_lesson( $made['lessons'][0], $user ) );

		$passed  = $this->result( Result::LESSON_PASSED, $user, 'lesson', $made['lessons'][0] );
		$outcome = $this->applier->apply( $passed );
		$this->assertSame( Apply_Outcome::APPLIED, $outcome->status );
		// The inbox processor records applied results; the score lives there.
		( new Apply_Ledger() )->record( $user, $passed->object, Result::LESSON_PASSED, self::STATEMENT, 0.9, true );

		$this->assertTrue( Sensei_Utils::user_completed_lesson( $made['lessons'][0], $user ) );
		$this->assertFalse( Sensei_Utils::user_completed_lesson( $made['lessons'][1], $user ), 'no ledger pass, no completion' );
	}

	public function test_passed_and_failed_leave_sensei_untouched() {
		$made = $this->course( 1 );
		$user = $this->enrolled_user( $made['course'] );
		$this->assertSame( Apply_Outcome::APPLIED, $this->applier->apply( $this->result( Result::LESSON_FAILED, $user, 'lesson', $made['lessons'][0] ) )->status );
		$this->assertSame( Apply_Outcome::APPLIED, $this->applier->apply( $this->result( Result::LESSON_PASSED, $user, 'lesson', $made['lessons'][0] ) )->status );
		$this->assertFalse( Sensei_Utils::user_started_lesson( $made['lessons'][0], $user ) );
	}

	public function test_not_enrolled_and_auto_enrol_off_is_deferred() {
		Connector_Settings::update( array( 'allow_auto_enrol' => false ) );
		$made    = $this->course( 1 );
		$user    = self::factory()->user->create();
		$outcome = $this->applier->apply( $this->result( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][0] ) );

		$this->assertSame( Apply_Outcome::DEFERRED, $outcome->status );
		$this->assertFalse( Sensei_Utils::user_completed_lesson( $made['lessons'][0], $user ) );
		$this->assertFalse( Sensei_Course::is_user_enrolled( $made['course'], $user ) );
	}

	public function test_not_enrolled_and_auto_enrol_on_enrols_then_applies() {
		Connector_Settings::update( array( 'allow_auto_enrol' => true ) );
		$made    = $this->course( 1 );
		$user    = self::factory()->user->create();
		$outcome = $this->applier->apply( $this->result( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][0] ) );

		$this->assertSame( Apply_Outcome::APPLIED, $outcome->status );
		$this->assertTrue( Sensei_Course::is_user_enrolled( $made['course'], $user ) );
		$this->assertTrue( Sensei_Utils::user_completed_lesson( $made['lessons'][0], $user ) );
	}

	public function test_course_satisfied_sets_program_user_meta() {
		Clock::freeze( new DateTimeImmutable( '2026-10-27 12:00:00', new DateTimeZone( 'UTC' ) ) );
		$user    = self::factory()->user->create();
		$outcome = $this->applier->apply( $this->result( Result::COURSE_SATISFIED, $user, 'program', 42 ) );

		$this->assertSame( Apply_Outcome::APPLIED, $outcome->status );
		$this->assertSame( (string) Clock::now()->getTimestamp(), get_user_meta( $user, '_lledger_program_satisfied_42', true ) );
	}

	public function test_applying_twice_changes_nothing() {
		$made    = $this->course( 2 );
		$user    = $this->enrolled_user( $made['course'] );
		$results = array(
			$this->result( Result::LESSON_COMPLETED, $user, 'lesson', $made['lessons'][0] ),
			$this->result( Result::LESSON_WAIVED, $user, 'lesson', $made['lessons'][1], 'prior learning' ),
			$this->result( Result::BLOCK_SATISFIED, $user, 'course', $made['course'] ),
			$this->result( Result::COURSE_SATISFIED, $user, 'program', 7 ),
		);
		Clock::freeze( new DateTimeImmutable( '2026-10-27 12:00:00', new DateTimeZone( 'UTC' ) ) );
		foreach ( $results as $r ) {
			$this->applier->apply( $r );
		}
		$waiver    = ( new Waiver_Ledger() )->get( $user, $made['lessons'][1] );
		$satisfied = get_user_meta( $user, '_lledger_program_satisfied_7', true );
		$ends      = did_action( 'sensei_user_lesson_end' );
		$courses   = did_action( 'sensei_user_course_end' );

		Clock::freeze( new DateTimeImmutable( '2026-10-28 12:00:00', new DateTimeZone( 'UTC' ) ) );
		foreach ( $results as $r ) {
			$this->assertSame( Apply_Outcome::SKIPPED, $this->applier->apply( $r )->status, $r->type );
		}

		$this->assertSame( $ends, did_action( 'sensei_user_lesson_end' ) );
		$this->assertSame( $courses, did_action( 'sensei_user_course_end' ) );
		$this->assertSame( $waiver, ( new Waiver_Ledger() )->get( $user, $made['lessons'][1] ) );
		$this->assertSame( $satisfied, get_user_meta( $user, '_lledger_program_satisfied_7', true ) );
		$this->assertTrue( Sensei_Utils::user_completed_course( $made['course'], $user ) );
	}
}
