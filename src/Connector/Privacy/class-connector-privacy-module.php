<?php
/**
 * Connector privacy module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Privacy;

use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Identity\Link_Code_Controller;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Pairing\Lrs_Client;
use LearningLedger\Kernel\Module;

/**
 * Registers the WordPress personal-data exporter and eraser, and the one route behind the
 * "My learning record" block: a learner asks for a view link to their own record.
 */
final class Connector_Privacy_Module implements Module {

	const NS    = 'learning-ledger/v1';
	const ROUTE = '/my-record/view-link';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Add the exporter.
	 *
	 * @param array $exporters Registered exporters.
	 *
	 * @return array
	 */
	public function add_exporter( array $exporters ): array {
		$exporters['learning-ledger'] = array(
			'exporter_friendly_name' => __( 'Learning Ledger', 'learning-ledger' ),
			'callback'               => array( new Connector_Exporter(), 'export' ),
		);
		return $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param array $erasers Registered erasers.
	 *
	 * @return array
	 */
	public function add_eraser( array $erasers ): array {
		$erasers['learning-ledger'] = array(
			'eraser_friendly_name' => __( 'Learning Ledger', 'learning-ledger' ),
			'callback'             => array( new Connector_Eraser(), 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Register the view-link route.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'view_link' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	/**
	 * Logged-in user with a valid REST nonce (unless an Application Password authenticated the
	 * request): the same check as the record-linking route.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error
	 */
	public function permission( \WP_REST_Request $request ) {
		return ( new Link_Code_Controller() )->permission( $request );
	}

	/**
	 * Ask the LRS for a view link to the signed-in user's own record. The actor is never taken
	 * from the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function view_link( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the signature is fixed by REST.
		$user = get_current_user_id();
		if ( '' === (string) get_user_meta( $user, Identity_Map::META, true ) ) {
			return self::error( 404, 'no_record', 'You have no record to view yet.' );
		}
		$actor = ( new Identity_Map() )->actor_for_user( $user );

		try {
			$res = ( new Lrs_Client() )->api( 'POST', 'view-links', array( 'actor' => $actor ) );
		} catch ( \RuntimeException $e ) {
			return self::unavailable();
		}
		$data = $res->status >= 200 && $res->status < 300 ? $res->json() : null;
		$url  = is_array( $data ) && is_string( $data['url'] ?? null ) ? esc_url_raw( $data['url'], array( 'http', 'https' ) ) : '';
		if ( '' === $url || ! self::is_lrs_url( $url ) ) {
			return self::unavailable();
		}
		return new \WP_REST_Response( array( 'url' => $url ), 200 );
	}

	/**
	 * Whether a URL is on the paired LRS's host.
	 *
	 * @param string $url URL.
	 *
	 * @return boolean
	 */
	private static function is_lrs_url( string $url ): bool {
		$host     = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$settings = Connector_Settings::get();
		foreach ( array( $settings['api_base'], $settings['lrs_url'] ) as $base ) {
			if ( '' !== $base && strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) ) === $host ) {
				return '' !== $host;
			}
		}
		return false;
	}

	/**
	 * The one answer when the LRS cannot give a usable link.
	 *
	 * @return \WP_REST_Response
	 */
	private static function unavailable(): \WP_REST_Response {
		return self::error( 502, 'lrs_unavailable', 'Your record could not be opened. Try again later.' );
	}

	/**
	 * Error response.
	 *
	 * @param integer $status  HTTP status.
	 * @param string  $code    Reason code.
	 * @param string  $message Message.
	 *
	 * @return \WP_REST_Response
	 */
	private static function error( int $status, string $code, string $message ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
