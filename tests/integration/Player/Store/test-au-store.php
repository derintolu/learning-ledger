<?php
/**
 * AU content store tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- tests read stored files directly.

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Player\Store\Au_Store;
use LearningLedger\Player\Store\Content_Host;
use LearningLedger\Player\Store\Extract_Job;
use LearningLedger\Player\Store\Mime_Map;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Invalid_Input;

require_once __DIR__ . '/../../../fixtures/packages/class-package-fixtures.php';

class Au_Store_Test extends WP_UnitTestCase {

	/**
	 * Package ids created by a test, deleted in tear_down.
	 *
	 * @var int[]
	 */
	private array $created = array();

	/**
	 * Temp ZIPs written by a test.
	 *
	 * @var string[]
	 */
	private array $zips = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'jobs', 'cmi5_packages', 'cmi5_files' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Extract_Job::$batch_files = 2;
		add_action( 'learning_ledger/jobs/cmi5_extract', array( Extract_Job::class, 'run' ) );
	}

	public function tear_down() {
		foreach ( $this->created as $id ) {
			Au_Store::delete( $id );
		}
		foreach ( $this->zips as $zip ) {
			if ( file_exists( $zip ) ) {
				unlink( $zip );
			}
		}
		Extract_Job::$batch_files   = 200;
		Extract_Job::$batch_seconds = 15;
		remove_all_actions( 'learning_ledger/jobs/cmi5_extract' );
		delete_option( 'lledger_cmi5_content_host' );
		parent::tear_down();
	}

	/**
	 * Five files: cmi5.xml plus four AU files, two with dangerous names.
	 *
	 * @return array<string,string>
	 */
	private function five_files(): array {
		return array(
			'cmi5.xml'          => Package_Fixtures::xml(),
			'au/index.html'     => '<!doctype html><title>AU</title>',
			'au/app.js'         => 'console.log("au");',
			'au/evil.php'       => '<?php echo "pwned";',
			'au/img/pixel.webp' => "RIFF\x00\x01binary\xff",
		);
	}

	private function zip_of( array $files ): string {
		$path         = Package_Fixtures::zip(
			static function ( ZipArchive $z ) use ( $files ) {
				foreach ( $files as $name => $bytes ) {
					$z->addFromString( $name, $bytes );
				}
			}
		);
		$this->zips[] = $path;
		return $path;
	}

	private function begin( string $zip ): int {
		$id              = Au_Store::begin( $zip, 42 );
		$this->created[] = $id;
		return $id;
	}

	private function extract_fully( int $id ): void {
		$runs = 0;
		while ( $runs < 20 && 'pending' === Au_Store::status( $id ) ) {
			Job_Queue::run();
			++$runs;
		}
	}

	private function package_dir( int $id ): string {
		global $wpdb;
		$dir = $wpdb->get_var( $wpdb->prepare( 'SELECT store_dir FROM ' . Tables::name( 'cmi5_packages' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
		return Au_Store::root() . '/' . $dir;
	}

	private function code_of( callable $call ): string {
		try {
			$call();
		} catch ( Invalid_Input $e ) {
			return $e->error_code();
		}
		return 'no exception';
	}

	public function test_root_is_uploads_folder_with_empty_index() {
		$root = Au_Store::root();
		$this->assertSame( wp_upload_dir()['basedir'] . '/learning-ledger-cmi5', $root );
		$this->assertDirectoryExists( $root );
		$this->assertFileExists( $root . '/index.php' );
		$this->assertSame( '', file_get_contents( $root . '/index.php' ) );
	}

	public function test_five_file_package_is_ready_after_three_runs_of_two_files() {
		$id = $this->begin( $this->zip_of( $this->five_files() ) );
		$this->assertSame( 'pending', Au_Store::status( $id ) );

		$this->assertSame( 1, Job_Queue::run() );
		$this->assertSame( 'pending', Au_Store::status( $id ) );
		$this->assertSame( 1, Job_Queue::run() );
		$this->assertSame( 'pending', Au_Store::status( $id ) );
		$this->assertSame( 1, Job_Queue::run() );
		$this->assertSame( 'ready', Au_Store::status( $id ) );
		$this->assertSame( 0, Job_Queue::run(), 'No job is queued once the package is ready.' );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT extract_cursor, files, bytes FROM ' . Tables::name( 'cmi5_packages' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 5, (int) $row->extract_cursor );
		$this->assertSame( 5, (int) $row->files );
		$this->assertSame( array_sum( array_map( 'strlen', $this->five_files() ) ), (int) $row->bytes );
	}

	public function test_stored_files_are_named_by_hash_and_hold_the_right_bytes() {
		$id = $this->begin( $this->zip_of( $this->five_files() ) );
		$this->extract_fully( $id );
		$this->assertSame( 'ready', Au_Store::status( $id ) );

		foreach ( $this->five_files() as $path => $bytes ) {
			$file = Au_Store::lookup( $id, $path );
			$this->assertIsArray( $file, $path );
			$this->assertSame( hash( 'sha256', $bytes ) . '.bin', basename( $file['file'] ) );
			$this->assertSame( $bytes, file_get_contents( $file['file'] ) );
			$this->assertSame( strlen( $bytes ), $file['size'] );
			$this->assertSame( hash( 'sha256', $bytes ), $file['sha256'] );
			$this->assertSame( Mime_Map::for_path( $path ), $file['mime'] );
		}
		$this->assertSame( 'text/html', Au_Store::lookup( $id, 'au/index.html' )['mime'] );
		$this->assertSame( 'application/octet-stream', Au_Store::lookup( $id, 'au/evil.php' )['mime'] );
		$this->assertSame( 'image/webp', Au_Store::lookup( $id, 'au/img/pixel.webp' )['mime'] );
	}

	public function test_no_stored_file_has_a_php_or_html_name() {
		$id = $this->begin( $this->zip_of( $this->five_files() ) );
		$this->extract_fully( $id );
		$this->assertSame( 'ready', Au_Store::status( $id ) );

		$names = array();
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->package_dir( $id ), FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$names[] = $f->getFilename();
			$this->assertFalse( $f->isLink(), $f->getFilename() );
		}
		$this->assertCount( 5, $names, 'Only the five hashed files remain once ready.' );
		foreach ( $names as $name ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}\.bin$/', $name );
			$this->assertDoesNotMatchRegularExpression( '/\.(php|html?)$/i', $name );
		}
	}

	public function test_traversal_package_fails_at_begin() {
		foreach ( array( '../evil.txt', 'au/../../evil.txt' ) as $name ) {
			$zip          = Package_Fixtures::traversal_zip( $name );
			$this->zips[] = $zip;
			$this->assertSame( 'zip_path', $this->code_of( fn() => $this->begin( $zip ) ), $name );
		}
		$this->assertSame( 0, $this->package_count() );
		$this->assertSame( 0, Job_Queue::due_count() );
	}

	public function test_symlink_package_fails_at_begin() {
		$zip          = Package_Fixtures::symlink_zip();
		$this->zips[] = $zip;
		$this->assertSame( 'zip_symlink', $this->code_of( fn() => $this->begin( $zip ) ) );
		$this->assertSame( 0, $this->package_count() );
	}

	public function test_paths_that_collide_after_normalising_fail_at_begin() {
		$zip = $this->zip_of(
			array(
				'cmi5.xml'  => Package_Fixtures::xml(),
				'au/a.js'   => 'one',
				'au/./a.js' => 'two',
			)
		);
		$this->assertSame( 'zip_path', $this->code_of( fn() => $this->begin( $zip ) ) );
	}

	public function test_lookup_normalises_and_rejects_dot_dot() {
		$id = $this->begin( $this->zip_of( $this->five_files() ) );
		$this->assertNull( Au_Store::lookup( $id, 'au/app.js' ), 'Nothing is served before the package is ready.' );
		$this->extract_fully( $id );

		$this->assertNull( Au_Store::lookup( $id, '../x' ) );
		$this->assertNull( Au_Store::lookup( $id, 'au/../cmi5.xml' ) );
		$this->assertNull( Au_Store::lookup( $id, "au/app.js\0" ) );
		$this->assertNull( Au_Store::lookup( $id, 'au\\app.js' ) );
		$this->assertNull( Au_Store::lookup( $id, 'au/missing.js' ) );
		$this->assertNull( Au_Store::lookup( $id + 1000, 'au/app.js' ) );
		$this->assertSame( hash( 'sha256', 'console.log("au");' ), Au_Store::lookup( $id, '/au/./app.js' )['sha256'] );
		$this->assertSame( hash( 'sha256', 'console.log("au");' ), Au_Store::lookup( $id, 'au//app.js' )['sha256'] );
	}

	public function test_corrupt_source_marks_failed_with_error_and_cleans_up() {
		$id = $this->begin( $this->zip_of( $this->five_files() ) );
		Job_Queue::run();
		global $wpdb;
		$source = $wpdb->get_var( $wpdb->prepare( 'SELECT source_url FROM ' . Tables::name( 'cmi5_packages' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
		file_put_contents( $source, 'not a zip any more' );

		$this->extract_fully( $id );
		$this->assertSame( 'failed', Au_Store::status( $id ) );
		$error = $wpdb->get_var( $wpdb->prepare( 'SELECT error FROM ' . Tables::name( 'cmi5_packages' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
		$this->assertStringContainsString( 'zip_invalid', (string) $error );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::name( 'cmi5_files' ) . ' WHERE package_id = %d', $id ) ) ); // phpcs:ignore WordPress.DB
		$this->assertDirectoryDoesNotExist( $this->package_dir( $id ) );
		$this->assertSame( 0, Job_Queue::due_count() );
	}

	public function test_delete_removes_files_rows_and_status() {
		$id = $this->begin( $this->zip_of( $this->five_files() ) );
		$this->extract_fully( $id );
		$dir = $this->package_dir( $id );
		$this->assertDirectoryExists( $dir );

		Au_Store::delete( $id );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertSame( 'missing', Au_Store::status( $id ) );
		$this->assertNull( Au_Store::lookup( $id, 'au/app.js' ) );
		$this->assertSame( 0, $this->package_count() );
	}

	public function test_mime_map_is_an_allowlist() {
		$this->assertSame( 'text/html', Mime_Map::for_path( 'a/INDEX.HTM' ) );
		$this->assertSame( 'text/javascript', Mime_Map::for_path( 'a.mjs' ) );
		$this->assertSame( 'image/svg+xml', Mime_Map::for_path( 'a.svg' ) );
		$this->assertSame( 'font/woff2', Mime_Map::for_path( 'a.woff2' ) );
		$this->assertSame( 'application/wasm', Mime_Map::for_path( 'a.wasm' ) );
		foreach ( array( 'a.php', 'a.phtml', 'a.exe', 'a', 'a.html.php', '.htaccess' ) as $p ) {
			$this->assertSame( 'application/octet-stream', Mime_Map::for_path( $p ), $p );
		}
	}

	public function test_content_host_safety_base_url_and_mode() {
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertNull( Content_Host::get() );
		$this->assertSame( 'sandbox', Content_Host::mode() );
		$this->assertSame( home_url( '/lledger-au/7/' ), Content_Host::base_url( 7 ) );

		$this->assertFalse( Content_Host::is_safe( $site ) );
		$this->assertFalse( Content_Host::is_safe( strtoupper( $site ) . ':9999' ), 'Cookies ignore the port.' );
		$this->assertFalse( Content_Host::is_safe( '' ) );
		$this->assertFalse( Content_Host::is_safe( 'au.example.org/x' ) );
		$this->assertTrue( Content_Host::is_safe( 'au.example.org' ) );

		update_option( 'lledger_cmi5_content_host', $site );
		$this->assertSame( 'sandbox', Content_Host::mode() );
		$this->assertSame( home_url( '/lledger-au/7/' ), Content_Host::base_url( 7 ) );

		update_option( 'lledger_cmi5_content_host', 'au.example.org' );
		$this->assertSame( 'au.example.org', Content_Host::get() );
		$this->assertSame( 'host', Content_Host::mode() );
		$this->assertSame( 'https://au.example.org/7/', Content_Host::base_url( 7 ) );
	}

	public function test_host_under_cookie_domain_is_unsafe() {
		$this->assertFalse( Content_Host::is_safe( 'au.example.org', '.example.org' ) );
		$this->assertFalse( Content_Host::is_safe( 'example.org', '.example.org' ) );
		$this->assertFalse( Content_Host::is_safe( 'au.example.org', 'example.org' ) );
		$this->assertTrue( Content_Host::is_safe( 'au.example.net', '.example.org' ) );
		$this->assertTrue( Content_Host::is_safe( 'notexample.org', '.example.org' ) );
	}

	private function package_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'cmi5_packages' ) ); // phpcs:ignore WordPress.DB
	}
}
