<?php
/**
 * Caps applied when reading a package ZIP.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Package;

/**
 * Immutable limits.
 */
final class Limits {

	/**
	 * Maximum total uncompressed size in bytes.
	 *
	 * @var int
	 */
	public readonly int $max_bytes;

	/**
	 * Maximum number of files.
	 *
	 * @var int
	 */
	public readonly int $max_files;

	/**
	 * Maximum uncompressed to compressed ratio.
	 *
	 * @var int
	 */
	public readonly int $max_ratio;

	/**
	 * Constructor.
	 *
	 * @param integer $max_bytes Total uncompressed bytes.
	 * @param integer $max_files File count.
	 * @param integer $max_ratio Compression ratio.
	 */
	public function __construct( int $max_bytes = 1073741824, int $max_files = 20000, int $max_ratio = 100 ) {
		$this->max_bytes = $max_bytes;
		$this->max_files = $max_files;
		$this->max_ratio = $max_ratio;
	}
}
