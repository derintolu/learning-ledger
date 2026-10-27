<?php
/**
 * Fixture integration used by loader tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Lms\Integration_Descriptor;
use LearningLedger\Tests\Fake_Adapter;

return new Integration_Descriptor(
	'fake',
	static fn(): bool => ! defined( 'LLEDGER_FAKELMS_UNAVAILABLE' ) && empty( $GLOBALS['lledger_fakelms_unavailable'] ),
	static fn() => new Fake_Adapter(),
	'1.0',
	'1.9',
	static fn(): string => '1.0.0'
);
