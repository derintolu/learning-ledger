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
use LearningLedger\Lrs\Http\Xapi_Response;
use LearningLedger\Lrs\Http\Xapi_Router;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Tests\Xapi_Test_Client;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Json;
use LearningLedger\Util\Uuid;

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

	public function set_up() {
		parent::set_up();
		Installer::install();
		self::clean();
		Clock::freeze( new DateTimeImmutable( self::NOW ) );
		( new Xapi_Router() )->register();
		( new Cmi5_Guard() )->register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
		$this->lms = Client_Factory::create( array( Scopes::STATEMENTS_WRITE, Scopes::STATEMENTS_READ, Scopes::STATE, Scopes::VOID, Scopes::TOKENS_MINT ) );
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
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'tokens', 'clients' ) as $t ) {
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
	 * Mint a child token of a client and return a test client sending it, plus its key.
	 *
	 * @return array{0:Xapi_Test_Client,1:string}
	 */
	private function child( string $mode = 'Normal', string $session = self::SESSION, ?array $minter = null, bool $guard = true ): array {
		$minter = $minter ?? $this->lms;
		$client = ( new Client_Repository() )->find( $minter['id'] );
		$t      = ( new Token_Repository() )->mint( $client, new Token_Spec( self::REG, self::actor(), self::AU, $session, $mode, 3600, array(), $guard ) );
		return array( Xapi_Test_Client::as( $t['key'], $t['secret'] ), $t['key'] );
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
			'id'      => Uuid::v4(),
			'actor'   => self::actor(),
			'verb'    => array( 'id' => $verb ),
			'object'  => array(
				'objectType' => 'Activity',
				'id'         => self::AU,
			),
			'context' => array(
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

	private static function count_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'statements' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function assert_status( int $expected, Xapi_Response $res, string $label = '' ): void {
		$this->assertSame( $expected, $res->status(), $label . ' ' . $res->body() );
	}

	public function test_valid_sequence_is_accepted_and_stored_terminated_marks_the_token() {
		[ $au, $key ] = $this->child();

		foreach ( array( Vocab::INITIALIZED, Vocab::COMPLETED, Vocab::PASSED ) as $verb ) {
			$this->assert_status( 200, self::post( $au, array( self::defined( $verb ) ) ), $verb );
		}
		$this->assertNull( ( new Token_Repository() )->find( $key )->terminated_at );

		Clock::freeze( new DateTimeImmutable( '2026-10-27T10:05:00Z' ) );
		$terminated = self::defined( Vocab::TERMINATED );
		$this->assert_status( 200, self::post( $au, array( $terminated ) ), 'terminated' );
		$this->assertSame( 4, self::count_rows() );

		// The child token authenticated the POSTs: the stored authority names the token.
		$stored = ( new Statement_Repository() )->find( $terminated['id'] );
		$this->assertSame( 'tok:' . $key, $stored->authority_client );
		$this->assertSame( $this->lms['id'] . '/' . $key, $stored->statement()['authority']['account']['name'] );

		$token = ( new Token_Repository() )->find( $key );
		$this->assertNotNull( $token->terminated_at );
		$this->assertSame( '2026-10-27T10:05:00+00:00', $token->terminated_at->format( DATE_ATOM ) );
	}

	public function test_valid_sequence_in_one_batch_is_accepted() {
		[ $au, $key ] = $this->child();
		$res          = self::post( $au, array( self::defined( Vocab::INITIALIZED ), self::allowed(), self::defined( Vocab::COMPLETED ), self::defined( Vocab::PASSED ), self::defined( Vocab::TERMINATED ) ) );
		$this->assert_status( 200, $res );
		$this->assertSame( 5, self::count_rows() );
		$this->assertSame( '2026-10-27T10:00:00+00:00', ( new Token_Repository() )->find( $key )->terminated_at->format( DATE_ATOM ) );
	}

	public function test_identical_resend_is_left_to_the_store() {
		[ $au ] = $this->child();
		$passed = self::defined( Vocab::PASSED );
		$this->assert_status( 200, self::post( $au, array( $passed ) ), 'passed' );
		$this->assert_status( 200, self::post( $au, array( $passed ) ), 'passed resent' );

		$terminated = self::defined( Vocab::TERMINATED );
		$this->assert_status( 200, self::post( $au, array( $terminated ) ), 'terminated' );
		$this->assert_status( 200, self::post( $au, array( $terminated ) ), 'terminated resent' );
		$this->assert_status( 204, $au->request( 'PUT', 'statements', array( 'statementId' => $passed['id'] ), Json::encode_exact( $passed ), array( 'Content-Type' => 'application/json' ) ), 'passed resent by PUT' );
		$this->assertSame( 2, self::count_rows() );

		$changed                       = $passed;
		$changed['result']['duration'] = 'PT6M';
		$this->assert_status( 409, self::post( $au, array( $changed ) ), 'same id, different content' );

		// A new statement after terminated is still judged.
		$this->assert_status( 403, self::post( $au, array( self::defined( Vocab::COMPLETED ) ) ), 'new defined statement' );
		$this->assertSame( 2, self::count_rows() );
	}

	public function test_lms_only_verbs_are_rejected_403() {
		[ $au ] = $this->child();
		foreach ( array( Vocab::LAUNCHED, Vocab::WAIVED, Vocab::SATISFIED, Vocab::ABANDONED ) as $verb ) {
			$this->assert_status( 403, self::post( $au, array( self::defined( $verb ) ) ), 'defined ' . $verb );
			$this->assert_status( 403, self::post( $au, array( self::allowed( $verb ) ) ), 'allowed ' . $verb );
		}
		$this->assertSame( 0, self::count_rows() );
	}

	public function test_sessionid_missing_is_400_and_another_session_is_403() {
		[ $au ] = $this->child();

		$missing = self::defined( Vocab::INITIALIZED );
		unset( $missing['context']['extensions'] );
		$this->assert_status( 400, self::post( $au, array( $missing ) ), 'missing' );

		$not_string = self::defined( Vocab::INITIALIZED );
		$not_string['context']['extensions'][ Vocab::EXT_SESSIONID ] = 42;
		$this->assert_status( 400, self::post( $au, array( $not_string ) ), 'not a string' );

		$this->assert_status( 403, self::post( $au, array( self::defined( Vocab::INITIALIZED, self::SESSION2 ) ) ), 'other session' );
		$this->assert_status( 403, self::post( $au, array( self::allowed( 'http://adlnet.gov/expapi/verbs/experienced', self::SESSION2 ) ) ), 'allowed, other session' );
		$this->assertSame( 0, self::count_rows() );

		// The session id compares case-insensitively; an allowed statement may omit it.
		$this->assert_status( 200, self::post( $au, array( self::defined( Vocab::INITIALIZED, strtoupper( self::SESSION ) ) ) ), 'upper case' );
		$bare = self::allowed();
		unset( $bare['context']['extensions'] );
		$this->assert_status( 200, self::post( $au, array( $bare ) ), 'allowed without session' );
	}

	public function test_browse_and_review_allow_only_initialized_terminated_among_defined() {
		foreach ( array(
			'Browse' => self::SESSION,
			'Review' => self::SESSION2,
		) as $mode => $session ) {
			[ $au ] = $this->child( $mode, $session );
			$this->assert_status( 200, self::post( $au, array( self::defined( Vocab::INITIALIZED, $session ) ) ), $mode . ' initialized' );
			$this->assert_status( 200, self::post( $au, array( self::allowed( 'http://adlnet.gov/expapi/verbs/experienced', $session ) ) ), $mode . ' allowed' );
			$this->assert_status( 200, self::post( $au, array( self::allowed( Vocab::COMPLETED, $session ) ) ), $mode . ' allowed completed verb' );
			foreach ( array( Vocab::COMPLETED, Vocab::PASSED, Vocab::FAILED ) as $verb ) {
				$this->assert_status( 403, self::post( $au, array( self::defined( $verb, $session ) ) ), $mode . ' ' . $verb );
			}
			$this->assert_status( 200, self::post( $au, array( self::defined( Vocab::TERMINATED, $session ) ) ), $mode . ' terminated' );
		}
		$this->assertSame( 8, self::count_rows() );
	}

	public function test_second_passed_in_the_registration_is_rejected() {
		[ $first ] = $this->child();
		$this->assert_status( 200, self::post( $first, array( self::defined( Vocab::PASSED ) ) ) );
		$this->assert_status( 403, self::post( $first, array( self::defined( Vocab::PASSED ) ) ), 'same session' );

		[ $later ] = $this->child( 'Normal', self::SESSION2 );
		$this->assert_status( 403, self::post( $later, array( self::defined( Vocab::PASSED, self::SESSION2 ) ) ), 'later session' );
		$this->assertSame( 1, self::count_rows() );
	}

	public function test_second_passed_in_one_batch_is_rejected() {
		[ $au ] = $this->child();
		$this->assert_status( 403, self::post( $au, array( self::defined( Vocab::PASSED ), self::defined( Vocab::PASSED ) ) ) );
		$this->assertSame( 0, self::count_rows() );
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
		$this->assertSame( 2, self::count_rows() );
	}

	public function test_second_completed_is_rejected() {
		[ $s1 ] = $this->child();
		$this->assert_status( 200, self::post( $s1, array( self::defined( Vocab::COMPLETED ) ) ) );
		$this->assert_status( 403, self::post( $s1, array( self::defined( Vocab::COMPLETED ) ) ), 'same session' );
		[ $s2 ] = $this->child( 'Normal', self::SESSION2 );
		$this->assert_status( 403, self::post( $s2, array( self::defined( Vocab::COMPLETED, self::SESSION2 ) ) ), 'later session' );
		$this->assertSame( 1, self::count_rows() );
	}

	public function test_defined_statement_after_terminated_is_rejected_and_everything_after_the_grace() {
		[ $au, $key ] = $this->child();
		$this->assert_status( 200, self::post( $au, array( self::defined( Vocab::INITIALIZED ), self::defined( Vocab::TERMINATED ) ) ) );

		foreach ( array( Vocab::COMPLETED, Vocab::PASSED, Vocab::INITIALIZED, Vocab::TERMINATED ) as $verb ) {
			$this->assert_status( 403, self::post( $au, array( self::defined( $verb ) ) ), 'defined ' . $verb );
		}

		// A token of the same session is held to the same end.
		[ $again ] = $this->child();
		$this->assert_status( 403, self::post( $again, array( self::defined( Vocab::COMPLETED ) ) ), 'second token of the session' );

		Clock::freeze( new DateTimeImmutable( '2026-10-27T10:10:00Z' ) );
		$this->assert_status( 200, self::post( $au, array( self::allowed() ) ), 'allowed within 10 minutes' );

		Clock::freeze( new DateTimeImmutable( '2026-10-27T10:10:01Z' ) );
		$this->assert_status( 403, self::post( $au, array( self::allowed() ) ), 'allowed after 10 minutes' );
		$bare = self::allowed();
		unset( $bare['context']['extensions'] );
		$this->assert_status( 403, self::post( $au, array( $bare ) ), 'anything after 10 minutes' );

		$this->assertSame( 3, self::count_rows() );
		$this->assertNotNull( ( new Token_Repository() )->find( $key )->terminated_at );
	}

	public function test_defined_statement_after_terminated_in_one_batch_is_rejected() {
		[ $au, $key ] = $this->child();
		$this->assert_status( 403, self::post( $au, array( self::defined( Vocab::TERMINATED ), self::defined( Vocab::COMPLETED ) ) ) );
		$this->assertSame( 0, self::count_rows() );
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
		$this->assertSame( 0, self::count_rows() );
	}

	public function test_full_client_is_never_guarded() {
		$lms = Xapi_Test_Client::as( $this->lms['id'], $this->lms['secret'] );
		$this->assert_status( 200, self::post( $lms, array( self::defined( Vocab::LAUNCHED ), self::defined( Vocab::SATISFIED ), self::defined( Vocab::PASSED ), self::defined( Vocab::PASSED ) ) ) );
		$no_session = self::defined( Vocab::WAIVED );
		unset( $no_session['context']['extensions'] );
		$this->assert_status( 200, self::post( $lms, array( $no_session ) ) );
		$this->assertSame( 5, self::count_rows() );
	}

	public function test_parent_with_guard_off_disables_it_for_its_tokens() {
		$off          = Client_Factory::create( array( Scopes::STATEMENTS_WRITE, Scopes::TOKENS_MINT ), array( 'guard_enabled' => false ) );
		[ $au, $key ] = $this->child( 'Normal', self::SESSION, $off );
		$this->assertTrue( ( new Token_Repository() )->find( $key )->guard );
		$this->assert_status( 200, self::post( $au, array( self::defined( Vocab::SATISFIED ), self::defined( Vocab::PASSED ), self::defined( Vocab::PASSED ) ) ) );

		[ $unguarded ] = $this->child( 'Normal', self::SESSION2, $off, false );
		$this->assert_status( 200, self::post( $unguarded, array( self::defined( Vocab::WAIVED, self::SESSION3 ) ) ) );
		$this->assertSame( 4, self::count_rows() );
	}
}
