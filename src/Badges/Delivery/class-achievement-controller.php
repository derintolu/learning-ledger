<?php
/**
 * Public Achievement documents.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Delivery;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table; the response itself is cacheable.

use LearningLedger\Badges\Claim\Credential_Builder;
use LearningLedger\Badges\Pipeline\Badge_Evaluator;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Db\Tables;

/**
 * Serves `GET /learning-ledger/v1/achievements/{hash}`: the OB 3.0 Achievement of a badge
 * declaration, where `{hash}` is the hex SHA-1 of the achievement id (as `lledger_badges`
 * keys it). Public and cacheable for an hour.
 *
 * The document holds only the declaration's Achievement fields (never its award rules),
 * shaped as the credential embeds them. It is known once a badge for it has been issued;
 * a declaration with only pending or held badges is a 404.
 */
final class Achievement_Controller {

	/**
	 * Headers of an Achievement document.
	 */
	const HEADERS = array( 'Cache-Control' => 'public, max-age=3600' );

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'learning-ledger/v1',
			'/achievements/(?P<hash>[0-9a-f]{40})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Return an Achievement document.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$declaration = self::declaration( (string) $request['hash'] );
		if ( null === $declaration ) {
			return new \WP_Error( 'lledger_not_found', __( 'No such achievement.', 'learning-ledger' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( self::document( $declaration ), 200, self::HEADERS );
	}

	/**
	 * The Achievement fields of the latest issued badge for an achievement.
	 *
	 * @param string $hash Hex SHA-1 of the achievement id.
	 *
	 * @return array|null
	 */
	private static function declaration( string $hash ): ?array {
		global $wpdb;
		$table       = Tables::name( 'badges' );
		$json        = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT achievement FROM {$table} WHERE achievement_hash = UNHEX(%s) AND status IN (%s, %s) ORDER BY created_at DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name is interpolated.
				$hash,
				Badge_Repository::ISSUED,
				Badge_Repository::REVOKED
			)
		);
		$declaration = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $declaration ) || ! is_string( $declaration['id'] ?? null ) || ! hash_equals( $hash, sha1( $declaration['id'] ) ) ) {
			return null;
		}
		return array_intersect_key( $declaration, array_flip( Badge_Evaluator::ACHIEVEMENT_FIELDS ) );
	}

	/**
	 * The OB 3.0 Achievement document of a declaration, exactly as the issued credential embeds it.
	 *
	 * @param array $d Declaration's Achievement fields.
	 *
	 * @return array
	 */
	private static function document( array $d ): array {
		return array( '@context' => Credential_Builder::CONTEXT ) + Credential_Builder::achievement( $d );
	}
}
