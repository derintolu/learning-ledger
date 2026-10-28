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

	public function test_whole_archive_ratio_catches_many_small_entries() {
		$path = Package_Fixtures::zip(
			function ( ZipArchive $z ) {
				$z->addFromString( 'cmi5.xml', Package_Fixtures::xml() );
				for ( $i = 0; $i < 20; $i++ ) {
					$z->addFromString( 'cxm/files/z' . $i, str_repeat( "\0", 1000 ) );
				}
			}
		);
		// Each entry is under the per-entry floor, so only the archive-wide check can reject this.
		$this->assertSame( 'zip_ratio', $this->code_of( $path, new Limits( 1073741824, 20000, 3 ) ) );
	}

	public function test_oversized_entry_is_refused_by_file_max() {
		$path         = Package_Fixtures::zip(
			function ( ZipArchive $z ) {
				$z->addFromString( 'cmi5.xml', Package_Fixtures::xml() );
				$z->addFromString( 'cxm/files/big', str_repeat( 'a', 5000 ) );
			}
		);
		$this->temp[] = $path;
		$p            = Package::from_zip( $path, new Limits( 1073741824, 20000, 100000 ) );
		$this->assertSame( 5000, strlen( $p->file( 'cxm/files/big', 5000 ) ) );
		try {
			$p->file( 'cxm/files/big', 4999 );
			$this->fail( 'expected Invalid_Input' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'too_large', $e->error_code() );
		}
	}

	public function test_forged_declared_size_bounds_the_read() {
		$zip = Package_Writer::write( Package_Fixtures::xml(), array(), array(), array( 'x' => 'abcdefghij' ) );
		$pos = 0;
		while ( false !== ( $pos = strpos( $zip, "PK\x01\x02", $pos ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- scanning loop.
			$nlen = unpack( 'v', substr( $zip, $pos + 28, 2 ) )[1];
			if ( 'cxm/files/x' === substr( $zip, $pos + 46, $nlen ) ) {
				// Declare 3 bytes (compressed and uncompressed) for a 10 byte stored entry.
				$zip = substr_replace( $zip, pack( 'VV', 3, 3 ), $pos + 20, 8 );
				break;
			}
			$pos += 46;
		}
		$p = Package::from_zip( $this->zip_of( $zip ), new Limits() );
		try {
			$this->assertLessThanOrEqual( 3, strlen( $p->file( 'cxm/files/x' ) ) );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'zip_too_large', $e->error_code() );
		}
	}

	public function test_corrupt_crc_entry_never_emits_a_php_warning() {
		$body = 'abcdefghij';
		$zip  = Package_Writer::write( Package_Fixtures::xml(), array(), array(), array( 'x' => $body ) );
		$crc  = pack( 'V', crc32( $body ) );
		$bad  = pack( 'V', crc32( $body ) ^ 0xffff );
		$this->assertSame( 2, substr_count( $zip, $crc ), 'local header and central directory each carry the CRC' );
		$p        = Package::from_zip( $this->zip_of( str_replace( $crc, $bad, $zip ) ), new Limits() );
		$warnings = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- recording warnings.
			static function ( int $no, string $str ) use ( &$warnings ): bool {
				$warnings[] = $str;
				return true;
			},
			E_WARNING
		);
		try {
			$p->file( 'cxm/files/x' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'zip_too_large', $e->error_code() );
		} finally {
			restore_error_handler();
		}
		$this->assertSame( array(), $warnings );
	}

	public function test_duplicate_entries_are_rejected() {
		$zip = Package_Writer::write(
			Package_Fixtures::xml(),
			array(),
			array(),
			array(
				'a' => '1',
				'b' => '2',
			)
		);
		$this->assertSame( 1, substr_count( $zip, 'cxm/files/b' ) / 2 );
		$zip = str_replace( 'cxm/files/b', 'cxm/files/a', $zip );
		$this->assertSame( 'zip_path', $this->code_of( $this->zip_of( $zip ) ) );
	}
}
