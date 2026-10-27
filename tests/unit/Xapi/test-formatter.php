<?php
/**
 * Formatter tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Xapi\Formatter;

class Formatter_Test extends \PHPUnit\Framework\TestCase {

	private static function statement(): array {
		return array(
			'id'        => '6f1c3a1e-6c8f-4d7b-9a3e-1b2c3d4e5f60',
			'actor'     => array(
				'objectType' => 'Agent',
				'name'       => 'Ada',
				'mbox'       => 'mailto:ada@example.test',
			),
			'verb'      => array(
				'id'      => 'http://adlnet.gov/expapi/verbs/completed',
				'display' => array(
					'en-US' => 'completed',
					'fr'    => 'termine',
				),
			),
			'object'    => array(
				'objectType' => 'Activity',
				'id'         => 'https://site.example/act/1',
				'definition' => array(
					'name'        => array(
						'de'    => 'Lektion',
						'en-GB' => 'Lesson',
						'fr-FR' => 'Lecon',
					),
					'description' => array( 'de' => 'Eine Lektion' ),
					'type'        => 'http://adlnet.gov/expapi/activities/lesson',
				),
			),
			'context'   => array(
				'instructor'        => array(
					'objectType' => 'Group',
					'name'       => 'Teachers',
					'member'     => array(
						array(
							'name'    => 'Bob',
							'account' => array(
								'homePage' => 'https://site.example',
								'name'     => 'bob',
							),
						),
					),
				),
				'contextActivities' => array(
					'parent' => array(
						array(
							'id'         => 'https://site.example/course/1',
							'definition' => array( 'name' => array( 'en' => 'Course' ) ),
						),
					),
				),
			),
			'authority' => array(
				'objectType' => 'Agent',
				'name'       => 'LRS',
				'account'    => array(
					'homePage' => 'https://lrs.example',
					'name'     => 'client-1',
				),
			),
			'stored'    => '2026-10-27T08:00:00.000000Z',
		);
	}

	public function test_exact_is_unchanged() {
		$this->assertSame( self::statement(), Formatter::format( self::statement(), 'exact', 'fr' ) );
	}

	public function test_ids_reduces_agents_groups_activities_and_verbs() {
		$out = Formatter::format( self::statement(), 'ids', '' );
		$this->assertSame(
			array(
				'objectType' => 'Agent',
				'mbox'       => 'mailto:ada@example.test',
			),
			$out['actor']
		);
		$this->assertSame( array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ), $out['verb'] );
		$this->assertSame(
			array(
				'objectType' => 'Activity',
				'id'         => 'https://site.example/act/1',
			),
			$out['object']
		);
		$this->assertSame(
			array(
				'objectType' => 'Group',
				'member'     => array(
					array(
						'account' => array(
							'homePage' => 'https://site.example',
							'name'     => 'bob',
						),
					),
				),
			),
			$out['context']['instructor']
		);
		$this->assertSame(
			array(
				array(
					'objectType' => 'Activity',
					'id'         => 'https://site.example/course/1',
				),
			),
			$out['context']['contextActivities']['parent']
		);
		$this->assertSame(
			array(
				'objectType' => 'Agent',
				'account'    => array(
					'homePage' => 'https://lrs.example',
					'name'     => 'client-1',
				),
			),
			$out['authority']
		);
		$this->assertSame( '2026-10-27T08:00:00.000000Z', $out['stored'] );
	}

	public function test_ids_reaches_into_substatements() {
		$st           = self::statement();
		$st['object'] = array(
			'objectType' => 'SubStatement',
			'actor'      => array(
				'name'         => 'Cy',
				'mbox_sha1sum' => str_repeat( 'a', 40 ),
			),
			'verb'       => array(
				'id'      => 'http://adlnet.gov/expapi/verbs/attempted',
				'display' => array( 'en' => 'attempted' ),
			),
			'object'     => array(
				'id'         => 'https://site.example/act/2',
				'definition' => array( 'name' => array( 'en' => 'Two' ) ),
			),
		);
		$out          = Formatter::format( $st, 'ids', '' );
		$this->assertSame( array( 'mbox_sha1sum' => str_repeat( 'a', 40 ) ), $out['object']['actor'] );
		$this->assertSame( array( 'id' => 'http://adlnet.gov/expapi/verbs/attempted' ), $out['object']['verb'] );
		$this->assertSame(
			array(
				'objectType' => 'Activity',
				'id'         => 'https://site.example/act/2',
			),
			$out['object']['object']
		);
	}

	public function test_canonical_picks_best_accept_language_match() {
		$out = Formatter::format( self::statement(), 'canonical', 'fr, en;q=0.5' );
		$this->assertSame( array( 'fr' => 'termine' ), $out['verb']['display'] );
		// `fr` matches the more specific `fr-FR`.
		$this->assertSame( array( 'fr-FR' => 'Lecon' ), $out['object']['definition']['name'] );
		// No match: the first entry.
		$this->assertSame( array( 'de' => 'Eine Lektion' ), $out['object']['definition']['description'] );
		$this->assertSame( array( 'en' => 'Course' ), $out['context']['contextActivities']['parent'][0]['definition']['name'] );
		// Agents are returned as received.
		$this->assertSame( self::statement()['actor'], $out['actor'] );
	}

	public function test_canonical_honours_quality_order_and_falls_back_to_first() {
		$st = self::statement();
		$this->assertSame( array( 'en-GB' => 'Lesson' ), Formatter::format( $st, 'canonical', 'fr;q=0.1, en;q=0.9' )['object']['definition']['name'] );
		$this->assertSame( array( 'de' => 'Lektion' ), Formatter::format( $st, 'canonical', '' )['object']['definition']['name'] );
		$this->assertSame( array( 'de' => 'Lektion' ), Formatter::format( $st, 'canonical', 'ja' )['object']['definition']['name'] );
		$this->assertSame( array( 'en-US' => 'completed' ), Formatter::format( $st, 'canonical', 'en-US' )['verb']['display'] );
	}

	public function test_canonical_reduces_interaction_components() {
		$st = self::statement();
		$st['object']['definition']['interactionType'] = 'choice';
		$st['object']['definition']['choices']         = array(
			array(
				'id'          => 'a',
				'description' => array(
					'en' => 'Apple',
					'fr' => 'Pomme',
				),
			),
		);
		$out = Formatter::format( $st, 'canonical', 'fr' );
		$this->assertSame( array( 'fr' => 'Pomme' ), $out['object']['definition']['choices'][0]['description'] );
	}
}
