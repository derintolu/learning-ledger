<?php
/**
 * Event sink that records what it receives.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Tests;

use LearningLedger\Connector\Lms\Event_Sink;
use LearningLedger\Connector\Lms\Neutral_Event;

/**
 * Recording sink.
 */
class Recording_Sink implements Event_Sink {

	/**
	 * Events received.
	 *
	 * @var Neutral_Event[]
	 */
	public array $events = array();

	/**
	 * Record an event.
	 *
	 * @param Neutral_Event $e Event.
	 *
	 * @return void
	 */
	public function emit( Neutral_Event $e ): void {
		$this->events[] = $e;
	}
}
