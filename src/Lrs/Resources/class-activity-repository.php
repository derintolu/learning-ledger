<?php
/**
 * Activity definition storage.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Resources;

use LearningLedger\Db\Tables;
use LearningLedger\Util\Json;

/**
 * Reads and merges Activity definitions keyed by the hash of the activity IRI.
 */
final class Activity_Repository {

	/**
	 * Definition fields that are language maps, merged key by key.
	 *
	 * @var string[]
	 */
	private const LANGUAGE_MAPS = array( 'name', 'description' );

	/**
	 * Definition fields that are JSON objects even when empty.
	 *
	 * @var string[]
	 */
	private const OBJECT_FIELDS = array( 'name', 'description', 'extensions' );

	/**
	 * Store a definition. An absent row is inserted; a present row changes only when `$may_define`.
	 *
	 * @param string     $iri        Activity IRI.
	 * @param array|null $definition Definition object, if the statement sent one.
	 * @param boolean    $may_define Whether the caller may define this activity.
	 *
	 * @return void
	 */
	public function merge( string $iri, ?array $definition, bool $may_define ): void {
		global $wpdb;
		$table = Tables::name( 'activities' );
		$hash  = bin2hex( sha1( $iri, true ) );
		$now   = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.u' );

		// A concurrent insert of the same IRI is a no-op, never an error.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (iri_hash, iri, definition, updated) VALUES (UNHEX(%s), %s, %s, %s) ON DUPLICATE KEY UPDATE iri_hash = iri_hash", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash,
				$iri,
				self::encode( $definition ?? array() ),
				$now
			)
		);
		if ( ! $may_define || null === $definition ) {
			return;
		}

		// Optimistic compare-and-swap: a concurrent define makes the UPDATE match nothing, so re-read and merge again.
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT iri, definition FROM {$table} WHERE iri_hash = UNHEX(%s)", $hash ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			if ( null === $row || $row['iri'] !== $iri ) {
				return;
			}
			$merged = self::encode( self::merge_definitions( self::decode( $row['definition'] ), $definition ) );
			if ( $merged === $row['definition'] ) {
				return;
			}
			$changed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$table} SET definition = %s, updated = %s WHERE iri_hash = UNHEX(%s) AND definition = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$merged,
					$now,
					$hash,
					$row['definition']
				)
			);
			if ( $changed ) {
				return;
			}
		}
	}

	/**
	 * The Activity object for an IRI.
	 *
	 * @param string $iri Activity IRI.
	 *
	 * @return array|null Null when unknown.
	 */
	public function get( string $iri ): ?array {
		global $wpdb;
		$table = Tables::name( 'activities' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT iri, definition FROM {$table} WHERE iri_hash = UNHEX(%s)", bin2hex( sha1( $iri, true ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( null === $row || $row['iri'] !== $iri ) {
			return null;
		}
		$activity = array(
			'objectType' => 'Activity',
			'id'         => $iri,
		);
		$def      = self::decode( $row['definition'] );
		if ( array() !== $def ) {
			$activity['definition'] = $def;
		}
		return $activity;
	}

	/**
	 * Replace top-level fields; merge language maps key by key.
	 *
	 * @param array $old Stored definition.
	 * @param array $new Incoming definition.
	 *
	 * @return array
	 */
	private static function merge_definitions( array $old, array $new ): array {
		$out = $old;
		foreach ( $new as $field => $value ) {
			if ( in_array( $field, self::LANGUAGE_MAPS, true ) && is_array( $value ) && isset( $old[ $field ] ) && is_array( $old[ $field ] ) ) {
				$out[ $field ] = array_merge( $old[ $field ], $value );
			} else {
				$out[ $field ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Encode a definition, keeping empty maps as JSON objects.
	 *
	 * @param array $definition Definition.
	 *
	 * @return string
	 */
	private static function encode( array $definition ): string {
		foreach ( self::OBJECT_FIELDS as $field ) {
			if ( isset( $definition[ $field ] ) && array() === $definition[ $field ] ) {
				$definition[ $field ] = new \stdClass();
			}
		}
		return array() === $definition ? '{}' : Json::encode_exact( $definition );
	}

	/**
	 * Decode a stored definition.
	 *
	 * @param string $json Stored JSON.
	 *
	 * @return array
	 */
	private static function decode( string $json ): array {
		$value = Json::decode_strict( $json, PHP_INT_MAX, 64 );
		return is_array( $value ) ? $value : array();
	}
}
