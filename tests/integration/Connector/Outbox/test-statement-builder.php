<?php
/**
 * Statement builder tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Outbox\Statement_Builder;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Uuid;

class Statement_Builder_Test extends WP_UnitTestCase {

	const COURSE_IRI = 'https://site.test/activities/courses/5';
	const LESSON_IRI = 'https://site.test/activities/courses/5/lessons/7';

	private int $user;

	private DateTimeImmutable $at;

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'registrations' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->user = self::factory()->user->create();
		$this->at   = new DateTimeImmutable( '2026-10-27 10:00:00.123456', new DateTimeZone( 'UTC' ) );
	}

	private function iris(): callable {
		return static function ( Neutral_Event $e ): array {
			return array(
				'object' => null === $e->lesson_id ? self::COURSE_IRI : self::LESSON_IRI,
				'parent' => self::COURSE_IRI,
			);
		};
	}

	private function event( string $type, ?int $lesson = 7, ?float $score = null, ?bool $success = null, ?DateTimeImmutable $at = null ): Neutral_Event {
		return new Neutral_Event( $type, 'test', $this->user, 5, $lesson, null === $score && null === $success ? null : 9, $score, $success, $at ?? $this->at );
	}

	private function build( Neutral_Event $e ): ?array {
		return ( new Statement_Builder() )->build( $e, $this->iris() );
	}

	public function test_verbs_per_event() {
		$cases = array(
			array( $this->event( Neutral_Event::ENROLLED, null ), 'http://adlnet.gov/expapi/verbs/registered', self::COURSE_IRI ),
			array( $this->event( Neutral_Event::LESSON_STARTED ), 'http://adlnet.gov/expapi/verbs/attempted', self::LESSON_IRI ),
			array( $this->event( Neutral_Event::LESSON_COMPLETED ), 'http://adlnet.gov/expapi/verbs/completed', self::LESSON_IRI ),
			array( $this->event( Neutral_Event::QUIZ_SUBMITTED, 7, 0.5 ), 'http://adlnet.gov/expapi/verbs/answered', self::LESSON_IRI ),
			array( $this->event( Neutral_Event::QUIZ_GRADED, 7, 0.8, true ), 'http://adlnet.gov/expapi/verbs/passed', self::LESSON_IRI ),
			array( $this->event( Neutral_Event::QUIZ_GRADED, 7, 0.2, false ), 'http://adlnet.gov/expapi/verbs/failed', self::LESSON_IRI ),
			array( $this->event( Neutral_Event::COURSE_COMPLETED, null ), 'http://adlnet.gov/expapi/verbs/completed', self::COURSE_IRI ),
			array( $this->event( Neutral_Event::PROGRESS_RESET ), 'http://id.tincanapi.com/verb/reset', self::LESSON_IRI ),
		);
		foreach ( $cases as $i => list( $event, $verb, $object ) ) {
			$s = $this->build( $event );
			$this->assertIsArray( $s, "case $i" );
			$this->assertSame( $verb, $s['verb']['id'], "case $i" );
			$this->assertSame( $object, $s['object']['id'], "case $i" );
			$this->assertSame( 'Activity', $s['object']['objectType'], "case $i" );
		}
		$this->assertNull( $this->build( $this->event( Neutral_Event::UNENROLLED, null ) ) );
	}

	public function test_actor_registration_parent_and_timestamp() {
		$s = $this->build( $this->event( Neutral_Event::LESSON_COMPLETED ) );

		$this->assertSame( ( new Identity_Map() )->actor_for_user( $this->user ), $s['actor'] );
		$registration = ( new Registration_Map() )->find( $this->user, 'course:5' );
		$this->assertNotNull( $registration );
		$this->assertSame( $registration, $s['context']['registration'] );
		$this->assertSame(
			array(
				array(
					'objectType' => 'Activity',
					'id'         => self::COURSE_IRI,
				),
			),
			$s['context']['contextActivities']['parent']
		);
		$this->assertSame( '2026-10-27T10:00:00.123456Z', $s['timestamp'] );
	}

	public function test_same_registration_for_events_of_one_course() {
		$a = $this->build( $this->event( Neutral_Event::LESSON_STARTED ) );
		$b = $this->build( $this->event( Neutral_Event::LESSON_COMPLETED ) );
		$this->assertSame( $a['context']['registration'], $b['context']['registration'] );
	}

	public function test_deterministic_id_for_identical_input() {
		$a = $this->build( $this->event( Neutral_Event::LESSON_COMPLETED ) );
		$b = $this->build( $this->event( Neutral_Event::LESSON_COMPLETED ) );
		$this->assertSame( $a['id'], $b['id'] );

		$expected = Uuid::v5(
			Statement_Builder::NAMESPACE,
			home_url() . '|' . $this->user . '|' . self::LESSON_IRI . '|' . Neutral_Event::LESSON_COMPLETED . '|' . $this->at->format( 'Uv' )
		);
		$this->assertSame( $expected, $a['id'] );
		$this->assertSame( '6f1d9c2e-4b7a-5e3c-8a1f-2d4b6c8e0a11', Statement_Builder::NAMESPACE );

		$later = $this->build( $this->event( Neutral_Event::LESSON_COMPLETED, 7, null, null, $this->at->modify( '+1 second' ) ) );
		$this->assertNotSame( $a['id'], $later['id'] );
		$started = $this->build( $this->event( Neutral_Event::LESSON_STARTED ) );
		$this->assertNotSame( $a['id'], $started['id'] );
	}

	public function test_quiz_graded_carries_scaled_score_and_no_response() {
		$s = $this->build( $this->event( Neutral_Event::QUIZ_GRADED, 7, 0.8, true ) );
		$this->assertSame( 0.8, $s['result']['score']['scaled'] );
		$this->assertTrue( $s['result']['success'] );
		$this->assertArrayNotHasKey( 'response', $s['result'] );

		$failed = $this->build( $this->event( Neutral_Event::QUIZ_GRADED, 7, 0.2, false ) );
		$this->assertSame( 0.2, $failed['result']['score']['scaled'] );
		$this->assertFalse( $failed['result']['success'] );
	}

	public function test_quiz_submitted_carries_score_only() {
		$s = $this->build( $this->event( Neutral_Event::QUIZ_SUBMITTED, 7, 0.5 ) );
		$this->assertSame( array( 'score' => array( 'scaled' => 0.5 ) ), $s['result'] );
	}

	public function test_course_reset_ends_the_registration() {
		$first = $this->build( $this->event( Neutral_Event::LESSON_COMPLETED ) );
		$reset = $this->build( $this->event( Neutral_Event::PROGRESS_RESET, null ) );

		$this->assertSame( self::COURSE_IRI, $reset['object']['id'] );
		$this->assertSame( $first['context']['registration'], $reset['context']['registration'] );
		$this->assertNull( ( new Registration_Map() )->find( $this->user, 'course:5' ) );

		$after = $this->build( $this->event( Neutral_Event::LESSON_STARTED ) );
		$this->assertNotSame( $first['context']['registration'], $after['context']['registration'] );
	}

	public function test_lesson_reset_keeps_the_registration() {
		$first = $this->build( $this->event( Neutral_Event::LESSON_COMPLETED ) );
		$this->build( $this->event( Neutral_Event::PROGRESS_RESET ) );
		$this->assertSame( $first['context']['registration'], ( new Registration_Map() )->find( $this->user, 'course:5' ) );
	}
}
