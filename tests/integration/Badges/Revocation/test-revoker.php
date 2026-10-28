<?php
/**
 * Revoker and revocation listener tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Claim\Claim_Handler;
use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Badges\Revocation\Revocation_Module;
use LearningLedger\Badges\Revocation\Revoker;
use LearningLedger\Badges\Signing\Eddsa_Rdfc_2022;
use LearningLedger\Badges\Status\Bitstring;
use LearningLedger\Badges\Status\Status_List_Repository;
use LearningLedger\Crypto\Multibase;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Links\Link_Repository;
use LearningLedger\Lrs\Privacy\Actor_Eraser;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

class Revoker_Test extends WP_UnitTestCase {

	const COURSE = 'https://example.org/courses/badges';
	const HOME   = 'https://site.example';
	const EMAIL  = 'alice@example.org';

	private string $registration;

	private Principal $writer;

	private string $trigger_id;

	private array $revoked = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'documents', 'tokens', 'badges', 'links', 'jobs', 'keys', 'status_lists', 'audit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lledger\\_status\\_list\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		wp_cache_flush();
		Key_Store::generate();
		Clock::freeze( new \DateTimeImmutable( gmdate( 'Y-m-d\TH:i:s' ) . '.000000Z' ) );

		$this->registration = Uuid::v4();
		$client             = Client_Factory::create( array( Scopes::STATEMENTS_WRITE, Scopes::VOID ), array( 'trusted' => true ) );
		$this->writer       = Principal::for_client( ( new Client_Repository() )->find( $client['id'] ) );
		$this->trigger_id   = $this->satisfied( 'learner-1' );

		$this->revoked = array();
		add_action(
			'learning_ledger/badge_revoked',
			function ( string $credential_id, string $reason ): void {
				$this->revoked[] = array( $credential_id, $reason, ( new Badge_Repository() )->get( substr( $credential_id, 9 ) ) );
			},
			10,
			2
		);
	}

	public function tear_down() {
		remove_all_actions( 'learning_ledger/badge_revoked' );
		remove_all_actions( 'learning_ledger/statement_voided' );
		remove_all_actions( 'learning_ledger/actor_erased' );
		remove_all_actions( 'learning_ledger/statements_committed' );
		Clock::freeze( null );
		parent::tear_down();
	}

	private static function actor( string $name ): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => self::HOME,
				'name'     => $name,
			),
		);
	}

	private static function hash( string $name ): string {
		return Agent_Identity::from_array( self::actor( $name ) )->hash();
	}

	private function satisfied( string $name ): string {
		[ $id ] = ( new Statement_Store() )->store(
			array(
				array(
					'actor'   => self::actor( $name ),
					'verb'    => array( 'id' => 'https://w3id.org/xapi/adl/verbs/satisfied' ),
					'object'  => array( 'id' => self::COURSE ),
					'context' => array( 'registration' => $this->registration ),
				),
			),
			$this->writer,
			'1.0.3'
		);
		return $id;
	}

	private function void( string $target ): void {
		( new Statement_Store() )->store(
			array(
				array(
					'actor'  => self::actor( 'admin' ),
					'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/voided' ),
					'object' => array(
						'objectType' => 'StatementRef',
						'id'         => $target,
					),
				),
			),
			$this->writer,
			'1.0.3'
		);
	}

	private function pending( string $achievement, string $name = 'learner-1', ?string $trigger = null, string $status = Badge_Repository::PENDING ): string {
		return (string) ( new Badge_Repository() )->create_pending(
			array(
				'id'          => $achievement,
				'name'        => 'Badge',
				'description' => 'Earned.',
				'criteria'    => array( 'narrative' => 'Do it.' ),
			),
			$this->registration,
			self::hash( $name ),
			self::COURSE,
			$trigger ?? $this->trigger_id,
			'1 of 1 lessons completed',
			0,
			$status
		);
	}

	private function issued( string $achievement, string $name = 'learner-1', ?string $trigger = null ): string {
		$id    = $this->pending( $achievement, $name, $trigger );
		$token = ( new Link_Repository() )->mint( 'claim', self::hash( $name ), $id, 900, 'client:test' );
		( new Claim_Handler() )->claim( $token, self::EMAIL, false );
		return $id;
	}

	private static function list_verifies( int $list_id ): bool {
		return Eddsa_Rdfc_2022::verify(
			( new Status_List_Repository() )->credential( $list_id ),
			static function ( string $vm ): ?string {
				$doc = Did_Document::document();
				if ( ! in_array( $vm, $doc['assertionMethod'], true ) ) {
					return null;
				}
				foreach ( $doc['verificationMethod'] as $method ) {
					if ( $method['id'] === $vm ) {
						return Multibase::from_ed25519_multikey( $method['publicKeyMultibase'] );
					}
				}
				return null;
			}
		);
	}

	/**
	 * Indexes set in a list's published encodedList.
	 *
	 * @param integer $list_id List id.
	 *
	 * @return int[]
	 */
	private static function set_bits( int $list_id ): array {
		$credential = ( new Status_List_Repository() )->credential( $list_id );
		$bits       = Bitstring::decode( $credential['credentialSubject']['encodedList'] );
		$set        = array();
		for ( $i = 0, $n = strlen( $bits ) * 8; $i < $n; $i++ ) {
			if ( Bitstring::get( $bits, $i ) ) {
				$set[] = $i;
			}
		}
		return $set;
	}

	private static function audit( string $event ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT actor, data FROM ' . Tables::name( 'audit' ) . ' WHERE event = %s ORDER BY id', $event ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map(
			static fn( array $r ): array => array( 'actor' => $r['actor'] ) + json_decode( $r['data'], true ),
			$rows
		);
	}

	private static function badge_rows( string $name ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::name( 'badges' ) . ' WHERE actor_hash = UNHEX(%s)', bin2hex( self::hash( $name ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	// Revoker::revoke.

	public function test_revoking_an_issued_badge_flips_exactly_its_bit_and_the_list_still_verifies() {
		$one   = $this->issued( 'https://example.org/badges/one' );
		$two   = $this->issued( 'https://example.org/badges/two' );
		$repo  = new Badge_Repository();
		$badge = $repo->get( $one );
		$this->assertSame( $badge['status_list_id'], $repo->get( $two )['status_list_id'] );
		$this->assertSame( array(), self::set_bits( $badge['status_list_id'] ) );

		Clock::freeze( Clock::now()->modify( '+5 seconds' ) );
		( new Revoker() )->revoke( $one, 'Issued in error' );

		$this->assertSame( array( $badge['status_index'] ), self::set_bits( $badge['status_list_id'] ) );
		$this->assertTrue( self::list_verifies( $badge['status_list_id'] ) );
		$after = $repo->get( $one );
		$this->assertSame( Badge_Repository::REVOKED, $after['status'] );
		$this->assertSame( Clock::now()->format( 'Y-m-d\TH:i:s.u\Z' ), $after['revoked_at'] );
		$this->assertSame( $badge['credential'], $after['credential'] );
		$this->assertSame( Badge_Repository::ISSUED, $repo->get( $two )['status'] );

		$this->assertCount( 1, $this->revoked );
		$this->assertSame( array( 'urn:uuid:' . $one, 'Issued in error' ), array_slice( $this->revoked[0], 0, 2 ) );

		$audit = self::audit( 'badge_revoked' );
		$this->assertCount( 1, $audit );
		$this->assertSame( $one, $audit[0]['badge'] );
		$this->assertSame( 'urn:uuid:' . $one, $audit[0]['credential'] );
		$this->assertSame( 'Issued in error', $audit[0]['reason'] );
		$this->assertSame( Badge_Repository::ISSUED, $audit[0]['from'] );
		$this->assertSame( $badge['status_list_id'], $audit[0]['status_list'] );
		$this->assertSame( $badge['status_index'], $audit[0]['status_index'] );
	}

	public function test_revoking_a_pending_or_held_badge_marks_it_revoked_without_touching_a_list() {
		$pending = $this->pending( 'https://example.org/badges/one' );
		$held    = $this->pending( 'https://example.org/badges/two', 'learner-1', null, Badge_Repository::HELD );

		( new Revoker() )->revoke( $pending, Revoker::REASON_ADMIN );
		( new Revoker() )->revoke( $held, Revoker::REASON_ADMIN );

		$repo = new Badge_Repository();
		foreach ( array( $pending, $held ) as $id ) {
			$badge = $repo->get( $id );
			$this->assertSame( Badge_Repository::REVOKED, $badge['status'] );
			$this->assertNotNull( $badge['revoked_at'] );
			$this->assertNull( $badge['status_list_id'] );
		}
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'status_lists' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( array( Badge_Repository::PENDING, Badge_Repository::HELD ), array_column( self::audit( 'badge_revoked' ), 'from' ) );
		$this->assertCount( 2, $this->revoked );
	}

	public function test_a_revoked_pending_badge_can_no_longer_be_claimed() {
		$id    = $this->pending( 'https://example.org/badges/one' );
		$token = ( new Link_Repository() )->mint( 'claim', self::hash( 'learner-1' ), $id, 900, 'client:test' );
		( new Revoker() )->revoke( $id, Revoker::REASON_ADMIN );

		try {
			( new Claim_Handler() )->claim( $token, self::EMAIL, false );
			$this->fail( 'A revoked badge was claimed' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( Claim_Handler::CONFLICT, $e->error_code() );
		}
		$this->assertNull( ( new Badge_Repository() )->get( $id )['credential'] );
	}

	public function test_revoking_again_does_nothing() {
		$id = $this->issued( 'https://example.org/badges/one' );
		( new Revoker() )->revoke( $id, Revoker::REASON_ADMIN );
		$first = ( new Badge_Repository() )->get( $id )['revoked_at'];

		Clock::freeze( Clock::now()->modify( '+1 minute' ) );
		( new Revoker() )->revoke( $id, Revoker::REASON_ADMIN );

		$this->assertSame( $first, ( new Badge_Repository() )->get( $id )['revoked_at'] );
		$this->assertCount( 1, $this->revoked );
		$this->assertCount( 1, self::audit( 'badge_revoked' ) );
	}

	public function test_unknown_badge_and_empty_reason_are_refused() {
		foreach ( array( array( Uuid::v4(), 'x', 'not_found' ), array( 'nope', 'x', 'not_found' ), array( $this->pending( 'https://example.org/badges/one' ), " \t", 'invalid' ) ) as [ $id, $reason, $code ] ) {
			try {
				( new Revoker() )->revoke( $id, $reason );
				$this->fail( 'Expected a refusal' );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( $code, $e->error_code() );
			}
		}
		$this->assertSame( array(), $this->revoked );
	}

	public function test_reason_is_sanitized_and_capped() {
		$id = $this->pending( 'https://example.org/badges/one' );
		( new Revoker() )->revoke( $id, '<b>Bad</b> ' . str_repeat( 'x', 300 ) );

		$reason = self::audit( 'badge_revoked' )[0]['reason'];
		$this->assertStringStartsWith( 'Bad x', $reason );
		$this->assertSame( Revoker::MAX_REASON, strlen( $reason ) );
		$this->assertSame( $reason, $this->revoked[0][1] );
	}

	// Void listener.

	public function test_voiding_the_trigger_revokes_its_badges() {
		( new Revocation_Module() )->register();
		$issued  = $this->issued( 'https://example.org/badges/one' );
		$pending = $this->pending( 'https://example.org/badges/two' );
		$other   = $this->pending( 'https://example.org/badges/three', 'learner-2', $this->satisfied( 'learner-2' ) );
		$list    = ( new Badge_Repository() )->get( $issued );

		$this->void( $this->trigger_id );

		$repo = new Badge_Repository();
		$this->assertSame( Badge_Repository::REVOKED, $repo->get( $issued )['status'] );
		$this->assertSame( Badge_Repository::REVOKED, $repo->get( $pending )['status'] );
		$this->assertSame( Badge_Repository::PENDING, $repo->get( $other )['status'] );
		$this->assertSame( array( $list['status_index'] ), self::set_bits( $list['status_list_id'] ) );
		$this->assertTrue( self::list_verifies( $list['status_list_id'] ) );
		$this->assertEqualsCanonicalizing( array( 'urn:uuid:' . $issued, 'urn:uuid:' . $pending ), array_column( $this->revoked, 0 ) );
		$this->assertSame( array( Revoker::REASON_VOIDED, Revoker::REASON_VOIDED ), array_column( $this->revoked, 1 ) );
	}

	public function test_a_failing_revocation_does_not_fail_the_void() {
		( new Revocation_Module() )->register();
		$issued = $this->issued( 'https://example.org/badges/one' );
		Key_Store::remove( Key_Store::active()->kid );

		$log = ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keeps the expected log line out of the test output.
		try {
			$this->void( $this->trigger_id );
		} finally {
			ini_set( 'error_log', (string) $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}

		$this->assertSame( Badge_Repository::ISSUED, ( new Badge_Repository() )->get( $issued )['status'] );
		$this->assertSame( array(), $this->revoked );
	}

	// Erasure listener.

	public function test_erasing_the_actor_revokes_then_deletes_its_badge_rows() {
		( new Revocation_Module() )->register();
		$issued  = $this->issued( 'https://example.org/badges/one' );
		$pending = $this->pending( 'https://example.org/badges/two' );
		$gone    = $this->pending( 'https://example.org/badges/three' );
		( new Revoker() )->revoke( $gone, Revoker::REASON_ADMIN );
		$this->revoked = array();
		$other         = $this->issued( 'https://example.org/badges/four', 'learner-2', $this->satisfied( 'learner-2' ) );
		$badge         = ( new Badge_Repository() )->get( $issued );
		$keep          = ( new Badge_Repository() )->get( $other );

		( new Actor_Eraser() )->erase( Agent_Identity::from_array( self::actor( 'learner-1' ) ), 'user:1' );

		$this->assertEqualsCanonicalizing( array( 'urn:uuid:' . $issued, 'urn:uuid:' . $pending ), array_column( $this->revoked, 0 ) );
		$this->assertSame( array( Revoker::REASON_ERASED, Revoker::REASON_ERASED ), array_column( $this->revoked, 1 ) );
		foreach ( $this->revoked as [ , , $row ] ) {
			$this->assertSame( Badge_Repository::REVOKED, $row['status'], 'The badge is revoked while its row still exists.' );
		}
		$this->assertSame( 0, self::badge_rows( 'learner-1' ) );
		foreach ( array( $issued, $pending, $gone ) as $id ) {
			$this->assertNull( ( new Badge_Repository() )->get( $id ) );
		}
		$stored = (string) $GLOBALS['wpdb']->get_var( 'SELECT GROUP_CONCAT(COALESCE(recipient_hash, \'\'), COALESCE(salt, \'\'), COALESCE(credential, \'\')) FROM ' . Tables::name( 'badges' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertStringNotContainsString( $badge['recipient_hash'], $stored );
		$this->assertStringNotContainsString( $badge['salt'], $stored );

		$this->assertSame( array( $badge['status_index'] ), array_values( array_diff( self::set_bits( $badge['status_list_id'] ), array( $keep['status_index'] ) ) ) );
		$this->assertNotContains( $keep['status_index'], self::set_bits( $badge['status_list_id'] ) );
		$this->assertTrue( self::list_verifies( $badge['status_list_id'] ) );
		$this->assertSame( Badge_Repository::ISSUED, ( new Badge_Repository() )->get( $other )['status'] );
		$this->assertSame( 1, self::badge_rows( 'learner-2' ) );
	}

	public function test_erasure_keeps_a_badge_whose_revocation_failed_and_a_second_erase_finishes_it() {
		( new Revocation_Module() )->register();
		$issued  = $this->issued( 'https://example.org/badges/one' );
		$pending = $this->pending( 'https://example.org/badges/two' );
		$old     = Key_Store::active()->kid;
		Key_Store::remove( $old );
		$actor = Agent_Identity::from_array( self::actor( 'learner-1' ) );

		$log = ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keeps the expected log line out of the test output.
		try {
			( new Actor_Eraser() )->erase( $actor, 'user:1' );
		} finally {
			ini_set( 'error_log', (string) $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}

		$this->assertNull( ( new Badge_Repository() )->get( $pending ) );
		$this->assertSame( Badge_Repository::ISSUED, ( new Badge_Repository() )->get( $issued )['status'], 'An issued badge whose bit could not be flipped is kept so it can still be revoked.' );

		Key_Store::rotate();
		( new Actor_Eraser() )->erase( $actor, 'user:1' );

		$this->assertSame( 0, self::badge_rows( 'learner-1' ) );
		$this->assertContains( 'urn:uuid:' . $issued, array_column( $this->revoked, 0 ) );
	}
}
