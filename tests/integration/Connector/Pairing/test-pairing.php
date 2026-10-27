<?php
/**
 * Connector pairing, secret and signing key tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixtures.

use LearningLedger\Connector\Pairing\Connector_Keys;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Pairing\Pairing_Command;
use LearningLedger\Connector\Pairing\Pairing_Module;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Secret_Box;
use LearningLedger\Crypto\Signed_Request;
use LearningLedger\Crypto\Memory_Nonce_Store;

class Pairing_Test extends WP_UnitTestCase {

	const LRS = 'https://lrs.example.test/xapi/';
	const EXT = 'https://github.com/derintolu/learning-ledger';

	/** @var array[] */
	private array $requests = array();

	/** @var string */
	private string $notify_public_b64 = '';

	/** @var string|null api_base the fake /about returns; null means the requested host. */
	private ?string $api_base = null;

	public function set_up(): void {
		parent::set_up();
		delete_option( Connector_Settings::OPTION );
		delete_option( Connector_Secret::OPTION );
		delete_option( Connector_Keys::OPTION );
		$this->requests          = array();
		$this->api_base          = null;
		$this->notify_public_b64 = base64_encode( Ed25519::keypair()['public'] );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		( new Pairing_Module() )->register();
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_all_filters( 'learning_ledger/status' );
		parent::tear_down();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( str_ends_with( $url, '/about' ) ) {
			$p        = wp_parse_url( $url );
			$api_base = $this->api_base ?? $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . '/wp-json/learning-ledger/v1/';
			$body     = array(
				'version'    => array( '1.0.3', '2.0.0' ),
				'extensions' => array(
					self::EXT => array(
						'api_base'      => $api_base,
						'notifications' => array(
							'kid'        => 'notify-kid-1',
							'public_key' => $this->notify_public_b64,
						),
					),
				),
			);
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( $body ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		return new WP_Error( 'unexpected', 'Unexpected request to ' . $url );
	}

	private function connect(): void {
		Pairing_Command::pair( self::LRS, 'connector-site' );
	}

	public function test_connect_pins_notify_key_and_generates_secret_once() {
		$this->assertNull( Connector_Secret::get() );
		$this->connect();

		$this->assertSame( self::LRS . 'about', $this->requests[0]['url'] );
		$this->assertSame( 'GET', $this->requests[0]['args']['method'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->requests[0]['args']['headers'], 'about is fetched without credentials' );

		$s = Connector_Settings::get();
		$this->assertSame( self::LRS, $s['lrs_url'] );
		$this->assertSame( 'connector-site', $s['client_id'] );
		$this->assertSame( 'https://lrs.example.test/wp-json/learning-ledger/v1/', $s['api_base'] );
		$this->assertSame( 'notify-kid-1', $s['notify_kid'] );
		$this->assertSame( $this->notify_public_b64, $s['notify_public_key'] );
		$this->assertFalse( $s['allow_auto_enrol'] );
		$this->assertFalse( $s['match_mbox'] );
		$this->assertSame( 15, $s['poll_minutes'] );

		$secret = Connector_Secret::get();
		$this->assertIsString( $secret );
		$this->assertGreaterThanOrEqual( 43, strlen( $secret ), '32 random bytes, base64url' );
		$this->assertSame( 'option', Connector_Secret::source() );

		$this->connect();
		$this->assertSame( $secret, Connector_Secret::get(), 'a second connect keeps the secret' );

		Connector_Secret::generate();
		$this->assertSame( $secret, Connector_Secret::get(), 'generate() is a no-op when a secret exists' );

		$this->assertSame( 'off', $this->autoload_of( Connector_Settings::OPTION ) );
		$this->assertSame( 'off', $this->autoload_of( Connector_Secret::OPTION ) );
	}

	public function test_connect_rejects_about_without_notification_key() {
		$this->notify_public_b64 = 'not-a-key';
		try {
			$this->connect();
			$this->fail( 'connect accepted a bad notification key' );
		} catch ( \Exception $e ) {
			$this->assertStringNotContainsString( 'not-a-key', $e->getMessage() );
		}
		$this->assertSame( '', Connector_Settings::get()['lrs_url'] );
		$this->assertNull( Connector_Secret::get(), 'no secret is generated when pairing fails' );
	}

	public function test_secret_never_plaintext_in_options() {
		$this->connect();
		$secret = Connector_Secret::get();
		$stored = get_option( Connector_Secret::OPTION );

		$this->assertIsString( $stored );
		$this->assertNotSame( $secret, $stored );
		$this->assertStringNotContainsString( $secret, $stored );
		$this->assertSame( $secret, Secret_Box::open( $stored, 'connector-secret' ) );
		$this->assertSame( hash( 'sha256', $secret ), Connector_Secret::sha256_hex() );

		global $wpdb;
		$dump = (string) $wpdb->get_var( $wpdb->prepare( "SELECT GROUP_CONCAT(option_value SEPARATOR '\n') FROM {$wpdb->options} WHERE option_name LIKE %s", 'lledger%' ) );
		$this->assertStringNotContainsString( $secret, $dump );

		$this->assertSame( 'off', $this->autoload_of( Connector_Keys::OPTION ) );
		$seed = Secret_Box::open( (string) get_option( Connector_Keys::OPTION ), 'connector-signing' );
		$this->assertStringNotContainsString( $seed, $dump, 'signing key is sealed' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_secret_wins() {
		$this->connect();
		$this->assertSame( 'option', Connector_Secret::source() );

		define( 'LLEDGER_CONNECTOR_SECRET', 'operator-made-secret-0123456789abcdef0123456789' );
		$this->assertSame( 'operator-made-secret-0123456789abcdef0123456789', Connector_Secret::get() );
		$this->assertSame( 'constant', Connector_Secret::source() );
		$this->assertSame( hash( 'sha256', 'operator-made-secret-0123456789abcdef0123456789' ), Connector_Secret::sha256_hex() );

		delete_option( Connector_Secret::OPTION );
		Connector_Secret::generate();
		$this->assertFalse( get_option( Connector_Secret::OPTION ), 'no option secret is generated while the constant is set' );
	}

	public function test_status_hash_only_when_privileged() {
		$public = apply_filters( 'learning_ledger/status', array(), false );
		$this->assertFalse( $public['connector']['paired'] );

		$this->connect();
		$secret = Connector_Secret::get();

		$public = apply_filters( 'learning_ledger/status', array( 'connector' => array( 'lms' => 'kept' ) ), false );
		$c      = $public['connector'];
		$this->assertSame( 'kept', $c['lms'], 'merges into an existing connector section' );
		$this->assertTrue( $c['paired'] );
		$this->assertSame( self::LRS, $c['lrs_url'] );
		$this->assertSame( 'connector-site', $c['client_id'] );
		$this->assertSame( 'option', $c['secret_source'] );
		$this->assertSame( Connector_Keys::key_id(), $c['signing_key_id'] );
		$this->assertSame( Connector_Keys::public_b64(), $c['signing_public_key'] );
		$this->assertSame( 'notify-kid-1', $c['notify_kid'] );
		$this->assertArrayNotHasKey( 'secret_sha256', $c );
		$this->assertStringNotContainsString( $secret, wp_json_encode( $public ) );
		$this->assertStringNotContainsString( hash( 'sha256', $secret ), wp_json_encode( $public ) );

		$privileged = apply_filters( 'learning_ledger/status', array(), true );
		$this->assertSame( hash( 'sha256', $secret ), $privileged['connector']['secret_sha256'] );
		$this->assertStringNotContainsString( $secret, wp_json_encode( $privileged ) );
	}

	public function test_signing_key_is_stable_and_signs_path_and_query() {
		$id  = Connector_Keys::key_id();
		$pub = Connector_Keys::public_b64();
		$this->assertSame( $id, Connector_Keys::key_id() );
		$this->assertSame( Ed25519::key_id( base64_decode( $pub ) ), $id );

		$headers = Connector_Keys::sign( 'post', 'https://lrs.example.test/wp-json/x/y?a=1&b=2', '{"k":1}' );
		$this->assertSame( $id, $headers[ Signed_Request::HEADER_KEY ] );
		$verified = Signed_Request::verify(
			$headers,
			'POST',
			'/wp-json/x/y?a=1&b=2',
			'{"k":1}',
			static function ( $kid ) use ( $id, $pub ) {
				return $kid === $id ? base64_decode( $pub ) : null;
			},
			new Memory_Nonce_Store(),
			time()
		);
		$this->assertSame( $id, $verified );
	}

	public function test_connect_rejects_foreign_api_base() {
		foreach ( array( 'https://evil.example.test/wp-json/learning-ledger/v1/', 'http://lrs.example.test/wp-json/learning-ledger/v1/', 'https://lrs.example.test:8443/wp-json/learning-ledger/v1/' ) as $foreign ) {
			$this->api_base = $foreign;
			try {
				$this->connect();
				$this->fail( 'connect accepted api_base ' . $foreign );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'api_base', $e->getMessage() );
			}
		}
		$this->assertFalse( get_option( Connector_Settings::OPTION ), 'no settings written' );
		$this->assertFalse( get_option( Connector_Secret::OPTION ), 'no secret written' );
		$this->assertFalse( get_option( Connector_Keys::OPTION ), 'no signing key written' );

		$this->api_base = 'https://lrs.example.test:443/wp-json/learning-ledger/v1/';
		$this->connect();
		$this->assertSame( 'https://lrs.example.test:443/wp-json/learning-ledger/v1/', Connector_Settings::get()['api_base'], 'default port is the same origin' );
	}

	public function test_api_base_may_be_on_the_loopback_of_the_same_lrs() {
		$this->connect();
		Pairing_Command::apply_setting( 'loopback-url', 'http://127.0.0.1:8080/xapi' );
		$this->api_base = 'http://127.0.0.1:8080/wp-json/learning-ledger/v1/';
		$this->connect();
		$this->assertSame( 'http://127.0.0.1:8080/wp-json/learning-ledger/v1/', Connector_Settings::get()['api_base'] );
		$this->assertSame( 'http://127.0.0.1:8080/xapi/', Connector_Settings::get()['loopback_url'] );
	}

	public function test_repair_to_another_lrs_clears_loopback() {
		$this->connect();
		Pairing_Command::apply_setting( 'loopback-url', 'http://127.0.0.1:8080/xapi' );
		$this->connect();
		$this->assertSame( 'http://127.0.0.1:8080/xapi/', Connector_Settings::get()['loopback_url'], 'same LRS keeps its loopback' );

		Pairing_Command::pair( 'https://other-lrs.example.test/xapi/', 'connector-site' );
		$s = Connector_Settings::get();
		$this->assertSame( 'https://other-lrs.example.test/xapi/', $s['lrs_url'] );
		$this->assertSame( '', $s['loopback_url'] );
	}

	public function test_unreadable_secret_fails_loudly_until_regenerated() {
		$this->connect();
		$old_hash = Connector_Secret::sha256_hex();
		update_option( Connector_Secret::OPTION, base64_encode( random_bytes( 64 ) ), false );

		$this->assertNull( Connector_Secret::get() );
		$this->assertSame( 'unreadable', Connector_Secret::source() );
		$status = apply_filters( 'learning_ledger/status', array(), true );
		$this->assertSame( 'unreadable', $status['connector']['secret_source'] );
		$this->assertFalse( $status['connector']['paired'] );
		$this->assertNull( $status['connector']['secret_sha256'] );

		$garbage  = get_option( Connector_Secret::OPTION );
		$requests = count( $this->requests );
		try {
			$this->connect();
			$this->fail( 'connect replaced an unreadable secret without --regenerate-secret' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '--regenerate-secret', $e->getMessage() );
		}
		$this->assertSame( $garbage, get_option( Connector_Secret::OPTION ), 'the stored value is left alone' );
		$this->assertCount( $requests, $this->requests, 'fails before contacting the LRS' );

		try {
			Connector_Secret::generate();
			$this->fail( 'generate() replaced an unreadable secret' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( $garbage, get_option( Connector_Secret::OPTION ) );
		}

		Pairing_Command::pair( self::LRS, 'connector-site', true );
		$this->assertSame( 'option', Connector_Secret::source() );
		$this->assertNotNull( Connector_Secret::sha256_hex() );
		$this->assertNotSame( $old_hash, Connector_Secret::sha256_hex() );
	}

	public function test_plain_http_to_remote_host_is_detected() {
		$this->assertTrue( Pairing_Command::is_plain_http_to_remote( 'http://lrs.example.test/xapi/' ) );
		$this->assertFalse( Pairing_Command::is_plain_http_to_remote( 'https://lrs.example.test/xapi/' ) );
		$this->assertFalse( Pairing_Command::is_plain_http_to_remote( 'http://localhost:8888/xapi/' ) );
		$this->assertFalse( Pairing_Command::is_plain_http_to_remote( 'http://127.0.0.1/xapi/' ) );
		$this->assertFalse( Pairing_Command::is_plain_http_to_remote( 'http://[::1]/xapi/' ) );
	}

	public function test_set_changes_settings() {
		Pairing_Command::apply_setting( 'allow-auto-enrol', 'true' );
		Pairing_Command::apply_setting( 'match-mbox', 'yes' );
		Pairing_Command::apply_setting( 'poll-minutes', '30' );
		Pairing_Command::apply_setting( 'loopback-url', 'http://127.0.0.1:8080/xapi' );
		$s = Connector_Settings::get();
		$this->assertTrue( $s['allow_auto_enrol'] );
		$this->assertTrue( $s['match_mbox'] );
		$this->assertSame( 30, $s['poll_minutes'] );
		$this->assertSame( 'http://127.0.0.1:8080/xapi/', $s['loopback_url'] );

		Pairing_Command::apply_setting( 'match-mbox', 'false' );
		$this->assertFalse( Connector_Settings::get()['match_mbox'] );

		$this->expectException( \InvalidArgumentException::class );
		Pairing_Command::apply_setting( 'client-secret', 'x' );
	}

	private function autoload_of( string $name ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}
}
