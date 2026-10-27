<?php
/**
 * REST endpoint that imports a cmi5 package for the player.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Import;

use LearningLedger\Connector\Packages\Import_Report;
use LearningLedger\Connector\Packages\Package_Fetcher;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Package\Limits;
use LearningLedger\Util\Invalid_Input;

/**
 * POST /learning-ledger/v1/cmi5/import, with `{url}` or an uploaded `file`.
 */
final class Player_Import_Controller {

	/**
	 * Hook the route.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'learning-ledger/v1',
			'/cmi5/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'url' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * A signed-in connector manager. Under cookie authentication the request must carry a
	 * valid `wp_rest` nonce; an Application Password needs none.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error 401 when signed out, 403 otherwise.
	 */
	public function authorize( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'lledger_unauthenticated', __( 'Authentication is required.', 'learning-ledger' ), array( 'status' => 401 ) );
		}
		if ( null === rest_get_authenticated_app_password() ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! is_string( $nonce ) || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new \WP_Error( 'lledger_bad_nonce', __( 'The REST nonce is missing or invalid.', 'learning-ledger' ), array( 'status' => 403 ) );
			}
		}
		if ( ! current_user_can( Program_Module::CAP ) ) {
			return new \WP_Error( 'lledger_import_forbidden', __( 'You are not allowed to import cmi5 packages.', 'learning-ledger' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Import from a URL or an uploaded ZIP or cmi5.xml.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$path = '';
		$own  = false;
		$url  = '';
		try {
			$files = $request->get_file_params();
			if ( isset( $files['file']['tmp_name'] ) && is_string( $files['file']['tmp_name'] ) ) {
				$upload = $files['file'];
				if ( UPLOAD_ERR_OK !== (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE ) || ! ( is_uploaded_file( $upload['tmp_name'] ) || ( defined( 'LLEDGER_TESTING' ) && LLEDGER_TESTING ) ) ) {
					throw new Invalid_Input( 'The upload failed.', 'upload_failed' );
				}
				$path = $upload['tmp_name'];
			} else {
				$param = $request->get_param( 'url' );
				if ( ! is_string( $param ) || '' === $param ) {
					throw new Invalid_Input( 'Send a url or upload a package.', 'missing_package' );
				}
				$url  = esc_url_raw( $param, array( 'http', 'https' ) );
				$path = Package_Fetcher::fetch( $url, new Limits() );
				$own  = true;
			}
			$report = ( new Player_Importer() )->import( $path, $url );
		} catch ( Invalid_Input $e ) {
			$report = Import_Report::failed(
				array(
					array(
						'code'    => $e->error_code(),
						'path'    => $e->path(),
						'message' => $e->getMessage(),
					),
				)
			);
		} finally {
			if ( $own && '' !== $path ) {
				wp_delete_file( $path );
			}
		}

		return new \WP_REST_Response( $report->to_array(), $report->ok() ? 200 : 422 );
	}
}
