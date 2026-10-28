<?php
/**
 * VC-JWT fallback (degraded badge mode): Vc_Jwt_Signer, Rsa_Key_Store and Jwt_Module tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Claim\Claim_Handler;
use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Issuer_Profile;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Badges\Jwt\Jwt_Module;
use LearningLedger\Badges\Jwt\Rsa_Key_Store;
use LearningLedger\Badges\Jwt\Vc_Jwt_Signer;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Kernel\Plugin;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Links\Link_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Uuid;
use LearningLedger\Vendor\Firebase\JWT\JWK;
use LearningLedger\Vendor\Firebase\JWT\JWT;
use LearningLedger\Xapi\Agent_Identity;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.DuplicateClassName.Found, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- minimal WP_CLI stand-in for this test only, defined only when no other test defined one.
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {

		public static function error( string $message ): void {
			throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		public static function success( string $message ): void {
			$GLOBALS['lledger_t65_cli'][] = $message;
		}
	}
}

class Vc_Jwt_Signer_Test extends WP_UnitTestCase {

	const COURSE      = 'https://example.org/courses/jwt';
	const HOME        = 'https://site.example';
	const NAME        = 'learner-65';
	const EMAIL       = 'alice@example.org';
	const ACHIEVEMENT = 'https://example.org/badges/jwt';

	private string $badge_id;

	private \DateTimeImmutable $t0;

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'badges', 'links', 'jobs', 'keys', 'status_lists', 'audit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lledger\\_status\\_list\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( Jwt_Module::OPTION );
		wp_cache_flush();
		Key_Store::generate();
		update_option( Issuer_Profile::NAME_OPTION, 'Learn WordPress, reimagined' );

		$this->t0 = new \DateTimeImmutable( gmdate( 'Y-m-d\TH:i:s' ) . '.000000Z' );
		Clock::freeze( $this->t0 );
		$GLOBALS['lledger_t65_cli'] = array();

		$writer         = Client_Factory::create( array( Scopes::STATEMENTS_WRITE ), array( 'trusted' => true ) );
		$registration   = Uuid::v4();
		[ $trigger ]    = ( new Statement_Store() )->store(
			array(
				array(
					'actor'   => self::actor(),
					'verb'    => array( 'id' => 'https://w3id.org/xapi/adl/verbs/satisfied' ),
					'object'  => array( 'id' => self::COURSE ),
					'context' => array( 'registration' => $registration ),
				),
			),
			Principal::for_client( ( new Client_Repository() )->find( $writer['id'] ) ),
			'1.0.3'
		);
		$this->badge_id = (string) ( new Badge_Repository() )->create_pending(
			array(
				'id'              => self::ACHIEVEMENT,
				'name'            => 'JWT badge',
				'description'     => 'Earned in VC-JWT mode.',
				'criteria'        => array( 'narrative' => 'Do it.' ),
				'achievementType' => 'Badge',
			),
			$registration,
			Agent_Identity::from_array( self::actor() )->hash(),
			self::COURSE,
			$trigger,
			'1 of 1 lessons completed',
			0
		);
	}

	public function tear_down() {
		try {
			remove_all_filters( 'learning_ledger/badge_sign' );
			remove_all_filters( 'learning_ledger/did_verification_methods' );
			remove_all_filters( 'site_status_tests' );
			remove_all_actions( 'learning_ledger/badge_issued' );
			remove_all_actions( 'learning_ledger/statements_committed' );
			delete_option( Issuer_Profile::NAME_OPTION );
			Clock::freeze( null );
			unset( $GLOBALS['lledger_t65_cli'] );
		} finally {
			// Always roll back, so a separate-process test never waits on this connection's locks.
			parent::tear_down();
		}
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

	private function claim(): array {
		$token = ( new Link_Repository() )->mint( 'claim', Agent_Identity::from_array( self::actor() )->hash(), $this->badge_id, 900, 'client:test' );
		return ( new Claim_Handler() )->claim( $token, self::EMAIL, false );
	}

	private static function segment( string $jwt, int $index ): array {
		return json_decode( JWT::urlsafeB64Decode( explode( '.', $jwt )[ $index ] ), true );
	}

	/**
	 * Resolve a header `kid` against did.json, as a verifier would: it must be an assertion method of type JsonWebKey.
	 */
	private static function published_jwk( string $kid ): ?array {
		$doc = Did_Document::document();
		if ( ! in_array( $kid, $doc['assertionMethod'], true ) ) {
			return null;
		}
		foreach ( $doc['verificationMethod'] as $method ) {
			if ( $method['id'] === $kid && 'JsonWebKey' === $method['type'] && $doc['id'] === $method['controller'] ) {
				return $method['publicKeyJwk'];
			}
		}
		return null;
	}

	private static function rsa_rows(): array {
		return (array) $GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( 'SELECT * FROM ' . Tables::name( 'keys' ) . ' WHERE purpose = %s', Rsa_Key_Store::PURPOSE ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Audit events after set_up's Ed25519 key generation.
	 */
	private static function audit_events(): array {
		return $GLOBALS['wpdb']->get_col( 'SELECT event FROM ' . Tables::name( 'audit' ) . " WHERE event <> 'badge_key_generated' ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function site_health( string $format ): array {
		update_option( Jwt_Module::OPTION, $format );
		$tests = apply_filters( 'site_status_tests', array() );
		return call_user_func( $tests['direct']['lledger_badge_format']['test'] );
	}

	// Degraded mode on: the claim stores a VC-JWT.

	public function test_vc_jwt_claim_yields_a_jwt_that_verifies_with_the_published_jwk() {
		( new Jwt_Module() )->register();
		update_option( Jwt_Module::OPTION, 'vc-jwt' );

		$credential = $this->claim();
		$stored     = ( new Badge_Repository() )->get( $this->badge_id )['credential'];

		$this->assertIsString( $stored );
		$header = self::segment( $stored, 0 );
		$this->assertSame(
			array(
				'typ' => 'JWT',
				'alg' => 'RS256',
				'kid' => Did_Document::did() . '#' . Rsa_Key_Store::active()['kid'],
			),
			$header
		);

		$this->assertContains( Did_Document::JWK_CONTEXT, Did_Document::document()['@context'] );
		$jwk = self::published_jwk( $header['kid'] );
		$this->assertIsArray( $jwk, 'The header kid must resolve to a JsonWebKey assertion method in did.json' );
		$this->assertSame( 'RSA', $jwk['kty'] );
		$this->assertArrayNotHasKey( 'd', $jwk );

		$payload = (array) JWT::decode( $stored, JWK::parseKey( $jwk, 'RS256' ) );
		$this->assertSame( Did_Document::did(), $payload['iss'] );
		$this->assertSame( 'urn:uuid:' . $this->badge_id, $payload['jti'] );
		$this->assertSame( $this->t0->getTimestamp(), $payload['nbf'] );
		$this->assertArrayNotHasKey( 'exp', $payload );

		$vc = self::segment( $stored, 1 )['vc'];
		$this->assertSame( $credential, $vc );
		$this->assertArrayNotHasKey( 'proof', $vc );
		$this->assertSame( 'urn:uuid:' . $this->badge_id, $vc['id'] );
		$this->assertSame( Did_Document::did(), $vc['issuer']['id'] );
		$this->assertSame( $this->t0->format( 'Y-m-d\TH:i:s\Z' ), $vc['validFrom'] );
	}

	public function test_jwt_signed_by_another_key_does_not_verify_with_the_published_jwk() {
		( new Jwt_Module() )->register();
		update_option( Jwt_Module::OPTION, 'vc-jwt' );
		$this->claim();
		$stored = (string) ( new Badge_Repository() )->get( $this->badge_id )['credential'];
		$parts  = explode( '.', $stored );
		$other  = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_sign( $parts[0] . '.' . $parts[1], $sig, $other, OPENSSL_ALGO_SHA256 );
		$forged = $parts[0] . '.' . $parts[1] . '.' . JWT::urlsafeB64Encode( $sig );

		$this->expectException( \LearningLedger\Vendor\Firebase\JWT\SignatureInvalidException::class );
		JWT::decode( $forged, JWK::parseKey( self::published_jwk( self::segment( $stored, 0 )['kid'] ), 'RS256' ) );
	}

	// Default mode: nothing changes.

	public function test_di_format_changes_nothing() {
		$before = Did_Document::document();
		( new Jwt_Module() )->register();

		$this->assertSame( 'di', Jwt_Module::format() );
		$this->assertSame( $before, Did_Document::document() );

		$credential = $this->claim();
		$this->assertSame( 'DataIntegrityProof', $credential['proof']['type'] );
		$this->assertSame( 'eddsa-rdfc-2022', $credential['proof']['cryptosuite'] );
		$this->assertSame( $credential, ( new Badge_Repository() )->get( $this->badge_id )['credential'] );
		$this->assertSame( array(), self::rsa_rows(), 'No RSA key is created in the default mode' );
		$this->assertSame( $before, Did_Document::document() );
	}

	public function test_unknown_format_value_is_treated_as_di() {
		update_option( Jwt_Module::OPTION, 'VC-JWT ' );
		$this->assertSame( 'di', Jwt_Module::format() );
	}

	public function test_rsa_key_stays_published_after_switching_back_to_di() {
		( new Jwt_Module() )->register();
		update_option( Jwt_Module::OPTION, 'vc-jwt' );
		$this->claim();
		$kid = self::segment( (string) ( new Badge_Repository() )->get( $this->badge_id )['credential'], 0 )['kid'];

		update_option( Jwt_Module::OPTION, 'di' );
		$this->assertIsArray( self::published_jwk( $kid ), 'Badges already issued as VC-JWT keep verifying' );
	}

	// Site Health.

	public function test_site_health_reports_the_degraded_mode() {
		( new Jwt_Module() )->register();

		$di = self::site_health( 'di' );
		$this->assertSame( 'good', $di['status'] );
		$this->assertSame( 'lledger_badge_format', $di['test'] );

		$jwt = self::site_health( 'vc-jwt' );
		$this->assertSame( 'recommended', $jwt['status'] );
		$this->assertSame( 'lledger_badge_format', $jwt['test'] );
		$this->assertStringContainsString( 'degraded', strtolower( $jwt['label'] ) );
		$this->assertStringContainsString( '1EdTech', $jwt['description'] );
		$this->assertStringContainsString( 'Learner Credential Wallet', $jwt['description'] );
		$this->assertStringContainsString( 'badges format di', $jwt['actions'] );
	}

	public function test_site_health_is_good_when_stored_rsa_keys_are_readable() {
		( new Jwt_Module() )->register();
		Rsa_Key_Store::active();
		$this->assertSame( array(), Rsa_Key_Store::unreadable() );
		$this->assertSame( 'recommended', self::site_health( 'vc-jwt' )['status'] );
	}

	// Unreadable stored RSA keys.

	private static function insert_rsa_row( string $kid, string $public_pem, string $sealed ): void {
		$GLOBALS['wpdb']->insert(
			Tables::name( 'keys' ),
			array(
				'kid'           => $kid,
				'purpose'       => Rsa_Key_Store::PURPOSE,
				'public_key'    => $public_pem,
				'secret_sealed' => $sealed,
				'created_at'    => '2026-01-01 00:00:00.000000',
			)
		);
	}

	public function test_unreadable_public_key_row_is_skipped_in_did_json_and_reported() {
		( new Jwt_Module() )->register();
		$good = Rsa_Key_Store::active();
		self::insert_rsa_row( 'bad-key', 'not a pem', 'not sealed' );

		$doc = Did_Document::document();
		$ids = array_column( $doc['verificationMethod'], 'id' );
		$this->assertContains( Did_Document::did() . '#' . $good['kid'], $ids );
		$this->assertNotContains( Did_Document::did() . '#bad-key', $ids );
		$this->assertContains( Did_Document::JWK_CONTEXT, $doc['@context'] );
		$this->assertSame( array( 'bad-key' ), Rsa_Key_Store::unreadable() );

		$jwt = self::site_health( 'vc-jwt' );
		$this->assertSame( 'critical', $jwt['status'] );
		$this->assertSame( 'lledger_badge_format', $jwt['test'] );
		$this->assertStringContainsString( 'bad-key', $jwt['description'] );
		$this->assertSame( 'recommended', self::site_health( 'di' )['status'] );
	}

	public function test_unopenable_private_key_is_reported_but_its_public_half_stays_published() {
		( new Jwt_Module() )->register();
		$res     = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$details = openssl_pkey_get_details( $res );
		$kid     = Rsa_Key_Store::thumbprint(
			array(
				'n' => JWT::urlsafeB64Encode( $details['rsa']['n'] ),
				'e' => JWT::urlsafeB64Encode( $details['rsa']['e'] ),
			)
		);
		self::insert_rsa_row( $kid, $details['key'], base64_encode( random_bytes( 64 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->assertIsArray( self::published_jwk( Did_Document::did() . '#' . $kid ) );
		$this->assertSame( array( $kid ), Rsa_Key_Store::unreadable() );
		$this->assertSame( 'critical', self::site_health( 'vc-jwt' )['status'] );
	}

	// Vc_Jwt_Signer claims.

	public function test_sign_sets_sub_and_exp_when_the_credential_has_them() {
		$credential = array(
			'id'                => 'urn:uuid:3f0c3b8e-0d8c-4b8e-9d55-2d3f1b6c7a10',
			'issuer'            => 'did:web:issuer.example',
			'validFrom'         => '2026-01-02T03:04:05Z',
			'validUntil'        => '2027-01-02T03:04:05Z',
			'credentialSubject' => array( 'id' => 'did:example:learner' ),
		);
		$payload    = self::segment( Vc_Jwt_Signer::sign( $credential ), 1 );

		$this->assertSame( 'did:web:issuer.example', $payload['iss'] );
		$this->assertSame( 'did:example:learner', $payload['sub'] );
		$this->assertSame( gmmktime( 3, 4, 5, 1, 2, 2026 ), $payload['nbf'] );
		$this->assertSame( gmmktime( 3, 4, 5, 1, 2, 2027 ), $payload['exp'] );
		$this->assertSame( $credential, $payload['vc'] );
	}

	public function test_sign_refuses_a_credential_without_id_issuer_or_valid_from() {
		$base = array(
			'id'        => 'urn:uuid:3f0c3b8e-0d8c-4b8e-9d55-2d3f1b6c7a10',
			'issuer'    => array( 'id' => 'did:web:issuer.example' ),
			'validFrom' => '2026-01-02T03:04:05Z',
		);
		foreach ( array( 'id', 'issuer', 'validFrom' ) as $missing ) {
			$credential = $base;
			unset( $credential[ $missing ] );
			try {
				Vc_Jwt_Signer::sign( $credential );
				$this->fail( 'Expected a credential without ' . $missing . ' to be refused' );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'invalid', $e->error_code() );
			}
		}
	}

	// Rsa_Key_Store.

	public function test_rsa_key_is_2048_bit_sealed_in_the_database_and_stable() {
		$this->assertSame( Key_Store::TIER_DATABASE, Rsa_Key_Store::tier() );
		$key = Rsa_Key_Store::active();

		$details = openssl_pkey_get_details( openssl_pkey_get_private( $key['private_pem'] ) );
		$this->assertSame( OPENSSL_KEYTYPE_RSA, $details['type'] );
		$this->assertSame( 2048, $details['bits'] );
		$this->assertSame( JWT::urlsafeB64Encode( $details['rsa']['n'] ), $key['public_jwk']['n'] );
		$this->assertSame( JWT::urlsafeB64Encode( $details['rsa']['e'] ), $key['public_jwk']['e'] );
		$this->assertArrayNotHasKey( 'd', $key['public_jwk'] );

		// RFC 7638 thumbprint of the required members, in lexicographic order.
		$thumb = wp_json_encode(
			array(
				'e'   => $key['public_jwk']['e'],
				'kty' => 'RSA',
				'n'   => $key['public_jwk']['n'],
			)
		);
		$this->assertSame( JWT::urlsafeB64Encode( hash( 'sha256', $thumb, true ) ), $key['kid'] );

		$rows = self::rsa_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( $key['kid'], $rows[0]['kid'] );
		$this->assertStringNotContainsString( 'PRIVATE KEY', (string) $rows[0]['secret_sealed'] );
		$this->assertStringNotContainsString( 'PRIVATE KEY', (string) $rows[0]['public_key'] );

		$this->assertSame( $key, Rsa_Key_Store::active() );
		$this->assertCount( 1, self::rsa_rows() );
		$this->assertSame(
			array(
				array(
					'kid'        => $key['kid'],
					'public_jwk' => $key['public_jwk'],
				),
			),
			Rsa_Key_Store::published()
		);
		$this->assertSame( array( 'badge_rsa_key_generated' ), self::audit_events() );
	}

	public function test_ed25519_badge_keys_are_untouched_by_the_rsa_store() {
		$ed = Key_Store::active();
		Rsa_Key_Store::active();
		$this->assertSame( array( $ed->kid ), array_map( static fn( $k ) => $k->kid, Key_Store::published() ) );
		$this->assertSame( $ed->kid, Key_Store::active()->kid );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_tier() {
		$res = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $res, $pem );
		define( 'LLEDGER_BADGE_RSA_KEY', $pem );

		$this->assertSame( Key_Store::TIER_CONSTANT, Rsa_Key_Store::tier() );
		$key = Rsa_Key_Store::active();
		$this->assertSame( JWT::urlsafeB64Encode( openssl_pkey_get_details( $res )['rsa']['n'] ), $key['public_jwk']['n'] );
		$this->assertSame( array( $key['kid'] ), array_column( Rsa_Key_Store::published(), 'kid' ) );
		$this->assertSame( array(), self::rsa_rows() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_tier_refuses_a_short_key() {
		$res = openssl_pkey_new(
			array(
				'private_key_bits' => 1024,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $res, $pem );
		define( 'LLEDGER_BADGE_RSA_KEY', $pem );

		$this->expectException( RuntimeException::class );
		Rsa_Key_Store::active();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_key_file_tier_0600_outside_abspath() {
		$res = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $res, $pem );
		$path = sys_get_temp_dir() . '/lledger-t65-' . wp_generate_password( 8, false ) . '.pem';
		file_put_contents( $path, $pem ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		clearstatcache();
		define( 'LLEDGER_BADGE_RSA_KEY_FILE', $path );
		try {
			$this->assertSame( Key_Store::TIER_FILE, Rsa_Key_Store::tier() );
			$key = Rsa_Key_Store::active();
			$this->assertSame( JWT::urlsafeB64Encode( openssl_pkey_get_details( $res )['rsa']['n'] ), $key['public_jwk']['n'] );
			$this->assertSame( array(), self::rsa_rows() );
		} finally {
			wp_delete_file( $path );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_world_readable_key_file_is_ignored() {
		$path = sys_get_temp_dir() . '/lledger-t65-' . wp_generate_password( 8, false ) . '.pem';
		file_put_contents( $path, 'not used' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		chmod( $path, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		clearstatcache();
		define( 'LLEDGER_BADGE_RSA_KEY_FILE', $path );
		try {
			$this->assertSame( Key_Store::TIER_DATABASE, Rsa_Key_Store::tier() );
		} finally {
			wp_delete_file( $path );
		}
	}

	// Module, manifest and WP-CLI.

	public function test_module_is_in_the_manifest_for_the_lrs_role() {
		$this->assertSame( 'lrs', Plugin::MODULES[ Jwt_Module::class ] );
	}

	public function test_cli_format_switches_and_creates_the_rsa_key() {
		$module = new Jwt_Module();
		$module->format_command( array( 'vc-jwt' ) );

		$this->assertSame( 'vc-jwt', get_option( Jwt_Module::OPTION ) );
		$this->assertCount( 1, self::rsa_rows() );
		$kid = Rsa_Key_Store::active()['kid'];
		// Another test file may have defined its own WP_CLI stand-in first; only this one records output.
		if ( __FILE__ === ( new ReflectionMethod( 'WP_CLI', 'success' ) )->getFileName() ) {
			$this->assertCount( 1, $GLOBALS['lledger_t65_cli'] );
			$this->assertStringContainsString( $kid, $GLOBALS['lledger_t65_cli'][0] );
			$this->assertStringNotContainsString( 'PRIVATE', $GLOBALS['lledger_t65_cli'][0] );
		}

		$module->format_command( array( 'di' ) );
		$this->assertSame( 'di', get_option( Jwt_Module::OPTION ) );
		$this->assertSame( array( 'badge_rsa_key_generated', 'badge_format_changed', 'badge_format_changed' ), self::audit_events() );
	}

	public function test_cli_format_rejects_other_values() {
		update_option( Jwt_Module::OPTION, 'di' );
		try {
			( new Jwt_Module() )->format_command( array( 'jwt' ) );
			$this->fail( 'Expected an unknown format to be refused' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'di', get_option( Jwt_Module::OPTION ) );
		}
	}
}
