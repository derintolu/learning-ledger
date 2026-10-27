<?php
/**
 * Default sink that drops events.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * No-op sink used until the outbox supplies one.
 */
final class Noop_Event_Sink implements Event_Sink {

	/**
	 * Drop the event.
	 *
	 * @param Neutral_Event $e Event.
	 *
	 * @return void
	 */
	public function emit( Neutral_Event $e ): void {}
}
