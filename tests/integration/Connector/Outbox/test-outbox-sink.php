<?php
/**
 * Outbox sink tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Ledger\Echo_Guard;
use LearningLedger\Connector\Lms\Event_Sink;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Lms\Noop_Event_Sink;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Outbox\Outbox_Module;
use LearningLedger\Connector\Outbox\Outbox_Sink;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Queue\Shutdown_Runner;
use LearningLedger\Util\Uuid;

class Outbox_Sink_Test extends WP_UnitTestCase {

	private int $user;

	private int $course;

	private int $lesson;

	private int $http_calls = 0;

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'conn_outbox', 'applied', 'registrations' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$this->user       = self::factory()->user->create();
		$this->course     = self::factory()->post->create();
		$this->lesson     = self::factory()->post->create();
		$this->http_calls = 0;
		add_filter( 'pre_http_request', array( $this, 'no_http' ), 10, 3 );
		add_filter( 'learning_ledger/direction2_iris', array( $this, 'iris' ), 10, 2 );
		remove_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'no_http' ), 10 );
		remove_filter( 'learning_ledger/direction2_iris', array( $this, 'iris' ), 10 );
		remove_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) );
		$this->assertSame( 0, $this->http_calls, 'The sink must not make HTTP calls.' );
		parent::tear_down();
	}

	public function no_http( $pre, $args, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		++$this->http_calls;
		return new WP_Error( 'http_not_allowed', 'No HTTP during an LMS action.' );
	}

	public function iris( $iris, Neutral_Event $e ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		$course = 'https://site.test/activities/courses/' . $e->course_id;
		return array(
			'object' => null === $e->lesson_id ? $course : $course . '/lessons/' . $e->lesson_id,
			'parent' => $course,
		);
	}

	private function event( string $type = Neutral_Event::LESSON_COMPLETED, ?DateTimeImmutable $at = null ): Neutral_Event {
		return new Neutral_Event( $type, 'test', $this->user, $this->course, $this->lesson, null, null, null, $at ?? new DateTimeImmutable( '2026-10-27 10:00:00', new DateTimeZone( 'UTC' ) ) );
	}

	private function rows(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . Tables::name( 'conn_outbox' ) . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_registered_through_event_sink_filter() {
		( new Outbox_Module() )->register();
		$sink = apply_filters( 'learning_ledger/event_sink', new Noop_Event_Sink() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertInstanceOf( Outbox_Sink::class, $sink );
		$this->assertInstanceOf( Event_Sink::class, $sink );
	}

	public function test_writes_one_row_and_requests_shutdown_run() {
		( new Outbox_Sink() )->emit( $this->event() );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$body = json_decode( $rows[0]['body'], true );
		$this->assertSame( 'pending', $rows[0]['status'] );
		$this->assertSame( (string) $this->course, $rows[0]['course_id'] );
		$this->assertSame( $body['id'], Uuid::from_bin( $rows[0]['statement_id'] ) );
		$this->assertSame( 'http://adlnet.gov/expapi/verbs/completed', $body['verb']['id'] );
		$this->assertSame( 'https://site.test/activities/courses/' . $this->course . '/lessons/' . $this->lesson, $body['object']['id'] );
		$this->assertNotFalse( has_action( 'shutdown', array( Shutdown_Runner::class, 'on_shutdown' ) ) );
	}

	public function test_at_most_one_insert_once_identity_exists() {
		$sink = new Outbox_Sink();
		$sink->emit( $this->event( Neutral_Event::LESSON_STARTED ) );

		$writes = array();
		$spy    = static function ( $sql ) use ( &$writes ) {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ) ) {
				$writes[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $spy );
		$sink->emit( $this->event() );
		remove_filter( 'query', $spy );

		$this->assertCount( 1, $writes, implode( "\n", $writes ) );
		$this->assertStringContainsString( Tables::name( 'conn_outbox' ), $writes[0] );
		$this->assertCount( 2, $this->rows() );
	}

	public function test_same_event_twice_keeps_one_row() {
		$sink = new Outbox_Sink();
		$sink->emit( $this->event() );
		$sink->emit( $this->event() );
		$this->assertCount( 1, $this->rows() );
	}

	public function test_nothing_inside_echo_guard() {
		Echo_Guard::while_applying(
			function () {
				( new Outbox_Sink() )->emit( $this->event() );
			}
		);
		$this->assertCount( 0, $this->rows() );
	}

	public function test_nothing_when_the_change_was_applied_by_the_connector() {
		( new Apply_Ledger() )->record( $this->user, new Lms_Object( 'lesson', $this->lesson ), Result::LESSON_COMPLETED, Uuid::v4() );
		( new Outbox_Sink() )->emit( $this->event() );
		$this->assertCount( 0, $this->rows() );

		( new Outbox_Sink() )->emit( $this->event( Neutral_Event::LESSON_STARTED ) );
		$this->assertCount( 1, $this->rows() );
	}

	public function test_nothing_when_direction2_is_off() {
		update_post_meta( $this->course, '_lledger_direction2', 'off' );
		( new Outbox_Sink() )->emit( $this->event() );
		$this->assertCount( 0, $this->rows() );
	}

	public function test_nothing_without_iris() {
		remove_filter( 'learning_ledger/direction2_iris', array( $this, 'iris' ), 10 );
		( new Outbox_Sink() )->emit( $this->event() );
		$this->assertCount( 0, $this->rows() );
	}

	public function test_nothing_for_unenrolled() {
		( new Outbox_Sink() )->emit( $this->event( Neutral_Event::UNENROLLED ) );
		$this->assertCount( 0, $this->rows() );
	}

	public function test_program_course_uses_program_iris() {
		$program = self::factory()->post->create( array( 'post_type' => 'lledger_program' ) );
		update_post_meta( $program, '_lledger_iri_base', 'https://author.test/prog/' );
		update_post_meta( $program, '_lledger_courses', array( $this->course ) );
		update_post_meta( $program, '_lledger_path_keys', array( $this->course => 'basics' ) );
		update_post_meta( $this->lesson, '_lledger_stable_key', 'intro' );

		$sink = new Outbox_Sink();
		$sink->emit( $this->event() );
		$sink->emit( new Neutral_Event( Neutral_Event::COURSE_COMPLETED, 'test', $this->user, $this->course ) );

		$rows   = $this->rows();
		$lesson = json_decode( $rows[0]['body'], true );
		$course = json_decode( $rows[1]['body'], true );
		$this->assertSame( 'https://author.test/prog/lessons/intro', $lesson['object']['id'] );
		$this->assertSame( 'https://author.test/prog/paths/basics', $lesson['context']['contextActivities']['parent'][0]['id'] );
		$this->assertSame( 'https://author.test/prog/paths/basics', $course['object']['id'] );
		$this->assertSame( 'https://author.test/prog', $course['context']['contextActivities']['parent'][0]['id'] );
	}
}
