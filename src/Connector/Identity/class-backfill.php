<?php
/**
 * Backfill of a linked actor's earlier statements.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Identity;

use LearningLedger\Connector\Inbox\Inbox_Processor;
use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Inbox\More_Link;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Pairing\Lrs_Client;

/**
 * Reads `GET statements?agent=<actor>` through `more` into the inbox. Read-only against the LRS:
 * statements are never rewritten, the link only changes which user the inbox rows are applied to.
 */
final class Backfill {

	const LIMIT     = 500;
	const MAX_PAGES = 200;

	/**
	 * LRS client.
	 *
	 * @var Lrs_Client
	 */
	private Lrs_Client $client;

	/**
	 * Inbox.
	 *
	 * @var Inbox_Repository
	 */
	private Inbox_Repository $inbox;

	/**
	 * Constructor.
	 *
	 * @param Lrs_Client|null       $client LRS client.
	 * @param Inbox_Repository|null $inbox  Inbox.
	 */
	public function __construct( ?Lrs_Client $client = null, ?Inbox_Repository $inbox = null ) {
		$this->client = $client ?? new Lrs_Client();
		$this->inbox  = $inbox ?? new Inbox_Repository();
	}

	/**
	 * Fetch every statement about an actor, store the new ones, then process the inbox.
	 *
	 * @param integer $user  User id the actor is linked to (the inbox matches by identity link).
	 * @param array   $actor Agent object.
	 *
	 * @return integer New inbox rows.
	 *
	 * @throws \RuntimeException When the LRS cannot be read, so a queued job is retried.
	 */
	public function run( int $user, array $actor ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the link, not the argument, decides the user.
		$settings = Connector_Settings::get();
		$resource = 'statements';
		$query    = array(
			'agent'     => (string) wp_json_encode( $actor ),
			'limit'     => self::LIMIT,
			'ascending' => 'true',
		);
		$added    = 0;
		$complete = false;

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$res  = $this->client->xapi( 'GET', $resource, $query );
			$data = 200 === $res->status ? $res->json() : null;
			if ( ! is_array( $data ) || ! is_array( $data['statements'] ?? null ) ) {
				throw new \RuntimeException( 'The LRS did not return a statements page.' );
			}
			foreach ( $data['statements'] as $statement ) {
				if ( is_array( $statement ) && $this->inbox->add( $statement, 'backfill' ) ) {
					++$added;
				}
			}
			$more = is_string( $data['more'] ?? null ) ? $data['more'] : '';
			if ( '' === $more ) {
				$complete = true;
				break;
			}
			$next = More_Link::resolve( $more, $settings );
			if ( null === $next ) {
				throw new \RuntimeException( 'The LRS returned a more link that cannot be followed.' );
			}
			list( $resource, $query ) = $next;
		}
		if ( ! $complete ) {
			throw new \RuntimeException( 'The backfill reached its page limit before the end of the statements.' );
		}

		$processor = new Inbox_Processor();
		$rounds    = 0;
		do {
			$left = $processor->process_pending();
		} while ( $left > 0 && ++$rounds < 100 );
		return $added;
	}
}
