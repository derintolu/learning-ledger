<?php
/**
 * LifterLMS integration descriptor. Evaluated by Integration_Loader, possibly
 * more than once, so it declares nothing.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Lms\Integration_Descriptor;
use LearningLedger\Integrations\Lifterlms\Lifterlms_Adapter;

defined( 'ABSPATH' ) || exit;

$lledger_lifterlms = new Integration_Descriptor(
	'lifterlms',
	static fn() => function_exists( 'llms' ) && version_compare( (string) llms()->version, '10.0', '>=' ),
	static fn() => new Lifterlms_Adapter(),
	'10.0',
	'10.3',
	static fn() => (string) llms()->version
);

if ( call_user_func( $lledger_lifterlms->is_available ) ) {
	Lifterlms_Adapter::boot();
}

return $lledger_lifterlms;
