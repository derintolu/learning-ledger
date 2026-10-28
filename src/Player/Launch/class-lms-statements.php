<?php
/**
 * Statements the LMS writes for cmi5.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Launch;

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Util\Duration;
use LearningLedger\Util\Uuid;

/**
 * Builds `launched`, `abandoned`, `waived` and `satisfied` [cmi5 9.3]. Every statement carries the
 * registration, the cmi5 category and the session id; AU statements name the AU activity
 * `{au@id}/au` and group it under the AU's publisher id. The authority is the LMS: the LRS sets it
 * from the connector's own credential. There is no timestamp (the LRS assigns it) and the id is a
 * UUID v5 of `registration|object|verb|session`, so a retry is the same statement.
 */
final class Lms_Statements {

	/**
	 * UUID v5 namespace of LMS statement ids.
	 */
	const NAMESPACE = 'c3e1a8d4-2f6b-5c7e-9a1d-4b8f0e2c6a53';

	/**
	 * The `launched` statement [cmi5 9.3.1].
	 *
	 * @param Au          $au                AU.
	 * @param array       $actor             Learner actor.
	 * @param string      $registration      Registration UUID.
	 * @param string      $session           Session id.
	 * @param string      $mode              Launch mode.
	 * @param string      $launch_url        URL of the AU, without the launch parameters.
	 * @param string|null $launch_parameters The AU's launchParameters, if any.
	 *
	 * @return array
	 */
	public function launched( Au $au, array $actor, string $registration, string $session, string $mode, string $launch_url, ?string $launch_parameters ): array {
		$extensions = array(
			Vocab::EXT_LAUNCHMODE => $mode,
			Vocab::EXT_LAUNCHURL  => $launch_url,
			Vocab::EXT_MOVEON     => $au->move_on(),
		);
		if ( null !== $launch_parameters ) {
			$extensions[ Vocab::EXT_LAUNCHPARAMETERS ] = $launch_parameters;
		}
		return self::statement( $actor, Vocab::LAUNCHED, 'Launched', Au::activity_id( $au->id() ), $registration, $session, $au->id(), $extensions );
	}

	/**
	 * The `abandoned` statement [cmi5 9.3.6], with the session's duration [cmi5 9.5.4].
	 *
	 * @param Au     $au               AU.
	 * @param array  $actor            Learner actor.
	 * @param string $registration     Registration UUID.
	 * @param string $session          Session id.
	 * @param float  $duration_seconds Seconds since the launch.
	 *
	 * @return array
	 */
	public function abandoned( Au $au, array $actor, string $registration, string $session, float $duration_seconds ): array {
		$st           = self::statement( $actor, Vocab::ABANDONED, 'Abandoned', Au::activity_id( $au->id() ), $registration, $session, $au->id() );
		$st['result'] = array( 'duration' => Duration::format( max( 0.0, $duration_seconds ) ) );
		return $st;
	}

	/**
	 * The `waived` statement [cmi5 9.3.7].
	 *
	 * @param Au      $au           AU.
	 * @param array   $actor        Learner actor.
	 * @param string  $registration Registration UUID.
	 * @param string  $session      Session id.
	 * @param string  $reason       Tested Out, Equivalent AU, Equivalent Outside Activity or Administrative.
	 * @param boolean $carried_over Add the course-xapi-mcp carried-over extension.
	 *
	 * @return array
	 */
	public function waived( Au $au, array $actor, string $registration, string $session, string $reason, bool $carried_over = false ): array {
		$extensions = array( Vocab::EXT_REASON => $reason );
		if ( $carried_over ) {
			$extensions[ Vocab::CXM_EXT_CARRIED_OVER ] = true;
		}
		$st           = self::statement( $actor, Vocab::WAIVED, 'Waived', Au::activity_id( $au->id() ), $registration, $session, $au->id() );
		$st['result'] = array(
			'success'    => true,
			'completion' => true,
			'extensions' => $extensions,
		);
		return $st;
	}

	/**
	 * The `satisfied` statement for a block or the course [cmi5 9.3.9].
	 *
	 * @param string $object_id    Block or course publisher id.
	 * @param array  $actor        Learner actor.
	 * @param string $registration Registration UUID.
	 * @param string $session      Session id.
	 *
	 * @return array
	 */
	public function satisfied( string $object_id, array $actor, string $registration, string $session ): array {
		return self::statement( $actor, Vocab::SATISFIED, 'Satisfied', $object_id, $registration, $session, null );
	}

	/**
	 * A statement with the shared cmi5 context.
	 *
	 * @param array       $actor        Actor.
	 * @param string      $verb         Verb IRI.
	 * @param string      $display      Verb display (en-US).
	 * @param string      $object_id    Object activity id.
	 * @param string      $registration Registration UUID.
	 * @param string      $session      Session id.
	 * @param string|null $grouping     Publisher id to group under, if any.
	 * @param array       $extensions   Context extensions besides the session id.
	 *
	 * @return array
	 */
	private static function statement( array $actor, string $verb, string $display, string $object_id, string $registration, string $session, ?string $grouping, array $extensions = array() ): array {
		$activities = array(
			'category' => array(
				array(
					'objectType' => 'Activity',
					'id'         => Vocab::CAT_CMI5,
				),
			),
		);
		if ( null !== $grouping ) {
			$activities['grouping'] = array(
				array(
					'objectType' => 'Activity',
					'id'         => $grouping,
				),
			);
		}
		return array(
			'id'      => Uuid::v5( self::NAMESPACE, $registration . '|' . $object_id . '|' . $verb . '|' . $session ),
			'actor'   => $actor,
			'verb'    => array(
				'id'      => $verb,
				'display' => array( 'en-US' => $display ),
			),
			'object'  => array(
				'objectType' => 'Activity',
				'id'         => $object_id,
			),
			'context' => array(
				'registration'      => $registration,
				'contextActivities' => $activities,
				'extensions'        => array_merge( array( Vocab::EXT_SESSIONID => $session ), $extensions ),
			),
		);
	}
}
