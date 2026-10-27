<?php
/**
 * Satisfaction_Evaluator tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Cmi5\Au_Fact;
use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Cmi5\Course_Structure_Parser;
use LearningLedger\Cmi5\Satisfaction_Evaluator;
use LearningLedger\Cmi5\Satisfaction_Result;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Cmi5\Waiver_Policy;

class Satisfaction_Evaluator_Test extends \PHPUnit\Framework\TestCase {

	const FIX = __DIR__ . '/../../fixtures/cmi5';

	const BASE = 'https://example.org/courses/json-basics';

	const PLACEMENT = self::BASE . '/placement';

	const WHAT = self::BASE . '/lessons/what-is-json';

	const SYNTAX = self::BASE . '/lessons/json-syntax';

	const BLOCK = self::BASE . '/paths/json';

	const NESTED = 'https://example.org/courses/nested';

	private int $seq = 0;

	private function structure( string $name = 'cxm-course.xml' ): Course_Structure {
		return Course_Structure_Parser::parse( file_get_contents( self::FIX . '/' . $name ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
	}

	/**
	 * Builds a fact through Au_Fact::from_statement; statement ids are s1, s2, ... in call order.
	 */
	private function fact( Course_Structure $s, string $verb, string $au_id, array $result = array() ): Au_Fact {
		++$this->seq;
		$st = array(
			'id'      => 's' . $this->seq,
			'stored'  => sprintf( '2026-10-27T10:00:%02d.000000Z', $this->seq ),
			'actor'   => array( 'mbox' => 'mailto:learner@example.org' ),
			'verb'    => array( 'id' => $verb ),
			'object'  => array( 'id' => $au_id . '/au' ),
			'context' => array(
				'contextActivities' => array(
					'grouping' => array( array( 'id' => $au_id ) ),
					'category' => array( array( 'id' => Vocab::CAT_CMI5 ) ),
				),
			),
		);
		if ( $result ) {
			$st['result'] = $result;
		}
		$fact = Au_Fact::from_statement( $st, $s );
		$this->assertNotNull( $fact );
		return $fact;
	}

	private function waived( Course_Structure $s, string $au_id, string $reason, bool $carried_over = false ): Au_Fact {
		$ext = array( Vocab::EXT_REASON => $reason );
		if ( $carried_over ) {
			$ext[ Vocab::CXM_EXT_CARRIED_OVER ] = true;
		}
		return $this->fact(
			$s,
			Vocab::WAIVED,
			$au_id,
			array(
				'success'    => true,
				'completion' => true,
				'extensions' => $ext,
			)
		);
	}

	private function evaluate( Course_Structure $s, array $facts ): Satisfaction_Result {
		return Satisfaction_Evaluator::evaluate( $s, $facts );
	}

	public function test_completed_and_passed_needs_both() {
		$s         = $this->structure();
		$completed = $this->fact( $s, Vocab::COMPLETED, self::WHAT );
		$r         = $this->evaluate( $s, array( $completed ) );
		$this->assertFalse( $r->au_satisfied( self::WHAT ) );
		$this->assertNull( $r->au_satisfying_statement( self::WHAT ) );

		$passed = $this->fact(
			$s,
			Vocab::PASSED,
			self::WHAT,
			array(
				'success' => true,
				'score'   => array( 'scaled' => 0.9 ),
			)
		);
		$r      = $this->evaluate( $s, array( $completed, $passed ) );
		$this->assertTrue( $r->au_satisfied( self::WHAT ) );
		$this->assertSame( 's2', $r->au_satisfying_statement( self::WHAT ) );
		$this->assertSame( array( self::WHAT => 0.9 ), $r->scores() );
		$this->assertFalse( $r->block_satisfied( self::BLOCK ) );
	}

	public function test_failed_does_not_satisfy() {
		$s = $this->structure();
		$r = $this->evaluate(
			$s,
			array(
				$this->fact( $s, Vocab::COMPLETED, self::WHAT ),
				$this->fact(
					$s,
					Vocab::FAILED,
					self::WHAT,
					array(
						'success' => false,
						'score'   => array( 'scaled' => 0.4 ),
					)
				),
			)
		);
		$this->assertFalse( $r->au_satisfied( self::WHAT ) );
		$this->assertSame( array( self::WHAT => 0.4 ), $r->scores() );
	}

	public function test_tested_out_waiver_and_policies() {
		$s = $this->structure();
		$r = $this->evaluate(
			$s,
			array(
				$this->fact( $s, Vocab::COMPLETED, self::WHAT ),
				$this->fact( $s, Vocab::PASSED, self::WHAT ),
				$this->waived( $s, self::SYNTAX, 'Tested Out' ),
			)
		);
		$this->assertTrue( $r->block_satisfied( self::BLOCK, Waiver_Policy::ALL ) );
		$this->assertFalse( $r->block_satisfied( self::BLOCK, Waiver_Policy::NONE ) );
		$this->assertTrue( $r->block_satisfied( self::BLOCK, Waiver_Policy::EXCLUDE_CARRIED_OVER ) );
		$this->assertTrue( $r->block_satisfied( self::BLOCK ) );
		$this->assertSame( 's3', $r->block_satisfying_statement( self::BLOCK, Waiver_Policy::ALL ) );
		$this->assertNull( $r->block_satisfying_statement( self::BLOCK, Waiver_Policy::NONE ) );
		$this->assertTrue( $r->course_satisfied() );
		$this->assertFalse( $r->course_satisfied( Waiver_Policy::NONE ) );
		$this->assertSame(
			array(
				'reason'       => 'Tested Out',
				'carried_over' => false,
				'statement_id' => 's3',
			),
			$r->au_waived( self::SYNTAX )
		);
		$this->assertNull( $r->au_waived( self::WHAT ) );
		$this->assertSame( 's3', $r->au_satisfying_statement( self::SYNTAX ) );
		$this->assertSame( 1, $r->waived_count( self::BLOCK ) );
		$this->assertSame( 1, $r->waived_count( self::BASE ) );
	}

	public function test_carried_over_waiver_excluded() {
		$s = $this->structure();
		$r = $this->evaluate(
			$s,
			array(
				$this->fact( $s, Vocab::COMPLETED, self::WHAT ),
				$this->fact( $s, Vocab::PASSED, self::WHAT ),
				$this->waived( $s, self::SYNTAX, 'Administrative', true ),
			)
		);
		$this->assertFalse( $r->block_satisfied( self::BLOCK, Waiver_Policy::EXCLUDE_CARRIED_OVER ) );
		$this->assertTrue( $r->block_satisfied( self::BLOCK, Waiver_Policy::ALL ) );
		$this->assertFalse( $r->block_satisfied( self::BLOCK, Waiver_Policy::NONE ) );
		$this->assertTrue( $r->au_waived( self::SYNTAX )['carried_over'] );
		$this->assertSame( 0, $r->waived_count( self::BLOCK ) );
		$this->assertSame( 1, $r->waived_count( self::BLOCK, false ) );
	}

	public function test_completed_moveon_ignores_waiver_policy_when_met() {
		$s = $this->structure();
		$r = $this->evaluate( $s, array( $this->fact( $s, Vocab::COMPLETED, self::SYNTAX ) ) );
		$this->assertTrue( $r->au_satisfied( self::SYNTAX, Waiver_Policy::NONE ) );
		$this->assertSame( 0, $r->waived_count( self::BLOCK, false ) );
	}

	public function test_not_applicable_satisfied_with_zero_facts() {
		$s = $this->structure();
		$r = $this->evaluate( $s, array() );
		$this->assertTrue( $r->au_satisfied( self::PLACEMENT ) );
		$this->assertTrue( $r->au_satisfied( self::PLACEMENT, Waiver_Policy::NONE ) );
		$this->assertTrue( $r->au_satisfied( self::PLACEMENT, Waiver_Policy::EXCLUDE_CARRIED_OVER ) );
		$this->assertNull( $r->au_satisfying_statement( self::PLACEMENT ) );
		$this->assertFalse( $r->au_satisfied( self::WHAT ) );
		$this->assertFalse( $r->block_satisfied( self::BLOCK ) );
		$this->assertFalse( $r->course_satisfied() );
		$this->assertNull( $r->first_satisfied_block() );
		$this->assertSame( array(), $r->scores() );
		$this->assertFalse( $r->au_satisfied( 'https://example.org/unknown' ) );
		$this->assertFalse( $r->block_satisfied( 'https://example.org/unknown' ) );
	}

	public function test_first_satisfied_block_follows_fact_order() {
		$s = $this->structure( 'nested-blocks.xml' );
		$a = self::NESTED . '/blocks/a';
		$b = self::NESTED . '/blocks/b';

		// b2 is CompletedOrPassed, b1 is Passed: b1 and b complete on s3 (b wins the tie by document order), a on s4.
		$r = $this->evaluate(
			$s,
			array(
				$this->fact( $s, Vocab::PASSED, self::NESTED . '/aus/b2' ),
				$this->fact( $s, Vocab::COMPLETED, self::NESTED . '/aus/b1' ),
				$this->fact( $s, Vocab::PASSED, self::NESTED . '/aus/b1' ),
				$this->fact( $s, Vocab::COMPLETED, self::NESTED . '/aus/a1' ),
			)
		);
		$this->assertTrue( $r->block_satisfied( $a ) );
		$this->assertTrue( $r->block_satisfied( $b ) );
		$this->assertSame( $b, $r->first_satisfied_block() );
		$this->assertSame( 's3', $r->block_satisfying_statement( $b, Waiver_Policy::ALL ) );
		$this->assertSame( 's4', $r->block_satisfying_statement( $a, Waiver_Policy::ALL ) );
		$this->assertTrue( $r->course_satisfied() );

		$r = $this->evaluate(
			$s,
			array(
				$this->fact( $s, Vocab::COMPLETED, self::NESTED . '/aus/a1' ),
				$this->fact( $s, Vocab::PASSED, self::NESTED . '/aus/b1' ),
				$this->fact( $s, Vocab::COMPLETED, self::NESTED . '/aus/b2' ),
			)
		);
		$this->assertSame( $a, $r->first_satisfied_block() );
	}

	public function test_first_satisfied_block_respects_policy() {
		$s = $this->structure( 'nested-blocks.xml' );
		$r = $this->evaluate(
			$s,
			array(
				$this->waived( $s, self::NESTED . '/aus/a1', 'Tested Out' ),
				$this->fact( $s, Vocab::PASSED, self::NESTED . '/aus/b1' ),
			)
		);
		$this->assertSame( self::NESTED . '/blocks/a', $r->first_satisfied_block( Waiver_Policy::ALL ) );
		$this->assertSame( self::NESTED . '/blocks/b1', $r->first_satisfied_block( Waiver_Policy::NONE ) );
	}
}
