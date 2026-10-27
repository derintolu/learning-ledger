<?php
/**
 * Content-addressed store for extracted AU files.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Store;

// phpcs:disable WordPress.WP.AlternativeFunctions -- the store writes plain files below its own root; WP_Filesystem may need credentials and cannot stream.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never echoed; HTTP layers JSON-encode them.

use LearningLedger\Db\Tables;
use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;

/**
 * AU files live as `<root>/<store dir>/<sha256>.bin`, with a manifest row per
 * package path (path, hash, MIME type, size). Nothing is stored under the
 * name it had in the package, so a direct request is a download, never HTML
 * or PHP.
 */
final class Au_Store {

	public const STATUS_PENDING = 'pending';

	public const STATUS_READY = 'ready';

	public const STATUS_FAILED = 'failed';

	/**
	 * Status reported for a package id with no row.
	 */
	public const STATUS_MISSING = 'missing';

	/**
	 * Job type that extracts a package.
	 */
	public const JOB = 'cmi5_extract';

	/**
	 * Name of the copy of the uploaded ZIP, kept in the package folder until extraction ends.
	 */
	private const SOURCE_NAME = 'source.zip';

	/**
	 * Store folder names: 32 lowercase hex characters.
	 */
	private const DIR_PATTERN = '/^[0-9a-f]{32}$/';

	/**
	 * Longest error message kept on a failed package.
	 */
	private const MAX_ERROR_LENGTH = 1000;

	/**
	 * The store root: `LLEDGER_CMI5_STORE` when it is an absolute path outside
	 * ABSPATH, else `uploads/learning-ledger-cmi5`. Created with an empty index.php.
	 *
	 * @return string Path without a trailing slash.
	 */
	public static function root(): string {
		$root = null;
		if ( defined( 'LLEDGER_CMI5_STORE' ) ) {
			$candidate = untrailingslashit( wp_normalize_path( (string) constant( 'LLEDGER_CMI5_STORE' ) ) );
			if ( '' !== $candidate && path_is_absolute( $candidate ) && self::outside_abspath( $candidate ) && wp_mkdir_p( $candidate ) ) {
				$real = realpath( $candidate );
				if ( false !== $real && self::outside_abspath( $real ) ) {
					$root = $candidate;
				}
			}
		}
		if ( null === $root ) {
			$root = wp_upload_dir()['basedir'] . '/learning-ledger-cmi5';
			wp_mkdir_p( $root );
		}
		if ( ! file_exists( $root . '/index.php' ) ) {
			file_put_contents( $root . '/index.php', '' );
		}
		return $root;
	}

	/**
	 * Validates a ZIP, copies it into a new package folder and queues its extraction.
	 *
	 * @param string  $zip_path   ZIP on disk.
	 * @param integer $program_id Program the package belongs to.
	 *
	 * @return integer Package id.
	 * @throws Invalid_Input Codes from Package::from_zip, zip_path (unsafe or colliding names), store_unwritable.
	 */
	public static function begin( string $zip_path, int $program_id ): int {
		global $wpdb;
		self::paths( Package::from_zip( $zip_path, new Limits() )->entries() );

		$store_dir = bin2hex( random_bytes( 16 ) );
		$dir       = self::root() . '/' . $store_dir;
		$source    = $dir . '/' . self::SOURCE_NAME;
		if ( ! wp_mkdir_p( $dir ) || ! copy( $zip_path, $source ) ) {
			self::remove_dir( $dir );
			throw new Invalid_Input( 'The content store is not writable.', 'store_unwritable' );
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Tables::name( 'cmi5_packages' ),
			array(
				'program_id'     => $program_id,
				'source_url'     => $source,
				'store_dir'      => $store_dir,
				'status'         => self::STATUS_PENDING,
				'extract_cursor' => 0,
				'created_at'     => Timestamp::to_db( Clock::now() ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		$id       = (int) $wpdb->insert_id;
		if ( false === $inserted || $id <= 0 ) {
			self::remove_dir( $dir );
			throw new Invalid_Input( 'The package could not be recorded.', 'store_unwritable' );
		}

		Job_Queue::push( self::JOB, array( 'package_id' => $id ), self::JOB . ':' . $id . ':0' );
		return $id;
	}

	/**
	 * A stored file for a package path, once the package is ready.
	 *
	 * @param integer $package_id Package id.
	 * @param string  $path       Path inside the package; `.` and empty segments are dropped, `..` is refused.
	 *
	 * @return array{file:string,mime:string,size:int,sha256:string}|null
	 */
	public static function lookup( int $package_id, string $path ): ?array {
		global $wpdb;
		$path = self::normalise( $path );
		$row  = self::row( $package_id );
		if ( null === $path || null === $row || self::STATUS_READY !== $row->status ) {
			return null;
		}
		$dir = self::dir_of( $row );
		if ( null === $dir ) {
			return null;
		}
		$file = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT path, LOWER(HEX(sha256)) AS sha256, mime, size FROM ' . Tables::name( 'cmi5_files' ) . ' WHERE package_id = %d AND path_hash = UNHEX(%s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$package_id,
				sha1( $path )
			)
		);
		if ( null === $file || $path !== $file->path || 1 !== preg_match( '/^[0-9a-f]{64}$/', (string) $file->sha256 ) ) {
			return null;
		}
		$bin = $dir . '/' . $file->sha256 . '.bin';
		if ( ! is_file( $bin ) ) {
			return null;
		}
		return array(
			'file'   => $bin,
			'mime'   => (string) $file->mime,
			'size'   => (int) $file->size,
			'sha256' => (string) $file->sha256,
		);
	}

	/**
	 * Package status: pending, ready, failed, or missing when there is no such package.
	 *
	 * @param integer $package_id Package id.
	 *
	 * @return string
	 */
	public static function status( int $package_id ): string {
		$row = self::row( $package_id );
		return null === $row ? self::STATUS_MISSING : (string) $row->status;
	}

	/**
	 * Removes a package: its files, manifest rows and package row.
	 *
	 * @param integer $package_id Package id.
	 *
	 * @return void
	 */
	public static function delete( int $package_id ): void {
		global $wpdb;
		$row = self::row( $package_id );
		if ( null === $row ) {
			return;
		}
		$dir = self::dir_of( $row );
		if ( null !== $dir ) {
			self::remove_dir( $dir );
		}
		$wpdb->delete( Tables::name( 'cmi5_files' ), array( 'package_id' => $package_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Tables::name( 'cmi5_packages' ), array( 'id' => $package_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Normalised package path: no empty or `.` segments, no leading slash.
	 *
	 * @param string $path Path.
	 *
	 * @return string|null Null for `..`, NUL or other control characters, backslashes, drive letters, invalid UTF-8 or an empty result.
	 */
	public static function normalise( string $path ): ?string {
		if ( 1 === preg_match( '/[\x00-\x1f\x7f\\\\]/', $path ) || ! mb_check_encoding( $path, 'UTF-8' ) ) {
			return null;
		}
		$out = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return null;
			}
			$out[] = $segment;
		}
		if ( array() === $out || 1 === preg_match( '/^[A-Za-z]:/', $out[0] ) ) {
			return null;
		}
		return implode( '/', $out );
	}

	/**
	 * Normalised paths for a package's entries, in entry order.
	 *
	 * @param string[] $entries Entry names from Package::entries().
	 *
	 * @return array<string,string> Normalised path by entry name.
	 * @throws Invalid_Input Code zip_path when a name cannot be normalised or two names collide.
	 */
	public static function paths( array $entries ): array {
		$paths = array();
		$seen  = array();
		foreach ( $entries as $entry ) {
			$path = self::normalise( $entry );
			if ( null === $path || isset( $seen[ $path ] ) ) {
				throw new Invalid_Input( 'The package contains an unsafe or duplicate path.', 'zip_path', (string) preg_replace( '/[\x00-\x1f\x7f]/', '', $entry ) );
			}
			$seen[ $path ]   = true;
			$paths[ $entry ] = $path;
		}
		return $paths;
	}

	/**
	 * A package row.
	 *
	 * @param integer $package_id Package id.
	 *
	 * @return object|null
	 */
	public static function row( int $package_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM ' . Tables::name( 'cmi5_packages' ) . ' WHERE id = %d', $package_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		return is_object( $row ) ? $row : null;
	}

	/**
	 * A package's folder, when its recorded folder name is well formed.
	 *
	 * @param object $row Package row.
	 *
	 * @return string|null
	 */
	public static function dir_of( object $row ): ?string {
		$name = (string) ( $row->store_dir ?? '' );
		return 1 === preg_match( self::DIR_PATTERN, $name ) ? self::root() . '/' . $name : null;
	}

	/**
	 * Path of the ZIP copy inside a package folder.
	 *
	 * @param string $dir Package folder.
	 *
	 * @return string
	 */
	public static function source_of( string $dir ): string {
		return $dir . '/' . self::SOURCE_NAME;
	}

	/**
	 * Marks a package failed with the error, and removes its files and manifest rows.
	 *
	 * @param integer    $package_id Package id.
	 * @param \Throwable $e          What went wrong.
	 *
	 * @return void
	 */
	public static function fail( int $package_id, \Throwable $e ): void {
		global $wpdb;
		$message = $e instanceof Invalid_Input ? $e->error_code() . ': ' . $e->getMessage() : $e->getMessage();
		if ( $e instanceof Invalid_Input && '' !== $e->path() ) {
			$message .= ' (' . $e->path() . ')';
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Tables::name( 'cmi5_packages' ),
			array(
				'status' => self::STATUS_FAILED,
				'error'  => mb_substr( $message, 0, self::MAX_ERROR_LENGTH ),
			),
			array( 'id' => $package_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		$wpdb->delete( Tables::name( 'cmi5_files' ), array( 'package_id' => $package_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = self::row( $package_id );
		$dir = null === $row ? null : self::dir_of( $row );
		if ( null !== $dir ) {
			self::remove_dir( $dir );
		}
	}

	/**
	 * Deletes a package folder and the plain files in it (the store never creates subfolders).
	 *
	 * @param string $dir Package folder.
	 *
	 * @return void
	 */
	private static function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			$name = (string) $name;
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$file = $dir . '/' . $name;
			if ( is_link( $file ) || is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Whether a path lies outside ABSPATH.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return boolean
	 */
	private static function outside_abspath( string $path ): bool {
		$path = trailingslashit( wp_normalize_path( $path ) );
		foreach ( array( ABSPATH, realpath( ABSPATH ) ) as $abs ) {
			if ( is_string( $abs ) && str_starts_with( $path, trailingslashit( wp_normalize_path( $abs ) ) ) ) {
				return false;
			}
		}
		return true;
	}
}
