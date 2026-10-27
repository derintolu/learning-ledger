<?php
/**
 * Receives neutral events from an adapter.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * Sink for neutral events.
 */
interface Event_Sink {

	/**
	 * Accept an event.
	 *
	 * @param Neutral_Event $e Event.
	 *
	 * @return void
	 */
	public function emit( Neutral_Event $e ): void;
}
