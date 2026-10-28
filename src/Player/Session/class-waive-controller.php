<?php
/**
 * The cmi5 waive route.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Session;

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Waiver_Policy;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Programs\Program_Iris;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Player\Import\Player_Importer;
use LearningLedger\Player\Launch\Lms_Statements;

/**
 * `POST /learning-ledger/v1/cmi5/waive` `{user, lesson, reason}`: the LMS waives a learner's AU
 * [cmi5 9.3.7] in the learner's cmi5 registration (created when missing), then pulls the
 * registration so the inbox completes the Sensei lesson and records the reason. For a user who
 * can edit the lesson, or holds `lledger_manage_connector`; REST nonce unless an Application
 * Password authenticated the request. An AU already satisfied or waived is a 409.
 */
final class Waive_Controller {

	const NS      = 'learning-ledger/v1';
	const ROUTE   = '/cmi5/waive';
	const REASONS = array( 'Tested Out', 'Equivalent AU', 'Equivalent Outside Activity', 'Administrative' );

	/**
	 * Evaluator.
	 *
	 * @var Player_Evaluator
	 */
	private Player_Evaluator $evaluator;

	/**
	 * Constructor.
	 *
	 * @param Player_Evaluator|null $evaluator Evaluator (the module's hooked one).
	 */
	public function __construct( ?Player_Evaluator $evaluator = null ) {
		$this->evaluator = $evaluator ?? new Player_Evaluator();
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
					'user'   => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'lesson' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'reason' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => self::REASONS,
					),
				),
			)
		);
	}

	/**
	 * A logged-in user who can edit the lesson or manage the connector, with a valid REST nonce
	 * unless an Application Password authenticated the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error
	 */
	public function permission( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'learning-ledger' ), array( 'status' => 401 ) );
		}
		if ( empty( $GLOBALS['wp_rest_application_password_uuid'] ) ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! is_string( $nonce ) || '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new \WP_Error( 'rest_cookie_invalid_nonce', __( 'Cookie check failed.', 'learning-ledger' ), array( 'status' => 403 ) );
			}
		}
		$lesson = (int) $request->get_param( 'lesson' );
		if ( current_user_can( Program_Module::CAP ) || ( $lesson > 0 && current_user_can( 'edit_post', $lesson ) ) ) {
			return true;
		}
		return new \WP_Error( 'rest_forbidden', __( 'You cannot waive this lesson.', 'learning-ledger' ), array( 'status' => 403 ) );
	}

	/**
	 * Waive the lesson's AU for the user.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user   = (int) $request->get_param( 'user' );
		$reason = (string) $request->get_param( 'reason' );
		$target = self::target( (int) $request->get_param( 'lesson' ) );
		if ( null === $target ) {
			return self::error( 'lledger_not_found', __( 'This lesson has no cmi5 content.', 'learning-ledger' ), 404 );
		}
		if ( false === get_userdata( $user ) ) {
			return self::error( 'lledger_unknown_user', __( 'Unknown user.', 'learning-ledger' ), 404 );
		}
		if ( ! in_array( $reason, self::REASONS, true ) ) {
			return self::error( 'lledger_invalid_reason', __( 'Unknown waiver reason.', 'learning-ledger' ), 400 );
		}

		$au      = $target['au'];
		$program = $target['program'];
		try {
			$registration = (string) Player_Evaluator::registration( $user, $program, true );
			$this->evaluator->pull( $registration );
			if ( $this->evaluator->evaluation( $target['structure'], $user, $registration )->au_satisfied( $au->id(), Waiver_Policy::ALL ) ) {
				return self::error( 'lledger_already_satisfied', __( 'This lesson is already satisfied or waived for the learner.', 'learning-ledger' ), 409 );
			}
			$statement = ( new Lms_Statements() )->waived( $au, ( new Identity_Map() )->actor_for_user( $user ), $registration, Player_Evaluator::waiver_session( $registration, $au->id() ), $reason );
			if ( 409 === $this->evaluator->record( $statement ) ) {
				return self::error( 'lledger_already_satisfied', __( 'This lesson is already waived for the learner.', 'learning-ledger' ), 409 );
			}
			// moveOn is evaluated on each waiver: the inbox applies it to Sensei with the reason.
			$this->evaluator->sync( $program, $user, $registration );
		} catch ( \RuntimeException $e ) {
			return self::error( 'lledger_lrs_unavailable', __( 'The LRS could not be reached. Try again shortly.', 'learning-ledger' ), 502 );
		}

		return new \WP_REST_Response(
			array(
				'statement_id' => $statement['id'],
				'registration' => $registration,
			),
			200
		);
	}

	/**
	 * The player program, structure and AU of a lesson, or null. A lesson names its AU in
	 * `_lledger_au_id`; a course-xapi-mcp lesson through its stable key under the program's IRI base.
	 *
	 * @param integer $lesson Lesson id.
	 *
	 * @return array{program:int,structure:\LearningLedger\Cmi5\Course_Structure,au:Au}|null
	 */
	private static function target( int $lesson ): ?array {
		if ( 'lesson' !== get_post_type( $lesson ) ) {
			return null;
		}
		$repo       = new Program_Repository();
		$program_id = $repo->for_lms_course( (int) get_post_meta( $lesson, '_lesson_course', true ) );
		if ( null === $program_id || ! (int) get_post_meta( $program_id, '_lledger_player', true ) ) {
			return null;
		}
		$program   = $repo->get( $program_id );
		$structure = $repo->structure( $program_id );
		if ( null === $program || null === $structure ) {
			return null;
		}
		$au_id = (string) get_post_meta( $lesson, Player_Importer::AU_META, true );
		$key   = (string) get_post_meta( $lesson, '_lledger_stable_key', true );
		if ( '' === $au_id && '' !== $program->iri_base && '' !== $key ) {
			$au_id = Program_Iris::au_iri( $program, $key );
		}
		$au = '' === $au_id ? null : $structure->find_au( $au_id );
		return null === $au ? null : array(
			'program'   => $program_id,
			'structure' => $structure,
			'au'        => $au,
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
