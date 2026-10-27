<?php
/**
 * Au_Fact tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Au_Fact;
use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Cmi5\Course_Structure_Parser;
use LearningLedger\Cmi5\Vocab;

class Au_Fact_Test extends \PHPUnit\Framework\TestCase {

	const FIX = __DIR__ . '/../../fixtures/cmi5';

	const WHAT = 'https://example.org/courses/json-basics/lessons/what-is-json';

	private function structure(): Course_Structure {
		return Course_Structure_Parser::parse( file_get_contents( self::FIX . '/cxm-course.xml' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
	}

	private function statement( string $verb, string $object_id, array $grouping = array(), array $result = array(), bool $cmi5 = true ): array {
		$context_activities = array( 'grouping' => array_map( fn( $id ) => array( 'id' => $id ), $grouping ) );
		if ( $cmi5 ) {
			$context_activities['category'] = array( array( 'id' => Vocab::CAT_CMI5 ) );
		}
		$st = array(
			'id'      => 'aaaaaaaa-0000-4000-8000-000000000001',
			'stored'  => '2026-10-27T10:00:00.000000Z',
			'actor'   => array( 'mbox' => 'mailto:learner@example.org' ),
			'verb'    => array( 'id' => $verb ),
			'object'  => array( 'id' => $object_id ),
			'context' => array( 'contextActivities' => $context_activities ),
		);
		if ( $result ) {
			$st['result'] = $result;
		}
		return $st;
	}

	public function test_activity_id_scheme() {
		$this->assertSame( self::WHAT . '/au', Au::activity_id( self::WHAT ) );
	}

	public function test_matches_by_object_id() {
		$fact = Au_Fact::from_statement(
			$this->statement(
				Vocab::PASSED,
				self::WHAT . '/au',
				array(),
				array(
					'success'    => true,
					'completion' => false,
					'score'      => array( 'scaled' => 0.9 ),
				)
			),
			$this->structure()
		);
		$this->assertInstanceOf( Au_Fact::class, $fact );
		$this->assertSame( self::WHAT, $fact->au_id );
		$this->assertSame( Vocab::PASSED, $fact->verb );
		$this->assertSame( 'aaaaaaaa-0000-4000-8000-000000000001', $fact->statement_id );
		$this->assertSame( '2026-10-27T10:00:00.000000Z', $fact->stored );
		$this->assertTrue( $fact->success );
		$this->assertFalse( $fact->completion );
		$this->assertSame( 0.9, $fact->score_scaled );
		$this->assertNull( $fact->reason );
		$this->assertFalse( $fact->carried_over );
	}

	public function test_matches_by_grouping_publisher_id() {
		$fact = Au_Fact::from_statement(
			$this->statement( Vocab::COMPLETED, 'https://lms.example.org/activities/xyz', array( 'https://example.org/courses/json-basics', self::WHAT ) ),
			$this->structure()
		);
		$this->assertInstanceOf( Au_Fact::class, $fact );
		$this->assertSame( self::WHAT, $fact->au_id );
		$this->assertSame( Vocab::COMPLETED, $fact->verb );
		$this->assertNull( $fact->success );
		$this->assertNull( $fact->completion );
		$this->assertNull( $fact->score_scaled );
	}

	public function test_waived_reason_and_carried_over() {
		$fact = Au_Fact::from_statement(
			$this->statement(
				Vocab::WAIVED,
				self::WHAT . '/au',
				array(),
				array(
					'success'    => true,
					'completion' => true,
					'extensions' => array(
						Vocab::EXT_REASON           => 'Administrative',
						Vocab::CXM_EXT_CARRIED_OVER => true,
					),
				)
			),
			$this->structure()
		);
		$this->assertSame( 'Administrative', $fact->reason );
		$this->assertTrue( $fact->carried_over );
	}

	public function test_unrelated_statement_returns_null() {
		$this->assertNull(
			Au_Fact::from_statement(
				$this->statement( Vocab::COMPLETED, 'https://example.org/other/au', array( 'https://example.org/other' ) ),
				$this->structure()
			)
		);
	}

	public function test_allowed_statement_without_cmi5_category_returns_null() {
		$this->assertNull(
			Au_Fact::from_statement(
				$this->statement( Vocab::COMPLETED, self::WHAT . '/au', array( self::WHAT ), array(), false ),
				$this->structure()
			)
		);
	}

	public function test_non_cmi5_verb_returns_null() {
		$this->assertNull(
			Au_Fact::from_statement(
				$this->statement( 'http://adlnet.gov/expapi/verbs/answered', self::WHAT . '/au', array( self::WHAT ) ),
				$this->structure()
			)
		);
	}

	public function test_vocab_iris() {
		$this->assertSame( 'http://adlnet.gov/expapi/verbs/launched', Vocab::LAUNCHED );
		$this->assertSame( 'http://adlnet.gov/expapi/verbs/initialized', Vocab::INITIALIZED );
		$this->assertSame( 'http://adlnet.gov/expapi/verbs/completed', Vocab::COMPLETED );
		$this->assertSame( 'http://adlnet.gov/expapi/verbs/passed', Vocab::PASSED );
		$this->assertSame( 'http://adlnet.gov/expapi/verbs/failed', Vocab::FAILED );
		$this->assertSame( 'http://adlnet.gov/expapi/verbs/terminated', Vocab::TERMINATED );
		$this->assertSame( 'https://w3id.org/xapi/adl/verbs/abandoned', Vocab::ABANDONED );
		$this->assertSame( 'https://w3id.org/xapi/adl/verbs/waived', Vocab::WAIVED );
		$this->assertSame( 'https://w3id.org/xapi/adl/verbs/satisfied', Vocab::SATISFIED );
		$this->assertSame( 'https://w3id.org/xapi/cmi5/context/categories/cmi5', Vocab::CAT_CMI5 );
		$this->assertSame( 'https://w3id.org/xapi/cmi5/context/categories/moveon', Vocab::CAT_MOVEON );
		$this->assertSame( 'https://w3id.org/xapi/cmi5/context/extensions/sessionid', Vocab::EXT_SESSIONID );
		$this->assertSame( 'https://w3id.org/xapi/cmi5/context/extensions/masteryscore', Vocab::EXT_MASTERYSCORE );
		$this->assertSame( 'https://w3id.org/xapi/cmi5/context/extensions/launchmode', Vocab::EXT_LAUNCHMODE );
		$this->assertSame( 'https://w3id.org/xapi/cmi5/context/extensions/launchurl', Vocab::EXT_LAUNCHURL );
		$this->assertSame( 'https://w3id.org/xapi/cmi5/context/extensions/moveon', Vocab::EXT_MOVEON );
		$this->assertSame( 'https://w3id.org/xapi/cmi5/context/extensions/launchparameters', Vocab::EXT_LAUNCHPARAMETERS );
		$this->assertSame( 'https://w3id.org/xapi/cmi5/result/extensions/reason', Vocab::EXT_REASON );
		$this->assertSame( 'https://course-xapi-mcp.sqwrlz.com/xapi/extensions/carried-over', Vocab::CXM_EXT_CARRIED_OVER );
		$this->assertSame( 'https://course-xapi-mcp.sqwrlz.com/xapi/extensions/waive-recommended', Vocab::CXM_EXT_WAIVE_RECOMMENDED );
		$this->assertSame( 'https://course-xapi-mcp.sqwrlz.com/course/v1', Vocab::CXM_NS );
		$this->assertSame( 'http://specification.openbadges.org/xapi/verbs/earned', Vocab::EARNED );
	}
}
