<?php
/**
 * Course-server learner routes.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\CourseServer;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- outbox rows are plugin-owned and never cached.

use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Identity\Identity_Module;
use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\LMS_Adapter;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Pairing\Lrs_Client;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Db\Tables;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Xapi\Agent_Identity;

/**
 * `learning-ledger/v1/course-server/learners…`: create, enrol, read progress and delete anonymous learners.
 * Every route authenticates only by a signed request (Signed_Request_Auth).
 */
final class Learners_Controller {

	const NS = 'learning-ledger/v1';

	/**
	 * Job type that retries an LRS erase that failed during a delete.
	 */
	const ERASE_JOB = 'connector_actor_erase';

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
	 * Identity map.
	 *
	 * @var Identity_Map
	 */
	private Identity_Map $identity;

	/**
	 * Constructor.
	 *
	 * @param Signed_Request_Auth|null $auth        Request auth.
	 * @param callable|null            $adapter_for Course id => ?LMS_Adapter; defaults to Integration_Loader::for_post().
	 */
	public function __construct( ?Signed_Request_Auth $auth = null, ?callable $adapter_for = null ) {
		$this->auth        = $auth ?? new Signed_Request_Auth();
		$this->adapter_for = $adapter_for ?? array( Integration_Loader::class, 'for_post' );
		$this->identity    = new Identity_Map();
	}

	/**
	 * Register the routes. They authenticate themselves in the callback (signed requests only),
	 * so that a 429 can carry Retry-After.
	 *
	 * @return void
	 */
	public function register(): void {
		$id = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);
		register_rest_route(
			self::NS,
			'/course-server/learners',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/course-server/learners/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => '__return_true',
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NS,
			'/course-server/learners/(?P<id>\d+)/enrolments',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'enrol' ),
				'permission_callback' => '__return_true',
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NS,
			'/course-server/learners/(?P<id>\d+)/progress',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'progress' ),
				'permission_callback' => '__return_true',
				'args'                => $id,
			)
		);
	}

	/**
	 * POST learners: a new anonymous learner (no email, role `lledger_learner`) and its actor.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function create( \WP_REST_Request $r ): \WP_REST_Response {
		$auth = $this->auth->authorize( $r, Trusted_Keys::CREATE_LEARNERS );
		if ( is_wp_error( $auth ) ) {
			return Signed_Request_Auth::to_response( $auth );
		}
		try {
			$user = $this->identity->create_anonymous_learner();
		} catch ( \RuntimeException $e ) {
			return self::error( 'lledger_learner_not_created', 'The learner could not be created.', 500 );
		}
		return new \WP_REST_Response(
			array(
				'user_id' => $user,
				'actor'   => $this->identity->actor_for_user( $user ),
			),
			201
		);
	}

	/**
	 * POST learners/{id}/enrolments `{course}`: enrol through the course's adapter.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function enrol( \WP_REST_Request $r ): \WP_REST_Response {
		$auth = $this->auth->authorize( $r, Trusted_Keys::ENROL );
		if ( is_wp_error( $auth ) ) {
			return Signed_Request_Auth::to_response( $auth );
		}
		$user = (int) $r['id'];
		if ( ! get_userdata( $user ) ) {
			return self::error( 'lledger_unknown_learner', 'No such learner.', 404 );
		}
		$json   = $r->get_json_params();
		$course = self::positive_int( is_array( $json ) ? ( $json['course'] ?? null ) : null );
		if ( null === $course ) {
			return self::error( 'lledger_invalid_course', 'Send {"course": <LMS course id>}.', 400 );
		}
		$adapter = $this->adapter( $course );
		if ( null === $adapter ) {
			return self::error( 'lledger_unknown_course', 'No active LMS integration handles this course.', 404 );
		}
		if ( ! $adapter->enrol( $user, $course ) ) {
			return self::error( 'lledger_enrol_failed', 'The LMS did not enrol the learner.', 409 );
		}
		return new \WP_REST_Response(
			array(
				'user_id'  => $user,
				'course'   => $course,
				'enrolled' => true,
			),
			200
		);
	}

	/**
	 * GET learners/{id}/progress?program=<id>|course=<id>.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function progress( \WP_REST_Request $r ): \WP_REST_Response {
		$auth = $this->auth->authorize( $r, Trusted_Keys::READ_PROGRESS );
		if ( is_wp_error( $auth ) ) {
			return Signed_Request_Auth::to_response( $auth );
		}
		$user = (int) $r['id'];
		if ( ! get_userdata( $user ) ) {
			return self::error( 'lledger_unknown_learner', 'No such learner.', 404 );
		}

		$program_id = self::positive_int( $r->get_param( 'program' ) );
		$course_id  = self::positive_int( $r->get_param( 'course' ) );
		$satisfied  = null;
		if ( null !== $program_id ) {
			$program = ( new Program_Repository() )->get( $program_id );
			if ( null === $program ) {
				return self::error( 'lledger_unknown_program', 'No such program.', 404 );
			}
			$course_ids = $program->course_ids;
			$satisfied  = ( new Apply_Ledger() )->has( $user, new Lms_Object( 'program', $program_id ), Result::COURSE_SATISFIED );
		} elseif ( null !== $course_id ) {
			$course_ids = array( $course_id );
		} else {
			return self::error( 'lledger_invalid_scope', 'Pass program=<id> or course=<id>.', 400 );
		}

		$courses = array();
		foreach ( $course_ids as $course ) {
			$adapter = $this->adapter( (int) $course );
			if ( null === $adapter ) {
				if ( null === $program_id ) {
					return self::error( 'lledger_unknown_course', 'No active LMS integration handles this course.', 404 );
				}
				continue;
			}
			$p         = $adapter->progress( $user, (int) $course );
			$courses[] = array(
				'course'           => (int) $course,
				'completed'        => array_values( array_map( 'intval', $p->completed_lesson_ids ) ),
				'tested_out'       => array_values( array_map( 'intval', $p->tested_out_lesson_ids ) ),
				'course_completed' => $p->course_completed,
				'certificate'      => $p->certificate,
			);
		}
		return new \WP_REST_Response(
			array(
				'courses'           => $courses,
				'program_satisfied' => $satisfied,
			),
			200
		);
	}

	/**
	 * DELETE learners/{id}: only a user whose only role is `lledger_learner`. Removes the user and
	 * every connector row about them, then asks the LRS to erase their actors. A failed LRS call
	 * is queued for retry and reported as `lrs_erase: queued`.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function delete( \WP_REST_Request $r ): \WP_REST_Response {
		$auth = $this->auth->authorize( $r, Trusted_Keys::DELETE_LEARNERS );
		if ( is_wp_error( $auth ) ) {
			return Signed_Request_Auth::to_response( $auth );
		}
		$id   = (int) $r['id'];
		$user = get_userdata( $id );
		if ( ! $user ) {
			return self::error( 'lledger_unknown_learner', 'No such learner.', 404 );
		}
		if ( array( Identity_Module::ROLE ) !== array_values( (array) $user->roles ) || is_super_admin( $id ) ) {
			return self::error( 'lledger_not_a_learner', 'Only anonymous learner accounts can be deleted here.', 403 );
		}

		$actors = $this->actors( $id );

		// Order matters: rows are found through the actor meta and identity links, which go last.
		$this->delete_outbox( $id );
		( new Inbox_Repository() )->delete_user( $id );
		( new Waiver_Ledger() )->delete_user( $id );
		( new Apply_Ledger() )->delete_user( $id );
		( new Registration_Map() )->delete_user( $id );
		$this->identity->delete_learner( $id );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( ! wp_delete_user( $id ) ) {
			return self::error( 'lledger_learner_not_deleted', 'WordPress did not delete the user.', 500 );
		}

		$erase = 'erased';
		foreach ( $actors as $actor ) {
			if ( ! self::erase( $actor ) ) {
				Job_Queue::push( self::ERASE_JOB, array( 'actor' => $actor ), self::ERASE_JOB . ':' . bin2hex( Agent_Identity::from_array( $actor )->hash() ), Job_Queue::retry_delay( 1 ) );
				$erase = 'queued';
			}
		}

		return new \WP_REST_Response(
			array(
				'deleted'   => true,
				'user_id'   => $id,
				'lrs_erase' => $erase,
			),
			200
		);
	}

	/**
	 * Job handler: retry an LRS erase. Throws so the queue backs off and tries again.
	 *
	 * @param array $payload Job payload `{actor}`.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the LRS call fails.
	 */
	public static function erase_job( array $payload ): void {
		$actor = $payload['actor'] ?? null;
		if ( ! is_array( $actor ) ) {
			return;
		}
		if ( ! self::erase( $actor ) ) {
			throw new \RuntimeException( 'The LRS did not erase the actor.' );
		}
	}

	/**
	 * Ask the LRS to erase one actor.
	 *
	 * @param array $actor Agent object.
	 *
	 * @return boolean Whether the LRS answered 2xx.
	 */
	private static function erase( array $actor ): bool {
		try {
			$res = ( new Lrs_Client() )->api( 'POST', 'actors/erase', array( 'actor' => $actor ) );
		} catch ( \RuntimeException $e ) {
			return false;
		}
		return $res->status >= 200 && $res->status < 300;
	}

	/**
	 * The learner's actors: the site-issued account (if one was minted), then the linked identities.
	 *
	 * @param integer $user User id.
	 *
	 * @return array[]
	 */
	private function actors( int $user ): array {
		$out = array();
		if ( '' !== (string) get_user_meta( $user, Identity_Map::META, true ) ) {
			$out[] = $this->identity->actor_for_user( $user );
		}
		foreach ( $this->identity->links_for_user( $user ) as $link ) {
			if ( ! is_array( $link['actor'] ) ) {
				continue;
			}
			try {
				Agent_Identity::from_array( $link['actor'] );
			} catch ( Invalid_Input $e ) {
				continue;
			}
			$out[] = $link['actor'];
		}
		return $out;
	}

	/**
	 * Delete the outbox rows whose statement actor is the learner's site-issued account.
	 * The outbox has no user column, so candidates are found by the account name and confirmed by decoding.
	 *
	 * @param integer $user User id.
	 *
	 * @return void
	 */
	private function delete_outbox( int $user ): void {
		global $wpdb;
		$name = (string) get_user_meta( $user, Identity_Map::META, true );
		if ( '' === $name ) {
			return;
		}
		$table = Tables::name( 'conn_outbox' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, body FROM %i WHERE body LIKE %s', $table, '%' . $wpdb->esc_like( $name ) . '%' )
		);
		$home  = $this->identity->home_page();
		$ids   = array();
		foreach ( (array) $rows as $row ) {
			$statement = json_decode( (string) $row->body, true );
			$account   = is_array( $statement ) ? ( $statement['actor']['account'] ?? null ) : null;
			if ( is_array( $account ) && ( $account['name'] ?? null ) === $name && ( $account['homePage'] ?? null ) === $home ) {
				$ids[] = (int) $row->id;
			}
		}
		foreach ( $ids as $row_id ) {
			$wpdb->delete( $table, array( 'id' => $row_id ), array( '%d' ) );
		}
	}

	/**
	 * The adapter for a course, if any.
	 *
	 * @param integer $course Course id.
	 *
	 * @return LMS_Adapter|null
	 */
	private function adapter( int $course ): ?LMS_Adapter {
		$adapter = call_user_func( $this->adapter_for, $course );
		return $adapter instanceof LMS_Adapter ? $adapter : null;
	}

	/**
	 * A positive integer from a query value, or null.
	 *
	 * @param mixed $v Value.
	 *
	 * @return integer|null
	 */
	private static function positive_int( mixed $v ): ?int {
		if ( is_int( $v ) ) {
			return $v > 0 ? $v : null;
		}
		return is_string( $v ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $v ) ? (int) $v : null;
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
