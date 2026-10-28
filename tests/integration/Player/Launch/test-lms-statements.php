<?php
/**
 * LMS statement builder tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Player\Launch\Lms_Statements;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Statement_Validator;

class Lms_Statements_Test extends WP_UnitTestCase {

	const REG     = '6f1c3c1e-9a5e-4c1b-8d4e-2b7f0c9a1d11';
	const SESSION = '0b6c7a52-5d0e-4c3a-9f1e-6a2b3c4d5e6f';
	const AU_ID   = 'https://example.org/course/au/1';

	private static function au( array $overrides = array() ): Au {
		return new Au(
			array_merge(
				array(
					'id'      => self::AU_ID,
					'title'   => array( 'en' => 'One' ),
					'move_on' => 'Passed',
					'url'     => 'https://au.example.org/1/index.html',
				),
				$overrides
			)
		);
	}

	private static function actor(): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => 'https://lms.example',
				'name'     => 'learner-1',
			),
		);
	}

	/**
	 * Checks the cmi5 context every LMS statement shares.
	 */
	private function assert_cmi5( array $st, string $object_id, string $verb, ?string $grouping ): void {
		Statement_Validator::validate( $st, '1.0.3' );
		Statement_Validator::validate( $st, '2.0.0' );
		$this->assertSame( self::actor(), $st['actor'] );
		$this->assertSame( $verb, $st['verb']['id'] );
		$this->assertSame(
			array(
				'objectType' => 'Activity',
				'id'         => $object_id,
			),
			$st['object']
		);
		$this->assertSame( self::REG, $st['context']['registration'] );
		$this->assertSame(
			array(
				array(
					'objectType' => 'Activity',
					'id'         => Vocab::CAT_CMI5,
				),
			),
			$st['context']['contextActivities']['category']
		);
		if ( null === $grouping ) {
			$this->assertArrayNotHasKey( 'grouping', $st['context']['contextActivities'] );
		} else {
			$this->assertSame( array( $grouping ), array_column( $st['context']['contextActivities']['grouping'], 'id' ) );
		}
		$this->assertSame( self::SESSION, $st['context']['extensions'][ Vocab::EXT_SESSIONID ] );
		$this->assertSame( Uuid::v5( Lms_Statements::NAMESPACE, self::REG . '|' . $object_id . '|' . $verb . '|' . self::SESSION ), $st['id'] );
		$this->assertArrayNotHasKey( 'timestamp', $st, 'the LRS assigns it, so a retry is identical' );
	}

	public function test_launched() {
		$st = ( new Lms_Statements() )->launched( self::au(), self::actor(), self::REG, self::SESSION, 'Normal', 'https://au.example.org/1/index.html', 'x=1' );
		$this->assert_cmi5( $st, self::AU_ID . '/au', Vocab::LAUNCHED, self::AU_ID );
		$this->assertSame(
			array(
				Vocab::EXT_SESSIONID        => self::SESSION,
				Vocab::EXT_LAUNCHMODE       => 'Normal',
				Vocab::EXT_LAUNCHURL        => 'https://au.example.org/1/index.html',
				Vocab::EXT_MOVEON           => 'Passed',
				Vocab::EXT_LAUNCHPARAMETERS => 'x=1',
			),
			$st['context']['extensions']
		);
		$none = ( new Lms_Statements() )->launched( self::au(), self::actor(), self::REG, self::SESSION, 'Browse', 'https://au.example.org/1/index.html', null );
		$this->assertArrayNotHasKey( Vocab::EXT_LAUNCHPARAMETERS, $none['context']['extensions'] );
		$this->assertSame( $st['id'], $none['id'], 'id depends on registration, object, verb and session only' );
	}

	/**
	 * CATAPULT 001-essentials, cmi5 9.6.3.2-1: `launched` carries the masteryScore from the launch data.
	 */
	public function test_launched_carries_the_mastery_score_when_set() {
		$st = ( new Lms_Statements() )->launched( self::au( array( 'mastery_score' => 0.9 ) ), self::actor(), self::REG, self::SESSION, 'Normal', 'https://au.example.org/1/index.html', null );
		$this->assertSame( 0.9, $st['context']['extensions'][ Vocab::EXT_MASTERYSCORE ] );
		$none = ( new Lms_Statements() )->launched( self::au(), self::actor(), self::REG, self::SESSION, 'Normal', 'https://au.example.org/1/index.html', null );
		$this->assertArrayNotHasKey( Vocab::EXT_MASTERYSCORE, $none['context']['extensions'] );
	}

	public function test_abandoned_carries_the_duration() {
		$st = ( new Lms_Statements() )->abandoned( self::au(), self::actor(), self::REG, self::SESSION, 3725.25 );
		$this->assert_cmi5( $st, self::AU_ID . '/au', Vocab::ABANDONED, self::AU_ID );
		$this->assertSame( array( 'duration' => 'PT1H2M5.25S' ), $st['result'] );
	}

	public function test_waived_carries_the_reason_and_carried_over_only_when_set() {
		$st = ( new Lms_Statements() )->waived( self::au(), self::actor(), self::REG, self::SESSION, 'Tested Out' );
		$this->assertSame( array( Vocab::CAT_CMI5, Vocab::CAT_MOVEON ), array_column( $st['context']['contextActivities']['category'], 'id' ) );
		$st['context']['contextActivities']['category'] = array_slice( $st['context']['contextActivities']['category'], 0, 1 );
		$this->assert_cmi5( $st, self::AU_ID . '/au', Vocab::WAIVED, self::AU_ID );
		$this->assertSame(
			array(
				'success'    => true,
				'completion' => true,
				'extensions' => array( Vocab::EXT_REASON => 'Tested Out' ),
			),
			$st['result']
		);
		$carried = ( new Lms_Statements() )->waived( self::au(), self::actor(), self::REG, self::SESSION, 'Tested Out', true );
		$this->assertTrue( $carried['result']['extensions'][ Vocab::CXM_EXT_CARRIED_OVER ] );
	}

	/**
	 * CATAPULT 009-1-waived, cmi5 9.6.2.2-1: `waived` is a moveOn statement, so it carries the moveon category.
	 */
	public function test_waived_carries_the_moveon_category() {
		$st = ( new Lms_Statements() )->waived( self::au(), self::actor(), self::REG, self::SESSION, 'Administrative' );
		$this->assertContains( Vocab::CAT_MOVEON, array_column( $st['context']['contextActivities']['category'], 'id' ) );
	}

	public function test_satisfied_names_the_block_or_course() {
		$st = ( new Lms_Statements() )->satisfied( 'https://example.org/course/block/1', self::actor(), self::REG, self::SESSION );
		$this->assert_cmi5( $st, 'https://example.org/course/block/1', Vocab::SATISFIED, null );
		$this->assertArrayNotHasKey( 'result', $st );
	}

	public function test_ids_differ_by_session_and_verb() {
		$b = new Lms_Statements();
		$this->assertNotSame(
			$b->launched( self::au(), self::actor(), self::REG, self::SESSION, 'Normal', 'https://au.example.org/1/', null )['id'],
			$b->launched( self::au(), self::actor(), self::REG, Uuid::v4(), 'Normal', 'https://au.example.org/1/', null )['id']
		);
		$this->assertNotSame(
			$b->launched( self::au(), self::actor(), self::REG, self::SESSION, 'Normal', 'https://au.example.org/1/', null )['id'],
			$b->abandoned( self::au(), self::actor(), self::REG, self::SESSION, 1.0 )['id']
		);
	}
}
