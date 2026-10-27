<?php
/**
 * Description of one integration.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * Returned by each integrations/<id>/bootstrap.php.
 */
final class Integration_Descriptor {

	/**
	 * Availability check.
	 *
	 * @var callable
	 */
	public $is_available;

	/**
	 * Adapter factory.
	 *
	 * @var callable
	 */
	public $factory;

	/**
	 * LMS version getter.
	 *
	 * @var callable
	 */
	public $version;

	/**
	 * Constructor.
	 *
	 * @param string   $id           Integration id.
	 * @param callable $is_available Returns bool.
	 * @param callable $factory      Returns LMS_Adapter.
	 * @param string   $min_version  Minimum supported LMS version.
	 * @param string   $tested_up_to Newest tested LMS version (major.minor).
	 * @param callable $version      Returns the installed LMS version.
	 */
	public function __construct(
		public readonly string $id,
		callable $is_available,
		callable $factory,
		public readonly string $min_version,
		public readonly string $tested_up_to,
		callable $version
	) {
		$this->is_available = $is_available;
		$this->factory      = $factory;
		$this->version      = $version;
	}
}
