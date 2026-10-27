<?php
/**
 * Sensei LMS integration descriptor. Evaluated by Integration_Loader, possibly
 * more than once, so it declares nothing.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Lms\Integration_Descriptor;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
use LearningLedger\Integrations\Sensei\Sensei_Integration;

defined( 'ABSPATH' ) || exit;

$lledger_sensei = new Integration_Descriptor(
	'sensei',
	static fn() => function_exists( 'Sensei' ) && version_compare( (string) Sensei()->version, '4.20.1', '>=' ),
	static fn() => new Sensei_Adapter(),
	'4.20.1',
	'4.26',
	static fn() => (string) Sensei()->version
);

if ( call_user_func( $lledger_sensei->is_available ) ) {
	Sensei_Integration::boot();
}

return $lledger_sensei;
