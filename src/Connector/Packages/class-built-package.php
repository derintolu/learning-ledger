<?php
/**
 * A built course package.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

/**
 * ZIP bytes with their ETag, version, flavour and warnings.
 */
final class Built_Package {

	/**
	 * Constructor.
	 *
	 * @param string   $zip      ZIP bytes.
	 * @param string   $etag     Strong ETag of the bytes.
	 * @param integer  $version  Program version the package carries.
	 * @param string   $flavour  Either full or public.
	 * @param string[] $warnings Skipped blocks and other notes.
	 */
	public function __construct(
		public readonly string $zip,
		public readonly string $etag,
		public readonly int $version,
		public readonly string $flavour,
		public readonly array $warnings = array()
	) {}
}
