<?php
/**
 * Builds xAPI statements from neutral LMS events.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Outbox;

use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

/**
 * One plain-xAPI statement shape for every LMS (direction 2).
 */
final class Statement_Builder {

	/**
	 * UUIDv5 namespace of outbox statement ids.
	 */
	const NAMESPACE = '6f1d9c2e-4b7a-5e3c-8a1f-2d4b6c8e0a11';

	const ADL = 'http://adlnet.gov/expapi/verbs/';

	const RESET = 'http://id.tincanapi.com/verb/reset';

	/**
	 * Identity map.
	 *
	 * @var Identity_Map
	 */
	private Identity_Map $identity;

	/**
	 * Registration map.
	 *
	 * @var Registration_Map
	 */
	private Registration_Map $registrations;

	/**
	 * Constructor.
	 *
	 * @param Identity_Map|null     $identity      Identity map.
	 * @param Registration_Map|null $registrations Registration map.
	 */
	public function __construct( ?Identity_Map $identity = null, ?Registration_Map $registrations = null ) {
		$this->identity      = $identity ?? new Identity_Map();
		$this->registrations = $registrations ?? new Registration_Map();
	}

	/**
	 * Build the statement for an event, or null when the event sends none.
	 *
	 * @param Neutral_Event $e       Event.
	 * @param callable      $iri_for Maps the event to array{object:string,parent:string}.
	 *
	 * @return array|null
	 */
	public function build( Neutral_Event $e, callable $iri_for ): ?array {
		$verb = self::verb( $e );
		if ( null === $verb ) {
			return null;
		}
		$iris   = $iri_for( $e );
		$object = is_array( $iris ) ? (string) ( $iris['object'] ?? '' ) : '';
		$parent = is_array( $iris ) ? (string) ( $iris['parent'] ?? '' ) : '';
		if ( '' === $object ) {
			return null;
		}

		// First use mints the actor (user meta) and the registration (one row); later events only read them.
		$scope        = 'course:' . $e->course_id;
		$registration = $this->registrations->find( $e->user_id, $scope ) ?? $this->registrations->get_or_create( $e->user_id, $scope );
		$actor        = $this->identity->actor_for_user( $e->user_id );

		$context = array( 'registration' => $registration );
		if ( '' !== $parent && $parent !== $object ) {
			$context['contextActivities'] = array(
				'parent' => array(
					array(
						'objectType' => 'Activity',
						'id'         => $parent,
					),
				),
			);
		}

		$statement = array(
			'id'        => Uuid::v5( self::NAMESPACE, home_url() . '|' . $e->user_id . '|' . $object . '|' . $e->type . '|' . $e->occurred_at->format( 'Uv' ) ),
			'actor'     => $actor,
			'verb'      => array(
				'id'      => $verb,
				'display' => array( 'en-US' => self::display( $verb ) ),
			),
			'object'    => array(
				'objectType' => 'Activity',
				'id'         => $object,
			),
			'context'   => $context,
			'timestamp' => Timestamp::to_xapi( $e->occurred_at ),
		);

		$result = self::result( $e );
		if ( array() !== $result ) {
			$statement['result'] = $result;
		}

		// A course reset ends the registration: later events start a new one.
		if ( Neutral_Event::PROGRESS_RESET === $e->type && null === $e->lesson_id ) {
			$this->registrations->end( $e->user_id, $scope );
		}

		return $statement;
	}

	/**
	 * Verb IRI of an event.
	 *
	 * @param Neutral_Event $e Event.
	 *
	 * @return string|null
	 */
	private static function verb( Neutral_Event $e ): ?string {
		switch ( $e->type ) {
			case Neutral_Event::ENROLLED:
				return self::ADL . 'registered';
			case Neutral_Event::LESSON_STARTED:
				return self::ADL . 'attempted';
			case Neutral_Event::LESSON_COMPLETED:
			case Neutral_Event::COURSE_COMPLETED:
				return self::ADL . 'completed';
			case Neutral_Event::QUIZ_SUBMITTED:
				return self::ADL . 'answered';
			case Neutral_Event::QUIZ_GRADED:
				return null === $e->success ? null : self::ADL . ( $e->success ? 'passed' : 'failed' );
			case Neutral_Event::PROGRESS_RESET:
				return self::RESET;
			default:
				return null;
		}
	}

	/**
	 * Display name: the last IRI segment.
	 *
	 * @param string $verb Verb IRI.
	 *
	 * @return string
	 */
	private static function display( string $verb ): string {
		return substr( $verb, strrpos( $verb, '/' ) + 1 );
	}

	/**
	 * Result block: scores only, never answer text.
	 *
	 * @param Neutral_Event $e Event.
	 *
	 * @return array
	 */
	private static function result( Neutral_Event $e ): array {
		$result = array();
		if ( Neutral_Event::QUIZ_SUBMITTED !== $e->type && Neutral_Event::QUIZ_GRADED !== $e->type ) {
			return $result;
		}
		if ( null !== $e->score_scaled ) {
			$result['score'] = array( 'scaled' => max( -1.0, min( 1.0, $e->score_scaled ) ) );
		}
		if ( Neutral_Event::QUIZ_GRADED === $e->type ) {
			$result['success'] = (bool) $e->success;
		}
		return $result;
	}
}
