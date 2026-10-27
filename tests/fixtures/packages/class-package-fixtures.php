<?php
/**
 * Builds package fixtures for tests: directory loaders and hostile ZIPs.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- test fixtures use plain file functions.

/**
 * Fixture helpers.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test helper.
final class Package_Fixtures {

	const DIR = __DIR__;

	/**
	 * Reads a fixture directory into the writer's arguments.
	 *
	 * @param string $name Directory name under tests/fixtures/packages.
	 *
	 * @return array{0:string,1:array,2:array,3:array} cmi5 xml, course json, lessons, files.
	 */
	public static function inputs( string $name ): array {
		$base    = self::DIR . '/' . $name;
		$lessons = array();
		foreach ( glob( $base . '/cxm/lessons/*.json' ) as $file ) {
			$lessons[ basename( $file, '.json' ) ] = json_decode( file_get_contents( $file ), true );
		}
		return array(
			file_get_contents( $base . '/cmi5.xml' ),
			json_decode( file_get_contents( $base . '/cxm/course.json' ), true ),
			$lessons,
			array(),
		);
	}

	/**
	 * Writes a ZIP built by the given callback to a temp file.
	 *
	 * @param callable $fill Receives the ZipArchive.
	 *
	 * @return string Path.
	 */
	public static function zip( callable $fill ): string {
		$path = tempnam( sys_get_temp_dir(), 'llzip' );
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$fill( $zip );
		$zip->close();
		return $path;
	}

	/**
	 * Minimal valid cmi5.xml.
	 *
	 * @return string
	 */
	public static function xml(): string {
		return file_get_contents( self::DIR . '/good-full/cmi5.xml' );
	}

	/**
	 * A ZIP with an entry that escapes the extraction directory.
	 *
	 * @param string $name Entry name.
	 *
	 * @return string Path.
	 */
	public static function traversal_zip( string $name = '../evil.txt' ): string {
		return self::zip(
			function ( ZipArchive $z ) use ( $name ) {
				$z->addFromString( 'cmi5.xml', self::xml() );
				$z->addFromString( $name, 'x' );
			}
		);
	}

	/**
	 * A ZIP whose members compress at a very high ratio.
	 *
	 * @return string Path.
	 */
	public static function bomb_zip(): string {
		return self::zip(
			function ( ZipArchive $z ) {
				$z->addFromString( 'cmi5.xml', self::xml() );
				$z->addFromString( 'cxm/files/zeros.bin', str_repeat( "\0", 5 * 1024 * 1024 ) );
				$z->setCompressionName( 'cxm/files/zeros.bin', ZipArchive::CM_DEFLATE, 9 );
			}
		);
	}

	/**
	 * A ZIP containing a symbolic link entry.
	 *
	 * @return string Path.
	 */
	public static function symlink_zip(): string {
		return self::zip(
			function ( ZipArchive $z ) {
				$z->addFromString( 'cmi5.xml', self::xml() );
				$z->addFromString( 'cxm/files/link', '/etc/passwd' );
				$z->setExternalAttributesName( 'cxm/files/link', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
			}
		);
	}
}
