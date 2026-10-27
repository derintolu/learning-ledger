<?php
/**
 * did:web document, issuer profile and route tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Issuer_Module;
use LearningLedger\Badges\Issuer\Issuer_Profile;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Crypto\Multibase;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;

class Did_Document_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'keys' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( 'lledger_did_mode' );
		delete_option( 'lledger_issuer_name' );
	}

	/**
	 * WP_HOME is defined in wp-env, so the home option is overridden before it is read.
	 */
	private function set_home( string $url ): void {
		remove_all_filters( 'pre_option_home' );
		add_filter( 'pre_option_home', static fn() => $url );
	}

	/**
	 * Run maybe_serve_did() and return its output; it must finish the request.
	 */
	private function serve( Issuer_Module $module ): string {
		ob_start();
		try {
			$module->maybe_serve_did();
			$this->fail( 'Expected the response to finish' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'finished', $e->getMessage() );
		} finally {
			$body = ob_get_clean();
		}
		return $body;
	}

	/**
	 * Module that records headers and returns instead of exiting.
	 */
	private function recording_module(): Issuer_Module {
		return new class() extends Issuer_Module {
			public array $sent = array();

			public int $status = 0;

			protected function send_headers( int $status, array $headers ): void {
				$this->status = $status;
				$this->sent   = $headers;
			}

			protected function finish(): void {
				throw new RuntimeException( 'finished' );
			}
		};
	}

	public function test_did_host_and_path_modes() {
		$this->set_home( 'https://lrs.sqwrlz.com' );
		$this->assertSame( 'did:web:lrs.sqwrlz.com', Did_Document::did() );
		update_option( 'lledger_did_mode', 'path' );
		$this->assertSame( 'did:web:lrs.sqwrlz.com:issuer', Did_Document::did() );
		update_option( 'lledger_did_mode', 'host' );
		$this->set_home( 'http://localhost:8888' );
		$this->assertSame( 'did:web:localhost%3A8888', Did_Document::did() );
		update_option( 'lledger_did_mode', 'path' );
		$this->assertSame( 'did:web:localhost%3A8888:issuer', Did_Document::did() );
	}

	public function test_document_lists_published_keys_as_multikeys() {
		$this->set_home( 'https://lrs.sqwrlz.com' );
		$key = Key_Store::generate();
		$did = 'did:web:lrs.sqwrlz.com';
		$id  = $did . '#' . $key->kid;
		$this->assertSame( $id, Did_Document::verification_method_for( $key ) );
		$this->assertSame(
			array(
				'@context'           => array( 'https://www.w3.org/ns/did/v1', 'https://w3id.org/security/multikey/v1' ),
				'id'                 => $did,
				'verificationMethod' => array(
					array(
						'id'                 => $id,
						'type'               => 'Multikey',
						'controller'         => $did,
						'publicKeyMultibase' => Multibase::ed25519_multikey( $key->public ),
					),
				),
				'assertionMethod'    => array( $id ),
			),
			Did_Document::document()
		);
		$this->assertStringNotContainsString( 'secret', wp_json_encode( Did_Document::document() ) );
	}

	public function test_verification_methods_filter_adds_to_assertion_method() {
		$key   = Key_Store::generate();
		$extra = array(
			'id'           => Did_Document::did() . '#rsa1',
			'type'         => 'JsonWebKey',
			'controller'   => Did_Document::did(),
			'publicKeyJwk' => array( 'kty' => 'RSA' ),
		);
		$seen  = null;
		$cb    = static function ( $methods ) use ( $extra, &$seen ) {
			$seen      = $methods;
			$methods[] = $extra;
			return $methods;
		};
		add_filter( 'learning_ledger/did_verification_methods', $cb );
		$doc = Did_Document::document();
		remove_filter( 'learning_ledger/did_verification_methods', $cb );

		$this->assertCount( 1, $seen );
		$this->assertSame( $extra, $doc['verificationMethod'][1] );
		$this->assertSame( array( Did_Document::verification_method_for( $key ), $extra['id'] ), $doc['assertionMethod'] );
	}

	public function test_did_json_route_serves_document() {
		Key_Store::generate();
		$module = $this->recording_module();
		$module->register();
		$module->add_rewrites();
		$this->set_permalink_structure( '/%postname%/' );

		foreach ( array( '/.well-known/did.json', '/issuer/did.json' ) as $path ) {
			$this->go_to( home_url( $path ) );
			$this->assertNotSame( '', (string) get_query_var( Issuer_Module::QUERY_VAR ), $path );
			$module->sent = array();
			$body         = $this->serve( $module );
			$this->assertSame( 200, $module->status );
			$this->assertSame( Did_Document::document(), json_decode( $body, true ), $path );
			$this->assertSame(
				array(
					'Content-Type'                => 'application/json',
					'Cache-Control'               => 'public, max-age=300',
					'Access-Control-Allow-Origin' => '*',
				),
				$module->sent
			);
		}

		$this->go_to( home_url( '/' ) );
		$this->assertSame( '', (string) get_query_var( Issuer_Module::QUERY_VAR ) );
		ob_start();
		$module->maybe_serve_did();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_did_json_unencodable_document_is_500() {
		Key_Store::generate();
		$module = $this->recording_module();
		$module->register();
		$module->add_rewrites();
		$this->set_permalink_structure( '/%postname%/' );
		$this->go_to( home_url( '/.well-known/did.json' ) );
		$cb = static function ( $methods ) {
			$methods[] = array(
				'id'  => 'did:web:example.org#bad',
				'nan' => NAN,
			);
			return $methods;
		};
		add_filter( 'learning_ledger/did_verification_methods', $cb );
		$body = $this->serve( $module );
		remove_filter( 'learning_ledger/did_verification_methods', $cb );
		$this->assertSame( 500, $module->status );
		$this->assertSame( '', $body );
		$this->assertSame( array( 'Cache-Control' => 'no-store' ), $module->sent );
	}

	public function test_issuer_profile_route() {
		update_option( 'lledger_issuer_name', 'Learn WordPress, reimagined' );
		$this->assertSame(
			array(
				'@context' => array( 'https://www.w3.org/ns/credentials/v2', 'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json' ),
				'id'       => Did_Document::did(),
				'type'     => array( 'Profile' ),
				'name'     => 'Learn WordPress, reimagined',
			),
			Issuer_Profile::document()
		);

		( new Issuer_Module() )->register();
		do_action( 'rest_api_init' );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/learning-ledger/v1/issuer' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Issuer_Profile::document(), $response->get_data() );
	}

	public function test_about_and_status_report_issuer() {
		( new Issuer_Module() )->register();
		$ext = apply_filters( 'learning_ledger/about_extension', array() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertSame(
			array(
				'did'            => Did_Document::did(),
				'issuer_profile' => rest_url( 'learning-ledger/v1/issuer' ),
				'key_tier'       => 'database',
			),
			$ext['badges']
		);
		$status = apply_filters( 'learning_ledger/status', array( 'plugin_version' => '0.1.0' ), false ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertSame( 'database', $status['badge_key_tier'] );
		$this->assertSame( '0.1.0', $status['plugin_version'] );
	}
}
