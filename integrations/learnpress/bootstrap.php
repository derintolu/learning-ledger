<?php
/**
 * LearnPress integration descriptor. Evaluated by Integration_Loader, possibly
 * more than once, so it declares nothing.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Lms\Integration_Descriptor;
use LearningLedger\Integrations\Learnpress\Learnpress_Adapter;

defined( 'ABSPATH' ) || exit;

$lledger_learnpress = new Integration_Descriptor(
	'learnpress',
	static fn() => class_exists( 'LearnPress' ) && defined( 'LEARNPRESS_VERSION' ) && version_compare( (string) LEARNPRESS_VERSION, '4.2', '>=' ),
	static fn() => new Learnpress_Adapter(),
	'4.2',
	'4.4',
	static fn() => (string) LEARNPRESS_VERSION
);

if ( call_user_func( $lledger_learnpress->is_available ) ) {
	Learnpress_Adapter::boot();
}

return $lledger_learnpress;
