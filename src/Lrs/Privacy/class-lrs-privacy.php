<?php
/**
 * WordPress privacy exporter and eraser for the LRS.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Privacy;

use LearningLedger\Db\Tables;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables, never cached.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name and fixed placeholders are interpolated.

/**
 * Registers `wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers` for
 * email addresses. An email stands for the actors `mbox: mailto:<email>` and the
 * `mbox_sha1sum` of that IRI (for the address as given and, when it differs, lowercased).
 */
final class Lrs_Privacy {

	/**
	 * Exporter and eraser id.
	 */
	const ID = 'learning-ledger-lrs';

	/**
	 * Export group id.
	 */
	const GROUP = 'learning-ledger-statements';

	/**
	 * Statements per export page.
	 */
	const PAGE_SIZE = 100;

	/**
	 * Eraser.
	 *
	 * @var Actor_Eraser
	 */
	private Actor_Eraser $eraser;

	/**
	 * Constructor.
	 *
	 * @param Actor_Eraser|null $eraser Eraser.
	 */
	public function __construct( ?Actor_Eraser $eraser = null ) {
		$this->eraser = $eraser ?? new Actor_Eraser();
	}

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
	}

	/**
	 * Add the exporter.
	 *
	 * @param array $exporters Exporters.
	 *
	 * @return array
	 */
	public function exporters( array $exporters ): array {
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Learning Ledger learning record (xAPI statements)', 'learning-ledger' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param array $erasers Erasers.
	 *
	 * @return array
	 */
	public function erasers( array $erasers ): array {
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Learning Ledger learning record (xAPI statements)', 'learning-ledger' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * One page of the email's actors' statements, each as its exact stored JSON.
	 *
	 * @param string  $email Email address.
	 * @param integer $page  Page, from 1.
	 *
	 * @return array{data:array,done:bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$hashes = array_map( static fn( Agent_Identity $a ) => bin2hex( $a->hash() ), self::identities( $email ) );
		if ( array() === $hashes ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		global $wpdb;
		$table        = Tables::name( 'statements' );
		$placeholders = implode( ', ', array_fill( 0, count( $hashes ), 'UNHEX(%s)' ) );
		$args         = array_merge( $hashes, array( self::PAGE_SIZE + 1, ( max( 1, $page ) - 1 ) * self::PAGE_SIZE ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT statement_id, body FROM {$table} WHERE actor_hash IN ({$placeholders}) ORDER BY seq LIMIT %d OFFSET %d", $args ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above, passed as one array.
			ARRAY_A
		);
		$rows         = is_array( $rows ) ? $rows : array();
		$done         = count( $rows ) <= self::PAGE_SIZE;

		$data = array();
		foreach ( array_slice( $rows, 0, self::PAGE_SIZE ) as $row ) {
			$id     = Uuid::from_bin( $row['statement_id'] );
			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'Learning record (xAPI statements)', 'learning-ledger' ),
				'item_id'     => 'learning-ledger-statement-' . $id,
				'data'        => array(
					array(
						'name'  => __( 'Statement ID', 'learning-ledger' ),
						'value' => $id,
					),
					array(
						'name'  => __( 'Statement', 'learning-ledger' ),
						'value' => $row['body'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => $done,
		);
	}

	/**
	 * Erase the email's actors from the LRS (all in one page).
	 *
	 * @param string  $email Email address.
	 * @param integer $page  Page (unused: everything is erased at once).
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		unset( $page );
		$user     = get_current_user_id();
		$by       = $user > 0 ? 'user:' . $user : 'wp-privacy';
		$removed  = false;
		$retained = false;
		$messages = array();
		foreach ( self::identities( $email ) as $actor ) {
			try {
				$counts = $this->eraser->erase( $actor, $by );
			} catch ( \Throwable $e ) {
				$retained   = true;
				$messages[] = __( 'Some of the learning record could not be erased. Try again.', 'learning-ledger' );
				continue;
			}
			$removed = $removed || ( $counts['statements'] + $counts['documents'] + $counts['agents'] + $counts['tokens'] + $counts['links'] ) > 0;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => array_values( array_unique( $messages ) ),
			'done'           => true,
		);
	}

	/**
	 * The actors an email stands for.
	 *
	 * @param string $email Email address.
	 *
	 * @return Agent_Identity[]
	 */
	private static function identities( string $email ): array {
		$email = trim( $email );
		if ( '' === $email ) {
			return array();
		}
		$out = array();
		foreach ( array_unique( array( $email, strtolower( $email ) ) ) as $address ) {
			$iri = 'mailto:' . $address;
			foreach ( array( array( 'mbox' => $iri ), array( 'mbox_sha1sum' => sha1( $iri ) ) ) as $agent ) {
				try {
					$out[] = Agent_Identity::from_array( $agent );
				} catch ( Invalid_Input $e ) {
					unset( $e );
				}
			}
		}
		return $out;
	}
}
