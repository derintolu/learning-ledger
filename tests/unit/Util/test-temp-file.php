<?php
/**
 * wp_tempnam() call site tests.
 *
 * @package LearningLedger
 */

/**
 * WordPress does not load wp-admin/includes/file.php on REST or cron requests, so a bare
 * wp_tempnam() call there is a fatal error that WP-CLI and the integration bootstrap hide.
 */
class Temp_File_Test extends \PHPUnit\Framework\TestCase {

	public function test_every_wp_tempnam_caller_loads_file_php_first() {
		$root    = dirname( __DIR__, 3 ) . '/';
		$callers = array();
		foreach ( array( 'src', 'integrations' ) as $dir ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				$code = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( preg_match( '/\bwp_tempnam\s*\(/', $code ) ) {
					$callers[] = substr( $file->getPathname(), strlen( $root ) );
					$this->assertMatchesRegularExpression(
						"/function_exists\(\s*'wp_tempnam'\s*\)[\s\S]*require_once ABSPATH \. 'wp-admin\/includes\/file\.php'/",
						$code,
						$file->getPathname() . ' calls wp_tempnam() without loading wp-admin/includes/file.php; use Temp_File::create().'
					);
				}
			}
		}
		$this->assertSame( array( 'src/Util/class-temp-file.php' ), $callers );
	}
}
