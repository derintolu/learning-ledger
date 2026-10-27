<?php
/**
 * Package_Writer tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Package\Package_Writer;
use LearningLedger\Util\Invalid_Input;

require_once __DIR__ . '/../../fixtures/packages/class-package-fixtures.php';

class Package_Writer_Test extends \PHPUnit\Framework\TestCase {

	public function test_same_input_gives_same_bytes_and_stable_etag() {
		$in = Package_Fixtures::inputs( 'good-full' );
		$a  = Package_Writer::write(
			$in[0],
			$in[1],
			$in[2],
			array(
				'x/b.txt' => 'b',
				'x/a.txt' => 'a',
			)
		);
		// Different insertion order for lessons and files.
		$b = Package_Writer::write(
			$in[0],
			$in[1],
			array_reverse( $in[2], true ),
			array(
				'x/a.txt' => 'a',
				'x/b.txt' => 'b',
			)
		);

		$this->assertSame( $a, $b );
		$this->assertSame( Package_Writer::etag( $a ), Package_Writer::etag( $b ) );
		$this->assertSame( '"' . hash( 'sha256', $a ) . '"', Package_Writer::etag( $a ) );
	}

	public function test_etag_changes_with_content() {
		$in                              = Package_Fixtures::inputs( 'good-full' );
		$a                               = Package_Writer::write( $in[0], $in[1], $in[2], array() );
		$in[2]['json-syntax']['minutes'] = 9;
		$b                               = Package_Writer::write( $in[0], $in[1], $in[2], array() );
		$this->assertNotSame( Package_Writer::etag( $a ), Package_Writer::etag( $b ) );
	}

	public function test_entries_are_sorted_with_fixed_mtime() {
		$in  = Package_Fixtures::inputs( 'good-full' );
		$zip = Package_Writer::write(
			$in[0],
			$in[1],
			$in[2],
			array(
				'z' => 'z',
				'a' => 'a',
			)
		);

		// Walk the central directory: signature, then DOS time at +12 and date at +14, name length at +28.
		$names = array();
		$pos   = 0;
		while ( false !== ( $pos = strpos( $zip, "PK\x01\x02", $pos ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- scanning loop.
			$h = unpack( 'vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen/vclen', substr( $zip, $pos + 12, 22 ) );
			$this->assertSame( 0, $h['time'] );
			$this->assertSame( ( 0 << 9 ) | ( 1 << 5 ) | 1, $h['date'], 'DOS date must be 1980-01-01' );
			$names[] = substr( $zip, $pos + 46, $h['nlen'] );
			$pos    += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];
		}
		$sorted = $names;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $names );
		$this->assertSame( 'cmi5.xml', $names[0] );
		$this->assertCount( 6, $names );
	}

	public function test_json_is_compact_and_exact() {
		$in             = Package_Fixtures::inputs( 'good-full' );
		$in[1]['rules'] = array( 'a/b "é"' );
		$path           = tempnam( sys_get_temp_dir(), 'llzip' );
		file_put_contents( $path, Package_Writer::write( $in[0], $in[1], $in[2], array() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file.
		$zip = new ZipArchive();
		$zip->open( $path );
		$json = $zip->getFromName( 'cxm/course.json' );
		$zip->close();
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file.
		$this->assertStringContainsString( '"rules":["a/b \"é\""]', $json );
		$this->assertStringNotContainsString( "\n", $json );
	}

	public function test_bad_lesson_key_and_bad_file_path_are_rejected() {
		$in = Package_Fixtures::inputs( 'good-full' );
		foreach ( array( array( 'Bad Key' => array() ), array( '../x' => array() ) ) as $lessons ) {
			try {
				Package_Writer::write( $in[0], $in[1], $lessons, array() );
				$this->fail( 'expected Invalid_Input' );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'bad_key', $e->error_code() );
			}
		}
		try {
			Package_Writer::write( $in[0], $in[1], array(), array( '../evil' => 'x' ) );
			$this->fail( 'expected Invalid_Input' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'zip_path', $e->error_code() );
		}
	}
}
