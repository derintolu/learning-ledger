<?php
/**
 * Applies inbox statements to the LMS.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Inbox;

use LearningLedger\Cmi5\Au_Fact;
use LearningLedger\Cmi5\Satisfaction_Evaluator;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Ledger\Echo_Guard;
use LearningLedger\Connector\Lms\Activity_Ref;
use LearningLedger\Connector\Lms\Apply_Outcome;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\LMS_Adapter;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Programs\Program;
use LearningLedger\Connector\Programs\Program_Iris;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Util\Uuid;

/**
 * Identity match, then moveOn evaluation per registration, then apply.
 *
 * Application is idempotent and order-tolerant: the apply ledger stops repeats, completion is
 * never undone, and a void only flags the ledger rows the voided statement produced.
 */
final class Inbox_Processor {

	/**
	 * Inbox.
	 *
	 * @var Inbox_Repository
	 */
	private Inbox_Repository $inbox;

	/**
	 * Identity map.
	 *
	 * @var Identity_Map
	 */
	private Identity_Map $identity;

	/**
	 * Programs.
	 *
	 * @var Program_Repository
	 */
	private Program_Repository $programs;

	/**
	 * Apply ledger.
	 *
	 * @var Apply_Ledger
	 */
	private Apply_Ledger $ledger;

	/**
	 * Program to adapter, or null when no active adapter handles it.
	 *
	 * @var callable
	 */
	private $adapter_for;

	/**
	 * Constructor.
	 *
	 * @param Inbox_Repository|null   $inbox       Inbox.
	 * @param Identity_Map|null       $identity    Identity map.
	 * @param Program_Repository|null $programs    Programs.
	 * @param Apply_Ledger|null       $ledger      Apply ledger.
	 * @param callable|null           $adapter_for Maps a Program to ?LMS_Adapter; defaults to the active adapter for the program's courses.
	 */
	public function __construct( ?Inbox_Repository $inbox = null, ?Identity_Map $identity = null, ?Program_Repository $programs = null, ?Apply_Ledger $ledger = null, ?callable $adapter_for = null ) {
		$this->inbox       = $inbox ?? new Inbox_Repository();
		$this->identity    = $identity ?? new Identity_Map();
		$this->programs    = $programs ?? new Program_Repository();
		$this->ledger      = $ledger ?? new Apply_Ledger();
		$this->adapter_for = $adapter_for ?? array( self::class, 'default_adapter' );
	}

	/**
	 * Process pending rows.
	 *
	 * @param integer $limit Maximum rows to read.
	 *
	 * @return integer Rows that left pending.
	 */
	public function process_pending( int $limit = 100 ): int {
		$done   = 0;
		$groups = array();

		foreach ( $this->inbox->pending( $limit ) as $row ) {
			$statement = $row['statement'];

			if ( Inbox_Repository::VOIDED === $row['verb_id'] ) {
				if ( Uuid::is_valid( $row['object_id'] ) ) {
					// Flag for review; a completion is never undone automatically.
					$this->ledger->flag_void( $row['object_id'] );
				}
				$this->inbox->mark( $row['statement_id'], Inbox_Repository::PROCESSED );
				++$done;
				continue;
			}

			$user = is_array( $statement['actor'] ?? null ) ? $this->identity->user_for_actor( $statement['actor'] ) : null;
			if ( null === $user ) {
				$this->inbox->mark( $row['statement_id'], Inbox_Repository::UNMATCHED );
				++$done;
				continue;
			}

			$program_id = $this->programs->for_activity( $row['object_id'] );
			if ( null === $program_id || '' === $row['registration'] ) {
				$this->inbox->mark( $row['statement_id'], Inbox_Repository::PROCESSED, $user );
				++$done;
				continue;
			}

			$key = $program_id . '|' . $user . '|' . $row['registration'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'program'      => $program_id,
					'user'         => $user,
					'registration' => $row['registration'],
					'rows'         => array(),
				);
			}
			$groups[ $key ]['rows'][] = $row;
		}

		foreach ( $groups as $group ) {
			$done += $this->process_registration( $group['program'], $group['user'], $group['registration'], $group['rows'] );
		}
		return $done;
	}

	/**
	 * Evaluate one registration and apply its new results.
	 *
	 * @param integer $program_id   Program id.
	 * @param integer $user         User id.
	 * @param string  $registration Registration UUID.
	 * @param array[] $rows         The pending rows of this registration.
	 *
	 * @return integer Rows that left pending.
	 */
	private function process_registration( int $program_id, int $user, string $registration, array $rows ): int {
		$program   = $this->programs->get( $program_id );
		$structure = $this->programs->structure( $program_id );
		if ( ! $program || ! $structure ) {
			return $this->mark_all( $rows, Inbox_Repository::PROCESSED, $user );
		}
		$adapter = ( $this->adapter_for )( $program );
		if ( ! $adapter instanceof LMS_Adapter ) {
			// No adapter can take the results now; keep the rows for a later run.
			return 0;
		}

		// Only this learner's statements count: the pending rows' actors, and rows matched to them before.
		$actors = array();
		foreach ( $rows as $row ) {
			$actors[ $row['actor_hash'] ] = true;
		}
		$voided = array_flip( $this->inbox->voided_ids( $registration ) );
		$facts  = array();
		foreach ( $this->inbox->for_registration( $registration ) as $row ) {
			if ( isset( $voided[ $row['statement_id'] ] ) || ( ! isset( $actors[ $row['actor_hash'] ] ) && $row['user_id'] !== $user ) ) {
				continue;
			}
			$fact = Au_Fact::from_statement( $row['statement'], $structure );
			if ( $fact ) {
				$facts[] = $fact;
			}
		}

		$e       = Satisfaction_Evaluator::evaluate( $structure, $facts );
		$results = ( new Result_Factory( $this->ledger ) )->results(
			$program,
			$structure,
			$e,
			$user,
			$registration,
			fn( string $id ): ?Lms_Object => $this->resolve( $adapter, $program, $id ),
			$facts
		);

		$deferred = false;
		$failed   = false;
		foreach ( $results as $r ) {
			$outcome = Echo_Guard::while_applying( fn() => $adapter->apply( $r ) );
			$status  = $outcome instanceof Apply_Outcome ? $outcome->status : Apply_Outcome::FAILED;
			if ( Apply_Outcome::APPLIED === $status || Apply_Outcome::SKIPPED === $status ) {
				$this->ledger->record( $user, $r->object, $r->type, $r->statement_id, $r->score_scaled, self::success( $r ) );
			} elseif ( Apply_Outcome::DEFERRED === $status ) {
				$deferred = true;
			} else {
				$failed = true;
			}
		}

		do_action( 'learning_ledger/connector_evaluated', $program_id, $user, $registration, $e ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		if ( $deferred ) {
			return 0;
		}
		return $this->mark_all( $rows, $failed ? Inbox_Repository::FLAGGED : Inbox_Repository::PROCESSED, $user );
	}

	/**
	 * AU or block id to an LMS object: the adapter first, then the program's path courses.
	 *
	 * @param LMS_Adapter $adapter Adapter.
	 * @param Program     $program Program.
	 * @param string      $id      AU or block id.
	 *
	 * @return Lms_Object|null
	 */
	private function resolve( LMS_Adapter $adapter, Program $program, string $id ): ?Lms_Object {
		$object = $adapter->resolve( new Activity_Ref( $id ) );
		if ( $object ) {
			return $object;
		}
		$key = Program_Iris::key_from_iri( $program, $id );
		if ( $key && 'path' === $key['kind'] ) {
			$course = array_search( $key['key'], $program->path_keys, true );
			if ( false !== $course ) {
				return new Lms_Object( 'course', (int) $course );
			}
		}
		return null;
	}

	/**
	 * Mark rows.
	 *
	 * @param array[] $rows   Rows.
	 * @param string  $status Status.
	 * @param integer $user   User id.
	 *
	 * @return integer Rows marked.
	 */
	private function mark_all( array $rows, string $status, int $user ): int {
		foreach ( $rows as $row ) {
			$this->inbox->mark( $row['statement_id'], $status, $user );
		}
		return count( $rows );
	}

	/**
	 * The success value the ledger keeps for a result.
	 *
	 * @param Result $r Result.
	 *
	 * @return boolean|null
	 */
	private static function success( Result $r ): ?bool {
		if ( Result::LESSON_PASSED === $r->type ) {
			return true;
		}
		return Result::LESSON_FAILED === $r->type ? false : null;
	}

	/**
	 * The active adapter handling the program's first mapped course.
	 *
	 * @param Program $program Program.
	 *
	 * @return LMS_Adapter|null
	 */
	public static function default_adapter( Program $program ): ?LMS_Adapter {
		foreach ( $program->course_ids as $course ) {
			$adapter = Integration_Loader::for_post( $course );
			if ( $adapter ) {
				return $adapter;
			}
		}
		return null;
	}
}
