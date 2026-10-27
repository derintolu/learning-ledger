<?php
/**
 * The xAPI Activities resource.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Resources;

use LearningLedger\Lrs\Http\Resource_Handler;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Http\Xapi_Response;

/**
 * Returns the stored Activity for an IRI.
 */
final class Activities_Handler implements Resource_Handler {

	/**
	 * Handle GET activities.
	 *
	 * @param Xapi_Request $r Request.
	 *
	 * @return Xapi_Response
	 * @throws Xapi_Error 400 when activityId is missing.
	 */
	public function handle( Xapi_Request $r ): Xapi_Response {
		$iri = $r->param( 'activityId' );
		if ( ! is_string( $iri ) || '' === $iri ) {
			throw new Xapi_Error( 400, 'activityId is required.' );
		}
		$activity = ( new Activity_Repository() )->get( $iri );
		return Xapi_Response::json(
			200,
			$activity ?? array(
				'objectType' => 'Activity',
				'id'         => $iri,
			)
		);
	}
}
