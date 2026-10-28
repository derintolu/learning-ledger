<?php
/**
 * Tutor LMS integration descriptor. Evaluated by Integration_Loader, possibly
 * more than once, so it declares nothing.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Lms\Integration_Descriptor;
use LearningLedger\Integrations\Tutor\Tutor_Adapter;

defined( 'ABSPATH' ) || exit;

$lledger_tutor = new Integration_Descriptor(
	'tutor',
	static fn() => function_exists( 'tutor' ) && defined( 'TUTOR_VERSION' ) && version_compare( (string) TUTOR_VERSION, '3.0', '>=' ),
	static fn() => new Tutor_Adapter(),
	'3.0',
	'3.9',
	static fn() => (string) TUTOR_VERSION
);

if ( call_user_func( $lledger_tutor->is_available ) ) {
	Tutor_Adapter::boot();
}

return $lledger_tutor;
