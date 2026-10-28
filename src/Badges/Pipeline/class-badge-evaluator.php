<?php
/**
 * Badge declarations evaluated against a registration's record.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Pipeline;

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Au_Fact;
use LearningLedger\Cmi5\Block;
use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Cmi5\Satisfaction_Evaluator;
use LearningLedger\Cmi5\Satisfaction_Result;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Cmi5\Waiver_Policy;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Courses\Course_Record;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Stored_Statement;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;

/**
 * Creates pending badges for the declarations a registration has earned.
 *
 * Satisfaction is recomputed from the registration's stored, non-voided statements against
 * the imported course tree, one learner (actor) at a time, so another actor's statements in
 * the same registration never count. A badge needs a trigger statement written by a client
 * marked trusted_for_satisfaction (never a child token): the statement that met the AU's
 * moveOn, or the `satisfied` statement on the block or course. A trusted `satisfied` the
 * recomputation contradicts gives a held badge, which is never signed without an admin.
 */
final class Badge_Evaluator {

	/**
	 * Audit event of a held badge.
	 */
	const HELD_EVENT = 'badge_held';

	/**
	 * Fields of a declaration that are the badge's Achievement.
	 *
	 * @var string[]
	 */
	const ACHIEVEMENT_FIELDS = array( 'id', 'name', 'description', 'criteria', 'image', 'achievementType', 'alignment' );

	/**
	 * Trust of each authority key seen in this evaluation.
	 *
	 * @var array<string,bool>
	 */
	private array $trust = array();

	/**
	 * Constructor.
	 *
	 * @param Statement_Repository $statements Statements.
	 * @param Badge_Repository     $badges     Badges.
	 * @param Client_Repository    $clients    Clients.
	 */
	public function __construct(
		private readonly Statement_Repository $statements = new Statement_Repository(),
		private readonly Badge_Repository $badges = new Badge_Repository(),
		private readonly Client_Repository $clients = new Client_Repository()
	) {}

	/**
	 * Evaluate every declaration of the course that has no badge yet in the registration.
	 *
	 * @param string        $registration Registration UUID.
	 * @param Course_Record $c            The registration's course.
	 *
	 * @return string[] Ids of the badges created (pending or held).
	 */
	public function evaluate( string $registration, Course_Record $c ): array {
		if ( array() === $c->badges || ! Uuid::is_valid( $registration ) ) {
			return array();
		}
		$registration = strtolower( $registration );
		$this->trust  = array();

		$have = array();
		foreach ( $this->badges->for_registration( $registration ) as $badge ) {
			$have[ (string) ( $badge['achievement']['id'] ?? '' ) ] = true;
		}
		$todo = array();
		foreach ( $c->badges as $declaration ) {
			$id = is_array( $declaration ) ? ( $declaration['id'] ?? null ) : null;
			if ( is_string( $id ) && '' !== $id && ! isset( $have[ $id ] ) ) {
				$todo[ $id ] = $declaration;
			}
		}
		if ( array() === $todo ) {
			return array();
		}

		$s       = $c->structure();
		$created = array();
		foreach ( $this->by_actor( $registration ) as $actor_hash => $rows ) {
			$record = $this->record( $s, $rows );
			foreach ( $todo as $achievement_id => $declaration ) {
				$outcome = $this->decide( $declaration, $s, $record, $c->custom_rules );
				if ( null === $outcome ) {
					continue;
				}
				[ $status, $trigger, $target ]      = $outcome;
				[ $total, $completed, $tested_out ] = self::counts( $s, $record['result'], $target, self::policy( $declaration ) );

				$id = $this->badges->create_pending(
					self::achievement( $declaration ),
					$registration,
					(string) $actor_hash,
					$c->course_iri,
					$trigger,
					Narrative::for( $total, $completed, $tested_out ),
					$tested_out,
					$status
				);
				unset( $todo[ $achievement_id ] );
				if ( null === $id ) {
					continue;
				}
				$created[] = $id;
				if ( Badge_Repository::HELD === $status ) {
					self::held( $id, $declaration, $registration, $trigger );
				}
			}
			if ( array() === $todo ) {
				break;
			}
		}
		return $created;
	}

	/**
	 * Whether and how a declaration is earned.
	 *
	 * @param array            $d      Declaration.
	 * @param Course_Structure $s      Course structure.
	 * @param array            $record One actor's record (see record()).
	 * @param boolean          $custom Whether the course has custom rules.
	 *
	 * @return array{0:string,1:string,2:Au|Block|null}|null Status, trigger statement id and target (null for the course).
	 */
	private function decide( array $d, Course_Structure $s, array $record, bool $custom ): ?array {
		$policy = self::policy( $d );
		$result = $record['result'];
		$key    = is_string( $d['for'] ?? null ) ? $d['for'] : null;

		switch ( $d['awardOn'] ?? null ) {
			case 'au-moveon':
				$au = null === $key ? null : $s->au_by_key( $key );
				if ( null === $au || ! $result->au_satisfied( $au->id(), $policy ) ) {
					return null;
				}
				$trigger = $this->move_on_statement( $s, $au, $record, $policy );
				return null === $trigger ? null : array( Badge_Repository::PENDING, $trigger, $au );

			case 'block-satisfied':
				$block = null === $key ? null : self::block_by_key( $s, $key );
				if ( null === $block ) {
					return null;
				}
				return self::verified(
					$record['satisfied'][ $block->id() ] ?? null,
					$custom,
					$result->block_satisfied( $block->id(), Waiver_Policy::ALL ),
					$result->block_satisfied( $block->id(), $policy ),
					$block
				);

			case 'course-satisfied':
				return self::verified(
					$record['satisfied'][ $s->course_id() ] ?? null,
					$custom,
					$result->course_satisfied( Waiver_Policy::ALL ),
					$result->course_satisfied( $policy ),
					null
				);

			case 'any-path':
				return self::any_path( $s, $record, $custom, $policy );

			default:
				// A custom author rule: trust the trusted client's `satisfied` on the target, or on the course.
				if ( ! $custom ) {
					return null;
				}
				$block   = null === $key ? null : self::block_by_key( $s, $key );
				$target  = null === $key ? $s->course_id() : $block?->id();
				$trigger = null === $target ? null : ( $record['satisfied'][ $target ] ?? null );
				return null === $trigger ? null : array( Badge_Repository::PENDING, $trigger, $block );
		}
	}

	/**
	 * Outcome of a block or course rule from its trusted `satisfied` and the recomputation.
	 *
	 * @param string|null $trigger Trusted `satisfied` statement id, if any.
	 * @param boolean     $custom  Whether the course has custom rules (no recomputation).
	 * @param boolean     $cmi5    Recomputed satisfaction in the cmi5 sense (every waiver counts).
	 * @param boolean     $earned  Recomputed satisfaction under the badge's waiver policy.
	 * @param Block|null  $target  Block, or null for the course.
	 *
	 * @return array{0:string,1:string,2:Block|null}|null
	 */
	private static function verified( ?string $trigger, bool $custom, bool $cmi5, bool $earned, ?Block $target ): ?array {
		if ( null === $trigger ) {
			return null;
		}
		if ( $custom ) {
			return array( Badge_Repository::PENDING, $trigger, $target );
		}
		if ( ! $cmi5 ) {
			return array( Badge_Repository::HELD, $trigger, $target );
		}
		return $earned ? array( Badge_Repository::PENDING, $trigger, $target ) : null;
	}

	/**
	 * The `any-path` rule: the first block satisfied in the registration.
	 *
	 * @param Course_Structure $s      Course structure.
	 * @param array            $record One actor's record.
	 * @param boolean          $custom Whether the course has custom rules.
	 * @param string           $policy Waiver_Policy constant.
	 *
	 * @return array{0:string,1:string,2:Block}|null
	 */
	private static function any_path( Course_Structure $s, array $record, bool $custom, string $policy ): ?array {
		$result = $record['result'];
		if ( $custom ) {
			foreach ( $record['satisfied'] as $object_id => $trigger ) {
				$block = $s->find_block( (string) $object_id );
				if ( null !== $block ) {
					return array( Badge_Repository::PENDING, $trigger, $block );
				}
			}
			return null;
		}

		$first = $result->first_satisfied_block( $policy );
		if ( null !== $first ) {
			$trigger = $record['satisfied'][ $first ] ?? null;
			return null === $trigger ? null : array( Badge_Repository::PENDING, $trigger, $s->find_block( $first ) );
		}
		foreach ( $record['satisfied'] as $object_id => $trigger ) {
			$block = $s->find_block( (string) $object_id );
			if ( null !== $block && ! $result->block_satisfied( $block->id(), Waiver_Policy::ALL ) ) {
				return array( Badge_Repository::HELD, $trigger, $block );
			}
		}
		return null;
	}

	/**
	 * The registration's non-voided statements grouped by actor, each group in stored order.
	 *
	 * @param string $registration Registration UUID.
	 *
	 * @return array<string,Stored_Statement[]> Raw actor hash => rows.
	 */
	private function by_actor( string $registration ): array {
		$groups = array();
		foreach ( $this->statements->for_registration( $registration ) as $row ) {
			if ( ! $row->voided && ! $row->is_voiding ) {
				$groups[ $row->actor_hash ][] = $row;
			}
		}
		return $groups;
	}

	/**
	 * One actor's facts, recomputed satisfaction and trusted `satisfied` statements.
	 *
	 * @param Course_Structure   $s    Course structure.
	 * @param Stored_Statement[] $rows Rows in stored order.
	 *
	 * @return array{facts:Au_Fact[],result:Satisfaction_Result,satisfied:array<string,string>,rows:array<string,Stored_Statement>}
	 *         `satisfied` maps a block or course id to its first trusted `satisfied` statement id, in stored order.
	 */
	private function record( Course_Structure $s, array $rows ): array {
		$facts     = array();
		$satisfied = array();
		$by_id     = array();
		foreach ( $rows as $row ) {
			$st                = $row->statement();
			$by_id[ $row->id ] = $row;
			$fact              = Au_Fact::from_statement( $st, $s );
			if ( null !== $fact ) {
				$facts[] = $fact;
			}
			$object_id = $st['object']['id'] ?? null;
			if ( Vocab::SATISFIED === ( $st['verb']['id'] ?? null ) && is_string( $object_id ) && ! isset( $satisfied[ $object_id ] )
				&& ( $s->course_id() === $object_id || null !== $s->find_block( $object_id ) ) && $this->trusted( $row ) ) {
				$satisfied[ $object_id ] = $row->id;
			}
		}
		return array(
			'facts'     => $facts,
			'result'    => Satisfaction_Evaluator::evaluate( $s, $facts ),
			'satisfied' => $satisfied,
			'rows'      => $by_id,
		);
	}

	/**
	 * Id of the first trusted statement that meets the AU's moveOn under the policy: a
	 * moveOn verb (or a waiver the policy counts) after which the AU is satisfied.
	 *
	 * @param Course_Structure $s      Course structure.
	 * @param Au               $au     AU.
	 * @param array            $record One actor's record (see record()).
	 * @param string           $policy Waiver_Policy constant.
	 *
	 * @return string|null Null when no trusted statement meets it (or moveOn is NotApplicable).
	 */
	private function move_on_statement( Course_Structure $s, Au $au, array $record, string $policy ): ?string {
		$own = array_values( array_filter( $record['facts'], static fn( Au_Fact $f ): bool => $f->au_id === $au->id() ) );
		foreach ( $own as $i => $fact ) {
			if ( ! self::meets_move_on( $au, $fact, $policy ) || ! $this->trusted( $record['rows'][ $fact->statement_id ] ?? null ) ) {
				continue;
			}
			if ( Satisfaction_Evaluator::evaluate( $s, array_slice( $own, 0, $i + 1 ) )->au_satisfied( $au->id(), $policy ) ) {
				return $fact->statement_id;
			}
		}
		return null;
	}

	/**
	 * Whether a fact is of a kind that can meet the AU's moveOn under the policy.
	 *
	 * @param Au      $au     AU.
	 * @param Au_Fact $fact   Fact.
	 * @param string  $policy Waiver_Policy constant.
	 *
	 * @return boolean
	 */
	private static function meets_move_on( Au $au, Au_Fact $fact, string $policy ): bool {
		if ( Vocab::WAIVED === $fact->verb ) {
			return Waiver_Policy::ALL === $policy || ( Waiver_Policy::EXCLUDE_CARRIED_OVER === $policy && ! $fact->carried_over );
		}
		switch ( $au->move_on() ) {
			case 'Completed':
				return Vocab::COMPLETED === $fact->verb;
			case 'Passed':
				return Vocab::PASSED === $fact->verb;
			case 'CompletedAndPassed':
			case 'CompletedOrPassed':
				return Vocab::COMPLETED === $fact->verb || Vocab::PASSED === $fact->verb;
			default:
				return false;
		}
	}

	/**
	 * Whether a statement was written by an active client marked trusted_for_satisfaction.
	 * Child tokens (`tok:`) and the LRS itself (`sys:`) never are.
	 *
	 * @param Stored_Statement|null $row Row.
	 *
	 * @return boolean
	 */
	private function trusted( ?Stored_Statement $row ): bool {
		if ( null === $row ) {
			return false;
		}
		$key = $row->authority_client;
		if ( ! isset( $this->trust[ $key ] ) ) {
			$client              = str_starts_with( $key, 'tok:' ) || str_starts_with( $key, 'sys:' ) ? null : $this->clients->find( $key );
			$this->trust[ $key ] = null !== $client && $client->trusted && $client->is_active( Clock::now() );
		}
		return $this->trust[ $key ];
	}

	/**
	 * Lessons covered, completed and counted through a waiver, for the narrative.
	 *
	 * @param Course_Structure    $s      Course structure.
	 * @param Satisfaction_Result $result Recomputed satisfaction.
	 * @param Au|Block|null       $target AU, block, or null for the course.
	 * @param string              $policy Waiver_Policy constant.
	 *
	 * @return array{0:int,1:int,2:int}
	 */
	private static function counts( Course_Structure $s, Satisfaction_Result $result, Au|Block|null $target, string $policy ): array {
		if ( $target instanceof Au ) {
			$aus = array( $target );
		} elseif ( $target instanceof Block ) {
			$aus = self::aus_under( $target );
		} else {
			$aus = $s->aus();
		}
		$completed  = 0;
		$tested_out = 0;
		foreach ( $aus as $au ) {
			if ( $result->au_satisfied( $au->id(), Waiver_Policy::NONE ) ) {
				++$completed;
			} elseif ( $result->au_satisfied( $au->id(), $policy ) ) {
				++$tested_out;
			}
		}
		return array( count( $aus ), $completed, $tested_out );
	}

	/**
	 * Audit and flag a held badge for the admin.
	 *
	 * @param string $id           Badge id.
	 * @param array  $d            Declaration.
	 * @param string $registration Registration UUID.
	 * @param string $trigger      Trigger statement id.
	 *
	 * @return void
	 */
	private static function held( string $id, array $d, string $registration, string $trigger ): void {
		Audit_Log::record(
			self::HELD_EVENT,
			array(
				'badge'        => $id,
				'achievement'  => (string) $d['id'],
				'award_on'     => (string) ( $d['awardOn'] ?? '' ),
				'registration' => $registration,
				'trigger'      => $trigger,
				'reason'       => 'recomputed satisfaction disagrees with the trusted satisfied statement',
			)
		);
		// A flag, not a counter, so concurrent jobs cannot lose an update; the notice counts the rows.
		update_option( Pipeline_Module::HELD_OPTION, 1, false );
	}

	/**
	 * Waiver policy of a declaration.
	 *
	 * @param array $d Declaration.
	 *
	 * @return string
	 */
	private static function policy( array $d ): string {
		return true === ( $d['countWaived'] ?? false ) ? Waiver_Policy::EXCLUDE_CARRIED_OVER : Waiver_Policy::NONE;
	}

	/**
	 * The Achievement fields of a declaration.
	 *
	 * @param array $d Declaration.
	 *
	 * @return array
	 */
	private static function achievement( array $d ): array {
		return array_intersect_key( $d, array_flip( self::ACHIEVEMENT_FIELDS ) );
	}

	/**
	 * Block by cxm:key.
	 *
	 * @param Course_Structure $s   Course structure.
	 * @param string           $key Key.
	 *
	 * @return Block|null
	 */
	private static function block_by_key( Course_Structure $s, string $key ): ?Block {
		foreach ( $s->blocks() as $block ) {
			if ( $block->key() === $key ) {
				return $block;
			}
		}
		return null;
	}

	/**
	 * Every AU under a block.
	 *
	 * @param Block $block Block.
	 *
	 * @return Au[]
	 */
	private static function aus_under( Block $block ): array {
		$out = array();
		foreach ( $block->children() as $child ) {
			if ( $child instanceof Au ) {
				$out[] = $child;
			} else {
				array_push( $out, ...self::aus_under( $child ) );
			}
		}
		return $out;
	}
}
