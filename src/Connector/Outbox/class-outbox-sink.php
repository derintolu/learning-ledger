<?php
/**
 * Event sink that queues statements in the outbox.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Outbox;

use LearningLedger\Connector\Ledger\Echo_Guard;
use LearningLedger\Connector\Lms\Event_Sink;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Programs\Program;
use LearningLedger\Connector\Programs\Program_Iris;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Queue\Shutdown_Runner;

/**
 * Runs during a learner's page view or LMS action, so it makes no HTTP call and
 * at most one INSERT (the outbox row). Exception: on a learner's first event for a
 * course, the actor (user meta `lledger_actor`), the registration row and, through
 * the integration's IRI filter, the course's `_lledger_activity_base` are written
 * once; later events only read them.
 */
final class Outbox_Sink implements Event_Sink {

	/**
	 * Whether the sender is already queued for this request's shutdown.
	 *
	 * @var boolean
	 */
	private static bool $scheduled = false;

	/**
	 * Builder.
	 *
	 * @var Statement_Builder
	 */
	private Statement_Builder $builder;

	/**
	 * Repository.
	 *
	 * @var Outbox_Repository
	 */
	private Outbox_Repository $repo;

	/**
	 * Programs.
	 *
	 * @var Program_Repository
	 */
	private Program_Repository $programs;

	/**
	 * Constructor.
	 *
	 * @param Statement_Builder|null  $builder  Builder.
	 * @param Outbox_Repository|null  $repo     Repository.
	 * @param Program_Repository|null $programs Programs.
	 */
	public function __construct( ?Statement_Builder $builder = null, ?Outbox_Repository $repo = null, ?Program_Repository $programs = null ) {
		$this->builder  = $builder ?? new Statement_Builder();
		$this->repo     = $repo ?? new Outbox_Repository();
		$this->programs = $programs ?? new Program_Repository();
	}

	/**
	 * Queue the event's statement unless the connector caused it or direction 2 is off.
	 *
	 * @param Neutral_Event $e Event.
	 *
	 * @return void
	 */
	public function emit( Neutral_Event $e ): void {
		if ( Echo_Guard::active() || ! Direction2::is_on( $e->course_id ) ) {
			return;
		}
		$applied = self::applied_key( $e );
		foreach ( $applied ? $applied[1] : array() as $status ) {
			if ( Echo_Guard::was_applied( $e->user_id, $applied[0], $status ) ) {
				return;
			}
		}

		$statement = $this->builder->build( $e, array( $this, 'iris' ) );
		if ( null === $statement ) {
			return;
		}
		$this->repo->add( $statement, $e->course_id );

		// Shutdown_Runner::add() requests the shutdown run; the sender then runs after the response.
		if ( ! self::$scheduled ) {
			self::$scheduled = true;
			Shutdown_Runner::add(
				static function (): void {
					self::$scheduled = false;
					( new Outbox_Sender() )->flush();
				}
			);
		} else {
			Shutdown_Runner::request();
		}
	}

	/**
	 * Object and parent IRIs: the program's for a program course, else the integration's filter.
	 *
	 * @param Neutral_Event $e Event.
	 *
	 * @return array{object:string,parent:string}|null
	 */
	public function iris( Neutral_Event $e ): ?array {
		$program_id = $this->programs->for_lms_course( $e->course_id );
		$program    = null === $program_id ? null : $this->programs->get( $program_id );
		if ( null !== $program && '' !== $program->iri_base ) {
			return self::program_iris( $program, $e );
		}

		/**
		 * Direction-2 IRIs of an event in a course outside any program.
		 *
		 * @param array|null    $iris  `{object, parent}` or null when unknown.
		 * @param Neutral_Event $e     Event.
		 */
		$iris = apply_filters( 'learning_ledger/direction2_iris', null, $e ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( ! is_array( $iris ) || ! is_string( $iris['object'] ?? null ) || '' === $iris['object'] ) {
			return null;
		}
		return array(
			'object' => $iris['object'],
			'parent' => is_string( $iris['parent'] ?? null ) ? $iris['parent'] : '',
		);
	}

	/**
	 * Program IRIs: lessons are the program's lesson IRIs inside the course's path block.
	 *
	 * @param Program       $p Program.
	 * @param Neutral_Event $e Event.
	 *
	 * @return array{object:string,parent:string}|null
	 */
	private static function program_iris( Program $p, Neutral_Event $e ): ?array {
		$path_key = (string) ( $p->path_keys[ $e->course_id ] ?? '' );
		if ( '' === $path_key ) {
			return null;
		}
		$block = Program_Iris::block_iri( $p, $path_key );
		if ( null === $e->lesson_id ) {
			return array(
				'object' => $block,
				'parent' => $p->course_iri,
			);
		}
		$lesson_key = (string) get_post_meta( $e->lesson_id, '_lledger_stable_key', true );
		if ( '' === $lesson_key ) {
			return null;
		}
		$lesson = Program_Iris::au_iri( $p, $lesson_key );
		$quiz   = Neutral_Event::QUIZ_SUBMITTED === $e->type || Neutral_Event::QUIZ_GRADED === $e->type;
		return array(
			'object' => $quiz ? $lesson . '/quiz' : $lesson,
			'parent' => $block,
		);
	}

	/**
	 * The apply-ledger object and statuses the connector would have recorded when it caused this event.
	 *
	 * @param Neutral_Event $e Event.
	 *
	 * @return array{0:Lms_Object,1:string[]}|null
	 */
	private static function applied_key( Neutral_Event $e ): ?array {
		if ( Neutral_Event::LESSON_COMPLETED === $e->type && null !== $e->lesson_id ) {
			return array( new Lms_Object( 'lesson', $e->lesson_id ), array( Result::LESSON_COMPLETED, Result::LESSON_WAIVED ) );
		}
		if ( Neutral_Event::QUIZ_GRADED === $e->type && null !== $e->lesson_id && null !== $e->success ) {
			return array( new Lms_Object( 'lesson', $e->lesson_id ), array( $e->success ? Result::LESSON_PASSED : Result::LESSON_FAILED ) );
		}
		if ( Neutral_Event::COURSE_COMPLETED === $e->type ) {
			return array( new Lms_Object( 'course', $e->course_id ), array( Result::BLOCK_SATISFIED ) );
		}
		return null;
	}
}
