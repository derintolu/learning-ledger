<?php
/**
 * AU files are served from the content host without cookies, and from the sandboxed fallback.
 *
 * Runs against the wp-env development site at LLEDGER_TEST_BASE_URL; setup goes through
 * `npx @wordpress/env run cli` from the plugin folder. The content host is 127.0.0.1 on the same port.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- plain HTTP client for the running site.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- setup failures are shown in the PHPUnit report only.

class Content_Origin_Http_Test extends \PHPUnit\Framework\TestCase {

	const INDEX = '<!doctype html><title>AU over HTTP</title>';

	/**
	 * Package id created for the class.
	 *
	 * @var int
	 */
	private static int $package_id = 0;

	/**
	 * Port of the base URL, defaulting by scheme.
	 *
	 * @return int
	 */
	private static function port(): int {
		$parts = parse_url( LLEDGER_TEST_BASE_URL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return (int) ( $parts['port'] ?? ( 'https' === ( $parts['scheme'] ?? '' ) ? 443 : 80 ) );
	}

	/**
	 * Content host, `127.0.0.1:<port of the base URL>`.
	 *
	 * @return string
	 */
	private static function content_host(): string {
		return '127.0.0.1:' . self::port();
	}

	/**
	 * Run a WP-CLI command in the wp-env development container.
	 *
	 * @param string[] $args Arguments after `wp`.
	 *
	 * @return array{0:int,1:string} Exit code and combined output.
	 */
	private static function wp( array $args ): array {
		$cmd  = 'cd ' . escapeshellarg( dirname( __DIR__, 2 ) ) . ' && npx @wordpress/env run cli --env-cwd=wp-content/plugins/learning-ledger wp ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
		$out  = array();
		$code = 0;
		exec( $cmd, $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		return array( $code, implode( "\n", $out ) );
	}

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		list( $code, $out ) = self::wp( array( 'learning-ledger', 'role', 'enable', 'connector' ) );
		if ( 0 !== $code ) {
			throw new \RuntimeException( 'Could not enable the connector role: ' . $out );
		}
		$php                = <<<'PHP'
require 'tests/fixtures/packages/class-package-fixtures.php';
$zip = Package_Fixtures::zip( function ( ZipArchive $z ) {
	$z->addFromString( 'cmi5.xml', Package_Fixtures::xml() );
	$z->addFromString( 'index.html', '<!doctype html><title>AU over HTTP</title>' );
} );
if ( ! has_action( 'learning_ledger/jobs/cmi5_extract' ) ) {
	add_action( 'learning_ledger/jobs/cmi5_extract', array( \LearningLedger\Player\Store\Extract_Job::class, 'run' ) );
}
$id = \LearningLedger\Player\Store\Au_Store::begin( $zip, 1 );
for ( $i = 0; $i < 20 && 'pending' === \LearningLedger\Player\Store\Au_Store::status( $id ); $i++ ) {
	\LearningLedger\Queue\Job_Queue::run();
}
unlink( $zip );
echo 'PACKAGE=' . $id . ':' . \LearningLedger\Player\Store\Au_Store::status( $id );
PHP;
		list( $code, $out ) = self::wp( array( 'eval', $php ) );
		if ( 0 !== $code || 1 !== preg_match( '/PACKAGE=(\d+):ready/', $out, $m ) ) {
			throw new \RuntimeException( 'Could not create a ready package: ' . $out );
		}
		self::$package_id = (int) $m[1];
		self::wp( array( 'rewrite', 'flush' ) );
	}

	public static function tearDownAfterClass(): void {
		if ( self::$package_id > 0 ) {
			self::wp( array( 'eval', '\LearningLedger\Player\Store\Au_Store::delete( ' . self::$package_id . ' );' ) );
		}
		self::wp( array( 'option', 'delete', 'lledger_cmi5_content_host' ) );
		parent::tearDownAfterClass();
	}

	/**
	 * GET a URL and return status, lower-cased headers (each a list) and body.
	 *
	 * @param string $url     URL.
	 * @param string $headers Extra request header lines.
	 *
	 * @return array{0:int,1:array<string,string[]>,2:string}
	 */
	private function get( string $url, string $headers = '' ): array {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'          => 'GET',
					'header'          => $headers,
					'ignore_errors'   => true,
					'follow_location' => 0,
					'timeout'         => 20,
				),
			)
		);
		$body    = file_get_contents( $url, false, $context );
		$this->assertIsString( $body, 'No response from ' . $url );
		// http_get_last_response_headers() (PHP 8.4+) replaces the deprecated local response-header variable.
		$lines  = function_exists( 'http_get_last_response_headers' ) ? (array) http_get_last_response_headers() : (array) ( get_defined_vars()['http_response_header'] ?? array() );
		$out    = array();
		$status = 0;
		foreach ( $lines as $line ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
				$status = (int) $m[1];
				continue;
			}
			$parts = explode( ':', $line, 2 );
			if ( 2 === count( $parts ) ) {
				$out[ strtolower( trim( $parts[0] ) ) ][] = trim( $parts[1] );
			}
		}
		return array( $status, $out, $body );
	}

	public function test_cli_refuses_unsafe_host() {
		$site               = (string) parse_url( LLEDGER_TEST_BASE_URL, PHP_URL_HOST ) . ':' . self::port(); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		list( $code, $out ) = self::wp( array( 'learning-ledger', 'cmi5', 'content-host', $site ) );
		$this->assertNotSame( 0, $code, $out );
		$this->assertStringContainsString( 'Error:', $out );
	}

	public function test_content_host_serves_file_without_cookies() {
		list( $code, $out ) = self::wp( array( 'learning-ledger', 'cmi5', 'content-host', self::content_host() ) );
		$this->assertSame( 0, $code, $out );

		$url                             = 'http://' . self::content_host() . '/' . self::$package_id . '/index.html';
		list( $status, $headers, $body ) = $this->get( $url, "Cookie: wordpress_logged_in_x=forged; wordpress_test_cookie=WP+Cookie+check\r\n" );

		$this->assertSame( 200, $status, $body );
		$this->assertSame( self::INDEX, $body );
		$this->assertSame( array( 'text/html' ), $headers['content-type'] ?? null );
		$this->assertSame( array( 'nosniff' ), $headers['x-content-type-options'] ?? null );
		$this->assertSame( array( 'frame-ancestors ' . rtrim( LLEDGER_TEST_BASE_URL, '/' ) ), $headers['content-security-policy'] ?? null );
		$this->assertSame( array( 'public, max-age=3600' ), $headers['cache-control'] ?? null );
		$this->assertArrayNotHasKey( 'set-cookie', $headers );

		list( $status, $headers, $body ) = $this->get( 'http://' . self::content_host() . '/' . self::$package_id . '/index.html', "Range: bytes=2-8\r\n" );
		$this->assertSame( 206, $status );
		$this->assertSame( substr( self::INDEX, 2, 7 ), $body );

		list( $status, $headers, $body ) = $this->get( 'http://' . self::content_host() . '/' . self::$package_id . '/../wp-login.php' );
		$this->assertSame( 404, $status );
		$this->assertArrayNotHasKey( 'set-cookie', $headers );
		$this->assertStringNotContainsString( 'wp-', $body, 'WordPress never renders on the content host.' );

		list( $status, $headers ) = $this->get( 'http://' . self::content_host() . '/wp-login.php' );
		$this->assertSame( 404, $status );
		$this->assertArrayNotHasKey( 'set-cookie', $headers );
	}

	public function test_sandbox_fallback_sends_sandbox_csp() {
		list( $status, $headers, $body ) = $this->get( rtrim( LLEDGER_TEST_BASE_URL, '/' ) . '/lledger-au/' . self::$package_id . '/index.html' );
		$this->assertSame( 200, $status, $body );
		$this->assertSame( self::INDEX, $body );
		$csp = implode( ', ', $headers['content-security-policy'] ?? array() );
		$this->assertSame( 'sandbox allow-scripts allow-forms allow-popups allow-modals; frame-ancestors ' . rtrim( LLEDGER_TEST_BASE_URL, '/' ), $csp );
		$this->assertSame( array( 'nosniff' ), $headers['x-content-type-options'] ?? null );
		$this->assertArrayNotHasKey( 'set-cookie', $headers );
	}
}
