<?php
/**
 * The cmi5 launch route.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Launch;

use LearningLedger\Cmi5\Au;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\LMS_Adapter;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Pairing\Lrs_Client;
use LearningLedger\Connector\Pairing\Lrs_Response;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Crypto\Tokens;
use LearningLedger\Player\Import\Player_Importer;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;

/**
 * `POST /learning-ledger/v1/cmi5/launch` `{lesson, mode?}` for a logged-in user (REST nonce).
 *
 * Normal needs an enrolled learner, Review an enrolled learner whose AU is satisfied, and Browse a
 * user who can edit the lesson; Browse runs in the registration scope `preview:<program>:<user>`,
 * which never reaches Sensei. A launch abandons the user's open session for the AU, opens a new one,
 * writes `LMS.LaunchData`, creates `cmi5LearnerPreferences` when missing, writes `launched`, and
 * answers the AU URL with the cmi5 launch parameters and a one-time fetch URL [cmi5 8.1, 8.2, 10.2].
 */
final class Launch_Controller {

	const NS        = 'learning-ledger/v1';
	const ROUTE     = '/cmi5/launch';
	const MODES     = array( 'Normal', 'Browse', 'Review' );
	const FETCH_TTL = 300;

	/**
	 * Agent profile document id of the learner preferences [cmi5 11].
	 */
	const PREFERENCES = 'cmi5LearnerPreferences';

	/**
	 * Adapter override (tests); null uses the loaded Sensei adapter.
	 *
	 * @var LMS_Adapter|null
	 */
	private ?LMS_Adapter $adapter;

	/**
	 * LRS client.
	 *
	 * @var Lrs_Client
	 */
	private Lrs_Client $lrs;

	/**
	 * Sessions.
	 *
	 * @var Session_Repository
	 */
	private Session_Repository $sessions;

	/**
	 * Constructor.
	 *
	 * @param LMS_Adapter|null        $adapter  Adapter override.
	 * @param Lrs_Client|null         $lrs      LRS client.
	 * @param Session_Repository|null $sessions Sessions.
	 */
	public function __construct( ?LMS_Adapter $adapter = null, ?Lrs_Client $lrs = null, ?Session_Repository $sessions = null ) {
		$this->adapter  = $adapter;
		$this->lrs      = $lrs ?? new Lrs_Client();
		$this->sessions = $sessions ?? new Session_Repository();
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'lesson' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'mode'   => array(
						'type'    => 'string',
						'enum'    => self::MODES,
						'default' => 'Normal',
					),
				),
			)
		);
	}

	/**
	 * Logged-in user, with a valid REST nonce unless an Application Password authenticated the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error
	 */
	public function permission( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'learning-ledger' ), array( 'status' => 401 ) );
		}
		if ( ! empty( $GLOBALS['wp_rest_application_password_uuid'] ) ) {
			return true;
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'rest_cookie_invalid_nonce', __( 'Cookie check failed.', 'learning-ledger' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Launch for the current user.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->start( get_current_user_id(), (int) $request->get_param( 'lesson' ), (string) $request->get_param( 'mode' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$res = new \WP_REST_Response( $result, 200 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/**
	 * Launch a lesson's AU for a user. Callers other than the REST route check the user themselves.
	 *
	 * @param integer $user   User id.
	 * @param integer $lesson Lesson id.
	 * @param string  $mode   Normal, Browse or Review.
	 *
	 * @return array{launch_url:string,method:string}|\WP_Error
	 */
	public function start( int $user, int $lesson, string $mode ): array|\WP_Error {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return self::error( 'lledger_invalid_mode', __( 'Unknown launch mode.', 'learning-ledger' ), 400 );
		}
		$target = self::target( $lesson, 'Browse' === $mode );
		if ( null === $target ) {
			return self::error( 'lledger_not_found', __( 'This lesson has no cmi5 content.', 'learning-ledger' ), 404 );
		}
		$adapter = $this->adapter ?? Integration_Loader::adapter( 'sensei' );
		if ( null === $adapter ) {
			return self::error( 'lledger_unavailable', __( 'The Sensei integration is not available.', 'learning-ledger' ), 503 );
		}
		if ( ! $this->may_launch( $adapter, $user, $lesson, $target['course'], $mode ) ) {
			return self::error( 'lledger_forbidden', __( 'You cannot launch this lesson in this mode.', 'learning-ledger' ), 403 );
		}

		$au           = $target['au'];
		$program      = $target['program'];
		$identity     = new Identity_Map();
		$actor        = $identity->actor_for_user( $user );
		$registration = $this->registration( $user, $program, $mode );
		$session      = null;
		$launched     = false;
		try {
			$this->abandon_open( $user, $au, $identity );
			$session = $this->sessions->open( $user, $au->id(), $registration, $lesson, $mode );

			$return_url = rest_url( self::NS . '/cmi5/return/' . $session );
			$this->expect(
				$this->lrs->xapi(
					'PUT',
					'activities/state',
					array(
						'activityId'   => Au::activity_id( $au->id() ),
						'agent'        => self::json( $actor ),
						'registration' => $registration,
						'stateId'      => Launch_Data::STATE_ID,
					),
					self::json( Launch_Data::document( $au, $registration, $session, $mode, $return_url ) ),
					array( 'Content-Type' => 'application/json' )
				),
				array( 204 )
			);
			$this->expect(
				$this->lrs->xapi(
					'PUT',
					'agents/profile',
					array(
						'agent'     => self::json( $actor ),
						'profileId' => self::PREFERENCES,
					),
					self::json( array( 'languagePreference' => str_replace( '_', '-', get_user_locale( $user ) ) ) ),
					array(
						'Content-Type'  => 'application/json',
						'If-None-Match' => '*',
					)
				),
				array( 204, 412 )
			);
			$this->post_statement( ( new Lms_Statements() )->launched( $au, $actor, $registration, $session, $mode, $au->url(), $au->launch_parameters() ) );
			$launched = true;
		} catch ( \RuntimeException $e ) {
			if ( null !== $session && ! $launched ) {
				// Never launched: close it without an `abandoned` statement, so nothing abandons it later.
				$this->sessions->mark( $session, Session_Repository::ABANDONED );
			}
			return self::error( 'lledger_lrs_unavailable', __( 'The LRS could not be reached. Try again shortly.', 'learning-ledger' ), 502 );
		}

		$token = Tokens::random( 32 );
		$this->sessions->set_fetch( $session, Tokens::hash( $token ), Timestamp::to_db( Clock::now()->modify( '+' . self::FETCH_TTL . ' seconds' ) ) );

		return array(
			'launch_url' => Launch_Url::build(
				$au->url(),
				array(
					'endpoint'     => Connector_Settings::public_xapi_base(),
					'fetch'        => rest_url( self::NS . '/cmi5/fetch/' . $token ),
					'actor'        => self::json( $actor ),
					'registration' => $registration,
					'activityId'   => Au::activity_id( $au->id() ),
				)
			),
			'method'     => $au->launch_method(),
		);
	}

	/**
	 * The program and AU of a player lesson, or null.
	 *
	 * @param integer $lesson     Lesson id.
	 * @param boolean $any_status Accept lessons that are not published (Browse).
	 *
	 * @return array{program:int,course:int,au:Au}|null
	 */
	private static function target( int $lesson, bool $any_status ): ?array {
		if ( 'lesson' !== get_post_type( $lesson ) || ( ! $any_status && 'publish' !== get_post_status( $lesson ) ) ) {
			return null;
		}
		$au_id = (string) get_post_meta( $lesson, Player_Importer::AU_META, true );
		$found = '' === $au_id ? null : self::find_au( $au_id );
		if ( null === $found ) {
			return null;
		}
		$course = (int) get_post_meta( $lesson, '_lesson_course', true );
		$repo   = new Program_Repository();
		$record = $repo->get( $found['program'] );
		if ( null === $record || ! in_array( $course, $record->course_ids, true ) ) {
			return null;
		}
		return array(
			'program' => $found['program'],
			'course'  => $course,
			'au'      => $found['au'],
		);
	}

	/**
	 * The player program holding an AU, and the AU from its stored structure.
	 *
	 * @param string $au_id AU publisher id.
	 *
	 * @return array{program:int,au:Au}|null
	 */
	public static function find_au( string $au_id ): ?array {
		$repo    = new Program_Repository();
		$program = $repo->for_activity( $au_id );
		if ( null === $program || ! (int) get_post_meta( $program, '_lledger_player', true ) ) {
			return null;
		}
		$structure = $repo->structure( $program );
		$au        = null === $structure ? null : $structure->find_au( $au_id );
		return null === $au ? null : array(
			'program' => $program,
			'au'      => $au,
		);
	}

	/**
	 * Whether the user may launch the lesson in the mode.
	 *
	 * @param LMS_Adapter $adapter Sensei adapter.
	 * @param integer     $user    User id.
	 * @param integer     $lesson  Lesson id.
	 * @param integer     $course  Course id.
	 * @param string      $mode    Mode.
	 *
	 * @return boolean
	 */
	private function may_launch( LMS_Adapter $adapter, int $user, int $lesson, int $course, string $mode ): bool {
		if ( $user <= 0 ) {
			return false;
		}
		if ( 'Browse' === $mode ) {
			return user_can( $user, 'edit_post', $lesson );
		}
		if ( ! class_exists( '\Sensei_Course' ) || ! \Sensei_Course::is_user_enrolled( $course, $user ) ) {
			return false;
		}
		if ( 'Review' === $mode ) {
			$progress = $adapter->progress( $user, $course );
			return in_array( $lesson, $progress->completed_lesson_ids, true ) || in_array( $lesson, $progress->tested_out_lesson_ids, true );
		}
		return true;
	}

	/**
	 * The registration: the program's cmi5 one (announced when first created), or the user's preview one for Browse.
	 *
	 * @param integer $user    User id.
	 * @param integer $program Program id.
	 * @param string  $mode    Mode.
	 *
	 * @return string
	 */
	private function registration( int $user, int $program, string $mode ): string {
		$map = new Registration_Map();
		if ( 'Browse' === $mode ) {
			return $map->get_or_create( $user, 'preview:' . $program . ':' . $user );
		}
		$scope = 'cmi5:' . $program;
		$found = $map->find( $user, $scope );
		if ( null !== $found ) {
			return $found;
		}
		$registration = $map->get_or_create( $user, $scope );
		do_action( 'learning_ledger/cmi5_registration_created', $user, $program, $registration ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		return $registration;
	}

	/**
	 * Abandon the user's open session for the AU: `abandoned` with its duration, its token revoked.
	 *
	 * @param integer      $user     User id.
	 * @param Au           $au       AU.
	 * @param Identity_Map $identity Identity map.
	 *
	 * @return void
	 * @throws \RuntimeException When the LRS refuses.
	 */
	private function abandon_open( int $user, Au $au, Identity_Map $identity ): void {
		$open = $this->sessions->open_for( $user, $au->id() );
		if ( null === $open ) {
			return;
		}
		$now      = Clock::now();
		$duration = (float) $now->format( 'U.u' ) - (float) Timestamp::from_db( $open['launched_at'] )->format( 'U.u' );
		$this->post_statement( ( new Lms_Statements() )->abandoned( $au, $identity->actor_for_user( $user ), $open['registration'], $open['session_id'], $duration ) );
		if ( null !== $open['token_key'] ) {
			$this->expect( $this->lrs->api( 'DELETE', 'tokens/' . rawurlencode( $open['token_key'] ) ), array( 204, 404 ) );
		}
		$this->sessions->mark( $open['session_id'], Session_Repository::ABANDONED, Timestamp::to_db( $now ) );
	}

	/**
	 * POST one statement.
	 *
	 * @param array $statement Statement.
	 *
	 * @return void
	 * @throws \RuntimeException When the LRS refuses.
	 */
	private function post_statement( array $statement ): void {
		$this->expect( $this->lrs->xapi( 'POST', 'statements', array(), self::json( $statement ), array( 'Content-Type' => 'application/json' ) ), array( 200 ) );
	}

	/**
	 * Throw unless the LRS answered with one of the statuses.
	 *
	 * @param Lrs_Response $res      Response.
	 * @param int[]        $statuses Accepted statuses.
	 *
	 * @return void
	 * @throws \RuntimeException Otherwise.
	 */
	private function expect( Lrs_Response $res, array $statuses ): void {
		if ( ! in_array( $res->status, $statuses, true ) ) {
			throw new \RuntimeException( 'LRS answered ' . (int) $res->status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never echoed.
		}
	}

	/**
	 * JSON with unescaped slashes.
	 *
	 * @param array $value Value.
	 *
	 * @return string
	 */
	private static function json( array $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
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
