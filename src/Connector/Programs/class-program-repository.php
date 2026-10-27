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
		foreach ( (array) get_post_meta( $id, '_lledger_path_keys', true ) as $cid => $key ) {
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
		if ( '' === $key ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_lledger_stable_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		);
		return $ids ? (int) $ids[0] : null;
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
	 * Program owning an activity IRI (longest IRI base prefix wins).
	 *
	 * @param string $iri Activity IRI.
	 *
	 * @return integer|null
	 */
	public function for_activity( string $iri ): ?int {
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
		return array_values( array_map( 'intval', (array) get_post_meta( $id, '_lledger_courses', true ) ) );
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
