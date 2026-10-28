<?php
/**
 * Badge pipeline module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Pipeline;

use LearningLedger\Cmi5\Vocab;
use LearningLedger\Kernel\Capabilities;
use LearningLedger\Kernel\Module;
use LearningLedger\Lrs\Courses\Course_Record;
use LearningLedger\Lrs\Courses\Course_Repository;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Stored_Statement;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Uuid;

/**
 * Queues a badge evaluation for each registration a stored batch touches, runs it from the
 * job queue, serves `GET /learning-ledger/v1/badges` and tells admins about held badges.
 *
 * Badge work never runs in the request that stored the statements: that request only pushes
 * one `badge_evaluate` job per registration (deduplicated on `badge:<registration>`).
 */
final class Pipeline_Module implements Module {

	/**
	 * Job type.
	 */
	const JOB = 'badge_evaluate';

	/**
	 * Option flagging that held badges may await an admin's review.
	 */
	const HELD_OPTION = 'lledger_badges_held';

	/**
	 * Constructor.
	 *
	 * @param Course_Repository    $courses    Courses.
	 * @param Statement_Repository $statements Statements.
	 * @param Badge_Evaluator      $evaluator  Evaluator.
	 */
	public function __construct(
		private readonly Course_Repository $courses = new Course_Repository(),
		private readonly Statement_Repository $statements = new Statement_Repository(),
		private readonly Badge_Evaluator $evaluator = new Badge_Evaluator()
	) {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'learning_ledger/statements_committed', array( $this, 'on_committed' ) );
		add_action( 'learning_ledger/jobs/' . self::JOB, array( $this, 'run' ) );
		add_action( 'rest_api_init', array( new Badges_Controller(), 'register_routes' ) );
		add_action( 'admin_notices', array( $this, 'held_notice' ) );
	}

	/**
	 * Queue an evaluation for each registration in the batch whose course declares badges.
	 *
	 * A batch whose only statements about the course are `satisfied` on a block cannot be
	 * matched to a course here (a block id is not an AU); its job finds the course itself.
	 *
	 * @param Stored_Statement[] $rows Committed rows.
	 *
	 * @return void
	 */
	public function on_committed( array $rows ): void {
		$resolved   = array();
		$unresolved = array();
		foreach ( $rows as $row ) {
			if ( ! $row instanceof Stored_Statement || null === $row->registration || isset( $resolved[ $row->registration ] ) ) {
				continue;
			}
			$st     = $row->statement();
			$course = $this->courses->for_statement( $st );
			if ( null === $course && Vocab::SATISFIED === ( $st['verb']['id'] ?? null ) ) {
				$object = $st['object']['id'] ?? null;
				$course = is_string( $object ) ? $this->courses->get( $object ) : null;
				if ( null === $course ) {
					$unresolved[ $row->registration ] = true;
					continue;
				}
			}
			if ( null === $course ) {
				continue;
			}
			$resolved[ $row->registration ] = true;
			unset( $unresolved[ $row->registration ] );
			if ( array() !== $course->badges ) {
				self::push( $row->registration, $course->course_iri );
			}
		}
		foreach ( array_keys( $unresolved ) as $registration ) {
			self::push( (string) $registration, null );
		}
	}

	/**
	 * Job handler: evaluate one registration.
	 *
	 * A statement stored while this runs queues a follow-up job (the queue releases a job's
	 * dedupe key when it claims it), so nothing stored mid-evaluation goes unevaluated.
	 *
	 * @param array $payload Keys `registration` and `course` (null when the request could not tell).
	 *
	 * @return void
	 */
	public function run( array $payload ): void {
		$registration = is_string( $payload['registration'] ?? null ) ? strtolower( $payload['registration'] ) : '';
		if ( ! Uuid::is_valid( $registration ) ) {
			return;
		}
		$course = is_string( $payload['course'] ?? null ) ? $this->courses->get( $payload['course'] ) : $this->course_of( $registration );
		if ( null === $course || array() === $course->badges ) {
			return;
		}
		$this->evaluator->evaluate( $registration, $course );
	}

	/**
	 * Admin notice while badges wait in the held state. The rows are counted only while the
	 * flag is set, and the flag is cleared once none is held.
	 *
	 * @return void
	 */
	public function held_notice(): void {
		if ( ! get_option( self::HELD_OPTION ) || ! current_user_can( Capabilities::MANAGE_BADGES ) ) {
			return;
		}
		$held = ( new Badge_Repository() )->count_with_status( Badge_Repository::HELD );
		if ( $held < 1 ) {
			delete_option( self::HELD_OPTION );
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of held badges. */
					_n(
						'Learning Ledger held %d badge: the course server said the learner was satisfied, but recomputing satisfaction from the record disagreed. It will not be issued. See the audit log (badge_held).',
						'Learning Ledger held %d badges: the course server said the learners were satisfied, but recomputing satisfaction from the record disagreed. They will not be issued. See the audit log (badge_held).',
						$held,
						'learning-ledger'
					),
					$held
				)
			)
		);
	}

	/**
	 * Queue one evaluation.
	 *
	 * @param string      $registration Registration UUID.
	 * @param string|null $course_iri   Course IRI, or null for the job to find it.
	 *
	 * @return void
	 */
	private static function push( string $registration, ?string $course_iri ): void {
		Job_Queue::push(
			self::JOB,
			array(
				'registration' => $registration,
				'course'       => $course_iri,
			),
			'badge:' . $registration
		);
	}

	/**
	 * The course of a registration, from the first of its statements that names an AU.
	 *
	 * @param string $registration Registration UUID.
	 *
	 * @return Course_Record|null
	 */
	private function course_of( string $registration ): ?Course_Record {
		foreach ( $this->statements->for_registration( $registration ) as $row ) {
			$course = $this->courses->for_statement( $row->statement() );
			if ( null !== $course ) {
				return $course;
			}
		}
		return null;
	}
}
