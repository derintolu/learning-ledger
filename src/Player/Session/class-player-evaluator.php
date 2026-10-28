<?php
/**
 * LMS-side moveOn evaluation for player courses.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Session;

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Au_Fact;
use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Cmi5\Satisfaction_Evaluator;
use LearningLedger\Cmi5\Satisfaction_Result;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Cmi5\Waiver_Policy;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Inbox\Inbox_Processor;
use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Inbox\More_Link;
use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Pairing\Lrs_Client;
use LearningLedger\Connector\Programs\Program;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Player\Launch\Launch_Controller;
use LearningLedger\Player\Launch\Lms_Statements;
use LearningLedger\Player\Launch\Session_Repository;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

/**
 * Writes the statements cmi5 makes the LMS write once moveOn is evaluated [cmi5 9.3.7, 9.3.9]:
 * `satisfied` once per registration for each satisfied block and the course, and, for
 * course-xapi-mcp packages with waive-recommended on, `waived` "Tested Out" for the AUs an
 * allowed statement recommends that are not satisfied yet. A `terminated` in the inbox closes
 * its session.
 *
 * Runs on `learning_ledger/connector_evaluated` (the inbox), on the return route (pulling the
 * registration's statements and processing them first), and when a registration is created
 * (so `NotApplicable` AUs are satisfied at once). Only a user's `cmi5:<program>` registration
 * gets LMS statements; preview registrations never do. Sensei completion stays with the inbox.
 *
 * The LRS has the LMS's own `satisfied` statements in the inbox once they come back, by
 * registration (they are about block and course ids, which no program matches exactly, so the
 * processor ignores them); a resend before then has the same id and content.
 */
final class Player_Evaluator {

	/**
	 * Job that pulls a registration's statements, processes them and writes the LMS statements.
	 */
	const SYNC_JOB = 'cmi5_sync';

	/**
	 * Page size and page cap when pulling a registration.
	 */
	const PULL_LIMIT = 500;
	const PULL_PAGES = 20;

	/**
	 * Inbox batches processed after a pull, at most.
	 */
	const PROCESS_ROUNDS = 5;

	/**
	 * Waiver reason for recommended waivers.
	 */
	const TESTED_OUT = 'Tested Out';

	/**
	 * Seconds a follow-up sync waits, so statements stored just now are past the LRS's
	 * consistent-through time (now minus one second) and a registration query returns them.
	 */
	const SETTLE = 2;

	/**
	 * LRS client.
	 *
	 * @var Lrs_Client
	 */
	private Lrs_Client $lrs;

	/**
	 * Inbox.
	 *
	 * @var Inbox_Repository
	 */
	private Inbox_Repository $inbox;

	/**
	 * Programs.
	 *
	 * @var Program_Repository
	 */
	private Program_Repository $programs;

	/**
	 * Sessions.
	 *
	 * @var Session_Repository
	 */
	private Session_Repository $sessions;

	/**
	 * Registrations evaluated through the inbox during the current sync, by UUID.
	 *
	 * @var array<string,bool>
	 */
	private array $evaluated = array();

	/**
	 * Constructor.
	 *
	 * @param Lrs_Client|null         $lrs      LRS client.
	 * @param Inbox_Repository|null   $inbox    Inbox.
	 * @param Program_Repository|null $programs Programs.
	 * @param Session_Repository|null $sessions Sessions.
	 */
	public function __construct( ?Lrs_Client $lrs = null, ?Inbox_Repository $inbox = null, ?Program_Repository $programs = null, ?Session_Repository $sessions = null ) {
		$this->lrs      = $lrs ?? new Lrs_Client();
		$this->inbox    = $inbox ?? new Inbox_Repository();
		$this->programs = $programs ?? new Program_Repository();
		$this->sessions = $sessions ?? new Session_Repository();
	}

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		add_action( 'learning_ledger/connector_evaluated', array( $this, 'on_evaluated' ), 10, 4 );
		add_action( 'learning_ledger/cmi5_return', array( $this, 'on_return' ) );
		add_action( 'learning_ledger/cmi5_registration_created', array( $this, 'on_registration_created' ), 10, 3 );
		add_action( 'learning_ledger/jobs/' . self::SYNC_JOB, array( $this, 'run_job' ) );
		// phpcs:enable
	}

	/**
	 * After the inbox evaluated a registration: close terminated sessions, then write the LMS
	 * statements. A failure queues a sync, which retries.
	 *
	 * @param integer             $program_id   Program id.
	 * @param integer             $user         User id.
	 * @param string              $registration Registration UUID.
	 * @param Satisfaction_Result $e            The evaluation.
	 *
	 * @return void
	 */
	public function on_evaluated( int $program_id, int $user, string $registration, Satisfaction_Result $e ): void {
		$this->evaluated[ strtolower( $registration ) ] = true;
		try {
			if ( $this->write( $program_id, $user, $registration, $e ) ) {
				// The new waivers complete their lessons once the inbox has them.
				self::queue( $program_id, $user, $registration );
			}
		} catch ( \RuntimeException $ex ) {
			self::queue( $program_id, $user, $registration, 60 );
		}
	}

	/**
	 * Return route: pull the session's registration into the inbox and process it before the
	 * redirect. Browse sessions are left to the inbox (their registration never reaches Sensei).
	 *
	 * @param string $session_id Session id.
	 *
	 * @return void
	 */
	public function on_return( string $session_id ): void {
		$session = $this->sessions->find( $session_id );
		if ( null === $session || Session_Repository::OPEN !== $session['status'] || 'Browse' === $session['launch_mode'] ) {
			return;
		}
		$found = Launch_Controller::find_au( $session['au_id'] );
		if ( null === $found ) {
			return;
		}
		try {
			$this->sync( $found['program'], $session['user_id'], $session['registration'] );
			// What the AU sent in the last second is not listed yet; pick it up shortly.
			self::queue( $found['program'], $session['user_id'], $session['registration'], self::SETTLE );
		} catch ( \RuntimeException $e ) {
			self::queue( $found['program'], $session['user_id'], $session['registration'], 60 );
		}
	}

	/**
	 * A new cmi5 registration: evaluate at once, so `NotApplicable` AUs (and blocks made only of
	 * them) are satisfied, and queue a sync so the inbox picks up the launch and completes their
	 * lessons.
	 *
	 * @param integer $user         User id.
	 * @param integer $program_id   Program id.
	 * @param string  $registration Registration UUID.
	 *
	 * @return void
	 */
	public function on_registration_created( int $user, int $program_id, string $registration ): void {
		try {
			$this->write( $program_id, $user, $registration, null );
			self::queue( $program_id, $user, $registration, self::SETTLE );
		} catch ( \RuntimeException $e ) {
			self::queue( $program_id, $user, $registration, 60 );
		}
	}

	/**
	 * Handler of the sync job. Throws so the queue retries.
	 *
	 * @param array $payload Holds `program`, `user` and `registration`.
	 *
	 * @return void
	 * @throws \RuntimeException When the LRS cannot be reached.
	 */
	public function run_job( array $payload ): void {
		$registration = (string) ( $payload['registration'] ?? '' );
		if ( ! Uuid::is_valid( $registration ) ) {
			return;
		}
		$this->sync( (int) ( $payload['program'] ?? 0 ), (int) ( $payload['user'] ?? 0 ), $registration );
	}

	/**
	 * Pull the registration, process the inbox, and write the LMS statements unless the inbox
	 * evaluation already did.
	 *
	 * @param integer $program_id   Program id.
	 * @param integer $user         User id.
	 * @param string  $registration Registration UUID.
	 *
	 * @return void
	 * @throws \RuntimeException When the LRS cannot be reached.
	 */
	public function sync( int $program_id, int $user, string $registration ): void {
		$key = strtolower( $registration );
		unset( $this->evaluated[ $key ] );
		$this->pull( $registration );
		$processor = new Inbox_Processor( $this->inbox );
		$rounds    = 0;
		while ( $rounds < self::PROCESS_ROUNDS && $processor->process_pending( self::PULL_LIMIT ) > 0 ) {
			++$rounds;
		}
		if ( empty( $this->evaluated[ $key ] ) && $this->write( $program_id, $user, $registration, null ) ) {
			self::queue( $program_id, $user, $registration );
		}
		unset( $this->evaluated[ $key ] );
	}

	/**
	 * Copy a registration's statements from the LRS into the inbox (`GET statements?registration=`).
	 *
	 * @param string $registration Registration UUID.
	 *
	 * @return integer New inbox rows.
	 * @throws \RuntimeException When the LRS cannot be read or the inbox refuses a row.
	 */
	public function pull( string $registration ): int {
		$resource = 'statements';
		$query    = array(
			'registration' => strtolower( $registration ),
			'limit'        => self::PULL_LIMIT,
			'ascending'    => 'true',
		);
		$added    = 0;
		for ( $page = 0; $page < self::PULL_PAGES; $page++ ) {
			$res  = $this->lrs->xapi( 'GET', $resource, $query );
			$data = 200 === $res->status ? $res->json() : null;
			if ( ! is_array( $data ) || ! is_array( $data['statements'] ?? null ) ) {
				throw new \RuntimeException( 'LRS answered ' . (int) $res->status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never echoed.
			}
			foreach ( $data['statements'] as $statement ) {
				if ( ! is_array( $statement ) ) {
					continue;
				}
				$result = $this->inbox->store( $statement, 'poll' );
				if ( Inbox_Repository::ERROR === $result ) {
					throw new \RuntimeException( 'The inbox refused a statement.' );
				}
				if ( Inbox_Repository::ADDED === $result ) {
					++$added;
				}
			}
			$next = More_Link::resolve( is_string( $data['more'] ?? null ) ? $data['more'] : '', Connector_Settings::get() );
			if ( null === $next ) {
				break;
			}
			list( $resource, $query ) = $next;
		}
		return $added;
	}

	/**
	 * The user's moveOn evaluation of a registration from the inbox.
	 *
	 * @param Course_Structure $structure    Structure.
	 * @param integer          $user         User id.
	 * @param string           $registration Registration UUID.
	 *
	 * @return Satisfaction_Result
	 */
	public function evaluation( Course_Structure $structure, int $user, string $registration ): Satisfaction_Result {
		return self::evaluate( $structure, $this->rows( $user, $registration ) );
	}

	/**
	 * The user's current cmi5 registration of a program; created and announced when asked and missing.
	 *
	 * @param integer $user       User id.
	 * @param integer $program_id Program id.
	 * @param boolean $create     Create it when missing.
	 *
	 * @return string|null
	 */
	public static function registration( int $user, int $program_id, bool $create ): ?string {
		$map   = new Registration_Map();
		$scope = 'cmi5:' . $program_id;
		$found = $map->find( $user, $scope );
		if ( null !== $found || ! $create ) {
			return $found;
		}
		$registration = $map->get_or_create( $user, $scope );
		do_action( 'learning_ledger/cmi5_registration_created', $user, $program_id, $registration ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		return $registration;
	}

	/**
	 * The session id of the LMS's `waived` for an AU in a registration. One per AU, so a
	 * registration holds at most one `waived` id per AU [cmi5 9.3.7].
	 *
	 * @param string $registration Registration UUID.
	 * @param string $au_id        AU publisher id.
	 *
	 * @return string
	 */
	public static function waiver_session( string $registration, string $au_id ): string {
		return Uuid::v5( Lms_Statements::NAMESPACE, strtolower( $registration ) . '|' . $au_id . '|waiver-session' );
	}

	/**
	 * Whether the course-xapi-mcp waive-recommended rule applies to a program: a package with
	 * a course-xapi-mcp IRI base (plain cmi5 imports have none) and the setting on.
	 *
	 * @param Program $program Program.
	 *
	 * @return boolean
	 */
	public static function waives_recommended( Program $program ): bool {
		return '' !== $program->iri_base && $program->waive_recommended;
	}

	/**
	 * POST one LMS statement; an identical resend of a stored one is a success too.
	 *
	 * @param Lrs_Client $lrs       LRS client.
	 * @param array      $statement Statement.
	 *
	 * @return integer The LRS status (200 or 204), or 409 when a different statement holds the id.
	 * @throws \RuntimeException On any other answer.
	 */
	public static function post( Lrs_Client $lrs, array $statement ): int {
		$res = $lrs->xapi( 'POST', 'statements', array(), (string) wp_json_encode( $statement, JSON_UNESCAPED_SLASHES ), array( 'Content-Type' => 'application/json' ) );
		if ( ! in_array( $res->status, array( 200, 204, 409 ), true ) ) {
			throw new \RuntimeException( 'LRS answered ' . (int) $res->status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never echoed.
		}
		return $res->status;
	}

	/**
	 * Write an LMS statement and copy the LRS's copy into the inbox by id (a registration query
	 * would not list it for another second), so the next evaluation sees it.
	 *
	 * @param array $statement Statement.
	 *
	 * @return integer The LRS status, see post().
	 * @throws \RuntimeException When the LRS refuses or cannot be read.
	 */
	public function record( array $statement ): int {
		$status = self::post( $this->lrs, $statement );
		if ( 409 === $status ) {
			return $status;
		}
		$res  = $this->lrs->xapi( 'GET', 'statements', array( 'statementId' => $statement['id'] ) );
		$data = 200 === $res->status ? $res->json() : null;
		if ( ! is_array( $data ) || Inbox_Repository::ERROR === $this->inbox->store( $data, 'poll' ) ) {
			throw new \RuntimeException( 'LRS answered ' . (int) $res->status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never echoed.
		}
		return $status;
	}

	/**
	 * Close sessions the inbox has `terminated` for, then write the LMS statements of a cmi5 registration.
	 *
	 * @param integer                  $program_id   Program id.
	 * @param integer                  $user         User id.
	 * @param string                   $registration Registration UUID.
	 * @param Satisfaction_Result|null $e            The evaluation, or null to evaluate from the inbox.
	 *
	 * @return boolean Whether a waiver was written.
	 * @throws \RuntimeException When the LRS refuses.
	 */
	private function write( int $program_id, int $user, string $registration, ?Satisfaction_Result $e ): bool {
		if ( $user <= 0 || ! Uuid::is_valid( $registration ) ) {
			return false;
		}
		$rows = $this->rows( $user, $registration );
		$this->close_terminated( $rows, $user, $registration );

		$program   = $this->programs->get( $program_id );
		$structure = $this->programs->structure( $program_id );
		if ( null === $program || null === $structure || ! (int) get_post_meta( $program_id, '_lledger_player', true ) ) {
			return false;
		}
		if ( strtolower( (string) self::registration( $user, $program_id, false ) ) !== strtolower( $registration ) ) {
			return false; // A preview registration, or one that has ended.
		}

		$e    ??= self::evaluate( $structure, $rows );
		$actor  = ( new Identity_Map() )->actor_for_user( $user );
		$waived = self::waives_recommended( $program ) ? $this->recommended_waivers( $structure, $e, $rows, $actor, $registration ) : 0;
		$this->satisfied( $structure, $e, $rows, $actor, $registration );
		return $waived > 0;
	}

	/**
	 * `waived` "Tested Out" for every AU a statement of the registration recommends that is not satisfied.
	 *
	 * @param Course_Structure    $structure    Structure.
	 * @param Satisfaction_Result $e            Evaluation.
	 * @param array[]             $rows         The user's inbox rows of the registration.
	 * @param array               $actor        Learner actor.
	 * @param string              $registration Registration UUID.
	 *
	 * @return integer Waivers written.
	 * @throws \RuntimeException When the LRS refuses.
	 */
	private function recommended_waivers( Course_Structure $structure, Satisfaction_Result $e, array $rows, array $actor, string $registration ): int {
		$done = array();
		foreach ( $rows as $row ) {
			$list = $row['statement']['result']['extensions'][ Vocab::CXM_EXT_WAIVE_RECOMMENDED ] ?? null;
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $au_id ) {
				$au = is_string( $au_id ) ? $structure->find_au( $au_id ) : null;
				if ( null === $au || isset( $done[ $au->id() ] ) || $e->au_satisfied( $au->id(), Waiver_Policy::ALL ) ) {
					continue;
				}
				$done[ $au->id() ] = true;
				$this->record( ( new Lms_Statements() )->waived( $au, $actor, $registration, self::waiver_session( $registration, $au->id() ), self::TESTED_OUT ) );
			}
		}
		return count( $done );
	}

	/**
	 * `satisfied` for each satisfied block and the course that the registration does not have one for.
	 *
	 * @param Course_Structure    $structure    Structure.
	 * @param Satisfaction_Result $e            Evaluation.
	 * @param array[]             $rows         The user's inbox rows of the registration.
	 * @param array               $actor        Learner actor.
	 * @param string              $registration Registration UUID.
	 *
	 * @return void
	 * @throws \RuntimeException When the LRS refuses.
	 */
	private function satisfied( Course_Structure $structure, Satisfaction_Result $e, array $rows, array $actor, string $registration ): void {
		$have = array();
		foreach ( $rows as $row ) {
			if ( Vocab::SATISFIED === $row['verb_id'] ) {
				$have[ $row['object_id'] ] = true;
			}
		}
		$targets = array();
		foreach ( $structure->blocks() as $block ) {
			if ( $e->block_satisfied( $block->id(), Waiver_Policy::ALL ) ) {
				$targets[ $block->id() ] = $e->block_satisfying_statement( $block->id(), Waiver_Policy::ALL );
			}
		}
		if ( $e->course_satisfied( Waiver_Policy::ALL ) ) {
			$targets[ $structure->course_id() ] = self::latest_satisfying( $structure, $e, $rows );
		}

		$lms = new Lms_Statements();
		foreach ( $targets as $object_id => $statement_id ) {
			if ( isset( $have[ $object_id ] ) ) {
				continue;
			}
			$session = self::session_of( $rows, $statement_id ) ?? Uuid::v5( Lms_Statements::NAMESPACE, strtolower( $registration ) . '|' . $object_id . '|satisfied-session' );
			$this->record( $lms->satisfied( (string) $object_id, $actor, $registration, $session ) );
		}
	}

	/**
	 * Mark open sessions of the registration terminated when the inbox holds their `terminated` [F23].
	 *
	 * @param array[] $rows         The user's inbox rows of the registration.
	 * @param integer $user         User id.
	 * @param string  $registration Registration UUID.
	 *
	 * @return void
	 */
	private function close_terminated( array $rows, int $user, string $registration ): void {
		foreach ( $rows as $row ) {
			if ( Vocab::TERMINATED !== $row['verb_id'] ) {
				continue;
			}
			$id = $row['statement']['context']['extensions'][ Vocab::EXT_SESSIONID ] ?? null;
			if ( ! is_string( $id ) || ! Uuid::is_valid( $id ) ) {
				continue;
			}
			$session = $this->sessions->find( $id );
			if ( null === $session || Session_Repository::OPEN !== $session['status'] || $session['user_id'] !== $user || strtolower( $session['registration'] ) !== strtolower( $registration ) ) {
				continue;
			}
			$this->sessions->mark( $session['session_id'], Session_Repository::TERMINATED, self::time_of( $row ) );
		}
	}

	/**
	 * The user's inbox rows of a registration, voided ones left out, in stored order.
	 *
	 * @param integer $user         User id.
	 * @param string  $registration Registration UUID.
	 *
	 * @return array[]
	 */
	private function rows( int $user, string $registration ): array {
		try {
			$hash = Agent_Identity::from_array( ( new Identity_Map() )->actor_for_user( $user ) )->hash();
		} catch ( Invalid_Input $e ) {
			$hash = '';
		}
		$voided = array_flip( $this->inbox->voided_ids( $registration ) );
		$out    = array();
		foreach ( $this->inbox->for_registration( $registration ) as $row ) {
			if ( isset( $voided[ $row['statement_id'] ] ) || ( $row['user_id'] !== $user && $row['actor_hash'] !== $hash ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Evaluate rows against a structure.
	 *
	 * @param Course_Structure $structure Structure.
	 * @param array[]          $rows      Inbox rows in stored order.
	 *
	 * @return Satisfaction_Result
	 */
	private static function evaluate( Course_Structure $structure, array $rows ): Satisfaction_Result {
		$facts = array();
		foreach ( $rows as $row ) {
			$fact = Au_Fact::from_statement( $row['statement'], $structure );
			if ( $fact ) {
				$facts[] = $fact;
			}
		}
		return Satisfaction_Evaluator::evaluate( $structure, $facts );
	}

	/**
	 * The satisfying statement of the course: the course's child satisfied last in stored order.
	 *
	 * @param Course_Structure    $structure Structure.
	 * @param Satisfaction_Result $e         Evaluation.
	 * @param array[]             $rows      Inbox rows in stored order.
	 *
	 * @return string|null
	 */
	private static function latest_satisfying( Course_Structure $structure, Satisfaction_Result $e, array $rows ): ?string {
		$ids = array();
		foreach ( $structure->children() as $child ) {
			$id = $child instanceof Au ? $e->au_satisfying_statement( $child->id() ) : $e->block_satisfying_statement( $child->id(), Waiver_Policy::ALL );
			if ( null !== $id ) {
				$ids[ $id ] = true;
			}
		}
		$latest = null;
		foreach ( $rows as $row ) {
			if ( isset( $ids[ $row['statement_id'] ] ) ) {
				$latest = $row['statement_id'];
			}
		}
		return $latest;
	}

	/**
	 * The session id carried by one of the rows' statements.
	 *
	 * @param array[]     $rows         Inbox rows.
	 * @param string|null $statement_id Statement id.
	 *
	 * @return string|null
	 */
	private static function session_of( array $rows, ?string $statement_id ): ?string {
		if ( null === $statement_id ) {
			return null;
		}
		foreach ( $rows as $row ) {
			if ( strtolower( $statement_id ) === $row['statement_id'] ) {
				$id = $row['statement']['context']['extensions'][ Vocab::EXT_SESSIONID ] ?? null;
				return is_string( $id ) && Uuid::is_valid( $id ) ? strtolower( $id ) : null;
			}
		}
		return null;
	}

	/**
	 * A row's statement time (timestamp, else stored) in database format.
	 *
	 * @param array $row Inbox row.
	 *
	 * @return string
	 */
	private static function time_of( array $row ): string {
		$value = $row['statement']['timestamp'] ?? null;
		if ( is_string( $value ) ) {
			try {
				return Timestamp::to_db( Timestamp::parse( $value, '1.0.3' ) );
			} catch ( Invalid_Input $e ) {
				return $row['stored'];
			}
		}
		return $row['stored'];
	}

	/**
	 * Queue a sync of a registration (one pending job per registration).
	 *
	 * @param integer $program_id   Program id.
	 * @param integer $user         User id.
	 * @param string  $registration Registration UUID.
	 * @param integer $delay        Seconds before it is due.
	 *
	 * @return void
	 */
	private static function queue( int $program_id, int $user, string $registration, int $delay = 0 ): void {
		Job_Queue::push(
			self::SYNC_JOB,
			array(
				'program'      => $program_id,
				'user'         => $user,
				'registration' => strtolower( $registration ),
			),
			self::SYNC_JOB . ':' . strtolower( $registration ),
			$delay
		);
	}
}
