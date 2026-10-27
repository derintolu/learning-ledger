<?php
/**
 * Course import routes.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Courses;

use LearningLedger\Cmi5\Course_Structure_Parser;
use LearningLedger\Lrs\Auth\Client_Route_Auth;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Package\Package_Validator;
use LearningLedger\Package\Package_Writer;
use LearningLedger\Util\Invalid_Input;

/**
 * `PUT /learning-ledger/v1/courses` (a course package ZIP or a plain cmi5.xml) and
 * `GET /learning-ledger/v1/courses?course=<iri>`, both for clients holding `courses/write`.
 */
final class Courses_Controller {

	const NAMESPACE = 'learning-ledger/v1';

	const ROUTE = '/courses';

	/**
	 * Constructor.
	 *
	 * @param Course_Repository $repo Repository.
	 */
	public function __construct( private readonly Course_Repository $repo = new Course_Repository() ) {}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'put' ),
					// Authenticated in the callback by Client_Route_Auth, so its 401/429 headers reach the client.
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'course' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Import a course.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function put( \WP_REST_Request $request ): \WP_REST_Response {
		$p = Client_Route_Auth::principal( $request, Scopes::COURSES_WRITE );
		if ( ! $p instanceof Principal ) {
			return self::error_response( $p );
		}

		$type = strtolower( (string) ( $request->get_content_type()['value'] ?? '' ) );
		$body = $request->get_body();
		if ( ! in_array( $type, array( 'application/zip', 'application/xml', 'text/xml' ), true ) ) {
			return self::error_response(
				new \WP_Error(
					'lledger_unsupported_media_type',
					__( 'Send a course package as application/zip or a cmi5.xml as application/xml.', 'learning-ledger' ),
					array( 'status' => 415 )
				)
			);
		}

		$tmp = null;
		try {
			if ( 'application/zip' === $type ) {
				$tmp = wp_tempnam( 'lledger-course' );
				if ( '' === $tmp || false === file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file read by ZipArchive.
					throw new \RuntimeException( 'Could not write the uploaded package to a temporary file.' );
				}
				$package = Package::from_zip( $tmp, new Limits() );
			} else {
				$package = Package::from_xml( $body );
			}
			return $this->import( $package, Package_Writer::etag( $body ), $p->client_id() );
		} catch ( Invalid_Input $e ) {
			return self::invalid(
				array(
					array(
						'code'    => $e->error_code(),
						'path'    => $e->path(),
						'message' => $e->getMessage(),
					),
				)
			);
		} catch ( \RuntimeException $e ) {
			return self::error_response(
				new \WP_Error( 'lledger_course_import_failed', __( 'The course could not be stored.', 'learning-ledger' ), array( 'status' => 500 ) )
			);
		} finally {
			if ( is_string( $tmp ) && '' !== $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * The stored version and ETag of a course.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get( \WP_REST_Request $request ): \WP_REST_Response {
		$p = Client_Route_Auth::principal( $request, Scopes::COURSES_WRITE );
		if ( ! $p instanceof Principal ) {
			return self::error_response( $p );
		}
		$record = $this->repo->get( (string) $request->get_param( 'course' ) );
		if ( null === $record ) {
			return self::error_response(
				new \WP_Error( 'lledger_course_not_found', __( 'No course with this IRI has been imported.', 'learning-ledger' ), array( 'status' => 404 ) )
			);
		}
		return self::no_store(
			new \WP_REST_Response(
				array(
					'course'  => $record->course_iri,
					'version' => $record->version,
					'etag'    => $record->etag,
				),
				200
			)
		);
	}

	/**
	 * Validate and store a package.
	 *
	 * @param Package $package      Package.
	 * @param string  $etag         ETag of the uploaded bytes.
	 * @param string  $owner_client Client id.
	 *
	 * @return \WP_REST_Response
	 *
	 * @throws Invalid_Input From the package reader or the repository (other than not_owner).
	 */
	private function import( Package $package, string $etag, string $owner_client ): \WP_REST_Response {
		$course_json = null;
		$flavour     = 'public';
		try {
			$course_json = $package->course_json();
			if ( 'authoring' === ( $course_json['flavour'] ?? null ) ) {
				$flavour = 'full';
			}
		} catch ( Invalid_Input $e ) {
			$course_json = null; // The validator reports the malformed course.json.
		}

		$result = Package_Validator::validate( $package, $flavour );
		if ( ! $result->ok() ) {
			return self::invalid( $result->errors() );
		}
		$structure = Course_Structure_Parser::parse( $package->cmi5_xml() );

		try {
			$stored = $this->repo->import( $structure, $course_json, $etag, $owner_client );
		} catch ( Invalid_Input $e ) {
			if ( 'not_owner' !== $e->error_code() ) {
				throw $e;
			}
			return self::error_response(
				new \WP_Error( 'lledger_forbidden', __( 'Only the client that imported this course may re-import it.', 'learning-ledger' ), array( 'status' => 403 ) )
			);
		}

		$status = $stored['created'] ? 201 : 200;
		unset( $stored['created'] );
		return self::no_store( new \WP_REST_Response( $stored, $status ) );
	}

	/**
	 * 422 with the validator's errors.
	 *
	 * @param array $errors Error rows (code, path, message).
	 *
	 * @return \WP_REST_Response
	 */
	private static function invalid( array $errors ): \WP_REST_Response {
		return self::error_response(
			new \WP_Error(
				'lledger_invalid_package',
				__( 'The course package is not valid.', 'learning-ledger' ),
				array(
					'status' => 422,
					'errors' => $errors,
				)
			)
		);
	}

	/**
	 * An error as a response, sending any headers in its data (WWW-Authenticate, Retry-After).
	 *
	 * @param \WP_Error $error Error.
	 *
	 * @return \WP_REST_Response
	 */
	private static function error_response( \WP_Error $error ): \WP_REST_Response {
		$data    = $error->get_error_data();
		$headers = is_array( $data ) && is_array( $data['headers'] ?? null ) ? $data['headers'] : array();
		if ( is_array( $data ) && isset( $data['headers'] ) ) {
			unset( $data['headers'] );
			$error = new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
		}
		$response = rest_convert_error_to_response( $error );
		foreach ( $headers as $name => $value ) {
			$response->header( (string) $name, (string) $value );
		}
		return self::no_store( $response );
	}

	/**
	 * Mark a response uncacheable.
	 *
	 * @param \WP_REST_Response $response Response.
	 *
	 * @return \WP_REST_Response
	 */
	private static function no_store( \WP_REST_Response $response ): \WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
