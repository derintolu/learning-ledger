<?php
/**
 * Connector personal-data exporter.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Privacy;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- connector tables are plugin-owned and never cached.

use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Inbox\More_Link;
use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Pairing\Lrs_Client;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

/**
 * "Learning Ledger" exporter, keyed by the user's email: identity links, actor, outbox rows,
 * inbox rows, waivers and applied rows, then the user's statements read from the LRS.
 *
 * The local groups come with page 1. Each page reads one page of statements from the LRS, one
 * actor at a time; the place reached is kept in a short-lived transient between pages.
 */
final class Connector_Exporter {

	const LRS_LIMIT  = 100;
	const CURSOR     = 'lledger_privacy_export_';
	const CURSOR_TTL = HOUR_IN_SECONDS;

	/**
	 * Export one page.
	 *
	 * @param string  $email_address The user's email address.
	 * @param integer $page          Page number, from 1.
	 *
	 * @return array{data:array[],done:bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$id     = (int) $user->ID;
		$actors = self::actors_for( $id );
		$items  = array();

		if ( $page <= 1 ) {
			$items = $this->local_items( $id );
			$state = array(
				'actor' => 0,
				'query' => self::first_query( $actors[0] ?? array() ),
			);
		} else {
			$state = get_transient( self::CURSOR . $id );
			if ( ! is_array( $state ) ) {
				return array(
					'data' => array(),
					'done' => true,
				);
			}
		}

		if ( ! isset( $actors[ (int) $state['actor'] ] ) ) {
			delete_transient( self::CURSOR . $id );
			return array(
				'data' => $items,
				'done' => true,
			);
		}

		list( $statements, $state ) = $this->read_lrs_page( $actors, $state, $items );
		foreach ( $statements as $statement ) {
			$items[] = self::statement_item( $statement );
		}

		if ( null === $state ) {
			delete_transient( self::CURSOR . $id );
			return array(
				'data' => $items,
				'done' => true,
			);
		}
		set_transient( self::CURSOR . $id, $state, self::CURSOR_TTL );
		return array(
			'data' => $items,
			'done' => false,
		);
	}

	/**
	 * The user's actors: the site-issued account (only if one was ever minted), then the linked identities.
	 *
	 * @param integer $user User id.
	 *
	 * @return array[] Agent objects.
	 */
	public static function actors_for( int $user ): array {
		$map = new Identity_Map();
		$out = array();
		if ( '' !== (string) get_user_meta( $user, Identity_Map::META, true ) ) {
			$out[] = $map->actor_for_user( $user );
		}
		foreach ( $map->links_for_user( $user ) as $link ) {
			if ( ! is_array( $link['actor'] ) ) {
				continue;
			}
			try {
				Agent_Identity::from_array( $link['actor'] );
			} catch ( Invalid_Input $e ) {
				continue;
			}
			$out[] = $link['actor'];
		}
		return $out;
	}

	/**
	 * Outbox rows whose statement actor is one of the user's actors. The outbox has no user column,
	 * so candidates are found by the actor's identifier and confirmed by decoding the statement.
	 *
	 * @param array[] $actors Agent objects.
	 *
	 * @return object[] Rows with id, statement_id (UUID), course_id, status, attempts, created_at, body.
	 */
	public static function outbox_rows( array $actors ): array {
		global $wpdb;
		$table = Tables::name( 'conn_outbox' );
		$found = array();
		foreach ( $actors as $actor ) {
			try {
				$identity = Agent_Identity::from_array( $actor );
			} catch ( Invalid_Input $e ) {
				continue;
			}
			$needle = 'account' === $identity->kind() ? (string) $actor['account']['name'] : (string) $actor[ $identity->kind() ];
			$rows   = $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, statement_id, course_id, status, attempts, created_at, body FROM %i WHERE body LIKE %s', $table, '%' . $wpdb->esc_like( $needle ) . '%' )
			);
			foreach ( (array) $rows as $row ) {
				$statement = json_decode( (string) $row->body, true );
				$about     = is_array( $statement ) && is_array( $statement['actor'] ?? null ) ? $statement['actor'] : null;
				if ( null === $about ) {
					continue;
				}
				try {
					$same = hash_equals( $identity->hash(), Agent_Identity::from_array( $about )->hash() );
				} catch ( Invalid_Input $e ) {
					continue;
				}
				if ( $same ) {
					$row->statement_id       = Uuid::from_bin( (string) $row->statement_id );
					$found[ (int) $row->id ] = $row;
				}
			}
		}
		return array_values( $found );
	}

	/**
	 * Inbox rows matched to the user, plus rows (matched or not) about the user's actors.
	 *
	 * @param integer $user   User id.
	 * @param array[] $actors Agent objects.
	 *
	 * @return array[] Rows with statement_id (UUID), status, source, stored, received_at, body.
	 */
	public static function inbox_rows( int $user, array $actors ): array {
		global $wpdb;
		$table  = Tables::name( 'conn_inbox' );
		$hashes = array();
		foreach ( $actors as $actor ) {
			try {
				$hashes[] = Agent_Identity::from_array( $actor )->hash();
			} catch ( Invalid_Input $e ) {
				continue;
			}
		}
		if ( array() === $hashes ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT statement_id, status, source, `stored`, received_at, body FROM %i WHERE user_id = %d ORDER BY `stored`, statement_id', $table, $user ), ARRAY_A );
		} else {
			$placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are passed as one list.
					"SELECT statement_id, status, source, `stored`, received_at, body FROM %i WHERE user_id = %d OR actor_hash IN ({$placeholders}) ORDER BY `stored`, statement_id", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...array_merge( array( $table, $user ), $hashes )
				),
				ARRAY_A
			);
		}
		$out = array();
		foreach ( (array) $rows as $row ) {
			$row['statement_id'] = Uuid::from_bin( (string) $row['statement_id'] );
			$out[]               = $row;
		}
		return $out;
	}

	/**
	 * The six local groups.
	 *
	 * @param integer $user User id.
	 *
	 * @return array[]
	 */
	private function local_items( int $user ): array {
		$actors = self::actors_for( $user );
		$map    = new Identity_Map();
		$items  = array();

		foreach ( $map->links_for_user( $user ) as $i => $link ) {
			$items[] = self::item(
				'lledger-identity-links',
				__( 'Learning Ledger: linked identities', 'learning-ledger' ),
				'identity-link-' . $i,
				array(
					__( 'Actor', 'learning-ledger' )     => wp_json_encode( $link['actor'] ),
					__( 'Linked at', 'learning-ledger' ) => $link['linked_at'],
				)
			);
		}

		if ( '' !== (string) get_user_meta( $user, Identity_Map::META, true ) ) {
			$items[] = self::item(
				'lledger-actor',
				__( 'Learning Ledger: site-issued actor', 'learning-ledger' ),
				'actor',
				array( __( 'Actor', 'learning-ledger' ) => wp_json_encode( $map->actor_for_user( $user ) ) )
			);
		}

		foreach ( self::outbox_rows( $actors ) as $row ) {
			$items[] = self::item(
				'lledger-outbox',
				__( 'Learning Ledger: statements waiting to be sent', 'learning-ledger' ),
				'outbox-' . $row->statement_id,
				array(
					__( 'Statement ID', 'learning-ledger' ) => $row->statement_id,
					__( 'Course ID', 'learning-ledger' ) => (string) $row->course_id,
					__( 'Status', 'learning-ledger' )    => $row->status,
					__( 'Attempts', 'learning-ledger' )  => (string) $row->attempts,
					__( 'Created', 'learning-ledger' )   => $row->created_at,
					__( 'Statement', 'learning-ledger' ) => $row->body,
				)
			);
		}

		foreach ( self::inbox_rows( $user, $actors ) as $row ) {
			$items[] = self::item(
				'lledger-inbox',
				__( 'Learning Ledger: statements received', 'learning-ledger' ),
				'inbox-' . $row['statement_id'],
				array(
					__( 'Statement ID', 'learning-ledger' ) => $row['statement_id'],
					__( 'Status', 'learning-ledger' )    => $row['status'],
					__( 'Source', 'learning-ledger' )    => $row['source'],
					__( 'Stored', 'learning-ledger' )    => $row['stored'],
					__( 'Received', 'learning-ledger' )  => $row['received_at'],
					__( 'Statement', 'learning-ledger' ) => $row['body'],
				)
			);
		}

		foreach ( ( new Waiver_Ledger() )->for_user( $user ) as $row ) {
			$items[] = self::item(
				'lledger-waivers',
				__( 'Learning Ledger: tested-out lessons', 'learning-ledger' ),
				'waiver-' . $row['lesson_id'],
				array(
					__( 'Lesson ID', 'learning-ledger' ) => (string) $row['lesson_id'],
					__( 'Reason', 'learning-ledger' )    => $row['reason'],
					__( 'Registration', 'learning-ledger' ) => $row['registration'],
					__( 'Statement ID', 'learning-ledger' ) => $row['statement_id'],
					__( 'Carried over', 'learning-ledger' ) => $row['carried_over'] ? 'yes' : 'no',
					__( 'Recorded', 'learning-ledger' )  => $row['created_at'],
				)
			);
		}

		foreach ( ( new Apply_Ledger() )->for_user( $user ) as $row ) {
			$items[] = self::item(
				'lledger-applied',
				__( 'Learning Ledger: results applied to the LMS', 'learning-ledger' ),
				'applied-' . $row['object_kind'] . '-' . $row['object_id'] . '-' . $row['status'],
				array(
					__( 'Object', 'learning-ledger' )  => $row['object_kind'] . ' ' . $row['object_id'],
					__( 'Result', 'learning-ledger' )  => $row['status'],
					__( 'Statement ID', 'learning-ledger' ) => $row['statement_id'],
					__( 'Score', 'learning-ledger' )   => null === $row['score_scaled'] ? '' : (string) $row['score_scaled'],
					__( 'Success', 'learning-ledger' ) => null === $row['success'] ? '' : ( $row['success'] ? 'yes' : 'no' ),
					__( 'Voided', 'learning-ledger' )  => $row['flagged'] ? 'yes' : 'no',
					__( 'Applied', 'learning-ledger' ) => $row['applied_at'],
				)
			);
		}
		return $items;
	}

	/**
	 * Read one page of statements for the actor the state points at, and say where to go next.
	 * A failed read becomes a visible notice item (appended to `$items`), never a silent gap.
	 *
	 * @param array[] $actors Agent objects.
	 * @param array   $state  Place reached: actor (int), query (array) and optionally resource (string).
	 * @param array[] $items  Export items so far (a notice is added here).
	 *
	 * @return array{0:array[],1:?array} Statements, and the next state or null when every actor is read.
	 */
	private function read_lrs_page( array $actors, array $state, array &$items ): array {
		$index    = (int) $state['actor'];
		$resource = is_string( $state['resource'] ?? null ) ? $state['resource'] : 'statements';

		$data   = null;
		$status = 0;
		try {
			$res    = ( new Lrs_Client() )->xapi( 'GET', $resource, (array) $state['query'] );
			$status = $res->status;
			$data   = 200 === $status ? $res->json() : null;
		} catch ( \RuntimeException $e ) {
			$data = null;
		}

		$statements = array();
		$next       = null;
		if ( is_array( $data ) && is_array( $data['statements'] ?? null ) ) {
			$statements = array_values( array_filter( $data['statements'], 'is_array' ) );
			$more       = is_string( $data['more'] ?? null ) ? $data['more'] : '';
			$resolved   = '' === $more ? null : More_Link::resolve( $more, Connector_Settings::get() );
			if ( null !== $resolved ) {
				return array(
					$statements,
					array(
						'actor'    => $index,
						'resource' => $resolved[0],
						'query'    => $resolved[1],
					),
				);
			}
		} else {
			$items[] = self::item(
				'lledger-lrs-statements',
				__( 'Learning Ledger: statements held by the LRS', 'learning-ledger' ),
				'lrs-unreadable-' . $index,
				array(
					__( 'Notice', 'learning-ledger' ) => sprintf(
						/* translators: 1: actor number, 2: HTTP status (0 when there was no answer). */
						__( 'The statements the LRS holds for actor %1$d could not be read (status %2$d). Run the export again later to include them.', 'learning-ledger' ),
						$index + 1,
						$status
					),
				)
			);
		}

		if ( isset( $actors[ $index + 1 ] ) ) {
			$next = array(
				'actor' => $index + 1,
				'query' => self::first_query( $actors[ $index + 1 ] ),
			);
		}
		return array( $statements, $next );
	}

	/**
	 * First statements query for an actor.
	 *
	 * @param array $actor Agent object.
	 *
	 * @return array
	 */
	private static function first_query( array $actor ): array {
		return array(
			'agent'     => (string) wp_json_encode( $actor ),
			'limit'     => self::LRS_LIMIT,
			'ascending' => 'true',
		);
	}

	/**
	 * One statement from the LRS as an export item.
	 *
	 * @param array $statement Statement.
	 *
	 * @return array
	 */
	private static function statement_item( array $statement ): array {
		return self::item(
			'lledger-lrs-statements',
			__( 'Learning Ledger: statements held by the LRS', 'learning-ledger' ),
			'lrs-' . ( is_string( $statement['id'] ?? null ) ? $statement['id'] : md5( (string) wp_json_encode( $statement ) ) ),
			array(
				__( 'Statement ID', 'learning-ledger' ) => is_string( $statement['id'] ?? null ) ? $statement['id'] : '',
				__( 'Verb', 'learning-ledger' )         => is_string( $statement['verb']['id'] ?? null ) ? $statement['verb']['id'] : '',
				__( 'Object', 'learning-ledger' )       => is_string( $statement['object']['id'] ?? null ) ? $statement['object']['id'] : '',
				__( 'Timestamp', 'learning-ledger' )    => is_string( $statement['timestamp'] ?? null ) ? $statement['timestamp'] : '',
				__( 'Statement', 'learning-ledger' )    => (string) wp_json_encode( $statement ),
			)
		);
	}

	/**
	 * One export item.
	 *
	 * @param string   $group_id    Group id.
	 * @param string   $group_label Group label.
	 * @param string   $item_id     Item id.
	 * @param string[] $fields      Name => value.
	 *
	 * @return array
	 */
	private static function item( string $group_id, string $group_label, string $item_id, array $fields ): array {
		$data = array();
		foreach ( $fields as $name => $value ) {
			$data[] = array(
				'name'  => (string) $name,
				'value' => (string) $value,
			);
		}
		return array(
			'group_id'    => $group_id,
			'group_label' => $group_label,
			'item_id'     => $item_id,
			'data'        => $data,
		);
	}
}
