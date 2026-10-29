<?php
/**
 * Course-server certificate route.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\CourseServer;

use LearningLedger\Connector\Lms\Certificate_Provider;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\LMS_Adapter;
use LearningLedger\Integrations\Sensei\Sensei_Certificate_Provider;
use LearningLedger\Kernel\Service_Context;

/**
 * `GET learning-ledger/v1/course-server/learners/{id}/certificates/{course}`: the learner's
 * certificate PDF for a course. Signed requests only, capability `read_certificates`.
 */
final class Certificates_Route {

	const NS = 'learning-ledger/v1';

	const ROUTE = '/course-server/learners/(?P<id>\d+)/certificates/(?P<course>\d+)';

	/**
	 * Integration id => certificate provider class: static `available(): bool` and
	 * `certificate( int $user, int $course ): string|\WP_Error|null`.
	 */
	const PROVIDERS = array(
		'sensei' => Sensei_Certificate_Provider::class,
	);

	/**
	 * Request auth.
	 *
	 * @var Signed_Request_Auth
	 */
	private Signed_Request_Auth $auth;

	/**
	 * Maps an LMS course id to its adapter, or null.
	 *
	 * @var callable
	 */
	private $adapter_for;

	/**
	 * Constructor.
	 *
	 * @param Signed_Request_Auth|null $auth        Request auth.
	 * @param callable|null            $adapter_for Course id => ?LMS_Adapter; defaults to Integration_Loader::for_post().
	 */
	public function __construct( ?Signed_Request_Auth $auth = null, ?callable $adapter_for = null ) {
		$this->auth        = $auth ?? new Signed_Request_Auth();
		$this->adapter_for = $adapter_for ?? array( Integration_Loader::class, 'for_post' );
	}

	/**
	 * Register the route (called inside rest_api_init) and the raw PDF serving.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'certificate' ),
				// Authenticated in the callback: signed requests only.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'     => array(
						'type'     => 'integer',
						'required' => true,
					),
					'course' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
		if ( false === has_filter( 'rest_pre_serve_request', array( self::class, 'serve' ) ) ) {
			add_filter( 'rest_pre_serve_request', array( self::class, 'serve' ), 10, 3 );
		}
	}

	/**
	 * GET learners/{id}/certificates/{course}: 200 application/pdf, 404 when the learner has no
	 * certificate, 501 `certificate_unavailable` when the course's LMS cannot render one.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function certificate( \WP_REST_Request $r ): \WP_REST_Response {
		$auth = $this->auth->authorize( $r, Trusted_Keys::READ_CERTIFICATES );
		if ( is_wp_error( $auth ) ) {
			return Signed_Request_Auth::to_response( $auth );
		}
		// Authorised: the rest runs in the service context, never before the signature is checked.
		return Service_Context::run( fn(): \WP_REST_Response => $this->certificate_authorized( $r ) );
	}

	/**
	 * The authorised part of certificate().
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response
	 */
	private function certificate_authorized( \WP_REST_Request $r ): \WP_REST_Response {
		$user   = (int) $r['id'];
		$course = (int) $r['course'];
		if ( $user <= 0 || ! get_userdata( $user ) ) {
			return self::error( 'lledger_unknown_learner', 'No such learner.', 404 );
		}
		$adapter = call_user_func( $this->adapter_for, $course );
		if ( ! $adapter instanceof LMS_Adapter ) {
			return self::error( 'lledger_unknown_course', 'No active LMS integration handles this course.', 404 );
		}
		$provider = self::provider( $adapter->id() );
		if ( null === $provider ) {
			return self::error( 'certificate_unavailable', 'Certificates are not available for this course.', 501 );
		}
		// Called directly rather than through the guarded adapter, so that a certificate that
		// cannot be drawn is a 500 for this request and does not pause the integration.
		$pdf = $provider->certificate( $user, $course );
		if ( is_wp_error( $pdf ) ) {
			return self::error( 'certificate_render_failed', 'The certificate could not be drawn.', 500 );
		}
		if ( ! is_string( $pdf ) ) {
			return self::error( 'lledger_no_certificate', 'The learner has no certificate for this course.', 404 );
		}
		$res = new \WP_REST_Response( $pdf, 200 );
		$res->header( 'Content-Type', 'application/pdf' );
		$res->header( 'Cache-Control', 'private, no-store' );
		return $res;
	}

	/**
	 * Serve this route's PDF as raw bytes instead of JSON.
	 *
	 * @param mixed            $served  Whether the request was already served.
	 * @param mixed            $result  Response.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public static function serve( mixed $served, mixed $result, \WP_REST_Request $request ): mixed {
		if ( $served || ! $result instanceof \WP_HTTP_Response || 200 !== $result->get_status() ) {
			return $served;
		}
		$route = '#^/' . preg_quote( self::NS, '#' ) . self::ROUTE . '$#';
		$data  = $result->get_data();
		if ( 1 !== preg_match( $route, untrailingslashit( $request->get_route() ) )
			|| 'application/pdf' !== ( $result->get_headers()['Content-Type'] ?? null ) || ! is_string( $data ) ) {
			return $served;
		}
		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw PDF bytes, served as application/pdf.
		return true;
	}

	/**
	 * The integration's certificate provider when it can render certificates right now.
	 *
	 * @param string $integration Integration id.
	 *
	 * @return Certificate_Provider|null
	 */
	private static function provider( string $integration ): ?Certificate_Provider {
		$class = self::PROVIDERS[ $integration ] ?? null;
		if ( null === $class || ! class_exists( $class ) || ! $class::available() ) {
			return null;
		}
		$provider = new $class();
		return $provider instanceof Certificate_Provider && method_exists( $provider, 'certificate' ) ? $provider : null;
	}

	/**
	 * Error response.
	 *
	 * @param string  $code    Code.
	 * @param string  $message Message.
	 * @param integer $status  HTTP status.
	 *
	 * @return \WP_REST_Response
	 */
	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array( 'status' => $status ),
			),
			$status
		);
	}
}
