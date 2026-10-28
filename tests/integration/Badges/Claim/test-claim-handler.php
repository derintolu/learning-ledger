<?php
/**
 * Claim_Handler, Earned_Statement and Claim_Page tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Claim\Claim_Handler;
use LearningLedger\Badges\Claim\Claim_Page;
use LearningLedger\Badges\Claim\Earned_Statement;
use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Issuer_Key;
use LearningLedger\Badges\Issuer\Issuer_Profile;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Badges\Signing\Eddsa_Rdfc_2022;
use LearningLedger\Crypto\Hmac;
use LearningLedger\Crypto\Multibase;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Links\Link_Repository;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

class Claim_Handler_Test extends WP_UnitTestCase {

	const COURSE      = 'https://example.org/courses/badges';
	const HOME        = 'https://site.example';
	const NAME        = 'learner-7f3a';
	const EMAIL       = 'alice@example.org';
	const ACHIEVEMENT = 'https://example.org/badges/one';
	const EARNED      = 'http://specification.openbadges.org/xapi/verbs/earned';
	const ASSERTION   = 'http://specification.openbadges.org/xapi/extensions/badgeassertion';

	private string $badge_id;

	private string $registration;

	private string $trigger_id;

	private \DateTimeImmutable $t0;

	private array $issued = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'badges', 'links', 'jobs', 'keys', 'status_lists', 'audit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lledger\\_status\\_list\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		wp_cache_flush();
		Key_Store::generate();
		update_option( Issuer_Profile::NAME_OPTION, 'Learn WordPress, reimagined' );

		$this->t0 = new \DateTimeImmutable( gmdate( 'Y-m-d\TH:i:s' ) . '.000000Z' );
		Clock::freeze( $this->t0 );

		$this->registration = Uuid::v4();
		$writer             = Client_Factory::create( array( Scopes::STATEMENTS_WRITE ), array( 'trusted' => true ) );
		[ $trigger ]        = ( new Statement_Store() )->store(
			array(
				array(
					'actor'   => self::actor(),
					'verb'    => array( 'id' => 'https://w3id.org/xapi/adl/verbs/satisfied' ),
					'object'  => array( 'id' => self::COURSE ),
					'context' => array( 'registration' => $this->registration ),
				),
			),
			Principal::for_client( ( new Client_Repository() )->find( $writer['id'] ) ),
			'1.0.3'
		);
		$this->trigger_id   = $trigger;
		$this->badge_id     = (string) ( new Badge_Repository() )->create_pending(
			array(
				'id'              => self::ACHIEVEMENT,
				'name'            => 'Badge <b>one</b> & more',
				'description'     => 'Earned one.',
				'criteria'        => array( 'narrative' => 'Do one.' ),
				'achievementType' => 'Badge',
			),
			$this->registration,
			Agent_Identity::from_array( self::actor() )->hash(),
			self::COURSE,
			$trigger,
			'1 of 1 lessons completed',
			0
		);

		$this->issued = array();
		add_action(
			'learning_ledger/badge_issued',
			function ( string $credential_id, array $credential, string $registration ): void {
				$this->issued[] = array( $credential_id, $credential, $registration );
			},
			10,
			3
		);
	}

	public function tear_down() {
		remove_all_actions( 'learning_ledger/badge_issued' );
		remove_all_filters( 'learning_ledger/badge_sign' );
		remove_all_actions( 'learning_ledger/statements_committed' );
		delete_option( Issuer_Profile::NAME_OPTION );
		Clock::freeze( null );
		Hmac::set_key_for_tests( null );
		parent::tear_down();
	}

	private static function actor(): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => self::HOME,
				'name'     => self::NAME,
			),
		);
	}

	private function mint(): string {
		return ( new Link_Repository() )->mint( 'claim', Agent_Identity::from_array( self::actor() )->hash(), $this->badge_id, 900, 'client:test' );
	}

	private static function verifies_against_did_document( array $credential ): bool {
		return Eddsa_Rdfc_2022::verify(
			$credential,
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

	private static function rows( string $table, string $where = '1=1' ): array {
		return (array) $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Tables::name( $table ) . ' WHERE ' . $where, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function earned_rows(): array {
		global $wpdb;
		$bodies = $wpdb->get_col( 'SELECT body FROM ' . Tables::name( 'statements' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out    = array();
		foreach ( $bodies as $body ) {
			$st = json_decode( (string) $body, true );
			if ( self::EARNED === ( $st['verb']['id'] ?? null ) ) {
				$out[] = $st;
			}
		}
		return $out;
	}

	private function assert_gone( callable $claim ): void {
		try {
			$claim();
			$this->fail( 'Expected the claim to be refused as gone' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( Claim_Handler::GONE, $e->error_code() );
		}
	}

	// Claim_Handler.

	public function test_claim_signs_a_credential_that_verifies_against_the_did_document() {
		$credential = ( new Claim_Handler() )->claim( $this->mint(), self::EMAIL, false );

		$this->assertTrue( self::verifies_against_did_document( $credential ) );
		$this->assertSame( 'urn:uuid:' . $this->badge_id, $credential['id'] );
		$this->assertSame( Did_Document::did(), $credential['issuer']['id'] );
		$this->assertSame( 'Learn WordPress, reimagined', $credential['issuer']['name'] );
		$this->assertSame( Did_Document::verification_method_for( Key_Store::active() ), $credential['proof']['verificationMethod'] );
		$this->assertSame( $this->t0->format( 'Y-m-d\TH:i:s\Z' ), $credential['validFrom'] );
		$this->assertSame( 'urn:uuid:' . $this->trigger_id, $credential['evidence'][0]['id'] );
		$this->assertSame( '1 of 1 lessons completed', $credential['evidence'][0]['narrative'] );

		$badge = ( new Badge_Repository() )->get( $this->badge_id );
		$this->assertSame( Badge_Repository::ISSUED, $badge['status'] );
		$this->assertSame( $credential, $badge['credential'] );
		$this->assertTrue( self::verifies_against_did_document( $badge['credential'] ) );
		$this->assertSame( (string) $badge['status_index'], $credential['credentialStatus']['statusListIndex'] );
		$this->assertStringEndsWith( '/status-lists/' . $badge['status_list_id'], $credential['credentialStatus']['statusListCredential'] );
		$this->assertFalse( $badge['shared'] );
	}

	public function test_identity_hash_recomputes_from_email_and_salt() {
		$credential = ( new Claim_Handler() )->claim( $this->mint(), self::EMAIL, false );

		$identity = $credential['credentialSubject']['identifier'][0];
		$this->assertSame( 'IdentityObject', $identity['type'] );
		$this->assertSame( 'emailAddress', $identity['identityType'] );
		$this->assertTrue( $identity['hashed'] );
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{32}\z/', $identity['salt'] );
		$this->assertSame( 'sha256$' . hash( 'sha256', self::EMAIL . $identity['salt'] ), $identity['identityHash'] );

		$badge = ( new Badge_Repository() )->get( $this->badge_id );
		$this->assertSame( $identity['identityHash'], $badge['recipient_hash'] );
		$this->assertSame( $identity['salt'], $badge['salt'] );
	}

	public function test_email_is_trimmed_and_lowercased_before_hashing() {
		$credential = ( new Claim_Handler() )->claim( $this->mint(), '  Alice@Example.ORG ', false );

		$identity = $credential['credentialSubject']['identifier'][0];
		$this->assertSame( 'sha256$' . hash( 'sha256', self::EMAIL . $identity['salt'] ), $identity['identityHash'] );
	}

	public function test_each_claim_gets_a_fresh_salt() {
		$first = ( new Claim_Handler() )->claim( $this->mint(), self::EMAIL, false );

		$other  = (string) ( new Badge_Repository() )->create_pending(
			array(
				'id'          => 'https://example.org/badges/two',
				'name'        => 'Badge two',
				'description' => 'Earned two.',
				'criteria'    => array( 'narrative' => 'Do two.' ),
			),
			$this->registration,
			Agent_Identity::from_array( self::actor() )->hash(),
			self::COURSE,
			$this->trigger_id,
			'1 of 1 lessons completed',
			0
		);
		$token  = ( new Link_Repository() )->mint( 'claim', Agent_Identity::from_array( self::actor() )->hash(), $other, 900, 'client:test' );
		$second = ( new Claim_Handler() )->claim( $token, self::EMAIL, false );

		$this->assertNotSame( $first['credentialSubject']['identifier'][0]['salt'], $second['credentialSubject']['identifier'][0]['salt'] );
		$this->assertNotSame( $first['credentialSubject']['identifier'][0]['identityHash'], $second['credentialSubject']['identifier'][0]['identityHash'] );
		$this->assertNotSame( $first['credentialStatus']['id'], $second['credentialStatus']['id'] );
	}

	public function test_credential_holds_neither_the_actor_nor_its_hash_nor_the_email() {
		$credential = ( new Claim_Handler() )->claim( $this->mint(), self::EMAIL, false );
		$hash       = Agent_Identity::from_array( self::actor() )->hash();

		$stored = (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT credential FROM ' . Tables::name( 'badges' ) . ' WHERE id = %s', $this->badge_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( array( (string) wp_json_encode( $credential ), $stored ) as $json ) {
			$this->assertStringNotContainsString( self::NAME, $json );
			$this->assertStringNotContainsString( bin2hex( $hash ), $json );
			$this->assertStringNotContainsString( sha1( self::actor()['account']['homePage'] . self::NAME ), $json );
			$this->assertStringNotContainsString( self::EMAIL, $json );
			$this->assertStringNotContainsString( 'alice', $json );
			$this->assertStringNotContainsString( '"actor"', $json );
		}
	}

	public function test_the_email_is_stored_nowhere() {
		( new Claim_Handler() )->claim( $this->mint(), self::EMAIL, true );

		global $wpdb;
		foreach ( array( 'badges', 'links', 'statements' ) as $t ) {
			$this->assertNotSame( array(), self::rows( $t ), $t . ' holds the claim' );
		}
		foreach ( array( 'badges', 'links', 'statements', 'audit', 'agents', 'jobs' ) as $t ) {
			$dump = strtolower( serialize( self::rows( $t ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			$this->assertStringNotContainsString( 'alice', $dump, $t );
		}
		$options = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( self::EMAIL ) . '%' ) );
		$this->assertSame( 0, $options );
	}

	public function test_earned_statement_is_stored_by_the_lrs_with_the_badgeassertion_and_trigger_ref() {
		$credential = ( new Claim_Handler() )->claim( $this->mint(), self::EMAIL, false );

		$rows = self::earned_rows();
		$this->assertCount( 1, $rows );
		$st = $rows[0];
		$this->assertSame( self::actor(), $st['actor'] );
		$this->assertSame( self::EARNED, $st['verb']['id'] );
		$this->assertSame( 'Activity', $st['object']['objectType'] );
		$this->assertSame( self::ACHIEVEMENT, $st['object']['id'] );
		$this->assertSame( array( self::ASSERTION => $credential['id'] ), $st['result']['extensions'] );
		$this->assertSame( $this->registration, $st['context']['registration'] );
		$this->assertSame(
			array(
				'objectType' => 'StatementRef',
				'id'         => $this->trigger_id,
			),
			$st['context']['statement']
		);
		$this->assertSame( Principal::SYSTEM_NAME, $st['authority']['account']['name'] );

		global $wpdb;
		$this->assertSame( 'sys:' . Principal::SYSTEM_NAME, $wpdb->get_var( $wpdb->prepare( 'SELECT authority_client FROM ' . Tables::name( 'statements' ) . ' WHERE statement_id = UNHEX(%s)', str_replace( '-', '', $st['id'] ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_earned_statement_builder_shape() {
		$trigger = Uuid::v4();
		$reg     = Uuid::v4();
		$st      = Earned_Statement::build( self::actor(), $reg, self::ACHIEVEMENT, 'urn:uuid:abc', $trigger );

		$this->assertSame(
			array(
				'actor'   => self::actor(),
				'verb'    => array(
					'id'      => self::EARNED,
					'display' => array( 'en-US' => 'earned' ),
				),
				'object'  => array(
					'objectType' => 'Activity',
					'id'         => self::ACHIEVEMENT,
				),
				'result'  => array(
					'extensions' => array( self::ASSERTION => 'urn:uuid:abc' ),
				),
				'context' => array(
					'registration' => $reg,
					'statement'    => array(
						'objectType' => 'StatementRef',
						'id'         => $trigger,
					),
				),
			),
			$st
		);
	}

	public function test_badge_issued_fires_once_with_the_credential_and_registration() {
		$token      = $this->mint();
		$credential = ( new Claim_Handler() )->claim( $token, self::EMAIL, false );
		$this->assert_gone( fn() => ( new Claim_Handler() )->claim( $token, self::EMAIL, false ) );

		$this->assertSame( array( array( $credential['id'], $credential, $this->registration ) ), $this->issued );
	}

	public function test_a_second_claim_is_impossible() {
		$token = $this->mint();
		( new Claim_Handler() )->claim( $token, self::EMAIL, false );

		$this->assert_gone( fn() => ( new Claim_Handler() )->claim( $token, 'mallory@example.org', false ) );

		// A fresh link for the now-issued badge cannot claim it either.
		try {
			( new Claim_Handler() )->claim( $this->mint(), 'mallory@example.org', false );
			$this->fail( 'Expected a conflict' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( Claim_Handler::CONFLICT, $e->error_code() );
		}

		$this->assertCount( 1, self::earned_rows() );
		$this->assertCount( 1, $this->issued );
		$badge = ( new Badge_Repository() )->get( $this->badge_id );
		$this->assertSame( 'sha256$' . hash( 'sha256', self::EMAIL . $badge['salt'] ), $badge['recipient_hash'] );
	}

	public function test_used_and_expired_and_unknown_tokens_are_gone() {
		$used = $this->mint();
		$this->assertTrue( ( new Link_Repository() )->consume( $used ) );
		$this->assert_gone( fn() => ( new Claim_Handler() )->claim( $used, self::EMAIL, false ) );

		$expired = $this->mint();
		Clock::freeze( $this->t0->modify( '+900 seconds' ) );
		$this->assert_gone( fn() => ( new Claim_Handler() )->claim( $expired, self::EMAIL, false ) );

		$this->assert_gone( fn() => ( new Claim_Handler() )->claim( str_repeat( 'A', 43 ), self::EMAIL, false ) );
		$this->assert_gone( fn() => ( new Claim_Handler() )->claim( 'short', self::EMAIL, false ) );

		$this->assertSame( Badge_Repository::PENDING, ( new Badge_Repository() )->get( $this->badge_id )['status'] );
		$this->assertSame( array(), $this->issued );
	}

	public function test_a_link_for_another_actor_is_gone() {
		$token = ( new Link_Repository() )->mint( 'claim', sha1( 'someone else', true ), $this->badge_id, 900, 'client:test' );

		$this->assert_gone( fn() => ( new Claim_Handler() )->claim( $token, self::EMAIL, false ) );
		$this->assertSame( Badge_Repository::PENDING, ( new Badge_Repository() )->get( $this->badge_id )['status'] );
	}

	public function test_an_invalid_email_is_refused_without_spending_the_link() {
		$token = $this->mint();
		foreach ( array( '', 'not-an-email', 'a@b', str_repeat( 'a', 250 ) . '@example.org' ) as $email ) {
			try {
				( new Claim_Handler() )->claim( $token, $email, false );
				$this->fail( 'Expected the email to be refused: ' . $email );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( Claim_Handler::INVALID_EMAIL, $e->error_code() );
			}
		}
		$this->assertNotNull( ( new Link_Repository() )->resolve( $token, 'claim' ) );
		( new Claim_Handler() )->claim( $token, self::EMAIL, false );
	}

	public function test_share_sets_the_shared_flag() {
		( new Claim_Handler() )->claim( $this->mint(), self::EMAIL, true );

		$this->assertTrue( ( new Badge_Repository() )->get( $this->badge_id )['shared'] );
	}

	public function test_signing_goes_through_the_badge_sign_filter() {
		$seen = array();
		add_filter(
			'learning_ledger/badge_sign',
			static function ( $signed, array $credential, Issuer_Key $key ) use ( &$seen ) {
				$seen[]               = array( $signed, $credential, $key );
				$signed['filteredBy'] = 'test';
				return $signed;
			},
			10,
			3
		);

		$result = ( new Claim_Handler() )->claim( $this->mint(), self::EMAIL, false );

		$this->assertCount( 1, $seen );
		[ $signed, $credential, $key ] = $seen[0];
		$this->assertArrayHasKey( 'proof', $signed );
		$this->assertArrayNotHasKey( 'proof', $credential );
		$this->assertSame( $credential, array_diff_key( $signed, array( 'proof' => 1 ) ) );
		$this->assertSame( Key_Store::active()->kid, $key->kid );
		$this->assertSame( 'test', $result['filteredBy'] );
		$this->assertSame( 'test', ( new Badge_Repository() )->get( $this->badge_id )['credential']['filteredBy'] );
	}

	public function test_a_string_from_the_badge_sign_filter_is_stored_as_is_in_one_write() {
		add_filter( 'learning_ledger/badge_sign', static fn() => 'eyJhbGciOiJSUzI1NiJ9.e30.c2ln', 10, 3 );
		$writes = array();
		$table  = Tables::name( 'badges' );
		$spy    = static function ( $sql ) use ( &$writes, $table ) {
			if ( 1 === preg_match( '/^\s*UPDATE\s+`?' . preg_quote( $table, '/' ) . '\b/i', (string) $sql ) ) {
				$writes[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $spy );

		( new Claim_Handler() )->claim( $this->mint(), self::EMAIL, true );
		remove_filter( 'query', $spy );

		$this->assertCount( 1, $writes );
		$this->assertStringContainsString( 'eyJhbGciOiJSUzI1NiJ9.e30.c2ln', $writes[0] );
		$badge = ( new Badge_Repository() )->get( $this->badge_id );
		$this->assertSame( Badge_Repository::ISSUED, $badge['status'] );
		$this->assertSame( 'eyJhbGciOiJSUzI1NiJ9.e30.c2ln', $badge['credential'] );
		$this->assertTrue( $badge['shared'] );
	}

	public function test_a_json_ld_claim_is_stored_in_one_write_with_the_share_choice() {
		$writes = 0;
		$table  = Tables::name( 'badges' );
		$spy    = static function ( $sql ) use ( &$writes, $table ) {
			if ( 1 === preg_match( '/^\s*UPDATE\s+`?' . preg_quote( $table, '/' ) . '\b/i', (string) $sql ) ) {
				++$writes;
			}
			return $sql;
		};
		add_filter( 'query', $spy );

		( new Claim_Handler() )->claim( $this->mint(), self::EMAIL, true );
		remove_filter( 'query', $spy );

		$this->assertSame( 1, $writes );
		$badge = ( new Badge_Repository() )->get( $this->badge_id );
		$this->assertTrue( $badge['shared'] );
		$this->assertArrayHasKey( 'proof', $badge['credential'] );
	}

	public function test_failures_after_issuance_still_show_the_result_page() {
		$log = tempnam( sys_get_temp_dir(), 'll57' );
		$old = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		add_action(
			'learning_ledger/badge_issued',
			static function (): void {
				throw new \RuntimeException( 'listener broke' );
			},
			5
		);
		add_filter(
			'learning_ledger/statement_guards',
			static function (): array {
				throw new \RuntimeException( 'statement store broke' );
			}
		);

		try {
			$token = $this->mint();
			$page  = $this->page();
			$body  = $this->serve( $page, $token, 'POST', $this->fields( $token ) );
		} finally {
			ini_set( 'error_log', (string) $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			remove_all_filters( 'learning_ledger/statement_guards' );
		}

		$this->assertSame( 200, $page->status );
		$this->assertStringContainsString( esc_url( home_url( '/badges/' . $this->badge_id . '.json' ) ), $body );
		$badge = ( new Badge_Repository() )->get( $this->badge_id );
		$this->assertSame( Badge_Repository::ISSUED, $badge['status'] );
		$this->assertTrue( self::verifies_against_did_document( $badge['credential'] ) );
		$this->assertCount( 1, self::rows( 'audit', "event = 'badge_issued'" ) );
		$this->assertSame( array(), self::earned_rows() );
		$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $log );
		$this->assertStringContainsString( 'listener broke', $logged );
		$this->assertStringContainsString( 'statement store broke', $logged );
	}

	public function test_a_filter_returning_garbage_issues_nothing() {
		add_filter( 'learning_ledger/badge_sign', static fn() => array( 'id' => 'urn:uuid:' . Uuid::v4() ), 10, 3 );

		try {
			( new Claim_Handler() )->claim( $this->mint(), self::EMAIL, false );
			$this->fail( 'Expected the claim to fail' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
		$this->assertSame( Badge_Repository::PENDING, ( new Badge_Repository() )->get( $this->badge_id )['status'] );
		$this->assertSame( array(), $this->issued );
	}

	public function test_without_an_active_key_nothing_is_spent() {
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'keys' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$token = $this->mint();

		try {
			( new Claim_Handler() )->claim( $token, self::EMAIL, false );
			$this->fail( 'Expected the claim to fail' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotInstanceOf( Invalid_Input::class, $e );
		}
		$this->assertNotNull( ( new Link_Repository() )->resolve( $token, 'claim' ) );
		$this->assertSame( Badge_Repository::PENDING, ( new Badge_Repository() )->get( $this->badge_id )['status'] );
	}

	public function test_issuance_is_audited_without_personal_data() {
		$credential = ( new Claim_Handler() )->claim( $this->mint(), self::EMAIL, false );

		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT actor, data FROM ' . Tables::name( 'audit' ) . ' WHERE event = %s', 'badge_issued' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertCount( 1, $rows );
		$data  = json_decode( $rows[0]['data'], true );
		$badge = ( new Badge_Repository() )->get( $this->badge_id );
		$this->assertSame( $this->badge_id, $data['badge'] );
		$this->assertSame( $credential['id'], $data['credential'] );
		$this->assertSame( self::ACHIEVEMENT, $data['achievement'] );
		$this->assertSame( $this->registration, $data['registration'] );
		$this->assertSame( Key_Store::active()->kid, $data['kid'] );
		$this->assertSame( $badge['status_list_id'], $data['status_list'] );
		$this->assertSame( $badge['status_index'], $data['status_index'] );
		$this->assertSame( 'client:test', $rows[0]['actor'] );
		$this->assertStringNotContainsString( 'alice', $rows[0]['data'] );
		$this->assertStringNotContainsString( self::NAME, $rows[0]['data'] );
	}

	public function test_signing_happens_nowhere_but_the_claim_handler() {
		$allowed = array( 'src/Badges/Status/', 'src/Badges/Revocation/', 'src/Badges/Claim/class-claim-handler.php' );
		$root    = dirname( __DIR__, 4 ) . '/';
		$found   = array();
		foreach ( array( 'src', 'integrations' ) as $dir ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				$code = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( 1 === preg_match( '/Eddsa_Rdfc_2022\s*::\s*sign\s*\(|Eddsa_Rdfc_2022::class\s*,\s*[\'"]sign[\'"]|Eddsa_Rdfc_2022::sign[\'"]/', $code ) ) {
					$found[] = substr( $file->getPathname(), strlen( $root ) );
				}
			}
		}
		$this->assertContains( 'src/Badges/Claim/class-claim-handler.php', $found );
		foreach ( $found as $path ) {
			$ok = false;
			foreach ( $allowed as $prefix ) {
				$ok = $ok || str_starts_with( $path, $prefix );
			}
			$this->assertTrue( $ok, $path . ' signs credentials' );
		}
	}

	// Claim_Page.

	private function page(): Claim_Page {
		return new class() extends Claim_Page {
			public int $status = 0;
			public array $sent = array();

			protected function send_headers( int $status, array $headers ): void {
				$this->status = $status;
				$this->sent   = $headers;
			}

			protected function finish(): void {
				throw new RuntimeException( 'finished' );
			}
		};
	}

	private function serve( Claim_Page $page, string $token, string $method = 'GET', array $fields = array() ): string {
		ob_start();
		try {
			$page->serve( $token, $method, $fields );
			$this->fail( 'Expected the response to finish' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'finished', $e->getMessage() );
		} finally {
			$body = (string) ob_get_clean();
		}
		return $body;
	}

	private function fields( string $token, array $overrides = array() ): array {
		return $overrides + array(
			'token' => $token,
			'nonce' => wp_create_nonce( Claim_Page::nonce_action( $token ) ),
			'email' => self::EMAIL,
			'share' => false,
		);
	}

	private function assert_private_headers( Claim_Page $page ): void {
		$this->assertSame( 'no-store', $page->sent['Cache-Control'] );
		$this->assertSame( 'noindex', $page->sent['X-Robots-Tag'] );
		$this->assertSame( 'no-referrer', $page->sent['Referrer-Policy'] );
	}

	public function test_page_shows_the_achievement_and_the_form_escaped_and_private() {
		$token = $this->mint();
		$page  = $this->page();
		$body  = $this->serve( $page, $token );

		$this->assertSame( 200, $page->status );
		$this->assert_private_headers( $page );
		$this->assertSame( 'text/html; charset=utf-8', $page->sent['Content-Type'] );
		$this->assertStringContainsString( "form-action 'self'", $page->sent['Content-Security-Policy'] );
		$this->assertStringContainsString( 'Badge &lt;b&gt;one&lt;/b&gt; &amp; more', $body );
		$this->assertStringNotContainsString( '<b>one</b>', $body );
		$this->assertStringContainsString( 'Do one.', $body );
		$this->assertStringContainsString( '<meta name="robots" content="noindex">', $body );
		$this->assertMatchesRegularExpression( '/<input[^>]+type="email"[^>]+name="lledger_email"/', $body );
		$this->assertMatchesRegularExpression( '/<input[^>]+type="checkbox"[^>]+name="lledger_share"/', $body );
		$this->assertMatchesRegularExpression( '/<input[^>]+name="lledger_token"[^>]+value="' . preg_quote( $token, '/' ) . '"/', $body );
		$this->assertMatchesRegularExpression( '/name="_wpnonce" value="' . preg_quote( wp_create_nonce( Claim_Page::nonce_action( $token ) ), '/' ) . '"/', $body );
		$this->assertStringNotContainsString( self::NAME, $body );
		$this->assertNotNull( ( new Link_Repository() )->resolve( $token, 'claim' ) );
	}

	public function test_page_for_a_used_or_expired_token_is_410() {
		$used = $this->mint();
		( new Link_Repository() )->consume( $used );
		$page = $this->page();
		$body = $this->serve( $page, $used );
		$this->assertSame( 410, $page->status );
		$this->assert_private_headers( $page );
		$this->assertStringNotContainsString( 'lledger_email', $body );

		$page = $this->page();
		$this->serve( $page, $used, 'POST', $this->fields( $used ) );
		$this->assertSame( 410, $page->status );

		$expired = $this->mint();
		Clock::freeze( $this->t0->modify( '+901 seconds' ) );
		foreach ( array( 'GET', 'POST' ) as $method ) {
			$page = $this->page();
			$this->serve( $page, $expired, $method, $this->fields( $expired ) );
			$this->assertSame( 410, $page->status, $method );
		}
		$this->assertSame( Badge_Repository::PENDING, ( new Badge_Repository() )->get( $this->badge_id )['status'] );
	}

	public function test_post_with_a_bad_nonce_or_token_issues_nothing() {
		$token = $this->mint();
		foreach ( array( array( 'nonce' => 'bad' ), array( 'nonce' => '' ), array( 'token' => str_repeat( 'B', 43 ) ) ) as $override ) {
			$page = $this->page();
			$this->serve( $page, $token, 'POST', $this->fields( $token, $override ) );
			$this->assertSame( 403, $page->status, (string) wp_json_encode( $override ) );
		}
		$this->assertSame( Badge_Repository::PENDING, ( new Badge_Repository() )->get( $this->badge_id )['status'] );
		$this->assertNotNull( ( new Link_Repository() )->resolve( $token, 'claim' ) );
	}

	public function test_post_with_an_invalid_email_shows_the_form_again() {
		$token = $this->mint();
		$page  = $this->page();
		$body  = $this->serve( $page, $token, 'POST', $this->fields( $token, array( 'email' => '<script>x</script>' ) ) );

		$this->assertSame( 400, $page->status );
		$this->assertStringContainsString( 'lledger_email', $body );
		$this->assertStringNotContainsString( '<script>x', $body );
		$this->assertNotNull( ( new Link_Repository() )->resolve( $token, 'claim' ) );
	}

	public function test_post_claims_and_offers_signed_downloads() {
		Hmac::set_key_for_tests( 'test-key' );
		$token = $this->mint();
		$page  = $this->page();
		$body  = $this->serve( $page, $token, 'POST', $this->fields( $token ) );

		$this->assertSame( 200, $page->status, $body );
		$this->assert_private_headers( $page );
		$this->assertSame( Badge_Repository::ISSUED, ( new Badge_Repository() )->get( $this->badge_id )['status'] );
		$this->assertStringContainsString( 'Badge &lt;b&gt;one&lt;/b&gt; &amp; more', $body );

		$exp = $this->t0->getTimestamp() + 900;
		$sig = rtrim( strtr( base64_encode( Hmac::sign( $this->badge_id . '|' . $exp, 'badge-download' ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		foreach ( array( 'json', 'png' ) as $ext ) {
			$url = home_url( '/badges/' . $this->badge_id . '.' . $ext ) . '?exp=' . $exp . '&sig=' . $sig;
			$this->assertSame( $url, Claim_Page::download_url( $this->badge_id, $ext, $exp ) );
			$this->assertStringContainsString( 'href="' . esc_url( $url ) . '"', $body );
		}
		$this->assertTrue( Hmac::verify( Hmac::sign( $this->badge_id . '|' . $exp, 'badge-download' ), $this->badge_id . '|' . $exp, 'badge-download' ) );
		$this->assertStringNotContainsString( 'value="' . esc_attr( home_url( '/badges/' . $this->badge_id ) ) . '"', $body );
		$this->assertStringNotContainsString( self::EMAIL, $body );

		// The link is spent: reloading the page is a 410.
		$again = $this->page();
		$this->serve( $again, $token, 'POST', $this->fields( $token ) );
		$this->assertSame( 410, $again->status );
		$this->assertCount( 1, $this->issued );
	}

	public function test_post_with_share_offers_the_public_link() {
		$token = $this->mint();
		$page  = $this->page();
		$body  = $this->serve( $page, $token, 'POST', $this->fields( $token, array( 'share' => true ) ) );

		$this->assertSame( 200, $page->status );
		$this->assertTrue( ( new Badge_Repository() )->get( $this->badge_id )['shared'] );
		$this->assertStringContainsString( 'value="' . esc_attr( home_url( '/badges/' . $this->badge_id ) ) . '"', $body );
	}

	public function test_maybe_serve_answers_only_on_its_own_rewrite_rule() {
		$token = $this->mint();
		$page  = $this->page();
		set_query_var( Claim_Page::QUERY_VAR, $token );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$wp                        = $GLOBALS['wp'];
		$rule                      = $wp->matched_rule;

		$wp->matched_rule = null;
		ob_start();
		$page->maybe_serve();
		$this->assertSame( '', ob_get_clean() );
		$this->assertSame( 0, $page->status );

		$wp->matched_rule = Claim_Page::RULE;
		try {
			ob_start();
			$page->maybe_serve();
			$this->fail( 'Expected the response to finish' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'finished', $e->getMessage() );
		} finally {
			ob_end_clean();
			$wp->matched_rule = $rule;
			set_query_var( Claim_Page::QUERY_VAR, '' );
			unset( $_SERVER['REQUEST_METHOD'] );
		}
		$this->assertSame( 200, $page->status );
	}
}
