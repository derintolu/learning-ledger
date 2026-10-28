<?php
/**
 * REST endpoint that exports a program as a course package.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Util\Invalid_Input;

/**
 * GET /learning-ledger/v1/programs/{id}/package?flavour=full|public.
 *
 * The response helpers are shared with the signed course-server package route.
 */
final class Export_Controller {

	const NS = 'learning-ledger/v1';

	/**
	 * Routes whose string bodies are raw ZIP bytes, not JSON.
	 */
	private const BINARY_ROUTES = '#^/learning-ledger/v1/(programs/[0-9]+/package|course-server/packages/[^/]+)$#';

	/**
	 * Hook the route and the raw serving of ZIP bytes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		self::hook_serving();
	}

	/**
	 * Send ZIP bodies as bytes instead of JSON. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function hook_serving(): void {
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve' ), 10, 3 );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/programs/(?P<id>\d+)/package',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'id'      => array(
						'type'     => 'integer',
						'required' => true,
					),
					'flavour' => array(
						'type'    => 'string',
						'enum'    => array( 'full', 'public' ),
						'default' => 'full',
					),
				),
			)
		);
	}

	/**
	 * Only the connector manager may export, and a cookie session needs the REST nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error
	 */
	public function authorize( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'lledger_unauthenticated', __( 'Authentication is required.', 'learning-ledger' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( Program_Module::CAP ) ) {
			return new \WP_Error( 'lledger_export_forbidden', __( 'You are not allowed to export programs.', 'learning-ledger' ), array( 'status' => 403 ) );
		}
		if ( null === rest_get_authenticated_app_password() ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! is_string( $nonce ) || '' === $nonce || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new \WP_Error( 'lledger_bad_nonce', __( 'The REST nonce is missing or invalid.', 'learning-ledger' ), array( 'status' => 403 ) );
			}
		}
		return true;
	}

	/**
	 * Build and return the package.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$id      = (int) $request->get_param( 'id' );
		$flavour = (string) $request->get_param( 'flavour' );
		try {
			$built = ( new Package_Builder() )->build( $id, '' === $flavour ? 'full' : $flavour );
		} catch ( Invalid_Input $e ) {
			return self::error_response( $e );
		}
		return self::package_response( $built, $request, $id );
	}

	/**
	 * The program id for an id or a stable key.
	 *
	 * @param string $ref Digits for a post id, otherwise a stable key.
	 *
	 * @return integer|null
	 */
	public static function program_id( string $ref ): ?int {
		$repo = new Program_Repository();
		if ( 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $ref ) && null !== $repo->get( (int) $ref ) ) {
			return (int) $ref;
		}
		return $repo->find_by_key( Program_Module::sanitize_stable_key( $ref ) );
	}

	/**
	 * The ZIP response, or 304 when the request already holds this ETag.
	 *
	 * @param Built_Package    $built      Built package.
	 * @param \WP_REST_Request $request    Request.
	 * @param integer          $program_id Program post id, for the file name.
	 *
	 * @return \WP_REST_Response
	 */
	public static function package_response( Built_Package $built, \WP_REST_Request $request, int $program_id ): \WP_REST_Response {
		$headers = array(
			'ETag'              => $built->etag,
			'X-Package-Version' => (string) $built->version,
			'Cache-Control'     => 'private, no-cache',
		);
		if ( self::etag_matches( (string) $request->get_header( 'If-None-Match' ), $built->etag ) ) {
			$res = new \WP_REST_Response( null, 304 );
			$res->set_headers( $headers );
			return $res;
		}

		$key  = (string) get_post_meta( $program_id, '_lledger_stable_key', true );
		$name = sanitize_file_name( ( '' !== $key ? $key : 'program-' . $program_id ) . '-' . $built->flavour . '.zip' );
		$res  = new \WP_REST_Response( $built->zip, 200 );
		$res->set_headers(
			array_merge(
				$headers,
				array(
					'Content-Type'        => 'application/zip',
					'Content-Disposition' => 'attachment; filename="' . $name . '"',
					'Content-Length'      => (string) strlen( $built->zip ),
				)
			)
		);
		return $res;
	}

	/**
	 * A builder failure as a JSON error response.
	 *
	 * @param Invalid_Input $e Builder error.
	 *
	 * @return \WP_REST_Response
	 */
	public static function error_response( Invalid_Input $e ): \WP_REST_Response {
		$status = match ( $e->error_code() ) {
			'not_found'   => 404,
			'bad_flavour' => 400,
			'validation'  => 422,
			default       => 409,
		};
		return new \WP_REST_Response(
			array(
				'code'    => 'lledger_package_' . $e->error_code(),
				'message' => $e->getMessage(),
				'data'    => array(
					'status' => $status,
					'path'   => $e->path(),
				),
			),
			$status
		);
	}

	/**
	 * Writes ZIP bytes (and nothing for a 304) straight to the output; everything else stays JSON.
	 *
	 * @param mixed             $served  Whether a filter already served the request.
	 * @param \WP_HTTP_Response $result  Result.
	 * @param \WP_REST_Request  $request Request.
	 *
	 * @return mixed
	 */
	public static function serve( mixed $served, \WP_HTTP_Response $result, \WP_REST_Request $request ): mixed {
		if ( $served ) {
			return $served;
		}
		if ( 1 !== preg_match( self::BINARY_ROUTES, $request->get_route() ) ) {
			return $served;
		}
		$data = $result->get_data();
		if ( 304 === $result->get_status() ) {
			return true;
		}
		if ( 'application/zip' !== ( $result->get_headers()['Content-Type'] ?? '' ) || ! is_string( $data ) ) {
			return $served;
		}
		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ZIP bytes.
		return true;
	}

	/**
	 * Whether an If-None-Match header names the ETag (weak validators and lists included).
	 *
	 * @param string $header If-None-Match value.
	 * @param string $etag   Current strong ETag.
	 *
	 * @return boolean
	 */
	private static function etag_matches( string $header, string $etag ): bool {
		if ( '' === trim( $header ) ) {
			return false;
		}
		if ( '*' === trim( $header ) ) {
			return true;
		}
		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( str_starts_with( $candidate, 'W/' ) ) {
				$candidate = substr( $candidate, 2 );
			}
			if ( hash_equals( $etag, $candidate ) ) {
				return true;
			}
		}
		return false;
	}
}
