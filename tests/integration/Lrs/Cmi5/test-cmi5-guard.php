<?php
/**
 * cmi5 guard tests, through the xAPI router with real child tokens.
 *
 * @package LearningLedger
 */

use LearningLedger\Cmi5\Vocab;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Auth\Token_Repository;
use LearningLedger\Lrs\Auth\Token_Spec;
use LearningLedger\Lrs\Cmi5\Cmi5_Guard;
use LearningLedger\Lrs\Documents\Doc_Key;
use LearningLedger\Lrs\Documents\Document_Store;
use LearningLedger\Lrs\Http\Xapi_Response;
use LearningLedger\Lrs\Http\Xapi_Router;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Tests\Xapi_Test_Client;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Json;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

class Cmi5_Guard_Test extends WP_UnitTestCase {

	const AU       = 'https://lms.example/course/au-1/au';
	const REG      = '6f1c3c1e-9a5e-4c1b-8d4e-2b7f0c9a1d11';
	const SESSION  = '1d2c3b4a-5e6f-4a7b-8c9d-0e1f2a3b4c5d';
	const SESSION2 = '2e3d4c5b-6f70-4b8c-9dae-1f2a3b4c5d6e';
	const SESSION3 = '3f4e5d6c-7081-4c9d-8ebf-2a3b4c5d6e7f';
	const NOW      = '2026-10-27T10:00:00Z';

	/**
	 * The LMS client (full client, guard on by default).
	 *
	 * @var array{id:string,secret:string}
	 */
	private array $lms;

	/**
	 * Ids of the `initialized` statements child() sends to start a session; count_rows() leaves them out.
	 *
	 * @var string[]
	 */
	private array $starts = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		self::clean();
		Clock::freeze( new DateTimeImmutable( self::NOW ) );
		( new Xapi_Router() )->register();
		( new Cmi5_Guard() )->register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
		$this->lms = Client_Factory::create( array( Scopes::STATEMENTS_WRITE, Scopes::STATEMENTS_READ, Scopes::STATE, Scopes::PROFILE, Scopes::VOID, Scopes::TOKENS_MINT ) );
	}

	public function tear_down() {
		Clock::freeze( null );
		$GLOBALS['wp_rest_server'] = null;
		self::clean();
		$GLOBALS['wpdb']->query( 'COMMIT' );
		parent::tear_down();
	}

	/**
	 * Empty the tables these tests write. The statement store commits its own transaction,
	 * so the test case's rollback does not undo these rows; tear_down() commits the cleanup.
	 */
	private static function clean(): void {
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'tokens', 'clients', 'documents' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	private static function actor(): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => 'https://lms.example',
				'name'     => 'learner-1',
			),
		);
	}

	/**
	 * Mint a child token of a client and return a test client sending it, plus its key. Unless
	 * $start is false the AU starts its session as cmi5 has it: it reads its learner preferences
	 * and sends `initialized`.
	 *
	 * @return array{0:Xapi_Test_Client,1:string,2:string} Client, key and secret.
	 */
	private function child( string $mode = 'Normal', string $session = self::SESSION, ?array $minter = null, bool $guard = true, bool $start = true ): array {
		$minter = $minter ?? $this->lms;
		$client = ( new Client_Repository() )->find( $minter['id'] );
		$t      = ( new Token_Repository() )->mint( $client, new Token_Spec( self::REG, self::actor(), self::AU, $session, $mode, 3600, array(), $guard ) );
		$au     = Xapi_Test_Client::as( $t['key'], $t['secret'] );
		if ( $start ) {
			self::read_preferences( $au );
			$initialized = self::defined( Vocab::INITIALIZED, $session );
			if ( 200 === self::post( $au, array( $initialized ) )->status() ) {
				$this->starts[] = $initialized['id'];
			}
		}
		return array( $au, $t['key'], $t['secret'] );
	}

	/**
	 * The AU's GET of its cmi5LearnerPreferences agent profile.
	 */
	private static function read_preferences( Xapi_Test_Client $au, ?array $agent = null ): Xapi_Response {
		return $au->request(
			'GET',
			'agents/profile',
			array(
				'profileId' => 'cmi5LearnerPreferences',
				'agent'     => wp_json_encode( $agent ?? self::actor() ),
			)
		);
	}

	/**
	 * A cmi5 defined statement for the AU.
	 */
	private static function defined( string $verb, string $session = self::SESSION, array $extra = array() ): array {
		$category = array( array( 'id' => Vocab::CAT_CMI5 ) );
		$result   = null;
		switch ( $verb ) {
			case Vocab::COMPLETED:
				$category[] = array( 'id' => Vocab::CAT_MOVEON );
				$result     = array(
					'completion' => true,
					'duration'   => 'PT5M',
				);
				break;
			case Vocab::PASSED:
				$category[] = array( 'id' => Vocab::CAT_MOVEON );
				$result     = array(
					'success'  => true,
					'duration' => 'PT5M',
				);
				break;
			case Vocab::FAILED:
				$category[] = array( 'id' => Vocab::CAT_MOVEON );
				$result     = array(
					'success'  => false,
					'duration' => 'PT5M',
				);
				break;
			case Vocab::TERMINATED:
				$result = array( 'duration' => 'PT10M' );
				break;
		}
		$st = array(
			'id'        => Uuid::v4(),
			'timestamp' => '2026-10-27T10:00:00.000Z',
			'actor'     => self::actor(),
			'verb'      => array( 'id' => $verb ),
			'object'    => array(
				'objectType' => 'Activity',
				'id'         => self::AU,
			),
			'context'   => array(
				'registration'      => self::REG,
				'contextActivities' => array(
					'category' => $category,
					'grouping' => array( array( 'id' => 'https://lms.example/course/au-1' ) ),
				),
				'extensions'        => array( Vocab::EXT_SESSIONID => $session ),
			),
		);
		if ( null !== $result ) {
			$st['result'] = $result;
		}
		return array_replace_recursive( $st, $extra );
	}

	/**
	 * A cmi5 allowed statement (no cmi5 category).
	 */
	private static function allowed( string $verb = 'http://adlnet.gov/expapi/verbs/experienced', string $session = self::SESSION ): array {
		$st = self::defined( $verb, $session );
		unset( $st['context']['contextActivities']['category'], $st['result'] );
		return $st;
	}

	private static function post( Xapi_Test_Client $c, array $statements ): Xapi_Response {
		return $c->request( 'POST', 'statements', array(), Json::encode_exact( $statements ), array( 'Content-Type' => 'application/json' ) );
	}

	private function count_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'statements' ) ) - count( $this->starts ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function assert_status( int $expected, Xapi_Response $res, string $label = '' ): void {
		$this->assertSame( $expected, $res->status(), $label . ' ' . $res->body() );
	}

	public function test_valid_sequence_is_accepted_and_stored_terminated_marks_the_token() {
		[ $au, $key ] = $this->child( 'Normal', self::SESSION, null, true, false );
		self::read_preferences( $au );

		foreach ( array( Vocab::INITIALIZED, Vocab::COMPLETED, Vocab::PASSED ) as $verb ) {
			$this->assert_status( 200, self::post( $au, array( self::defined( $verb ) ) ), $verb );
		}
		$this->assertNull( ( new Token_Repository() )->find( $key )->terminated_at );

		Clock::freeze( new DateTimeImmutable( '2026-10-27T10:05:00Z' ) );
		$terminated = self::defined( Vocab::TERMINATED );
		$this->assert_status( 200, self::post( $au, array( $terminated ) ), 'terminated' );
		$this->assertSame( 4, $this->count_rows() );

		// The child token authenticated the POSTs: the stored authority names the token.
		$stored = ( new Statement_Repository() )->find( $terminated['id'] );
		$this->assertSame( 'tok:' . $key, $stored->authority_client );
		$this->assertSame( $this->lms['id'] . '/' . $key, $stored->statement()['authority']['account']['name'] );

		$token = ( new Token_Repository() )->find( $key );
		$this->assertNotNull( $token->terminated_at );
		$this->assertSame( '2026-10-27T10:05:00+00:00', $token->terminated_at->format( DATE_ATOM ) );
	}

	public function test_valid_sequence_in_one_batch_is_accepted() {
		[ $au, $key ] = $this->child( 'Normal', self::SESSION, null, true, false );
		self::read_preferences( $au );
		$res = self::post( $au, array( self::defined( Vocab::INITIALIZED ), self::allowed(), self::defined( Vocab::COMPLETED ), self::defined( Vocab::PASSED ), self::defined( Vocab::TERMINATED ) ) );
		$this->assert_status( 200, $res );
		$this->assertSame( 5, $this->count_rows() );
		$this->assertSame( '2026-10-27T10:00:00+00:00', ( new Token_Repository() )->find( $key )->terminated_at->format( DATE_ATOM ) );
	}

	public function test_identical_resend_is_left_to_the_store() {
		[ $au ] = $this->child();
		$passed = self::defined( Vocab::PASSED );
		$this->assert_status( 200, self::post( $au, array( $passed ) ), 'passed' );
		$this->assert_status( 200, self::post( $au, array( $passed ) ), 'passed resent' );
		$this->assert_status( 204, $au->request( 'PUT', 'statements', array( 'statementId' => $passed['id'] ), Json::encode_exact( $passed ), array( 'Content-Type' => 'application/json' ) ), 'passed resent by PUT' );
		$changed                       = $passed;
		$changed['result']['duration'] = 'PT6M';
		$this->assert_status( 409, self::post( $au, array( $changed ) ), 'same id, different content' );

		$terminated = self::defined( Vocab::TERMINATED );
		$this->assert_status( 200, self::post( $au, array( $terminated ) ), 'terminated' );
		$this->assert_status( 200, self::post( $au, array( $terminated ) ), 'terminated resent' );
		$this->assertSame( 2, $this->count_rows() );

		// After terminated only terminated itself may be resent [cmi5 9.3.0.0-5]; a new statement is still judged.
		$this->assert_status( 403, self::post( $au, array( $passed ) ), 'passed resent after terminated' );
		$this->assert_status( 403, self::post( $au, array( self::defined( Vocab::COMPLETED ) ) ), 'new defined statement' );
		$this->assertSame( 2, $this->count_rows() );
	}

	public function test_lms_only_verbs_are_rejected_403() {
		[ $au ] = $this->child();
		foreach ( array( Vocab::LAUNCHED, Vocab::WAIVED, Vocab::SATISFIED, Vocab::ABANDONED ) as $verb ) {
			$this->assert_status( 403, self::post( $au, array( self::defined( $verb ) ) ), 'defined ' . $verb );
			$this->assert_status( 403, self::post( $au, array( self::allowed( $verb ) ) ), 'allowed ' . $verb );
		}
		$this->assertSame( 0, $this->count_rows() );
	}

	/**
	 * CATAPULT 005-1-invalid-au, cmi5 9.6.3.1-4: defined and allowed statements carry the session id.
	 */
	public function test_sessionid_missing_or_of_another_session_is_403() {
		[ $au ] = $this->child( 'Normal', self::SESSION, null, true, false );
		self::read_preferences( $au );

		$missing = self::defined( Vocab::INITIALIZED );
		unset( $missing['context']['extensions'] );
		$this->assert_status( 403, self::post( $au, array( $missing ) ), 'missing' );

		$not_string = self::defined( Vocab::INITIALIZED );
		$not_string['context']['extensions'][ Vocab::EXT_SESSIONID ] = 42;
		$this->assert_status( 403, self::post( $au, array( $not_string ) ), 'not a string' );

		$this->assert_status( 403, self::post( $au, array( self::defined( Vocab::INITIALIZED, self::SESSION2 ) ) ), 'other session' );
		$this->assert_status( 403, self::post( $au, array( self::allowed( 'http://adlnet.gov/expapi/verbs/experienced', self::SESSION2 ) ) ), 'allowed, other session' );
		$this->assertSame( 0, $this->count_rows() );

		// The session id compares case-insensitively; an allowed statement carries it too.
		$this->assert_status( 200, self::post( $au, array( self::defined( Vocab::INITIALIZED, strtoupper( self::SESSION ) ) ) ), 'upper case' );
		$bare = self::allowed();
		unset( $bare['context']['extensions'] );
		$this->assert_status( 403, self::post( $au, array( $bare ) ), 'allowed without session' );
		$this->assert_status( 200, self::post( $au, array( self::allowed() ) ), 'allowed with it' );
	}

	public function test_browse_and_review_allow_only_initialized_terminated_among_defined() {
		foreach ( array(
			'Browse' => self::SESSION,
			'Review' => self::SESSION2,
		) as $mode => $session ) {
			[ $au ] = $this->child( $mode, $session, null, true, false );
			self::read_preferences( $au );
			$this->assert_status( 200, self::post( $au, array( self::defined( Vocab::INITIALIZED, $session ) ) ), $mode . ' initialized' );
			$this->assert_status( 200, self::post( $au, array( self::allowed( 'http://adlnet.gov/expapi/verbs/experienced', $session ) ) ), $mode . ' allowed' );
			$this->assert_status( 200, self::post( $au, array( self::allowed( Vocab::COMPLETED, $session ) ) ), $mode . ' allowed completed verb' );
			foreach ( array( Vocab::COMPLETED, Vocab::PASSED, Vocab::FAILED ) as $verb ) {
				$this->assert_status( 403, self::post( $au, array( self::defined( $verb, $session ) ) ), $mode . ' ' . $verb );
			}
			$this->assert_status( 200, self::post( $au, array( self::defined( Vocab::TERMINATED, $session ) ) ), $mode . ' terminated' );
		}
		$this->assertSame( 8, $this->count_rows() );
	}

	public function test_second_passed_in_the_registration_is_rejected() {
		[ $first ] = $this->child();
		$this->assert_status( 200, self::post( $first, array( self::defined( Vocab::PASSED ) ) ) );
		$this->assert_status( 403, self::post( $first, array( self::defined( Vocab::PASSED ) ) ), 'same session' );

		[ $later ] = $this->child( 'Normal', self::SESSION2 );
		$this->assert_status( 403, self::post( $later, array( self::defined( Vocab::PASSED, self::SESSION2 ) ) ), 'later session' );
		$this->assertSame( 1, $this->count_rows() );
	}

	public function test_second_passed_in_one_batch_is_rejected() {
		[ $au ] = $this->child();
		$this->assert_status( 403, self::post( $au, array( self::defined( Vocab::PASSED ), self::defined( Vocab::PASSED ) ) ) );
		$this->assertSame( 0, $this->count_rows() );
	}

	public function test_voided_passed_does_not_count() {
		[ $first ] = $this->child();
		$passed    = self::defined( Vocab::PASSED );
		$this->assert_status( 200, self::post( $first, array( $passed ) ) );

		$void = array(
			'actor'   => self::actor(),
			'verb'    => array( 'id' => 'http://adlnet.gov/expapi/verbs/voided' ),
			'object'  => array(
				'objectType' => 'StatementRef',
				'id'         => $passed['id'],
			),
			'context' => array( 'registration' => self::REG ),
		);
		$this->assert_status( 200, self::post( Xapi_Test_Client::as( $this->lms['id'], $this->lms['secret'] ), array( $void ) ), 'void' );

		[ $later ] = $this->child( 'Normal', self::SESSION2 );
		$this->assert_status( 200, self::post( $later, array( self::defined( Vocab::PASSED, self::SESSION2 ) ) ) );
	}

	public function test_failed_after_passed_is_rejected() {
		[ $s1 ] = $this->child();
		$this->assert_status( 200, self::post( $s1, array( self::defined( Vocab::FAILED ) ) ), 'failed first' );
		[ $s2 ] = $this->child( 'Normal', self::SESSION2 );
		$this->assert_status( 200, self::post( $s2, array( self::defined( Vocab::PASSED, self::SESSION2 ) ) ), 'then passed' );
		[ $s3 ] = $this->child( 'Normal', self::SESSION3 );
		$this->assert_status( 403, self::post( $s3, array( self::defined( Vocab::FAILED, self::SESSION3 ) ) ), 'failed after passed' );
		$this->assert_status( 403, self::post( $s3, array( self::defined( Vocab::PASSED, self::SESSION3 ), self::defined( Vocab::FAILED, self::SESSION3 ) ) ), 'in one batch' );
		$this->assertSame( 2, $this->count_rows() );
	}

	/**
	 * CATAPULT 005-2-invalid-au, cmi5 9.3.0.0-2 and 9.3.0.0-3: within one session a cmi5 defined
	 * verb is not repeated, and only one of passed or failed is sent; a later session may retry.
	 */
	public function test_repeated_verb_or_second_judgement_in_the_session_is_rejected() {
		[ $s1 ] = $this->child( 'Normal', self::SESSION, null, true, false );
		self::read_preferences( $s1 );
		$this->assert_status( 200, self::post( $s1, array( self::defined( Vocab::INITIALIZED ) ) ), 'initialized' );
		$this->assert_status( 403, self::post( $s1, array( self::defined( Vocab::INITIALIZED ) ) ), 'initialized again' );
		$this->assert_status( 200, self::post( $s1, array( self::defined( Vocab::FAILED ) ) ), 'failed' );
		$this->assert_status( 403, self::post( $s1, array( self::defined( Vocab::FAILED ) ) ), 'failed again' );
		$this->assert_status( 403, self::post( $s1, array( self::defined( Vocab::PASSED ) ) ), 'passed after failed' );
		$this->assertSame( 2, $this->count_rows() );

		[ $s2 ] = $this->child( 'Normal', self::SESSION2 );
		$this->assert_status( 403, self::post( $s2, array( self::defined( Vocab::FAILED, self::SESSION2 ), self::defined( Vocab::FAILED, self::SESSION2 ) ) ), 'failed twice in one batch' );
		$this->assert_status( 200, self::post( $s2, array( self::defined( Vocab::FAILED, self::SESSION2 ) ) ), 'a later session retries' );
		[ $s3 ] = $this->child( 'Normal', self::SESSION3 );
		$this->assert_status( 200, self::post( $s3, array( self::defined( Vocab::PASSED, self::SESSION3 ) ) ), 'passed in a later session' );
		$this->assertSame( 4, $this->count_rows() );
	}

	public function test_second_completed_is_rejected() {
		[ $s1 ] = $this->child();
		$this->assert_status( 200, self::post( $s1, array( self::defined( Vocab::COMPLETED ) ) ) );
		$this->assert_status( 403, self::post( $s1, array( self::defined( Vocab::COMPLETED ) ) ), 'same session' );
		[ $s2 ] = $this->child( 'Normal', self::SESSION2 );
		$this->assert_status( 403, self::post( $s2, array( self::defined( Vocab::COMPLETED, self::SESSION2 ) ) ), 'later session' );
		$this->assertSame( 1, $this->count_rows() );
	}

	/**
	 * CATAPULT 005-1-invalid-au, cmi5 9.3.0.0-5: terminated is the last statement, allowed ones included.
	 */
	public function test_every_statement_after_terminated_is_rejected() {
		[ $au, $key ] = $this->child( 'Normal', self::SESSION, null, true, false );
		self::read_preferences( $au );
		$this->assert_status( 200, self::post( $au, array( self::defined( Vocab::INITIALIZED ), self::defined( Vocab::TERMINATED ) ) ) );

		foreach ( array( Vocab::COMPLETED, Vocab::PASSED, Vocab::INITIALIZED, Vocab::TERMINATED ) as $verb ) {
			$this->assert_status( 403, self::post( $au, array( self::defined( $verb ) ) ), 'defined ' . $verb );
		}
		$this->assert_status( 403, self::post( $au, array( self::allowed() ) ), 'allowed, at once' );

		// A token of the same session is held to the same end.
		[ $again ] = $this->child();
		$this->assert_status( 403, self::post( $again, array( self::defined( Vocab::COMPLETED ) ) ), 'second token of the session' );

		$this->assertSame( 2, $this->count_rows() );
		$this->assertNotNull( ( new Token_Repository() )->find( $key )->terminated_at );
	}

	public function test_defined_statement_after_terminated_in_one_batch_is_rejected() {
		[ $au, $key ] = $this->child();
		$this->assert_status( 403, self::post( $au, array( self::defined( Vocab::TERMINATED ), self::defined( Vocab::COMPLETED ) ) ) );
		$this->assertSame( 0, $this->count_rows() );
		$this->assertNull( ( new Token_Repository() )->find( $key )->terminated_at );
	}

	public function test_defined_statement_about_another_activity_is_rejected() {
		[ $au ] = $this->child();
		$other  = self::defined(
			Vocab::PASSED,
			self::SESSION,
			array(
				'object' => array( 'id' => 'https://lms.example/course/au-2/au' ),
			)
		);
		$this->assert_status( 403, self::post( $au, array( $other ) ) );
		$this->assertSame( 0, $this->count_rows() );
	}

	/**
	 * CATAPULT 005-1-invalid-au, cmi5 9.3.0.0-4 and 11.0.0.0-3: initialized is the first statement of
	 * the session, and the AU reads its learner preferences before it.
	 */
	public function test_initialized_comes_first_after_the_learner_preferences() {
		[ $au ] = $this->child( 'Normal', self::SESSION, null, true, false );
		$this->assert_status( 403, self::post( $au, array( self::allowed() ) ), 'allowed before initialized' );
		$this->assert_status( 403, self::post( $au, array( self::defined( Vocab::INITIALIZED ) ) ), 'initialized before the preferences' );
		$this->assertSame( 404, self::read_preferences( $au )->status() );
		$this->assert_status( 403, self::post( $au, array( self::defined( Vocab::COMPLETED ), self::defined( Vocab::INITIALIZED ) ) ), 'completed before initialized in one batch' );
		$this->assert_status( 200, self::post( $au, array( self::defined( Vocab::INITIALIZED ), self::allowed() ) ), 'initialized, then allowed' );
		$this->assertSame( 2, $this->count_rows() );

		// Another token's read does not count for this one.
		[ $other ] = $this->child( 'Normal', self::SESSION2, null, true, false );
		$this->assert_status( 403, self::post( $other, array( self::defined( Vocab::INITIALIZED, self::SESSION2 ) ) ) );
	}

	/**
	 * T64 review: a host that hands Basic credentials to PHP only as PHP_AUTH_USER/PHP_AUTH_PW (no
	 * Authorization header) still has the preferences read noted, from the principal the router
	 * resolved; a read of another agent's preferences does not count.
	 */
	public function test_preferences_read_counts_with_php_auth_credentials_and_only_for_the_tokens_actor() {
		[ $header_client, $key, $secret ] = $this->child( 'Normal', self::SESSION, null, true, false );
		$server                           = $_SERVER;
		$bare                             = new Xapi_Test_Client();
		try {
			$_SERVER['PHP_AUTH_USER'] = $key;
			$_SERVER['PHP_AUTH_PW']   = $secret;
			$other                    = self::actor();
			$other['account']['name'] = 'learner-2';
			self::read_preferences( $bare, $other );
			$this->assert_status( 403, self::post( $bare, array( self::defined( Vocab::INITIALIZED ) ) ), 'another agent\'s preferences do not count' );
			$this->assertNotSame( 401, self::read_preferences( $bare )->status() );
			$this->assert_status( 200, self::post( $bare, array( self::defined( Vocab::INITIALIZED ) ) ), 'initialized after the read' );
		} finally {
			$_SERVER = $server;
		}
		unset( $header_client );
	}

	/**
	 * CATAPULT 005-1-invalid-au, cmi5 9.1.0.0-1, 9.2.0.0-2/3, 9.6.0.0-1, 9.6.1.0-1, 9.7.0.0-1/2:
	 * every AU statement has an id, a UTC timestamp, the launched account Agent and the launch registration.
	 */
	public function test_every_statement_has_an_id_utc_timestamp_the_learner_and_the_registration() {
		[ $au ] = $this->child();
		$broken = array(
			'no id'                => static function ( array &$st ) {
				unset( $st['id'] );
			},
			'no timestamp'         => static function ( array &$st ) {
				unset( $st['timestamp'] );
			},
			'non-UTC timestamp'    => static function ( array &$st ) {
				$st['timestamp'] = '2026-10-27T04:00:00.000-06:00';
			},
			'Group actor'          => static function ( array &$st ) {
				$st['actor']['objectType'] = 'Group';
			},
			'mbox actor'           => static function ( array &$st ) {
				unset( $st['actor']['account'] );
				$st['actor']['mbox'] = 'mailto:learner@example.org';
			},
			'another learner'      => static function ( array &$st ) {
				$st['actor']['account']['name'] = 'learner-2';
			},
			'no context'           => static function ( array &$st ) {
				unset( $st['context'] );
			},
			'no registration'      => static function ( array &$st ) {
				unset( $st['context']['registration'] );
			},
			'another registration' => static function ( array &$st ) {
				$st['context']['registration'] = 'ccaf384c-f8d4-4e7a-8304-49af58f0b176';
			},
		);
		foreach ( $broken as $label => $alter ) {
			foreach ( array( self::allowed(), self::defined( Vocab::COMPLETED ) ) as $st ) {
				$alter( $st );
				$this->assert_status( 403, self::post( $au, array( $st ) ), $label );
			}
		}
		$this->assertSame( 0, $this->count_rows() );
		$utc              = self::allowed();
		$utc['timestamp'] = '2026-10-27T10:00:00+00:00';
		$this->assert_status( 200, self::post( $au, array( $utc ) ), '+00:00 is UTC' );
	}

	/**
	 * CATAPULT 005-1-invalid-au, cmi5 9.6.2.2-1/2: the moveOn category is on defined completed,
	 * passed and failed statements, and on nothing else.
	 */
	public function test_moveon_category_marks_exactly_the_judging_statements() {
		[ $au ] = $this->child();
		foreach ( array( Vocab::COMPLETED, Vocab::PASSED, Vocab::FAILED ) as $verb ) {
			$st = self::defined( $verb );
			array_pop( $st['context']['contextActivities']['category'] );
			$this->assert_status( 403, self::post( $au, array( $st ) ), $verb . ' without moveOn' );
		}
		$allowed = self::allowed();
		$allowed['context']['contextActivities']['category'] = array( array( 'id' => Vocab::CAT_MOVEON ) );
		$this->assert_status( 403, self::post( $au, array( $allowed ) ), 'allowed with moveOn' );
		$terminated = self::defined( Vocab::TERMINATED );
		$terminated['context']['contextActivities']['category'][] = array( 'id' => Vocab::CAT_MOVEON );
		$this->assert_status( 403, self::post( $au, array( $terminated ) ), 'terminated with moveOn' );
		$this->assertSame( 0, $this->count_rows() );
	}

	/**
	 * CATAPULT 005-1-invalid-au, cmi5 9.5.1-9.5.4: result rules of the judging and terminated verbs.
	 */
	public function test_result_rules_of_the_defined_verbs() {
		[ $au ] = $this->child();
		$cases  = array(
			array( Vocab::COMPLETED, array( 'completion' => null ), 'completed without completion' ),
			array( Vocab::COMPLETED, array( 'completion' => false ), 'completed with completion false' ),
			array( Vocab::COMPLETED, array( 'success' => true ), 'completed with success' ),
			array( Vocab::COMPLETED, array( 'score' => array( 'scaled' => 0.5 ) ), 'completed with a score' ),
			array( Vocab::COMPLETED, array( 'duration' => null ), 'completed without duration' ),
			array( Vocab::PASSED, array( 'success' => null ), 'passed without success' ),
			array( Vocab::PASSED, array( 'success' => false ), 'passed with success false' ),
			array( Vocab::PASSED, array( 'completion' => true ), 'passed with completion' ),
			array( Vocab::PASSED, array( 'duration' => null ), 'passed without duration' ),
			array(
				Vocab::PASSED,
				array(
					'score' => array(
						'raw' => 90,
						'max' => 100,
					),
				),
				'raw score without min',
			),
			array(
				Vocab::PASSED,
				array(
					'score' => array(
						'raw' => 90,
						'min' => 0,
					),
				),
				'raw score without max',
			),
			array( Vocab::FAILED, array( 'success' => true ), 'failed with success true' ),
			array( Vocab::FAILED, array( 'duration' => null ), 'failed without duration' ),
			array( Vocab::TERMINATED, array( 'duration' => null ), 'terminated without duration' ),
		);
		foreach ( $cases as list( $verb, $change, $label ) ) {
			$st = self::defined( $verb );
			foreach ( $change as $key => $value ) {
				if ( null === $value ) {
					unset( $st['result'][ $key ] );
				} else {
					$st['result'][ $key ] = $value;
				}
			}
			if ( array() === $st['result'] ) {
				unset( $st['result'] );
			}
			$this->assert_status( 403, self::post( $au, array( $st ) ), $label );
		}
		$this->assertSame( 0, $this->count_rows() );
		$scored                    = self::defined( Vocab::PASSED );
		$scored['result']['score'] = array(
			'raw' => 90,
			'min' => 0,
			'max' => 100,
		);
		$this->assert_status( 200, self::post( $au, array( $scored ) ), 'passed with a full raw score' );
	}

	/**
	 * CATAPULT 005-1-invalid-au, cmi5 9.3.4.0-2, 9.3.5.0-2 and 9.6.3.2-2: with a masteryScore in
	 * LMS.LaunchData, passed and failed carry it and their scaled score falls on the right side of it.
	 */
	public function test_passed_and_failed_follow_the_launch_mastery_score() {
		( new Document_Store() )->put(
			new Doc_Key( 'state', self::AU, Agent_Identity::from_array( self::actor() ), self::REG, 'LMS.LaunchData' ),
			(string) wp_json_encode( array( 'masteryScore' => 0.9 ) ),
			'application/json',
			null,
			null
		);
		[ $au ] = $this->child();
		$with   = static function ( string $verb, ?float $mastery, ?float $scaled, string $session = self::SESSION ): array {
			$st = self::defined( $verb, $session );
			if ( null !== $mastery ) {
				$st['context']['extensions'][ Vocab::EXT_MASTERYSCORE ] = $mastery;
			}
			if ( null !== $scaled ) {
				$st['result']['score'] = array( 'scaled' => $scaled );
			}
			return $st;
		};
		$this->assert_status( 403, self::post( $au, array( $with( Vocab::PASSED, null, 0.95 ) ) ), 'passed without masteryScore' );
		$this->assert_status( 403, self::post( $au, array( $with( Vocab::PASSED, 0.8, 0.95 ) ) ), 'passed with another masteryScore' );
		$this->assert_status( 403, self::post( $au, array( $with( Vocab::PASSED, 0.9, 0.89 ) ) ), 'passed below masteryScore' );
		$this->assert_status( 403, self::post( $au, array( $with( Vocab::FAILED, 0.9, 0.9 ) ) ), 'failed at masteryScore' );
		$this->assert_status( 403, self::post( $au, array( $with( Vocab::FAILED, 0.9, 0.91 ) ) ), 'failed above masteryScore' );
		$this->assert_status( 403, self::post( $au, array( $with( Vocab::FAILED, 0.8, null ) ) ), 'failed with another masteryScore, no score' );
		$this->assertSame( 0, $this->count_rows() );
		$this->assert_status( 200, self::post( $au, array( $with( Vocab::FAILED, null, null ) ) ), 'a judgement without a score needs no masteryScore' );
		[ $later ] = $this->child( 'Normal', self::SESSION2 );
		$this->assert_status( 200, self::post( $later, array( $with( Vocab::FAILED, 0.9, 0.89, self::SESSION2 ) ) ), 'failed below masteryScore' );
		[ $last ] = $this->child( 'Normal', self::SESSION3 );
		$this->assert_status( 200, self::post( $last, array( $with( Vocab::PASSED, 0.9, 0.9, self::SESSION3 ) ) ), 'passed at masteryScore' );
	}

	public function test_full_client_is_never_guarded() {
		$lms = Xapi_Test_Client::as( $this->lms['id'], $this->lms['secret'] );
		$this->assert_status( 200, self::post( $lms, array( self::defined( Vocab::LAUNCHED ), self::defined( Vocab::SATISFIED ), self::defined( Vocab::PASSED ), self::defined( Vocab::PASSED ) ) ) );
		$no_session = self::defined( Vocab::WAIVED );
		unset( $no_session['context']['extensions'] );
		$this->assert_status( 200, self::post( $lms, array( $no_session ) ) );
		$this->assertSame( 5, $this->count_rows() );
	}

	public function test_parent_with_guard_off_disables_it_for_its_tokens() {
		$off          = Client_Factory::create( array( Scopes::STATEMENTS_WRITE, Scopes::TOKENS_MINT ), array( 'guard_enabled' => false ) );
		[ $au, $key ] = $this->child( 'Normal', self::SESSION, $off );
		$this->assertTrue( ( new Token_Repository() )->find( $key )->guard );
		$this->assert_status( 200, self::post( $au, array( self::defined( Vocab::SATISFIED ), self::defined( Vocab::PASSED ), self::defined( Vocab::PASSED ) ) ) );

		[ $unguarded ] = $this->child( 'Normal', self::SESSION2, $off, false );
		$this->assert_status( 200, self::post( $unguarded, array( self::defined( Vocab::WAIVED, self::SESSION3 ) ) ) );
		$this->assertSame( 4, $this->count_rows() );
	}
}
