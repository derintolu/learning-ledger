<?php
/**
 * Contract for xAPI resource handlers.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Http;

/**
 * Handles one xAPI resource (for one or more methods).
 */
interface Resource_Handler {

	/**
	 * Handle an authenticated, version-checked request.
	 *
	 * @param Xapi_Request $r Request.
	 *
	 * @return Xapi_Response
	 * @throws Xapi_Error For xAPI-shaped error responses.
	 */
	public function handle( Xapi_Request $r ): Xapi_Response;
}
