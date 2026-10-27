<?php
/**
 * Resumable extraction of a package into the AU store.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Store;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streams ZIP entries into plain files below the store root.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never echoed; they are stored on the package row.

use LearningLedger\Db\Tables;
use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Invalid_Input;

/**
 * Handler for the `cmi5_extract` job. Each run extracts a batch from the saved
 * cursor, then re-queues itself until every entry is stored.
 */
final class Extract_Job {

	/**
	 * Most files extracted per run. Tests lower it.
	 *
	 * @var int
	 */
	public static int $batch_files = 200;

	/**
	 * No new file is started after this many seconds in one run. Tests may lower it.
	 *
	 * @var int
	 */
	public static int $batch_seconds = 15;

	/**
	 * Bytes read from a ZIP entry at a time.
	 */
	private const CHUNK = 65536;

	/**
	 * Runs one batch for the package in the payload.
	 *
	 * @param array $payload Holds `package_id`.
	 *
	 * @return void
	 */
	public static function run( array $payload ): void {
		$id  = (int) ( $payload['package_id'] ?? 0 );
		$row = Au_Store::row( $id );
		if ( null === $row || Au_Store::STATUS_PENDING !== $row->status ) {
			return;
		}
		try {
			$dir = Au_Store::dir_of( $row );
			if ( null === $dir ) {
				throw new Invalid_Input( 'The package folder name is not valid.', 'store_unwritable' );
			}
			self::batch( $id, $dir, (int) $row->extract_cursor, (int) $row->bytes );
		} catch ( \Throwable $e ) {
			Au_Store::fail( $id, $e );
		}
	}

	/**
	 * Extracts from the cursor, then marks the package ready or queues the next run.
	 *
	 * @param integer $id     Package id.
	 * @param string  $dir    Package folder.
	 * @param integer $cursor Index of the next entry.
	 * @param integer $total  Bytes stored so far.
	 *
	 * @return void
	 * @throws Invalid_Input When the ZIP or an entry is bad, or the store cannot be written.
	 */
	private static function batch( int $id, string $dir, int $cursor, int $total ): void {
		$limits  = new Limits();
		$source  = Au_Store::source_of( $dir );
		$paths   = Au_Store::paths( Package::from_zip( $source, $limits )->entries() );
		$entries = array_keys( $paths );
		$count   = count( $entries );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $source, \ZipArchive::RDONLY ) ) {
			throw new Invalid_Input( 'The package is not a readable ZIP file.', 'zip_invalid' );
		}
		try {
			$started = microtime( true );
			$done    = 0;
			while ( $cursor < $count && $done < self::$batch_files && microtime( true ) - $started < self::$batch_seconds ) {
				$entry = $entries[ $cursor ];
				$bytes = self::store( $zip, $entry, $paths[ $entry ], $id, $dir, $limits->max_bytes - $total );
				if ( ! self::advance( $id, $cursor, $bytes ) ) {
					return; // Another runner moved the cursor, or the package was deleted.
				}
				$total += $bytes;
				++$cursor;
				++$done;
			}
		} finally {
			$zip->close();
		}

		if ( $cursor < $count ) {
			Job_Queue::push( Au_Store::JOB, array( 'package_id' => $id ), Au_Store::JOB . ':' . $id . ':' . $cursor );
			return;
		}
		self::finish( $id, $dir, $cursor );
	}

	/**
	 * Streams one entry to `<sha256>.bin` and records its manifest row.
	 *
	 * @param \ZipArchive $zip       Open ZIP.
	 * @param string      $entry     Entry name.
	 * @param string      $path      Normalised path.
	 * @param integer     $id        Package id.
	 * @param string      $dir       Package folder.
	 * @param integer     $remaining Bytes left under the unpacked cap.
	 *
	 * @return integer Bytes stored.
	 * @throws Invalid_Input Codes zip_invalid, zip_too_large, store_unwritable.
	 */
	private static function store( \ZipArchive $zip, string $entry, string $path, int $id, string $dir, int $remaining ): int {
		global $wpdb;
		$stat = $zip->statName( $entry );
		if ( false === $stat ) {
			throw new Invalid_Input( 'The package is not a readable ZIP file.', 'zip_invalid', $path );
		}
		$declared = (int) $stat['size'];
		if ( $declared > $remaining ) {
			throw new Invalid_Input( 'The package is too large when unpacked.', 'zip_too_large', $path );
		}
		$in = $zip->getStream( $entry );
		if ( false === $in ) {
			throw new Invalid_Input( 'The package is not a readable ZIP file.', 'zip_invalid', $path );
		}
		$tmp = $dir . '/' . bin2hex( random_bytes( 8 ) ) . '.part';
		$out = fopen( $tmp, 'xb' );
		if ( false === $out ) {
			fclose( $in );
			throw new Invalid_Input( 'The content store is not writable.', 'store_unwritable', $path );
		}

		$hash = hash_init( 'sha256' );
		$read = 0;
		try {
			while ( ! feof( $in ) ) {
				$chunk = fread( $in, self::CHUNK );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				$read += strlen( $chunk );
				if ( $read > $declared ) {
					throw new Invalid_Input( 'A package entry does not match its declared size.', 'zip_too_large', $path );
				}
				hash_update( $hash, $chunk );
				if ( fwrite( $out, $chunk ) !== strlen( $chunk ) ) {
					throw new Invalid_Input( 'The content store is not writable.', 'store_unwritable', $path );
				}
			}
			if ( $read !== $declared ) {
				throw new Invalid_Input( 'A package entry does not match its declared size.', 'zip_invalid', $path );
			}
		} catch ( Invalid_Input $e ) {
			fclose( $in );
			fclose( $out );
			wp_delete_file( $tmp );
			throw $e;
		}
		fclose( $in );
		fclose( $out );

		$sha256 = hash_final( $hash );
		$target = $dir . '/' . $sha256 . '.bin';
		if ( is_file( $target ) ) {
			wp_delete_file( $tmp );
		} elseif ( ! rename( $tmp, $target ) ) {
			wp_delete_file( $tmp );
			throw new Invalid_Input( 'The content store is not writable.', 'store_unwritable', $path );
		}

		// A re-run after a crash between this insert and the cursor update writes the same row again.
		$ok = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'INSERT INTO ' . Tables::name( 'cmi5_files' ) . ' (package_id, path_hash, path, sha256, mime, size) VALUES (%d, UNHEX(%s), %s, UNHEX(%s), %s, %d) ON DUPLICATE KEY UPDATE sha256 = VALUES(sha256), mime = VALUES(mime), size = VALUES(size)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$id,
				sha1( $path ),
				$path,
				$sha256,
				Mime_Map::for_path( $path ),
				$read
			)
		);
		if ( false === $ok ) {
			throw new Invalid_Input( 'The package manifest could not be written.', 'store_unwritable', $path );
		}
		return $read;
	}

	/**
	 * Moves the cursor past one stored entry, only from the expected position.
	 *
	 * @param integer $id     Package id.
	 * @param integer $cursor Expected cursor.
	 * @param integer $bytes  Bytes stored for the entry.
	 *
	 * @return boolean False when the row was not at that cursor or no longer pending.
	 */
	private static function advance( int $id, int $cursor, int $bytes ): bool {
		global $wpdb;
		$changed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'UPDATE ' . Tables::name( 'cmi5_packages' ) . ' SET extract_cursor = %d, bytes = bytes + %d, files = files + 1 WHERE id = %d AND extract_cursor = %d AND status = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$cursor + 1,
				$bytes,
				$id,
				$cursor,
				Au_Store::STATUS_PENDING
			)
		);
		return 1 === $changed;
	}

	/**
	 * Marks the package ready and removes the ZIP copy and any partial files.
	 *
	 * @param integer $id     Package id.
	 * @param string  $dir    Package folder.
	 * @param integer $cursor Final cursor.
	 *
	 * @return void
	 */
	private static function finish( int $id, string $dir, int $cursor ): void {
		global $wpdb;
		$changed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'UPDATE ' . Tables::name( 'cmi5_packages' ) . ' SET status = %s, error = NULL WHERE id = %d AND extract_cursor = %d AND status = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				Au_Store::STATUS_READY,
				$id,
				$cursor,
				Au_Store::STATUS_PENDING
			)
		);
		if ( 1 !== $changed ) {
			return;
		}
		wp_delete_file( Au_Store::source_of( $dir ) );
		foreach ( (array) glob( $dir . '/*.part' ) as $part ) {
			wp_delete_file( (string) $part );
		}
	}
}
