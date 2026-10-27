<?php
/**
 * The xAPI About resource.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Resources;

use LearningLedger\Lrs\Http\Resource_Handler;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Http\Xapi_Response;

/**
 * Lists the supported xAPI versions and the plugin's extension; needs no authentication.
 */
final class About_Handler implements Resource_Handler {

	/**
	 * Extension key under which the plugin describes itself.
	 */
	const EXTENSION_KEY = 'https://github.com/derintolu/learning-ledger';

	/**
	 * Handle GET about.
	 *
	 * @param Xapi_Request $r Request.
	 *
	 * @return Xapi_Response
	 */
	public function handle( Xapi_Request $r ): Xapi_Response {
		$ext = apply_filters( // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
			'learning_ledger/about_extension',
			array(
				'api_base'       => rest_url( 'learning-ledger/v1/' ),
				'plugin_version' => LEARNING_LEDGER_VERSION,
			)
		);

		return Xapi_Response::json(
			200,
			array(
				'version'    => array( '1.0.3', '2.0.0' ),
				'extensions' => array( self::EXTENSION_KEY => is_array( $ext ) ? $ext : (object) array() ),
			)
		);
	}
}
