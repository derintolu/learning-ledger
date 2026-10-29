<?php
/**
 * Course-server module: signed routes, nonce pruning and the trust-key commands.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\CourseServer;

use LearningLedger\Kernel\Module;
use LearningLedger\Kernel\Status_Route;
use LearningLedger\Integrations\Sensei\Sensei_Storage;
use LearningLedger\Kernel\Service_Context;

/**
 * Registers the `learning-ledger/v1/course-server/` routes. They accept only Ed25519-signed requests.
 */
final class Course_Server_Module implements Module {

	const NS = 'learning-ledger/v1';

	const PRUNE_EVENT = 'lledger_course_server_prune_nonces';

	/**
	 * Course id => ?LMS_Adapter, for the learner routes; null uses the default.
	 *
	 * @var callable|null
	 */
	private $adapter_for;

	/**
	 * Constructor.
	 *
	 * @param callable|null $adapter_for Course id => ?LMS_Adapter; defaults to Integration_Loader::for_post().
	 */
	public function __construct( ?callable $adapter_for = null ) {
		$this->adapter_for = $adapter_for;
	}

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::PRUNE_EVENT, array( $this, 'prune_nonces' ) );
		add_action( 'learning_ledger/jobs/' . Learners_Controller::ERASE_JOB, array( Learners_Controller::class, 'erase_job' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			$command = new Trust_Key_Command();
			\WP_CLI::add_command( 'learning-ledger connector trust-key', array( $command, 'trust_key' ) );
			\WP_CLI::add_command( 'learning-ledger connector revoke-key', array( $command, 'revoke_key' ) );
		}
	}

	/**
	 * Register the routes, plus the package and certificate routes when those classes exist.
	 *
	 * @return void
	 */
	public function routes(): void {
		( new Learners_Controller( new Signed_Request_Auth(), $this->adapter_for ) )->register();
		register_rest_route(
			self::NS,
			'/course-server/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				// Authenticated in the callback: signed requests only.
				'permission_callback' => '__return_true',
			)
		);
		foreach ( array( __NAMESPACE__ . '\Packages_Route', __NAMESPACE__ . '\Certificates_Route' ) as $class ) {
			if ( class_exists( $class ) ) {
				$route = new $class();
				if ( method_exists( $route, 'register' ) ) {
					$route->register();
				}
			}
		}
	}

	/**
	 * GET course-server/status: the privileged status payload, including the connector secret hash.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function status( \WP_REST_Request $r ): \WP_REST_Response {
		$auth = ( new Signed_Request_Auth() )->authorize( $r, Signed_Request_Auth::ANY );
		if ( is_wp_error( $auth ) ) {
			return Signed_Request_Auth::to_response( $auth );
		}
		$payload = Status_Route::payload( true );
		// What this host's query filters do, for course servers diagnosing a silent host.
		$payload['host_queries'] = Service_Context::host_queries();
		$sensei                  = Sensei_Storage::status();
		// Where Sensei reads lesson progress from (comments or tables), and whether its progress tables exist.
		$payload['sensei_progress_storage']   = $sensei['storage'];
		$payload['sensei_progress_tables']    = $sensei['tables'];
		$payload['sensei_progress_migration'] = $sensei['migration'];
		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Cron callback: drop nonces older than Signed_Request::NONCE_TTL.
	 *
	 * @return void
	 */
	public function prune_nonces(): void {
		Db_Nonce_Store::prune();
	}

	/**
	 * Schedule the hourly nonce prune.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( false === wp_next_scheduled( self::PRUNE_EVENT ) ) {
			wp_schedule_event( time(), 'hourly', self::PRUNE_EVENT );
		}
	}
}
