<?php
/**
 * Agent identity storage.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Resources;

use LearningLedger\Db\Tables;
use LearningLedger\Util\Json;
use LearningLedger\Xapi\Agent_Identity;

/**
 * Known agents by IFI hash, grouped into persons by person_id.
 */
final class Agent_Repository {

	/**
	 * Remember an agent. A new row's person_id is its own id.
	 *
	 * @param Agent_Identity $a Agent.
	 *
	 * @return void
	 */
	public function record( Agent_Identity $a ): void {
		global $wpdb;
		$table = Tables::name( 'agents' );
		$hash  = bin2hex( $a->hash() );
		$ifi   = $a->to_array();
		unset( $ifi['objectType'] );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (ifi_hash, ifi, person_id, updated) VALUES (UNHEX(%s), %s, 0, %s) ON DUPLICATE KEY UPDATE updated = VALUES(updated)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash,
				Json::encode_exact( $ifi ),
				( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.u' )
			)
		);
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "UPDATE {$table} SET person_id = id WHERE ifi_hash = UNHEX(%s) AND person_id = 0", $hash ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * The Person object for every IFI sharing this agent's person_id.
	 *
	 * @param Agent_Identity $a Agent.
	 *
	 * @return array
	 */
	public function person_for( Agent_Identity $a ): array {
		global $wpdb;
		$table  = Tables::name( 'agents' );
		$person = array(
			'objectType'   => 'Person',
			'name'         => array(),
			'mbox'         => array(),
			'mbox_sha1sum' => array(),
			'openid'       => array(),
			'account'      => array(),
		);
		$rows   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ifi FROM {$table} p JOIN {$table} me ON me.ifi_hash = UNHEX(%s) WHERE p.person_id = IF(me.person_id = 0, me.id, me.person_id) OR (p.person_id = 0 AND p.id = me.id) ORDER BY p.id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				bin2hex( $a->hash() )
			)
		);
		foreach ( $rows as $json ) {
			$ifi = Json::decode_strict( $json, PHP_INT_MAX, 8 );
			foreach ( Agent_Identity::IFI_KEYS as $key ) {
				if ( isset( $ifi[ $key ] ) ) {
					$person[ $key ][] = $ifi[ $key ];
				}
			}
		}
		return $person;
	}

	/**
	 * Forget an agent.
	 *
	 * @param Agent_Identity $a Agent.
	 *
	 * @return void
	 */
	public function delete( Agent_Identity $a ): void {
		global $wpdb;
		$table = Tables::name( 'agents' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$table} WHERE ifi_hash = UNHEX(%s)", bin2hex( $a->hash() ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
