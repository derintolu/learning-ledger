<?php
/**
 * Package tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Package\Package_Writer;
use LearningLedger\Util\Invalid_Input;

require_once __DIR__ . '/../../fixtures/packages/class-package-fixtures.php';

class Package_Test extends \PHPUnit\Framework\TestCase {

	private array $temp = array();

	protected function tearDown(): void {
		foreach ( $this->temp as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file.
			}
		}
	}

	private function zip_of( string $bytes ): string {
		$path         = tempnam( sys_get_temp_dir(), 'llzip' );
		$this->temp[] = $path;
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file.
		return $path;
	}

	private function code_of( string $path, ?Limits $limits = null ): string {
		$this->temp[] = $path;
		try {
			Package::from_zip( $path, $limits ?? new Limits() );
		} catch ( Invalid_Input $e ) {
			return $e->error_code();
		}
		return 'none';
	}

	public function test_round_trip_from_written_zip() {
		$in  = Package_Fixtures::inputs( 'good-full' );
		$zip = Package_Writer::write( $in[0], $in[1], $in[2], array( 'starter/a.txt' => 'hello' ) );
		$p   = Package::from_zip( $this->zip_of( $zip ), new Limits() );

		$this->assertTrue( $p->is_cxm() );
		$this->assertSame( $in[0], $p->cmi5_xml() );
		$this->assertSame( $in[1], $p->course_json() );
		$this->assertSame( $in[2]['what-is-json'], $p->lesson_json( 'what-is-json' ) );
		$this->assertSame( array( 'json-syntax', 'what-is-json' ), $p->lesson_keys() );
		$this->assertNull( $p->lesson_json( 'nope' ) );
		$this->assertSame( array( 'cxm/files/starter/a.txt' ), $p->files() );
		$this->assertSame( 'hello', $p->file( 'cxm/files/starter/a.txt' ) );
		$this->assertSame(
			array( 'cmi5.xml', 'cxm/course.json', 'cxm/files/starter/a.txt', 'cxm/lessons/json-syntax.json', 'cxm/lessons/what-is-json.json' ),
			$p->entries()
		);
	}

	public function test_from_xml_is_plain_cmi5() {
		$p = Package::from_xml( Package_Fixtures::xml() );
		$this->assertFalse( $p->is_cxm() );
		$this->assertNull( $p->course_json() );
		$this->assertSame( array(), $p->lesson_keys() );
		$this->assertSame( array(), $p->files() );
		$this->assertSame( array( 'cmi5.xml' ), $p->entries() );
		$this->assertSame( Package_Fixtures::xml(), $p->cmi5_xml() );
	}

	public function test_missing_file_reads_as_empty_error() {
		$this->expectException( Invalid_Input::class );
		Package::from_xml( '<x/>' )->file( 'cxm/files/none' );
	}

	public function test_no_cmi5_xml() {
		$path = Package_Fixtures::zip(
			function ( ZipArchive $z ) {
				$z->addFromString( 'readme.txt', 'x' );
			}
		);
		$this->assertSame( 'no_cmi5_xml', $this->code_of( $path ) );
	}

	public function test_not_a_zip() {
		$this->assertSame( 'zip_invalid', $this->code_of( $this->zip_of( 'this is not a zip' ) ) );
	}

	public function test_traversal_entry_is_rejected() {
		$this->assertSame( 'zip_path', $this->code_of( Package_Fixtures::traversal_zip( '../evil.txt' ) ) );
		$this->assertSame( 'zip_path', $this->code_of( Package_Fixtures::traversal_zip( 'cxm/../../evil.txt' ) ) );
	}

	public function test_absolute_backslash_and_drive_paths_are_rejected() {
		$this->assertSame( 'zip_path', $this->code_of( Package_Fixtures::traversal_zip( '/etc/passwd' ) ) );
		$this->assertSame( 'zip_path', $this->code_of( Package_Fixtures::traversal_zip( 'cxm\\files\\a' ) ) );
		$this->assertSame( 'zip_path', $this->code_of( Package_Fixtures::traversal_zip( 'C:/win.txt' ) ) );
		// ZipArchive cannot create a name with a NUL byte, so check the name rule directly.
		try {
			Package::check_name( "a\0b" );
			$this->fail( 'expected Invalid_Input' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'zip_path', $e->error_code() );
		}
	}

	public function test_symlink_entry_is_rejected() {
		$this->assertSame( 'zip_symlink', $this->code_of( Package_Fixtures::symlink_zip() ) );
	}

	public function test_too_many_files() {
		$path = Package_Fixtures::zip(
			function ( ZipArchive $z ) {
				$z->addFromString( 'cmi5.xml', Package_Fixtures::xml() );
				$z->addFromString( 'cxm/files/a', 'a' );
				$z->addFromString( 'cxm/files/b', 'b' );
			}
		);
		$this->assertSame( 'zip_too_many', $this->code_of( $path, new Limits( 1073741824, 2, 100 ) ) );
	}

	public function test_total_size_over_cap() {
		$path = Package_Fixtures::zip(
			function ( ZipArchive $z ) {
				$z->addFromString( 'cmi5.xml', Package_Fixtures::xml() );
				$z->addFromString( 'cxm/files/a', str_repeat( 'a', 5000 ) );
			}
		);
		$this->assertSame( 'zip_too_large', $this->code_of( $path, new Limits( 4000, 20000, 1000000 ) ) );
	}

	public function test_ratio_bomb() {
		$path = Package_Fixtures::bomb_zip();
		$this->assertSame( 'zip_ratio', $this->code_of( $path ) );
	}

	public function test_a_normal_package_passes_default_limits() {
		$in = Package_Fixtures::inputs( 'good-full' );
		$this->assertSame( 'none', $this->code_of( $this->zip_of( Package_Writer::write( $in[0], $in[1], $in[2], array() ) ) ) );
	}
}
