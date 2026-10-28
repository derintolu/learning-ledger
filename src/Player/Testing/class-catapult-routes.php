<?php
/**
 * Test-only routes for the CATAPULT cmi5 LMS Test Suite adapter.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Testing;

use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Crypto\Tokens;
use LearningLedger\Kernel\Module;
use LearningLedger\Kernel\Roles;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Client_Spec;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Player\Import\Player_Importer;
use LearningLedger\Player\Launch\Launch_Controller;
use LearningLedger\Player\Session\Abandoner;
use LearningLedger\Player\Session\Player_Evaluator;
use LearningLedger\Player\Session\Waive_Controller;
use LearningLedger\Player\Store\Au_Store;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Uuid;

/**
 * `learning-ledger/v1/testing/catapult/{setup,import,launch-url,waive,abandon,cleanup}`, the LMS
 * automation surface the CATAPULT LTS adapter (tests/catapult/adapter.js) drives [cmi5 LTS].
 *
 * Registered only when `LLEDGER_CATAPULT_TEST_ROUTES` is true, and every request must carry the
 * header `X-Learning-Ledger-Test` equal to `LLEDGER_CATAPULT_TEST_SECRET` (compared in constant
 * time; an empty or short secret never authorises). Never enable these on a real site: they
 * create users, pair the connector with this site's own LRS and launch without a nonce.
 */
final class Catapult_Routes implements Module {

	const NS     = 'learning-ledger/v1';
	const BASE   = '/testing/catapult';
	const HEADER = 'X-Learning-Ledger-Test';

	/**
	 * Shortest secret that authorises.
	 */
	const MIN_SECRET = 16;

	/**
	 * The LRS client id the connector uses when setup pairs it with this site.
	 */
	const CLIENT_ID = 'catapult-connector';

	/**
	 * Login prefix of the test learners; each setup creates a new one, so no run sees another's
	 * registrations, sessions or documents.
	 */
	const LEARNER = 'catapult-learner-';

	/**
	 * Seconds the import waits for the package's files to be extracted.
	 */
	const EXTRACT_SECONDS = 120;

	/**
	 * Whether the routes are on.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * The shared secret.
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * Directory holding the built LTS packages.
	 *
	 * @var string
	 */
	private string $packages;

	/**
	 * Constructor; the defaults read the constants.
	 *
	 * @param boolean|null $enabled  Whether the routes are on.
	 * @param string|null  $secret   The shared secret.
	 * @param string|null  $packages Directory of the built LTS packages.
	 */
	public function __construct( ?bool $enabled = null, ?string $secret = null, ?string $packages = null ) {
		$this->enabled  = $enabled ?? ( defined( 'LLEDGER_CATAPULT_TEST_ROUTES' ) && true === constant( 'LLEDGER_CATAPULT_TEST_ROUTES' ) );
		$this->secret   = $secret ?? ( defined( 'LLEDGER_CATAPULT_TEST_SECRET' ) && is_string( constant( 'LLEDGER_CATAPULT_TEST_SECRET' ) ) ? (string) constant( 'LLEDGER_CATAPULT_TEST_SECRET' ) : '' );
		$this->packages = trailingslashit( $packages ?? LEARNING_LEDGER_DIR . '.cache/catapult/lts/pkg/dist' );
	}

	/**
	 * Hook the routes when they are on.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->enabled ) {
			add_action( 'rest_api_init', array( $this, 'routes' ) );
		}
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function routes(): void {
		$routes = array(
			'setup'      => 'setup',
			'import'     => 'import',
			'launch-url' => 'launch_url',
			'waive'      => 'waive',
			'abandon'    => 'abandon',
			'cleanup'    => 'cleanup',
		);
		foreach ( $routes as $path => $method ) {
			register_rest_route(
				self::NS,
				self::BASE . '/' . $path,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $method ),
					'permission_callback' => array( $this, 'permission' ),
				)
			);
		}
	}

	/**
	 * The test header must equal the secret.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error
	 */
	public function permission( \WP_REST_Request $request ) {
		$given = $request->get_header( self::HEADER );
		if ( strlen( $this->secret ) < self::MIN_SECRET || ! is_string( $given ) || ! hash_equals( $this->secret, $given ) ) {
			return new \WP_Error( 'lledger_forbidden', 'Forbidden.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Pair the connector with this site's own LRS (both roles must be on, and Sensei active) and
	 * create a new test learner.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function setup(): \WP_REST_Response|\WP_Error {
		if ( ! Roles::is_enabled( 'lrs' ) || ! Roles::is_enabled( 'connector' ) ) {
			return self::error( 'lledger_roles', 'Enable the lrs and connector roles first.', 409 );
		}
		if ( null === Integration_Loader::adapter( 'sensei' ) ) {
			return self::error( 'lledger_unavailable', 'The Sensei integration is not available.', 409 );
		}

		try {
			Connector_Secret::generate( true );
		} catch ( \RuntimeException $e ) {
			return self::error( 'lledger_secret', 'The connector secret could not be stored.', 500 );
		}
		Connector_Settings::update(
			array(
				'lrs_url'      => home_url( '/wp-json/learning-ledger/xapi/' ),
				'loopback_url' => '',
				'api_base'     => home_url( '/wp-json/learning-ledger/v1/' ),
				'client_id'    => self::CLIENT_ID,
			)
		);
		$hash    = Tokens::hash( (string) Connector_Secret::get() );
		$clients = new Client_Repository();
		$scopes  = array( Scopes::TOKENS_MINT, Scopes::VOID, Scopes::STATE, Scopes::PROFILE, Scopes::STATEMENTS_WRITE, Scopes::STATEMENTS_READ );
		try {
			if ( null === $clients->find( self::CLIENT_ID ) ) {
				$clients->create( new Client_Spec( self::CLIENT_ID, 'CATAPULT connector', $hash, $scopes, array(), array(), false, 'unlimited', true ) );
			} else {
				$clients->update(
					self::CLIENT_ID,
					array(
						'secret_sha256_raw' => $hash,
						'scopes'            => $scopes,
						'rate_class'        => 'unlimited',
					)
				);
			}
		} catch ( Invalid_Input $e ) {
			return self::error( 'lledger_client', $e->getMessage(), 500 );
		}

		$user = self::new_learner();
		if ( 0 === $user ) {
			return self::error( 'lledger_user', 'The test learner could not be created.', 500 );
		}
		return new \WP_REST_Response( array( 'user' => $user ), 200 );
	}

	/**
	 * `{package, user}`: import a built LTS package (a file name in the package directory) with
	 * the player importer, wait for its files, and enrol the test learner in its courses. A
	 * package the importer refuses is a 422 with its errors.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$name = (string) $request->get_param( 'package' );
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name ) || str_contains( $name, '..' ) ) {
			return self::error( 'lledger_invalid', 'Name a package file in the LTS package directory.', 400 );
		}
		$path = $this->packages . $name;
		if ( ! is_file( $path ) ) {
			return self::error( 'lledger_not_found', 'No such package.', 404 );
		}

		$report = ( new Player_Importer() )->import( $path );
		if ( ! $report->ok() ) {
			return new \WP_REST_Response( $report->to_array(), 422 );
		}
		$program = $report->program_id;
		if ( ! self::extracted( $program ) ) {
			return self::error( 'lledger_extract', 'The package files were not extracted in time.', 500 );
		}

		$adapter = Integration_Loader::adapter( 'sensei' );
		$user    = (int) $request->get_param( 'user' );
		$record  = ( new Program_Repository() )->get( $program );
		foreach ( null === $record || null === $adapter || ! self::is_learner( $user ) ? array() : $record->course_ids as $course ) {
			$adapter->enrol( $user, $course );
		}
		return new \WP_REST_Response( array( 'course' => $program ), 200 );
	}

	/**
	 * `{course, au, user, registration?, launchMode?}`: the launch URL for the AU at an index
	 * (document order, from 0), through the launch code path without its nonce and mode checks.
	 * A registration other than the one the launch uses is a 409.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function launch_url( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$lesson = self::lesson( (int) $request->get_param( 'course' ), (int) $request->get_param( 'au' ) );
		if ( 0 === $lesson ) {
			return self::error( 'lledger_not_found', 'No such AU.', 404 );
		}
		$mode   = (string) ( $request->get_param( 'launchMode' ) ?? '' );
		$result = ( new Launch_Controller() )->start( (int) $request->get_param( 'user' ), $lesson, '' === $mode ? 'Normal' : $mode, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$wanted = (string) ( $request->get_param( 'registration' ) ?? '' );
		$query  = array();
		wp_parse_str( (string) wp_parse_url( $result['launch_url'], PHP_URL_QUERY ), $query );
		if ( '' !== $wanted && strtolower( $wanted ) !== strtolower( (string) ( $query['registration'] ?? '' ) ) ) {
			return self::error( 'lledger_registration', 'The launch used another registration.', 409 );
		}
		return new \WP_REST_Response( array( 'url' => $result['launch_url'] ), 200 );
	}

	/**
	 * `{registration, au, reason}`: waive the AU at an index for the registration's learner,
	 * through the waive route's handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function waive( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$registration = (string) $request->get_param( 'registration' );
		$map          = new Registration_Map();
		$scope        = Uuid::is_valid( $registration ) ? (string) $map->scope_for( $registration ) : '';
		if ( 1 !== preg_match( '/^cmi5:(\d+)$/', $scope, $m ) ) {
			return self::error( 'lledger_not_found', 'Unknown cmi5 registration.', 404 );
		}
		$lesson = self::lesson( (int) $m[1], (int) $request->get_param( 'au' ) );
		if ( 0 === $lesson ) {
			return self::error( 'lledger_not_found', 'No such AU.', 404 );
		}
		$inner = new \WP_REST_Request( 'POST', '/' . Waive_Controller::NS . Waive_Controller::ROUTE );
		$inner->set_param( 'user', (int) $map->user_for( $registration ) );
		$inner->set_param( 'lesson', $lesson );
		$inner->set_param( 'reason', (string) $request->get_param( 'reason' ) );
		if ( ! in_array( $inner->get_param( 'reason' ), Waive_Controller::REASONS, true ) ) {
			return self::error( 'lledger_invalid', 'Unknown waiver reason.', 400 );
		}
		return ( new Waive_Controller( new Player_Evaluator() ) )->handle( $inner );
	}

	/**
	 * `{session}`: abandon an open session now, as the abandoner does once its token expires.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function abandon( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$session = (string) $request->get_param( 'session' );
		if ( ! Uuid::is_valid( $session ) ) {
			return self::error( 'lledger_invalid', 'Send a session id.', 400 );
		}
		try {
			$closed = ( new Abandoner() )->close_now( $session );
		} catch ( \RuntimeException $e ) {
			return self::error( 'lledger_lrs_unavailable', 'The LRS could not be reached.', 502 );
		}
		if ( null === $closed ) {
			return self::error( 'lledger_not_found', 'No open session with this id.', 404 );
		}
		return new \WP_REST_Response( array( 'abandoned' => $closed ), 200 );
	}

	/**
	 * `{courses: int[]}`: delete imported player programs with their Sensei courses, lessons and
	 * stored files, so the next import of the same package starts afresh.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function cleanup( \WP_REST_Request $request ): \WP_REST_Response {
		// The adapter stops waiting after a few seconds (the suite's hook limit); finish anyway.
		ignore_user_abort( true );
		$repo    = new Program_Repository();
		$deleted = array();
		foreach ( array_map( 'intval', (array) $request->get_param( 'courses' ) ) as $program ) {
			$record = $repo->get( $program );
			if ( null === $record || ! (int) get_post_meta( $program, '_lledger_player', true ) ) {
				continue;
			}
			foreach ( $record->course_ids as $course ) {
				foreach ( get_posts( self::lessons_query( $course ) ) as $lesson ) {
					wp_delete_post( (int) $lesson, true );
				}
				wp_delete_post( $course, true );
			}
			$package = (int) get_post_meta( $program, Player_Importer::PACKAGE_META, true );
			if ( $package > 0 ) {
				Au_Store::delete( $package );
			}
			wp_delete_post( $program, true );
			$deleted[] = $program;
		}
		return new \WP_REST_Response( array( 'deleted' => $deleted ), 200 );
	}

	/**
	 * Create a test learner.
	 *
	 * @return integer User id, 0 on failure.
	 */
	private static function new_learner(): int {
		$login = self::LEARNER . strtolower( wp_generate_password( 12, false ) );
		$id    = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => $login . '@example.invalid',
				'role'       => 'subscriber',
				'locale'     => 'en_US',
			)
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Whether a user is a test learner (only those are enrolled by the import route).
	 *
	 * @param integer $user User id.
	 *
	 * @return boolean
	 */
	private static function is_learner( int $user ): bool {
		$data = $user > 0 ? get_userdata( $user ) : false;
		return false !== $data && str_starts_with( $data->user_login, self::LEARNER );
	}

	/**
	 * Run the job queue until the program's package is extracted (or it has none).
	 *
	 * @param integer $program Program id.
	 *
	 * @return boolean Whether the files are ready.
	 */
	private static function extracted( int $program ): bool {
		$package = (int) get_post_meta( $program, Player_Importer::PACKAGE_META, true );
		if ( $package <= 0 ) {
			return true;
		}
		$until = microtime( true ) + self::EXTRACT_SECONDS;
		while ( Au_Store::STATUS_PENDING === Au_Store::status( $package ) && microtime( true ) < $until ) {
			Job_Queue::run( 10, 20 );
		}
		return Au_Store::STATUS_READY === Au_Store::status( $package );
	}

	/**
	 * The lesson of the AU at an index of a player program, or 0.
	 *
	 * @param integer $program Program id.
	 * @param integer $index   AU index in document order, from 0.
	 *
	 * @return integer
	 */
	private static function lesson( int $program, int $index ): int {
		$repo      = new Program_Repository();
		$record    = $repo->get( $program );
		$structure = $repo->structure( $program );
		$au        = null === $structure ? null : ( $structure->aus()[ $index ] ?? null );
		if ( null === $record || null === $au || $index < 0 ) {
			return 0;
		}
		foreach ( $record->course_ids as $course ) {
			foreach ( get_posts( self::lessons_query( $course ) ) as $lesson ) {
				if ( get_post_meta( (int) $lesson, Player_Importer::AU_META, true ) === $au->id() ) {
					return (int) $lesson;
				}
			}
		}
		return 0;
	}

	/**
	 * Query for the ids of a course's lessons.
	 *
	 * @param integer $course Course id.
	 *
	 * @return array
	 */
	private static function lessons_query( int $course ): array {
		return array(
			'post_type'      => 'lesson',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'meta_key'       => '_lesson_course', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $course, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'no_found_rows'  => true,
		);
	}

	/**
	 * A REST error.
	 *
	 * @param string  $code    Code.
	 * @param string  $message Message.
	 * @param integer $status  Status.
	 *
	 * @return \WP_Error
	 */
	private static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
