<?php
/**
 * Reissuer tests: re-issue after key removal and status list re-signing.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Claim\Claim_Handler;
use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Issuer_Key;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Badges\Revocation\Reissuer;
use LearningLedger\Badges\Revocation\Revoker;
use LearningLedger\Badges\Signing\Eddsa_Rdfc_2022;
use LearningLedger\Badges\Status\Status_List_Repository;
use LearningLedger\Crypto\Multibase;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Links\Link_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

class Reissuer_Test extends WP_UnitTestCase {

	const COURSE = 'https://example.org/courses/badges';
	const EMAIL  = 'alice@example.org';

	private string $registration;

	private string $trigger_id;

	private \DateTimeImmutable $t0;

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
		$this->t0 = new \DateTimeImmutable( gmdate( 'Y-m-d\TH:i:s' ) . '.000000Z' );
		Clock::freeze( $this->t0 );

		$this->registration   = Uuid::v4();
		$client               = Client_Factory::create( array( Scopes::STATEMENTS_WRITE ), array( 'trusted' => true ) );
		[ $this->trigger_id ] = ( new Statement_Store() )->store(
			array(
				array(
					'actor'   => self::actor(),
					'verb'    => array( 'id' => 'https://w3id.org/xapi/adl/verbs/satisfied' ),
					'object'  => array( 'id' => self::COURSE ),
					'context' => array( 'registration' => $this->registration ),
				),
			),
			Principal::for_client( ( new Client_Repository() )->find( $client['id'] ) ),
			'1.0.3'
		);
	}

	public function tear_down() {
		remove_all_filters( 'learning_ledger/badge_sign' );
		remove_all_actions( 'learning_ledger/statements_committed' );
		Clock::freeze( null );
		parent::tear_down();
	}

	private static function actor(): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => 'https://site.example',
				'name'     => 'learner-1',
			),
		);
	}

	private function pending( string $achievement ): string {
		return (string) ( new Badge_Repository() )->create_pending(
			array(
				'id'          => $achievement,
				'name'        => 'Badge',
				'description' => 'Earned.',
				'criteria'    => array( 'narrative' => 'Do it.' ),
			),
			$this->registration,
			Agent_Identity::from_array( self::actor() )->hash(),
			self::COURSE,
			$this->trigger_id,
			'1 of 1 lessons completed',
			0
		);
	}

	private function issued( string $achievement ): string {
		$id    = $this->pending( $achievement );
		$token = ( new Link_Repository() )->mint( 'claim', Agent_Identity::from_array( self::actor() )->hash(), $id, 900, 'client:test' );
		( new Claim_Handler() )->claim( $token, self::EMAIL, false );
		return $id;
	}

	private static function verifies( array $secured ): bool {
		return Eddsa_Rdfc_2022::verify(
			$secured,
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

	private static function list_ids(): array {
		return array_map( 'intval', $GLOBALS['wpdb']->get_col( 'SELECT id FROM ' . Tables::name( 'status_lists' ) . ' ORDER BY id' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function audit( string $event ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT data FROM ' . Tables::name( 'audit' ) . ' WHERE event = %s ORDER BY id', $event ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( static fn( string $d ): array => json_decode( $d, true ), $rows );
	}

	private static function fake_jwt( array $credential, Issuer_Key $key ): string {
		$header  = Multibase::base64url_encode(
			(string) wp_json_encode(
				array(
					'alg' => 'RS256',
					'kid' => Did_Document::verification_method_for( $key ),
				)
			)
		);
		$payload = Multibase::base64url_encode( (string) wp_json_encode( array( 'vc' => $credential ) ) );
		return $header . '.' . $payload . '.' . Multibase::base64url_encode( 'sig' );
	}

	public function test_reissue_keeps_the_id_and_verifies_against_the_new_key_after_remove_key() {
		$id     = $this->issued( 'https://example.org/badges/one' );
		$before = ( new Badge_Repository() )->get( $id );
		$old    = Key_Store::active()->kid;
		$this->assertTrue( self::verifies( $before['credential'] ) );

		Clock::freeze( $this->t0->modify( '+1 hour' ) );
		$new = Key_Store::rotate();
		Key_Store::remove( $old );
		$this->assertFalse( self::verifies( $before['credential'] ), 'A credential signed with a removed key no longer verifies.' );

		$this->assertSame( 1, ( new Reissuer() )->reissue_for_key( $old ) );

		$after = ( new Badge_Repository() )->get( $id );
		$cred  = $after['credential'];
		$this->assertSame( Badge_Repository::ISSUED, $after['status'] );
		$this->assertSame( $before['credential']['id'], $cred['id'] );
		$this->assertSame( 'urn:uuid:' . $id, $cred['id'] );
		$this->assertSame( $this->t0->modify( '+1 hour' )->format( 'Y-m-d\TH:i:s\Z' ), $cred['validFrom'] );
		$this->assertGreaterThan( strtotime( $before['credential']['validFrom'] ), strtotime( $cred['validFrom'] ) );
		$this->assertSame( Did_Document::verification_method_for( $new ), $cred['proof']['verificationMethod'] );
		$this->assertTrue( self::verifies( $cred ) );
		$this->assertSame( $before['credential']['credentialStatus'], $cred['credentialStatus'] );
		$this->assertSame( $before['credential']['credentialSubject'], $cred['credentialSubject'] );
		$this->assertSame( $this->t0->modify( '+1 hour' )->format( 'Y-m-d\TH:i:s.u\Z' ), $after['valid_from'] );
		$this->assertSame( $before['issued_at'], $after['issued_at'] );

		$this->assertTrue( self::verifies( ( new Status_List_Repository() )->credential( $after['status_list_id'] ) ), 'The status list is signed with the active key.' );

		$audit = self::audit( 'badge_reissued' );
		$this->assertCount( 1, $audit );
		$this->assertSame( $id, $audit[0]['badge'] );
		$this->assertSame( $new->kid, $audit[0]['kid'] );
		$this->assertSame( $old, $audit[0]['replaced_kid'] );
	}

	public function test_validfrom_is_strictly_newer_even_within_the_same_second() {
		$id     = $this->issued( 'https://example.org/badges/one' );
		$before = ( new Badge_Repository() )->get( $id )['credential'];
		$old    = Key_Store::active()->kid;
		Key_Store::rotate();
		Key_Store::remove( $old );

		( new Reissuer() )->reissue_for_key( $old );

		$after = ( new Badge_Repository() )->get( $id )['credential'];
		$this->assertSame( $this->t0->modify( '+1 second' )->format( 'Y-m-d\TH:i:s\Z' ), $after['validFrom'] );
		$this->assertNotSame( $before['validFrom'], $after['validFrom'] );
		$this->assertTrue( self::verifies( $after ) );
	}

	public function test_reissue_skips_other_keys_pending_and_revoked_badges() {
		$old     = Key_Store::active()->kid;
		$a       = $this->issued( 'https://example.org/badges/one' );
		$revoked = $this->issued( 'https://example.org/badges/two' );
		( new Revoker() )->revoke( $revoked, Revoker::REASON_ADMIN );
		$pending = $this->pending( 'https://example.org/badges/three' );
		Key_Store::rotate();
		$b      = $this->issued( 'https://example.org/badges/four' );
		$repo   = new Badge_Repository();
		$b_cred = $repo->get( $b )['credential'];
		$r_cred = $repo->get( $revoked )['credential'];
		Key_Store::remove( $old );

		$this->assertSame( 1, ( new Reissuer() )->reissue_for_key( $old ) );

		$this->assertTrue( self::verifies( $repo->get( $a )['credential'] ) );
		$this->assertSame( $b_cred, $repo->get( $b )['credential'] );
		$this->assertSame( $r_cred, $repo->get( $revoked )['credential'] );
		$this->assertSame( Badge_Repository::REVOKED, $repo->get( $revoked )['status'] );
		$this->assertNull( $repo->get( $pending )['credential'] );
		$this->assertSame( 0, ( new Reissuer() )->reissue_for_key( $old ), 'Re-issued credentials no longer use the removed key.' );
	}

	public function test_reissue_refuses_the_active_key_and_a_missing_active_key_and_a_bad_kid() {
		$kid = Key_Store::active()->kid;
		foreach ( array( $kid, 'bad kid!' ) as $bad ) {
			try {
				( new Reissuer() )->reissue_for_key( $bad );
				$this->fail( 'Expected a refusal for ' . $bad );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'invalid', $e->error_code() );
			}
		}
		Key_Store::remove( $kid );
		$this->expectException( RuntimeException::class );
		( new Reissuer() )->reissue_for_key( $kid );
	}

	public function test_reissue_passes_the_result_through_the_badge_sign_filter() {
		add_filter( 'learning_ledger/badge_sign', static fn( $signed, array $credential, Issuer_Key $key ): string => self::fake_jwt( $credential, $key ), 10, 3 );
		$id  = $this->issued( 'https://example.org/badges/one' );
		$old = Key_Store::active()->kid;
		$jwt = ( new Badge_Repository() )->get( $id )['credential'];
		$this->assertIsString( $jwt );

		Clock::freeze( $this->t0->modify( '+1 hour' ) );
		$new = Key_Store::rotate();
		Key_Store::remove( $old );
		$this->assertSame( 1, ( new Reissuer() )->reissue_for_key( $old ) );

		$after = ( new Badge_Repository() )->get( $id )['credential'];
		$this->assertIsString( $after );
		[ $header, $payload ] = explode( '.', $after );
		$this->assertSame( Did_Document::verification_method_for( $new ), json_decode( Multibase::base64url_decode( $header ), true )['kid'] );
		$vc = json_decode( Multibase::base64url_decode( $payload ), true )['vc'];
		$this->assertSame( 'urn:uuid:' . $id, $vc['id'] );
		$this->assertSame( $this->t0->modify( '+1 hour' )->format( 'Y-m-d\TH:i:s\Z' ), $vc['validFrom'] );
		$this->assertArrayNotHasKey( 'proof', $vc );
	}

	public function test_reissue_fails_closed_when_the_filter_returns_garbage() {
		$id     = $this->issued( 'https://example.org/badges/one' );
		$old    = Key_Store::active()->kid;
		$before = ( new Badge_Repository() )->get( $id )['credential'];
		Key_Store::rotate();
		Key_Store::remove( $old );
		add_filter( 'learning_ledger/badge_sign', static fn() => array( 'id' => 'urn:uuid:other' ) );

		try {
			( new Reissuer() )->reissue_for_key( $old );
			$this->fail( 'Expected the re-issue to fail' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( $before, ( new Badge_Repository() )->get( $id )['credential'] );
		}
	}

	// Status list re-signing (rotation and removal).

	public function test_resign_after_rotation_moves_every_live_list_to_the_active_key() {
		$small = new Status_List_Repository( 8 );
		for ( $i = 0; $i < 7; $i++ ) {
			$small->allocate();
		}
		$this->assertCount( 2, self::list_ids() );
		$id   = $this->issued( 'https://example.org/badges/one' );
		$old  = Key_Store::active()->kid;
		$list = ( new Badge_Repository() )->get( $id )['status_list_id'];
		( new Revoker() )->revoke( $id, Revoker::REASON_ADMIN );
		$encoded = ( new Status_List_Repository() )->credential( $list )['credentialSubject']['encodedList'];

		Clock::freeze( $this->t0->modify( '+1 minute' ) );
		$new = Key_Store::rotate();
		$this->assertSame( count( self::list_ids() ), ( new Reissuer() )->resign_status_lists() );
		$this->assertSame( 0, ( new Reissuer() )->resign_status_lists(), 'Lists already signed with the active key are left alone.' );
		Key_Store::remove( $old );

		foreach ( self::list_ids() as $list_id ) {
			$credential = ( new Status_List_Repository() )->credential( $list_id );
			$this->assertSame( Did_Document::verification_method_for( $new ), $credential['proof']['verificationMethod'] );
			$this->assertSame( $this->t0->modify( '+1 minute' )->format( 'Y-m-d\TH:i:s\Z' ), $credential['validFrom'] );
			$this->assertTrue( self::verifies( $credential ) );
		}
		$this->assertSame( $encoded, ( new Status_List_Repository() )->credential( $list )['credentialSubject']['encodedList'], 'Revocations survive re-signing.' );
	}

	public function test_removing_a_retired_key_resigns_its_lists_first() {
		$this->issued( 'https://example.org/badges/one' );
		$old = Key_Store::active()->kid;
		$new = Key_Store::rotate();

		( new Reissuer() )->prepare_key_removal( $old );
		Key_Store::remove( $old );

		foreach ( self::list_ids() as $list_id ) {
			$credential = ( new Status_List_Repository() )->credential( $list_id );
			$this->assertSame( Did_Document::verification_method_for( $new ), $credential['proof']['verificationMethod'] );
			$this->assertTrue( self::verifies( $credential ) );
		}
	}

	public function test_removing_the_key_that_signs_a_live_list_is_refused_when_no_other_key_can_resign_it() {
		$this->issued( 'https://example.org/badges/one' );
		$kid = Key_Store::active()->kid;

		try {
			( new Reissuer() )->prepare_key_removal( $kid );
			$this->fail( 'Removing the only key that signs a live list was allowed' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'rotate', $e->getMessage() );
		}
	}

	public function test_removing_the_active_key_is_allowed_when_no_list_exists() {
		$kid = Key_Store::active()->kid;
		( new Reissuer() )->prepare_key_removal( $kid );
		Key_Store::remove( $kid );
		$this->assertNull( Key_Store::active() );
	}
}
