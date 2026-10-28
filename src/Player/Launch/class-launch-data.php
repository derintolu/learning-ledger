<?php
/**
 * The cmi5 LMS.LaunchData document.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Launch;

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Vocab;

/**
 * Builds `LMS.LaunchData` [cmi5 10.2.1]. launchParameters, masteryScore and entitlementKey appear
 * only when the course structure defines them.
 */
final class Launch_Data {

	/**
	 * The State document id.
	 */
	const STATE_ID = 'LMS.LaunchData';

	/**
	 * The document.
	 *
	 * @param Au     $au           AU.
	 * @param string $registration Registration UUID.
	 * @param string $session      Session id.
	 * @param string $mode         Launch mode.
	 * @param string $return_url   The return route for this session.
	 *
	 * @return array
	 */
	public static function document( Au $au, string $registration, string $session, string $mode, string $return_url ): array {
		$doc = array(
			'contextTemplate' => array(
				'registration'      => $registration,
				'contextActivities' => array(
					'grouping' => array(
						array(
							'objectType' => 'Activity',
							'id'         => $au->id(),
						),
					),
				),
				'extensions'        => array( Vocab::EXT_SESSIONID => $session ),
			),
			'launchMode'      => $mode,
			'moveOn'          => $au->move_on(),
			'returnURL'       => $return_url,
		);
		if ( null !== $au->launch_parameters() ) {
			$doc['launchParameters'] = $au->launch_parameters();
		}
		if ( null !== $au->mastery_score() ) {
			$doc['masteryScore'] = $au->mastery_score();
		}
		if ( null !== $au->entitlement_key() ) {
			$doc['entitlementKey'] = array( 'courseStructure' => $au->entitlement_key() );
		}
		return $doc;
	}
}
