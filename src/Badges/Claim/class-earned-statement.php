<?php
/**
 * The xAPI `earned` statement written after a badge is signed.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Claim;

/**
 * Builds the Open Badges xAPI `earned` statement (OB §4.2): the achievement as the object,
 * the credential id in the `badgeassertion` result extension, a StatementRef to the
 * statement that earned the badge, and the same registration.
 */
final class Earned_Statement {

	const VERB = 'http://specification.openbadges.org/xapi/verbs/earned';

	const BADGE_ASSERTION = 'http://specification.openbadges.org/xapi/extensions/badgeassertion';

	/**
	 * Build the statement (no id: the LRS assigns one when it stores it).
	 *
	 * @param array  $actor          The learner, as in the trigger statement.
	 * @param string $registration   Registration UUID.
	 * @param string $achievement_id Achievement id (an IRI).
	 * @param string $credential_id  Credential id (`urn:uuid:<badge id>`).
	 * @param string $trigger_id     Id of the statement that earned the badge.
	 *
	 * @return array
	 */
	public static function build( array $actor, string $registration, string $achievement_id, string $credential_id, string $trigger_id ): array {
		return array(
			'actor'   => $actor,
			'verb'    => array(
				'id'      => self::VERB,
				'display' => array( 'en-US' => 'earned' ),
			),
			'object'  => array(
				'objectType' => 'Activity',
				'id'         => $achievement_id,
			),
			'result'  => array(
				'extensions' => array( self::BADGE_ASSERTION => $credential_id ),
			),
			'context' => array(
				'registration' => $registration,
				'statement'    => array(
					'objectType' => 'StatementRef',
					'id'         => $trigger_id,
				),
			),
		);
	}
}
