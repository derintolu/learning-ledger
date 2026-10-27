<?php
/**
 * Course_Structure_Parser tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Block;
use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Cmi5\Course_Structure_Parser;
use LearningLedger\Util\Invalid_Input;

class Course_Structure_Parser_Test extends \PHPUnit\Framework\TestCase {

	const FIX = __DIR__ . '/../../fixtures/cmi5';

	private function parse( string $name ): Course_Structure {
		return Course_Structure_Parser::parse( file_get_contents( self::FIX . '/' . $name ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
	}

	public function test_xsd_path_points_at_bundled_schema() {
		$this->assertFileExists( Course_Structure_Parser::XSD_PATH );
		$this->assertStringContainsString(
			'targetNamespace="https://w3id.org/xapi/profiles/cmi5/v1/CourseStructure.xsd"',
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
			file_get_contents( Course_Structure_Parser::XSD_PATH )
		);
	}

	public function test_cxm_course() {
		$s = $this->parse( 'cxm-course.xml' );
		$this->assertSame( 'https://example.org/courses/json-basics', $s->course_id() );
		$this->assertSame( array( 'placement', 'what-is-json', 'json-syntax' ), array_map( fn( $a ) => $a->key(), $s->aus() ) );
		$this->assertSame( 'JSON basics', $s->title()['en'] );

		$placement = $s->au_by_key( 'placement' );
		$this->assertInstanceOf( Au::class, $placement );
		$this->assertSame( 'https://example.org/courses/json-basics/placement', $placement->id() );
		$this->assertSame( 'placement', $placement->role() );
		$this->assertSame( 'NotApplicable', $placement->move_on() );
		$this->assertSame( 'OwnWindow', $placement->launch_method() );
		$this->assertNull( $placement->parent_block_id() );
		$this->assertNull( $placement->lesson_src() );

		$what = $s->find_au( 'https://example.org/courses/json-basics/lessons/what-is-json' );
		$this->assertSame( 'what-is-json', $what->key() );
		$this->assertNull( $what->role() );
		$this->assertSame( 'CompletedAndPassed', $what->move_on() );
		$this->assertSame( 0.8, $what->mastery_score() );
		$this->assertSame( 'http://adlnet.gov/expapi/activities/lesson', $what->activity_type() );
		$this->assertSame( 'https://json-basics.example.org/cmi5/launch?au=what-is-json', $what->url() );
		$this->assertSame( 'https://example.org/courses/json-basics/paths/json', $what->parent_block_id() );
		$this->assertSame( 'cxm/lessons/what-is-json.json', $what->lesson_src() );
		$this->assertSame( array( 'en' => 'What is JSON' ), $what->title() );
		$this->assertSame( array( 'en' => 'Values, objects and arrays.' ), $what->description() );

		$block = $s->find_block( 'https://example.org/courses/json-basics/paths/json' );
		$this->assertInstanceOf( Block::class, $block );
		$this->assertSame( 'json', $block->key() );
		$this->assertSame( array( 'en' => 'JSON' ), $block->title() );
		$this->assertNull( $block->parent_block_id() );
		$this->assertSame(
			array( 'what-is-json', 'json-syntax' ),
			array_map( fn( $a ) => $a->key(), $block->children() )
		);
		$this->assertCount( 2, $s->children() );
		$this->assertInstanceOf( Au::class, $s->children()[0] );
		$this->assertInstanceOf( Block::class, $s->children()[1] );
		$this->assertNull( $s->au_by_key( 'missing' ) );
	}

	public function test_simple_defaults_and_trimming() {
		$s = $this->parse( 'simple.xml' );
		$this->assertSame(
			array(
				'en-US' => 'Simple course',
				'fr'    => 'Cours simple',
			),
			$s->title()
		);
		$this->assertSame( array(), $s->blocks() );
		$au = $s->aus()[0];
		$this->assertSame( 'https://example.org/courses/simple/au1', $au->id() );
		$this->assertSame( 'NotApplicable', $au->move_on() );
		$this->assertSame( 'AnyWindow', $au->launch_method() );
		$this->assertNull( $au->key() );
		$this->assertNull( $au->role() );
		$this->assertNull( $au->mastery_score() );
		$this->assertNull( $au->activity_type() );
		$this->assertNull( $au->parent_block_id() );
		$this->assertSame( 'index.html', $au->url() );
		$this->assertSame( array( 'en-US' => 'Only lesson' ), $au->title() );
		$this->assertSame( '{"mode":"quiz"}', $au->launch_parameters() );
		$this->assertSame( 'ent-123', $au->entitlement_key() );
	}

	public function test_nested_blocks() {
		$s    = $this->parse( 'nested-blocks.xml' );
		$base = 'https://example.org/courses/nested';
		$this->assertSame(
			array( "$base/blocks/a", "$base/blocks/b", "$base/blocks/b1" ),
			array_map( fn( $b ) => $b->id(), $s->blocks() )
		);
		$this->assertSame(
			array( "$base/aus/a1", "$base/aus/b1", "$base/aus/b2" ),
			array_map( fn( $a ) => $a->id(), $s->aus() )
		);
		$b = $s->find_block( "$base/blocks/b" );
		$this->assertInstanceOf( Block::class, $b->children()[0] );
		$this->assertInstanceOf( Au::class, $b->children()[1] );
		$this->assertSame( "$base/blocks/b", $s->find_block( "$base/blocks/b1" )->parent_block_id() );
		$this->assertSame( "$base/blocks/b1", $s->find_au( "$base/aus/b1" )->parent_block_id() );
		$this->assertSame( "$base/blocks/b", $s->find_au( "$base/aus/b2" )->parent_block_id() );
		$this->assertNull( $s->find_au( "$base/aus/zz" ) );
		$this->assertNull( $s->find_block( "$base/blocks/zz" ) );
	}

	public function test_invalid_xml_fails_xsd() {
		try {
			$this->parse( 'invalid.xml' );
			$this->fail( 'Expected Invalid_Input' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'xsd', $e->error_code() );
			$this->assertStringContainsString( 'moveOn', $e->getMessage() );
			$this->assertStringContainsString( 'url', $e->getMessage() );
		}
	}

	public function test_malformed_xml_is_rejected() {
		try {
			Course_Structure_Parser::parse( '<courseStructure><course>' );
			$this->fail( 'Expected Invalid_Input' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'xsd', $e->error_code() );
			$this->assertNotSame( '', $e->getMessage() );
		}
	}

	public function test_doctype_is_rejected() {
		$xml = str_replace(
			'<courseStructure ',
			"<!DOCTYPE courseStructure [ <!ENTITY x SYSTEM \"file:///etc/passwd\"> ]>\n<courseStructure ",
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
			file_get_contents( self::FIX . '/simple.xml' )
		);
		try {
			Course_Structure_Parser::parse( $xml );
			$this->fail( 'Expected Invalid_Input' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'doctype', $e->error_code() );
		}
	}

	public function test_xml_is_whitespace_trimmed() {
		$s = $this->parse( 'cxm-course.xml' );
		$this->assertStringContainsString( '<langstring lang="en">JSON basics</langstring>', $s->xml() );
		$this->assertStringContainsString( 'cxm:key="what-is-json"', $s->xml() );
		$this->assertSame( $s->to_array(), Course_Structure_Parser::parse( $s->xml() )->to_array() );
	}

	public function test_array_round_trip() {
		$s    = $this->parse( 'nested-blocks.xml' );
		$back = Course_Structure::from_array( json_decode( json_encode( $s->to_array(), JSON_THROW_ON_ERROR ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in unit tests.
		$this->assertSame( $s->to_array(), $back->to_array() );
		$this->assertSame( $s->xml(), $back->xml() );
		$this->assertSame( 0.5, $back->find_au( 'https://example.org/courses/nested/aus/b1' )->mastery_score() );
		$this->assertSame( 'https://example.org/courses/nested/blocks/b', $back->find_block( 'https://example.org/courses/nested/blocks/b1' )->parent_block_id() );
	}
}
