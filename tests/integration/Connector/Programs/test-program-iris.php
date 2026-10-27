<?php
/**
 * Program IRI tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Cmi5\Au;
use LearningLedger\Connector\Programs\Program;
use LearningLedger\Connector\Programs\Program_Iris;

class Program_Iris_Test extends WP_UnitTestCase {

	private function program(): Program {
		return new Program(
			id: 1,
			stable_key: 'prototype',
			iri_base: 'https://learn-wp-reimagined.space.fast/activities/',
			course_iri: 'https://learn-wp-reimagined.space.fast/activities/course'
		);
	}

	public function test_prototype_iris() {
		$p = $this->program();
		$this->assertSame( 'https://learn-wp-reimagined.space.fast/activities/course', $p->course_iri );
		$au = Program_Iris::au_iri( $p, 'bt-what-is-a-block-theme' );
		$this->assertSame( 'https://learn-wp-reimagined.space.fast/activities/lessons/bt-what-is-a-block-theme', $au );
		$this->assertSame( $au . '/au', Program_Iris::au_activity_id( $au ) );
		$this->assertSame( Au::activity_id( $au ), Program_Iris::au_activity_id( $au ) );
		$this->assertSame( 'https://learn-wp-reimagined.space.fast/activities/paths/block-themes', Program_Iris::block_iri( $p, 'block-themes' ) );
		$this->assertSame( 'https://learn-wp-reimagined.space.fast/activities/placement', Program_Iris::placement_iri( $p ) );
	}

	public function test_key_from_iri_round_trips() {
		$p = $this->program();
		$this->assertSame(
			array(
				'kind' => 'lesson',
				'key'  => 'bt-what-is-a-block-theme',
			),
			Program_Iris::key_from_iri( $p, Program_Iris::au_iri( $p, 'bt-what-is-a-block-theme' ) )
		);
		$this->assertSame(
			array(
				'kind' => 'lesson',
				'key'  => 'k1',
			),
			Program_Iris::key_from_iri( $p, Program_Iris::au_activity_id( Program_Iris::au_iri( $p, 'k1' ) ) )
		);
		$this->assertSame(
			array(
				'kind' => 'path',
				'key'  => 'block-themes',
			),
			Program_Iris::key_from_iri( $p, Program_Iris::block_iri( $p, 'block-themes' ) )
		);
		$this->assertSame(
			array(
				'kind' => 'placement',
				'key'  => 'placement',
			),
			Program_Iris::key_from_iri( $p, Program_Iris::placement_iri( $p ) )
		);
		$this->assertNull( Program_Iris::key_from_iri( $p, 'https://elsewhere.test/lessons/x' ) );
		$this->assertNull( Program_Iris::key_from_iri( $p, 'https://learn-wp-reimagined.space.fast/activities/other/x' ) );
	}
}
