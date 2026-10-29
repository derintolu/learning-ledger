<?php
/**
 * Package_Validator tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Package\Package;
use LearningLedger\Package\Package_Validator;
use LearningLedger\Package\Package_Writer;

require_once __DIR__ . '/../../fixtures/packages/class-package-fixtures.php';

class Package_Validator_Test extends \PHPUnit\Framework\TestCase {

	private array $temp = array();

	protected function tearDown(): void {
		foreach ( $this->temp as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file.
			}
		}
	}

	/**
	 * Writes inputs to a ZIP and loads it.
	 *
	 * @param array $in Writer arguments.
	 *
	 * @return Package
	 */
	private function load( array $in ): Package {
		$path         = tempnam( sys_get_temp_dir(), 'llzip' );
		$this->temp[] = $path;
		file_put_contents( $path, Package_Writer::write( $in[0], $in[1], $in[2], $in[3] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file.
		return Package::from_zip( $path, new \LearningLedger\Package\Limits() );
	}

	private function codes( $result ): array {
		return array_map( fn( $e ) => $e['code'], $result->errors() );
	}

	public function test_good_full_is_ok_as_full() {
		$r = Package_Validator::validate( $this->load( Package_Fixtures::inputs( 'good-full' ) ), 'full' );
		$this->assertSame( array(), $r->errors() );
		$this->assertTrue( $r->ok() );
	}

	public function test_good_full_fails_as_public_with_secret_in_public() {
		$r = Package_Validator::validate( $this->load( Package_Fixtures::inputs( 'good-full' ) ), 'public' );
		$this->assertFalse( $r->ok() );
		$secret = array_values( array_filter( $r->errors(), fn( $e ) => 'secret_in_public' === $e['code'] ) );
		$paths  = array_column( $secret, 'path' );
		sort( $paths );
		$this->assertSame(
			array(
				'/lessons/what-is-json/bug/explanation',
				'/lessons/what-is-json/bug/fixChecks',
				'/lessons/what-is-json/referenceSolution',
			),
			$paths
		);
		$this->assertSame( array( 'secret_in_public' ), array_values( array_unique( $this->codes( $r ) ) ) );
	}

	public function test_good_public_is_ok_as_public_and_fails_as_full() {
		$p = $this->load( Package_Fixtures::inputs( 'good-public' ) );
		$this->assertSame( array(), Package_Validator::validate( $p, 'public' )->errors() );
		$full = Package_Validator::validate( $p, 'full' );
		$this->assertFalse( $full->ok() );
		$this->assertContains( 'schema', $this->codes( $full ) );
	}

	public function test_bad_xsd() {
		$r = Package_Validator::validate( $this->load( Package_Fixtures::inputs( 'bad-xsd' ) ), 'full' );
		$this->assertFalse( $r->ok() );
		$this->assertContains( 'xsd', $this->codes( $r ) );
	}

	public function test_bad_schema_reports_the_json_pointer_path() {
		$r = Package_Validator::validate( $this->load( Package_Fixtures::inputs( 'bad-schema' ) ), 'full' );
		$this->assertFalse( $r->ok() );
		$schema = array_values( array_filter( $r->errors(), fn( $e ) => 'schema' === $e['code'] ) );
		$this->assertContains( '/lessons/what-is-json/checkpoint/kind', array_column( $schema, 'path' ) );
		$this->assertNotSame( '', $schema[0]['message'] );
	}

	public function test_course_json_schema_errors() {
		$in               = Package_Fixtures::inputs( 'good-full' );
		$in[1]['custody'] = 'nope';
		$r                = Package_Validator::validate( $this->load( $in ), 'full' );
		$schema           = array_values( array_filter( $r->errors(), fn( $e ) => 'schema' === $e['code'] ) );
		$this->assertSame( '/course/custody', $schema[0]['path'] );
	}

	public function test_missing_lesson_json() {
		$in = Package_Fixtures::inputs( 'good-full' );
		unset( $in[2]['json-syntax'] );
		$r = Package_Validator::validate( $this->load( $in ), 'full' );
		$this->assertSame( array( 'missing_lesson_json' ), $this->codes( $r ) );
		$this->assertSame( '/au/json-syntax/lesson', $r->errors()[0]['path'] );
	}

	public function test_duplicate_and_bad_keys() {
		$in    = Package_Fixtures::inputs( 'good-full' );
		$in[0] = str_replace( 'cxm:key="json-syntax"', 'cxm:key="what-is-json"', $in[0] );
		$r     = Package_Validator::validate( $this->load( $in ), 'full' );
		$this->assertContains( 'duplicate_key', $this->codes( $r ) );

		$in    = Package_Fixtures::inputs( 'good-full' );
		$in[0] = str_replace( 'cxm:key="json-syntax"', 'cxm:key="Bad_Key"', $in[0] );
		$r     = Package_Validator::validate( $this->load( $in ), 'full' );
		$this->assertContains( 'bad_key', $this->codes( $r ) );

		$in    = Package_Fixtures::inputs( 'good-full' );
		$in[0] = str_replace( 'cxm:key="json-syntax"', 'cxm:key="' . str_repeat( 'a', 65 ) . '"', $in[0] );
		$this->assertContains( 'bad_key', $this->codes( Package_Validator::validate( $this->load( $in ), 'full' ) ) );
	}

	public function test_a_block_key_clashing_with_an_au_key_is_a_duplicate() {
		$in    = Package_Fixtures::inputs( 'good-full' );
		$in[0] = str_replace( 'cxm:key="json">', 'cxm:key="placement">', $in[0] );
		$this->assertContains( 'duplicate_key', $this->codes( Package_Validator::validate( $this->load( $in ), 'full' ) ) );
	}

	public function test_branch_to_unknown_lesson() {
		$in = Package_Fixtures::inputs( 'good-full' );
		$in[2]['what-is-json']['checkpoint']['branches'][0]['to'] = 'ghost';
		$r = Package_Validator::validate( $this->load( $in ), 'full' );
		$this->assertSame( array( 'branch_target_unknown' ), $this->codes( $r ) );
		$this->assertSame( '/lessons/what-is-json/checkpoint/branches/0/to', $r->errors()[0]['path'] );
	}

	public function test_branch_to_a_lesson_in_another_block() {
		$in = Package_Fixtures::inputs( 'good-full' );
		// Move json-syntax out of the block into a second block.
		$in[0]        = str_replace(
			'</block>',
			'</block><block id="https://example.org/courses/json-basics/paths/other" cxm:key="other"><title><langstring lang="en">Other</langstring></title><description><langstring lang="en">Other path.</langstring></description><au id="https://example.org/courses/json-basics/lessons/far" cxm:key="far" moveOn="Completed" launchMethod="OwnWindow"><title><langstring lang="en">Far</langstring></title><description><langstring lang="en">Far.</langstring></description><url>https://json-basics.example.org/cmi5/launch?au=far</url><cxm:lesson src="cxm/lessons/far.json"/></au></block>',
			$in[0]
		);
		$in[2]['far'] = $in[2]['json-syntax'];
		$in[2]['what-is-json']['checkpoint']['branches'][0]['to'] = 'far';
		$r = Package_Validator::validate( $this->load( $in ), 'full' );
		$this->assertSame( array( 'branch_cross_path' ), $this->codes( $r ) );
		$this->assertSame( '/lessons/what-is-json/checkpoint/branches/0/to', $r->errors()[0]['path'] );
	}

	public function test_plain_cmi5_without_cxm_is_ok_when_no_lesson_src() {
		$xml = preg_replace( '#<cxm:lesson[^>]*/>#', '', Package_Fixtures::xml() );
		$xml = preg_replace( '#<cxm:course[^>]*/>#', '', $xml );
		$xml = preg_replace( '#<cxm:path[^>]*/>#', '', $xml );
		$r   = Package_Validator::validate( Package::from_xml( $xml ), 'full' );
		$this->assertSame( array(), $r->errors() );
	}

	public function test_malformed_lesson_json_is_a_schema_error() {
		$in = Package_Fixtures::inputs( 'good-full' );
		// Build a package with a broken lesson file.
		$path         = Package_Fixtures::zip(
			function ( ZipArchive $z ) use ( $in ) {
				$z->addFromString( 'cmi5.xml', $in[0] );
				$z->addFromString( 'cxm/course.json', '{"specVersion":"1.0"}' );
				$z->addFromString( 'cxm/lessons/what-is-json.json', '{not json' );
				$z->addFromString( 'cxm/lessons/json-syntax.json', '{"minutes":1,"beats":[{"key":"a","goal":"g","content":"c"}]}' );
			}
		);
		$this->temp[] = $path;
		$r            = Package_Validator::validate( Package::from_zip( $path, new \LearningLedger\Package\Limits() ), 'full' );
		$paths        = array_column( array_filter( $r->errors(), fn( $e ) => 'schema' === $e['code'] ), 'path' );
		$this->assertContains( '/lessons/what-is-json', $paths );
		$this->assertContains( '/course', $paths );
	}

	public function test_branches_of_the_wrong_type_do_not_crash() {
		$in = Package_Fixtures::inputs( 'good-full' );
		$in[2]['what-is-json']['checkpoint']['branches'] = 'nope';
		$r = Package_Validator::validate( $this->load( $in ), 'full' );
		$this->assertContains( 'schema', $this->codes( $r ) );
	}

	public function test_public_scans_json_outside_the_known_locations() {
		$in           = Package_Fixtures::inputs( 'good-public' );
		$path         = Package_Fixtures::zip(
			function ( ZipArchive $z ) use ( $in ) {
				$z->addFromString( 'cmi5.xml', $in[0] );
				$z->addFromString( 'cxm/course.json', \LearningLedger\Util\Json::encode_exact( $in[1] ) );
				$z->addFromString( 'cxm/lessons/json-syntax.json', \LearningLedger\Util\Json::encode_exact( $in[2]['json-syntax'] ) );
				$z->addFromString( 'cxm/lessons/what-is-json.json', \LearningLedger\Util\Json::encode_exact( $in[2]['what-is-json'] ) );
				$z->addFromString( 'cxm/extra/leak.json', '{"referenceSolution":{"files":{}}}' );
				$z->addFromString( 'cxm/files/starter.json', '{"referenceSolution":1}' );
			}
		);
		$this->temp[] = $path;
		$r            = Package_Validator::validate( Package::from_zip( $path, new \LearningLedger\Package\Limits() ), 'public' );
		$this->assertSame( array( 'secret_in_public' ), $this->codes( $r ) );
		$this->assertSame( '/cxm/extra/leak.json/referenceSolution', $r->errors()[0]['path'] );
	}

	public function test_oversized_cmi5_xml_is_an_xsd_error_not_an_inflate() {
		$path         = Package_Fixtures::zip(
			function ( ZipArchive $z ) {
				$z->addFromString( 'cmi5.xml', str_repeat( ' ', Package::MAX_XML_BYTES + 1 ) );
			}
		);
		$this->temp[] = $path;
		$r            = Package_Validator::validate( Package::from_zip( $path, new \LearningLedger\Package\Limits( 1073741824, 20000, 100000 ) ), 'full' );
		$this->assertSame( array( 'xsd' ), $this->codes( $r ) );
	}

	public function test_oversized_json_entry_is_refused_before_reading() {
		$in           = Package_Fixtures::inputs( 'good-full' );
		$path         = Package_Fixtures::zip(
			function ( ZipArchive $z ) use ( $in ) {
				$z->addFromString( 'cmi5.xml', $in[0] );
				$z->addFromString( 'cxm/lessons/what-is-json.json', '{"a":"' . str_repeat( 'a', Package::MAX_JSON_BYTES ) . '"}' );
			}
		);
		$this->temp[] = $path;
		$p            = Package::from_zip( $path, new \LearningLedger\Package\Limits( 1073741824, 20000, 100000 ) );
		$r            = Package_Validator::validate( $p, 'full' );
		$this->assertContains( '/lessons/what-is-json', array_column( array_filter( $r->errors(), fn( $e ) => 'schema' === $e['code'] ), 'path' ) );
	}
}
