<?php
/**
 * Program storage.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Programs;

use LearningLedger\Cmi5\Course_Structure;

/**
 * Reads and writes lledger_program posts.
 */
final class Program_Repository {

	public const POST_TYPE = 'lledger_program';

	/**
	 * Program by post id.
	 *
	 * @param integer $id Post id.
	 *
	 * @return Program|null
	 */
	public function get( int $id ): ?Program {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$base       = (string) get_post_meta( $id, '_lledger_iri_base', true );
		$course_iri = (string) get_post_meta( $id, '_lledger_course_iri', true );
		$waive      = get_post_meta( $id, '_lledger_waive_recommended', true );
		$path_keys  = array();
		$raw_keys   = get_post_meta( $id, '_lledger_path_keys', true );
		foreach ( is_array( $raw_keys ) ? $raw_keys : array() as $cid => $key ) {
			$path_keys[ (int) $cid ] = (string) $key;
		}
		$custody = (string) get_post_meta( $id, '_lledger_custody', true );

		return new Program(
			id: $id,
			stable_key: (string) get_post_meta( $id, '_lledger_stable_key', true ),
			iri_base: $base,
			course_iri: '' !== $course_iri ? $course_iri : untrailingslashit( $base ),
			course_ids: $this->course_ids( $id ),
			path_keys: $path_keys,
			placement: $this->json_array( $id, '_lledger_placement' ),
			rules: (string) get_post_meta( $id, '_lledger_rules', true ),
			badges: $this->json_array( $id, '_lledger_badges' ),
			badge_issuer: $this->json_array( $id, '_lledger_badge_issuer' ),
			custody: '' !== $custody ? $custody : 'lms-launched',
			course_server_url: (string) get_post_meta( $id, '_lledger_course_server_url', true ),
			au_url_template: (string) get_post_meta( $id, '_lledger_au_url_template', true ),
			waive_recommended: '' === $waive ? true : (bool) (int) $waive,
			version: (int) get_post_meta( $id, '_lledger_version', true ),
			etag: (string) get_post_meta( $id, '_lledger_etag', true )
		);
	}

	/**
	 * Program id for a stable key.
	 *
	 * @param string $key Stable key.
	 *
	 * @return integer|null
	 */
	public function find_by_key( string $key ): ?int {
		global $wpdb;
		if ( '' === $key ) {
			return null;
		}
		// A direct query, not get_posts(): some hosts filter post queries on anonymous
		// requests (pre_get_posts), which are what signed course-server requests are.
		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = '_lledger_stable_key' AND m.meta_value = %s AND p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft') ORDER BY p.ID ASC LIMIT 1",
				$key,
				self::POST_TYPE
			)
		);
		return null !== $id ? (int) $id : null;
	}

	/**
	 * Program that groups an LMS course.
	 *
	 * @param integer $course_id LMS course id.
	 *
	 * @return integer|null
	 */
	public function for_lms_course( int $course_id ): ?int {
		foreach ( $this->all_ids() as $id ) {
			if ( in_array( $course_id, $this->course_ids( $id ), true ) ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Program owning an activity IRI: the player program with that exact AU id or AU activity
	 * id, else the longest IRI base prefix.
	 *
	 * @param string $iri Activity IRI.
	 *
	 * @return integer|null
	 */
	public function for_activity( string $iri ): ?int {
		$player = $this->for_au( $iri );
		if ( null !== $player ) {
			return $player;
		}
		$best     = null;
		$best_len = 0;
		foreach ( $this->all_ids() as $id ) {
			$base = (string) get_post_meta( $id, '_lledger_iri_base', true );
			$len  = strlen( $base );
			if ( $len > $best_len && 0 === strpos( $iri, $base ) ) {
				$best     = $id;
				$best_len = $len;
			}
		}
		return $best;
	}

	/**
	 * Player program holding an AU whose id, or AU activity id `{au@id}/au`, is exactly the IRI.
	 *
	 * Only runs through the player programs when a lesson carries the AU id in `_lledger_au_id`.
	 *
	 * @param string $iri Activity IRI.
	 *
	 * @return integer|null
	 */
	private function for_au( string $iri ): ?int {
		global $wpdb;
		if ( '' === $iri ) {
			return null;
		}
		$ids = array( $iri );
		if ( str_ends_with( $iri, '/au' ) ) {
			$ids[] = substr( $iri, 0, -3 );
		}
		$hit = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_lledger_au_id' AND meta_value IN (%s, %s) ORDER BY meta_value = %s DESC LIMIT 1",
				$ids[0],
				$ids[1] ?? $ids[0],
				$iri
			)
		);
		if ( ! is_string( $hit ) || '' === $hit ) {
			return null;
		}
		foreach ( $this->all_ids() as $id ) {
			if ( ! (int) get_post_meta( $id, '_lledger_player', true ) ) {
				continue;
			}
			$structure = $this->structure( $id );
			if ( null !== $structure && null !== $structure->find_au( $hit ) ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Stored course structure.
	 *
	 * @param integer $id Program id.
	 *
	 * @return Course_Structure|null
	 */
	public function structure( int $id ): ?Course_Structure {
		$data = $this->json_array( $id, '_lledger_structure' );
		if ( ! $data ) {
			return null;
		}
		try {
			return Course_Structure::from_array( $data );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Stores a course structure.
	 *
	 * @param integer          $id Program id.
	 * @param Course_Structure $s  Structure.
	 *
	 * @return void
	 */
	public function set_structure( int $id, Course_Structure $s ): void {
		update_post_meta( $id, '_lledger_structure', wp_slash( (string) wp_json_encode( $s->to_array() ) ) );
	}

	/**
	 * Increments the version when the etag changed.
	 *
	 * @param integer $id   Program id.
	 * @param string  $etag New etag.
	 *
	 * @return integer Current version.
	 */
	public function bump_version( int $id, string $etag ): int {
		$version = (int) get_post_meta( $id, '_lledger_version', true );
		if ( (string) get_post_meta( $id, '_lledger_etag', true ) === $etag && $version > 0 ) {
			return $version;
		}
		++$version;
		update_post_meta( $id, '_lledger_version', $version );
		update_post_meta( $id, '_lledger_etag', $etag );
		return $version;
	}

	/**
	 * Every program post id.
	 *
	 * @return int[]
	 */
	public function all_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			)
		);
	}

	/**
	 * Ordered LMS course ids.
	 *
	 * @param integer $id Program id.
	 *
	 * @return int[]
	 */
	private function course_ids( int $id ): array {
		$raw = get_post_meta( $id, '_lledger_courses', true );
		return is_array( $raw ) ? array_values( array_map( 'intval', $raw ) ) : array();
	}

	/**
	 * Decodes a JSON meta value.
	 *
	 * @param integer $id  Post id.
	 * @param string  $key Meta key.
	 *
	 * @return array<mixed>
	 */
	private function json_array( int $id, string $key ): array {
		$decoded = json_decode( (string) get_post_meta( $id, $key, true ), true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
