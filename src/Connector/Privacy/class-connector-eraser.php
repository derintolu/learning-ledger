<?php
/**
 * Connector personal-data eraser.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Privacy;

use LearningLedger\Connector\CourseServer\Learners_Controller;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Db\Tables;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Xapi\Agent_Identity;

/**
 * "Learning Ledger" eraser, keyed by the user's email. It deletes the same connector rows as the
 * course-server learner delete, then asks the LRS to erase each of the user's actors. An LRS
 * failure is queued on the existing retry job and reported in the eraser response. The WordPress
 * user itself is left to WordPress.
 */
final class Connector_Eraser {

	/**
	 * Erase the user's connector data.
	 *
	 * @param string  $email_address The user's email address.
	 * @param integer $page          Page number; everything is done on the first.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the signature is fixed by WordPress.
		$out  = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return $out;
		}
		$id     = (int) $user->ID;
		$actors = Connector_Exporter::actors_for( $id );
		$map    = new Identity_Map();

		// Order matters: rows are found through the actor meta and identity links, which go last.
		$removed = $this->delete_outbox( $actors );
		$removed = ( new Inbox_Repository() )->delete_user( $id ) + $removed;
		$removed = ( new Waiver_Ledger() )->delete_user( $id ) + $removed;
		$removed = ( new Apply_Ledger() )->delete_user( $id ) + $removed;
		$removed = ( new Registration_Map() )->delete_user( $id ) + $removed;
		$removed = count( $map->links_for_user( $id ) ) + $removed + ( '' === (string) get_user_meta( $id, Identity_Map::META, true ) ? 0 : 1 );
		$map->delete_learner( $id );

		$total = count( $actors );
		foreach ( $actors as $i => $actor ) {
			try {
				Learners_Controller::erase_job( array( 'actor' => $actor ) );
				++$removed;
			} catch ( \RuntimeException $e ) {
				Job_Queue::push(
					Learners_Controller::ERASE_JOB,
					array( 'actor' => $actor ),
					Learners_Controller::ERASE_JOB . ':' . bin2hex( Agent_Identity::from_array( $actor )->hash() ),
					Job_Queue::retry_delay( 1 )
				);
				$out['items_retained'] = true;
				$out['messages'][]     = sprintf(
					/* translators: 1: actor number, 2: number of actors. */
					__( 'The Learning Ledger server could not be reached to erase actor %1$d of %2$d. The request is queued and will be retried.', 'learning-ledger' ),
					$i + 1,
					$total
				);
			}
		}
		$out['items_removed'] = $removed > 0;
		return $out;
	}

	/**
	 * Delete the user's outbox rows.
	 *
	 * @param array[] $actors Agent objects.
	 *
	 * @return integer Rows deleted.
	 */
	private function delete_outbox( array $actors ): int {
		global $wpdb;
		$n = 0;
		foreach ( Connector_Exporter::outbox_rows( $actors ) as $row ) {
			$n += (int) $wpdb->delete( Tables::name( 'conn_outbox' ), array( 'id' => (int) $row->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $n;
	}
}
