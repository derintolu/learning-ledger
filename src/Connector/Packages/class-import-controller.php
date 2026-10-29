<?php
/**
 * REST endpoint that imports a course package.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Util\Invalid_Input;

/**
 * POST /learning-ledger/v1/programs/import.
 */
final class Import_Controller {

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
			'/programs/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'url'     => array(
						'type'     => 'string',
						'required' => false,
					),
					'adapter' => array(
						'type'              => 'string',
						'default'           => 'sensei',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Only the connector manager may import.
	 *
	 * @return true|\WP_Error
	 */
	public function authorize() {
		if ( current_user_can( Program_Module::CAP ) ) {
			return true;
		}
		return new \WP_Error(
			'lledger_import_forbidden',
			__( 'You are not allowed to import programs.', 'learning-ledger' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * Import from a URL or an uploaded ZIP.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param boolean|null     $trusted Whether the caller is a trusted import path; null means
	 *                                  the current user can post unfiltered HTML.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request, ?bool $trusted = null ): \WP_REST_Response {
		$trusted = $trusted ?? current_user_can( 'unfiltered_html' );
		$limits  = new Limits();
		$adapter = (string) $request->get_param( 'adapter' );
		$adapter = '' === $adapter ? 'sensei' : $adapter;
		$path    = '';
		$own     = false;

		try {
			$files = $request->get_file_params();
			if ( isset( $files['file']['tmp_name'] ) && is_string( $files['file']['tmp_name'] ) ) {
				$upload = $files['file'];
				if ( UPLOAD_ERR_OK !== (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE ) || ! ( is_uploaded_file( $upload['tmp_name'] ) || ( defined( 'LLEDGER_TESTING' ) && LLEDGER_TESTING ) ) ) {
					throw new Invalid_Input( 'The upload failed.', 'upload_failed' );
				}
				$path = $upload['tmp_name'];
			} else {
				$url = $request->get_param( 'url' );
				if ( ! is_string( $url ) || '' === $url ) {
					throw new Invalid_Input( 'Send a url or upload a ZIP file.', 'missing_package' );
				}
				$path = Package_Fetcher::fetch( esc_url_raw( $url ), $limits );
				$own  = true;
			}
			$report = ( new Package_Importer() )->import( Package::from_zip( $path, $limits ), $adapter, $trusted );
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
