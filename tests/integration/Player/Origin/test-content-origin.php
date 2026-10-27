<?php
/**
 * AU content origin and sandboxed fallback tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- tests remove temp ZIPs directly.
// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- AU fixture HTML, never output by WordPress.

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Player\Origin\Content_Origin;
use LearningLedger\Player\Origin\File_Responder;
use LearningLedger\Player\Store\Au_Store;
use LearningLedger\Player\Store\Content_Host;
use LearningLedger\Player\Store\Extract_Job;
use LearningLedger\Queue\Job_Queue;

require_once __DIR__ . '/../../../fixtures/packages/class-package-fixtures.php';

class Content_Origin_Test extends WP_UnitTestCase {

	const INDEX = '<!doctype html><title>AU</title><script src="app.js"></script>';

	const APP = 'console.log("0123456789");';

	/**
	 * Package ids created by a test.
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
		remove_all_actions( 'learning_ledger/jobs/cmi5_extract' );
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
		remove_all_actions( 'learning_ledger/jobs/cmi5_extract' );
		delete_option( Content_Host::OPTION );
		parent::tear_down();
	}

	private function ready_package(): int {
		$zip             = Package_Fixtures::zip(
			static function ( ZipArchive $z ) {
				$z->addFromString( 'cmi5.xml', Package_Fixtures::xml() );
				$z->addFromString( 'au/index.html', self::INDEX );
				$z->addFromString( 'au/app.js', self::APP );
				$z->addFromString( 'au/my file.txt', 'spaced' );
			}
		);
		$this->zips[]    = $zip;
		$id              = Au_Store::begin( $zip, 42 );
		$this->created[] = $id;
		$runs            = 0;
		while ( $runs < 20 && 'pending' === Au_Store::status( $id ) ) {
			Job_Queue::run();
			++$runs;
		}
		$this->assertSame( 'ready', Au_Store::status( $id ) );
		return $id;
	}

	private function site_origin(): string {
		$p = wp_parse_url( home_url() );
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
	}

	public function test_unsafe_content_host_is_refused() {
		$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		update_option( Content_Host::OPTION, $site );
		$this->assertFalse( Content_Origin::is_content_request( $site ), 'The site host is never a content host.' );

		update_option( Content_Host::OPTION, 'au.example.org' );
		$this->assertFalse( Content_Origin::is_content_request( 'au.example.org', '.example.org' ), 'A host under COOKIE_DOMAIN is refused.' );
		$this->assertTrue( Content_Origin::is_content_request( 'au.example.org', '' ) );
		$this->assertTrue( Content_Origin::is_content_request( 'AU.example.org', '' ), 'Host comparison ignores case.' );
		$this->assertFalse( Content_Origin::is_content_request( 'other.example.org', '' ), 'Only the configured host is served.' );

		delete_option( Content_Host::OPTION );
		$this->assertFalse( Content_Origin::is_content_request( 'au.example.org', '' ), 'Nothing is served when no host is configured.' );
	}

	public function test_manifest_path_served_with_headers() {
		$id   = $this->ready_package();
		$plan = Content_Origin::host_plan( '/' . $id . '/au/index.html?x=1', null );

		$this->assertSame( 200, $plan['status'] );
		$this->assertSame( 'text/html', $plan['headers']['Content-Type'] );
		$this->assertSame( 'nosniff', $plan['headers']['X-Content-Type-Options'] );
		$this->assertSame( 'frame-ancestors ' . $this->site_origin(), $plan['headers']['Content-Security-Policy'] );
		$this->assertSame( 'public, max-age=3600', $plan['headers']['Cache-Control'] );
		$this->assertSame( (string) strlen( self::INDEX ), $plan['headers']['Content-Length'] );
		$this->assertSame( 'bytes', $plan['headers']['Accept-Ranges'] );
		$this->assertArrayNotHasKey( 'Set-Cookie', $plan['headers'] );

		$js = Content_Origin::host_plan( '/' . $id . '/au/app.js', null );
		$this->assertSame( 200, $js['status'] );
		$this->assertSame( 'text/javascript', $js['headers']['Content-Type'] );

		$spaced = Content_Origin::host_plan( '/' . $id . '/au/my%20file.txt', null );
		$this->assertSame( 200, $spaced['status'], 'Percent-encoded paths are decoded once.' );

		$file = Au_Store::lookup( $id, 'au/index.html' );
		ob_start();
		( new File_Responder() )->send( $file, null, $plan['headers'] );
		$this->assertSame( self::INDEX, ob_get_clean() );
	}

	public function test_unknown_path_is_404() {
		$id = $this->ready_package();
		foreach ( array( '/' . $id . '/au/missing.html', '/' . ( $id + 1000 ) . '/au/index.html', '/' . $id . '/', '/' . $id, '/', '/x/au/index.html', '/' . $id . '/au/index.html%00' ) as $uri ) {
			$plan = Content_Origin::host_plan( $uri, null );
			$this->assertSame( 404, $plan['status'], $uri );
			$this->assertNull( $plan['file'], $uri );
			$this->assertSame( 'nosniff', $plan['headers']['X-Content-Type-Options'], $uri );
		}
	}

	public function test_dot_dot_is_404() {
		$id = $this->ready_package();
		foreach ( array( '/' . $id . '/au/../au/index.html', '/' . $id . '/au/%2e%2e/au/index.html', '/' . $id . '/../' . $id . '/au/index.html', '/' . $id . '/au/..%2fau/index.html', '/' . $id . '/au\\index.html' ) as $uri ) {
			$this->assertSame( 404, Content_Origin::host_plan( $uri, null )['status'], $uri );
		}
		$this->assertSame( 404, Content_Origin::sandbox_plan( 'lledger-au/' . $id . '/au/%2E%2E/cmi5.xml', null )['status'] );
	}

	public function test_pending_package_is_404() {
		$zip             = Package_Fixtures::zip(
			static function ( ZipArchive $z ) {
				$z->addFromString( 'cmi5.xml', Package_Fixtures::xml() );
				$z->addFromString( 'au/index.html', self::INDEX );
			}
		);
		$this->zips[]    = $zip;
		$id              = Au_Store::begin( $zip, 42 );
		$this->created[] = $id;
		$this->assertSame( 404, Content_Origin::host_plan( '/' . $id . '/au/index.html', null )['status'] );
	}

	public function test_range_request_is_206_with_the_right_bytes() {
		$id   = $this->ready_package();
		$file = Au_Store::lookup( $id, 'au/app.js' );
		$size = strlen( self::APP );

		$cases = array(
			'bytes=13-22' => array( 13, 22 ),
			'bytes=20-'   => array( 20, $size - 1 ),
			'bytes=-5'    => array( $size - 5, $size - 1 ),
			'bytes=5-999' => array( 5, $size - 1 ),
		);
		foreach ( $cases as $range => list( $start, $end ) ) {
			$plan = Content_Origin::host_plan( '/' . $id . '/au/app.js', $range );
			$this->assertSame( 206, $plan['status'], $range );
			$this->assertSame( 'bytes ' . $start . '-' . $end . '/' . $size, $plan['headers']['Content-Range'], $range );
			$this->assertSame( (string) ( $end - $start + 1 ), $plan['headers']['Content-Length'], $range );

			ob_start();
			( new File_Responder() )->send( $file, $range, array() );
			$this->assertSame( substr( self::APP, $start, $end - $start + 1 ), ob_get_clean(), $range );
		}

		$unsatisfiable = Content_Origin::host_plan( '/' . $id . '/au/app.js', 'bytes=' . $size . '-' );
		$this->assertSame( 416, $unsatisfiable['status'] );
		$this->assertSame( 'bytes */' . $size, $unsatisfiable['headers']['Content-Range'] );

		foreach ( array( 'bytes=0-1,4-5', 'items=0-1', 'bytes=5-2', 'bytes=-', 'garbage' ) as $ignored ) {
			$plan = Content_Origin::host_plan( '/' . $id . '/au/app.js', $ignored );
			$this->assertSame( 200, $plan['status'], $ignored );
			$this->assertArrayNotHasKey( 'Content-Range', $plan['headers'], $ignored );
			$this->assertSame( (string) $size, $plan['headers']['Content-Length'], $ignored );
		}
	}

	public function test_sandbox_fallback_csp_has_sandbox_and_lacks_allow_same_origin() {
		$id   = $this->ready_package();
		$plan = Content_Origin::sandbox_plan( 'lledger-au/' . $id . '/au/index.html', null );

		$this->assertSame( 200, $plan['status'] );
		$csp = $plan['headers']['Content-Security-Policy'];
		$this->assertSame( 'sandbox allow-scripts allow-forms allow-popups allow-modals; frame-ancestors ' . $this->site_origin(), $csp );
		$this->assertStringContainsString( 'sandbox', $csp );
		$this->assertStringNotContainsString( 'allow-same-origin', $csp );
		$this->assertSame( 'nosniff', $plan['headers']['X-Content-Type-Options'] );
		$this->assertSame( 'public, max-age=3600', $plan['headers']['Cache-Control'] );
		$this->assertSame( 'text/html', $plan['headers']['Content-Type'] );

		$this->assertSame( 404, Content_Origin::sandbox_plan( 'lledger-au/' . $id . '/au/missing.html', null )['status'] );
	}

	public function test_fallback_rewrite_rule_is_registered() {
		global $wp_rewrite;
		( new Content_Origin() )->add_rewrites();
		$this->assertArrayHasKey( '^lledger-au/(\d+)/(.+)$', $wp_rewrite->extra_rules_top );
	}
}
