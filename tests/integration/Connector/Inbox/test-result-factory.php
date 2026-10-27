<?php
/**
 * Result factory tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Au_Fact;
use LearningLedger\Cmi5\Block;
use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Cmi5\Satisfaction_Evaluator;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Connector\Inbox\Result_Factory;
use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Programs\Program;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Uuid;

class Result_Factory_Test extends WP_UnitTestCase {

	const BASE = 'https://example.org/prog/';

	private Course_Structure $s;
	private Program $p;
	private string $reg;
	private int $i = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'applied' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$block     = self::BASE . 'paths/p1';
		$this->s   = new Course_Structure(
			self::BASE . 'course',
			array(),
			array(),
			array(
				new Block(
					$block,
					'p1',
					array(),
					array(),
					null,
					array(
						new Au(
							array(
								'id'      => self::BASE . 'lessons/a',
								'move_on' => 'CompletedAndPassed',
							)
						),
						new Au(
							array(
								'id'      => self::BASE . 'lessons/b',
								'move_on' => 'Completed',
							)
						),
					)
				),
				new Au(
					array(
						'id'      => self::BASE . 'lessons/unmapped',
						'move_on' => 'Passed',
					)
				),
			),
			''
		);
		$this->p   = new Program( id: 77, stable_key: 'prog', iri_base: self::BASE, course_ids: array( 5 ), path_keys: array( 5 => 'p1' ) );
		$this->reg = Uuid::v4();
	}

	private function fact( string $verb, string $key, ?bool $success = null, ?float $score = null, ?string $reason = null, bool $carried = false ): Au_Fact {
		++$this->i;
		return new Au_Fact( self::BASE . 'lessons/' . $key, $verb, Uuid::v4(), '2026-10-27 10:00:0' . $this->i, $success, null, $score, $reason, $carried );
	}

	private function resolve(): callable {
		return static function ( string $id ): ?Lms_Object {
			$map = array(
				self::BASE . 'lessons/a' => new Lms_Object( 'lesson', 11 ),
				self::BASE . 'lessons/b' => new Lms_Object( 'lesson', 12 ),
				self::BASE . 'paths/p1'  => new Lms_Object( 'course', 5 ),
			);
			return $map[ $id ] ?? null;
		};
	}

	/**
	 * @param Au_Fact[] $facts Facts.
	 *
	 * @return Result[]
	 */
	private function results( array $facts ): array {
		$e = Satisfaction_Evaluator::evaluate( $this->s, $facts );
		return ( new Result_Factory() )->results( $this->p, $this->s, $e, 3, $this->reg, $this->resolve(), $facts );
	}

	private function types( array $results ): array {
		return array_map( static fn( Result $r ) => $r->type . ':' . $r->object->kind . ':' . $r->object->id, $results );
	}

	public function test_bare_completed_on_completed_and_passed_yields_nothing() {
		$this->assertSame( array(), $this->results( array( $this->fact( Vocab::COMPLETED, 'a' ) ) ) );
	}

	public function test_completed_and_passed_yield_pass_then_completion() {
		$c   = $this->fact( Vocab::COMPLETED, 'a' );
		$p   = $this->fact( Vocab::PASSED, 'a', true, 0.75 );
		$out = $this->results( array( $c, $p ) );
		$this->assertSame( array( 'lesson_passed:lesson:11', 'lesson_completed:lesson:11' ), $this->types( $out ) );
		$this->assertSame( 0.75, $out[0]->score_scaled );
		$this->assertSame( $p->statement_id, $out[0]->statement_id );
		$this->assertSame( $p->statement_id, $out[1]->statement_id );
		$this->assertSame( 3, $out[1]->user_id );
		$this->assertSame( $this->reg, $out[1]->registration );
	}

	public function test_failed_yields_lesson_failed_with_score_and_no_completion() {
		$f   = $this->fact( Vocab::FAILED, 'a', false, 0.2 );
		$out = $this->results( array( $this->fact( Vocab::COMPLETED, 'a' ), $f ) );
		$this->assertSame( array( 'lesson_failed:lesson:11' ), $this->types( $out ) );
		$this->assertSame( 0.2, $out[0]->score_scaled );
		$this->assertSame( $f->statement_id, $out[0]->statement_id );
	}

	public function test_failed_then_passed_yields_only_the_pass() {
		$out = $this->results(
			array(
				$this->fact( Vocab::FAILED, 'a', false, 0.2 ),
				$this->fact( Vocab::PASSED, 'a', true, 0.9 ),
			)
		);
		$this->assertSame( array( 'lesson_passed:lesson:11' ), $this->types( $out ) );
		$this->assertSame( 0.9, $out[0]->score_scaled );
	}

	public function test_waiver_yields_lesson_waived_with_reason_and_carried_over() {
		$w   = $this->fact( Vocab::WAIVED, 'b', null, null, 'Tested Out', true );
		$out = $this->results( array( $w ) );
		$this->assertSame( array( 'lesson_waived:lesson:12' ), $this->types( $out ) );
		$this->assertSame( 'Tested Out', $out[0]->reason );
		$this->assertTrue( $out[0]->carried_over );
		$this->assertSame( $w->statement_id, $out[0]->statement_id );
	}

	public function test_completed_before_waiver_yields_completion() {
		$c   = $this->fact( Vocab::COMPLETED, 'b' );
		$out = $this->results( array( $c, $this->fact( Vocab::WAIVED, 'b', null, null, 'Administrative' ) ) );
		$this->assertSame( array( 'lesson_completed:lesson:12' ), $this->types( $out ) );
		$this->assertSame( $c->statement_id, $out[0]->statement_id );
	}

	public function test_block_and_course_satisfied() {
		$facts = array(
			$this->fact( Vocab::COMPLETED, 'a' ),
			$this->fact( Vocab::PASSED, 'a', true ),
			$this->fact( Vocab::COMPLETED, 'b' ),
		);
		$this->assertSame(
			array( 'lesson_passed:lesson:11', 'lesson_completed:lesson:11', 'lesson_completed:lesson:12', 'block_satisfied:course:5' ),
			$this->types( $this->results( $facts ) ),
			'course needs the unmapped AU too'
		);

		$last    = $this->fact( Vocab::PASSED, 'unmapped', true );
		$facts[] = $last;
		$out     = $this->results( $facts );
		$this->assertSame( 'course_satisfied:program:77', $this->types( $out )[4] );
		$this->assertSame( $last->statement_id, $out[4]->statement_id, 'the fact that completed the course' );
		$this->assertSame( $facts[2]->statement_id, $out[3]->statement_id, 'the fact that completed the block' );
		$this->assertCount( 5, $out, 'an AU that resolves to nothing yields nothing' );
	}

	public function test_results_already_in_the_ledger_are_not_yielded() {
		$ledger = new Apply_Ledger();
		$ledger->record( 3, new Lms_Object( 'lesson', 11 ), Result::LESSON_PASSED, Uuid::v4() );
		$ledger->record( 3, new Lms_Object( 'lesson', 12 ), Result::LESSON_WAIVED, Uuid::v4() );
		$out = $this->results(
			array(
				$this->fact( Vocab::COMPLETED, 'a' ),
				$this->fact( Vocab::PASSED, 'a', true ),
				$this->fact( Vocab::COMPLETED, 'b' ),
			)
		);
		$this->assertSame( array( 'lesson_completed:lesson:11', 'block_satisfied:course:5' ), $this->types( $out ) );

		$ledger->record( 3, new Lms_Object( 'lesson', 11 ), Result::LESSON_FAILED, Uuid::v4() );
		$this->assertSame( array(), $this->types( $this->results( array( $this->fact( Vocab::FAILED, 'a', false ) ) ) ) );
	}
}
