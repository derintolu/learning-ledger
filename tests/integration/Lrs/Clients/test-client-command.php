<?php
/**
 * Client command tests (the logic behind `wp learning-ledger client ...`).
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Authenticator;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Clients\Client_Command;
use LearningLedger\Lrs\Clients\Client_Input;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Util\Invalid_Input;

class Client_Command_Test extends WP_UnitTestCase {

	const SECRET = 'a-secret-the-cli-never-sees';

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'audit' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function hash(): string {
		return hash( 'sha256', self::SECRET );
	}

	private function create( string $id, array $extra = array() ): void {
		Client_Command::create_client(
			array_merge(
				array(
					'id'            => $id,
					'secret-sha256' => $this->hash(),
					'scopes'        => 'all,void',
				),
				$extra
			)
		);
	}

	private function authenticate( string $id, string $secret ) {
		$request = new Xapi_Request( 'GET', 'statements', array(), array( 'Authorization' => 'Basic ' . base64_encode( $id . ':' . $secret ) ), '', '2.0.0', '203.0.113.9' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return ( new Authenticator() )->authenticate( $request, array() );
	}

	private function audit_rows( string $event ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event = %s', Tables::name( 'audit' ), $event ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_create_stores_the_hash_scopes_and_options() {
		$this->create(
			'conf.one',
			array(
				'name'                  => 'Conformance',
				'bind-actor-home-page'  => 'https://lms.example.test',
				'activity-prefix'       => 'https://a.example.test/,https://b.example.test/',
				'write-activity-prefix' => 'https://w.example.test/',
				'cors-origin'           => 'https://App.example.test:8443,null',
				'trusted'               => true,
				'rate-class'            => 'bulk',
				'guard'                 => false,
				'reject-non-account'    => true,
				'expires'               => '2031-01-02T03:04:05Z',
			)
		);
		$c = ( new Client_Repository() )->find( 'conf.one' );
		$this->assertTrue( $c->secret_matches( self::SECRET ) );
		$this->assertFalse( $c->secret_matches( 'other' ) );
		$this->assertSame( array( 'all', 'void' ), $c->scopes );
		$this->assertSame( 'Conformance', $c->name );
		$this->assertSame(
			array(
				'actor_home_page'         => 'https://lms.example.test',
				'activity_prefixes'       => array( 'https://a.example.test/', 'https://b.example.test/' ),
				'write_activity_prefixes' => array( 'https://w.example.test/' ),
			),
			$c->bindings->to_array()
		);
		$this->assertSame( array( 'https://app.example.test:8443', 'null' ), $c->cors_origins );
		$this->assertTrue( $c->trusted );
		$this->assertSame( 'bulk', $c->rate_class );
		$this->assertFalse( $c->guard_enabled );
		$this->assertTrue( $c->reject_non_account );
		$this->assertSame( '2031-01-02', $c->expires_at->format( 'Y-m-d' ) );
		$this->assertSame( 'conf.one', $this->authenticate( 'conf.one', self::SECRET )->client_id() );
	}

	public function test_rejects_bad_input() {
		$cases = array(
			'non-hex hash'       => array( array( 'secret-sha256' => str_repeat( 'z', 64 ) ), 'secret_sha256' ),
			'short hash'         => array( array( 'secret-sha256' => 'abcd' ), 'secret_sha256' ),
			'unknown scope'      => array( array( 'scopes' => 'all,nonsense' ), 'scopes' ),
			'tok_ id'            => array( array( 'id' => 'tok_x' ), 'id' ),
			'uppercase id'       => array( array( 'id' => 'Bad' ), 'id' ),
			'one character id'   => array( array( 'id' => 'a' ), 'id' ),
			'reserved id'        => array( array( 'id' => 'learning-ledger' ), 'id' ),
			'bad origin'         => array( array( 'cors-origin' => 'https://x.test/path' ), 'cors_origins' ),
			'bad prefix'         => array( array( 'activity-prefix' => 'not an iri' ), 'bindings.activity_prefixes' ),
			'unknown rate class' => array( array( 'rate-class' => 'warp' ), 'rate_class' ),
			'bad expiry'         => array( array( 'expires' => 'tomorrow' ), 'expires_at' ),
			'plaintext secret'   => array( array( 'secret' => 'hunter2' ), 'secret' ),
		);
		foreach ( $cases as $label => list( $override, $path ) ) {
			try {
				Client_Command::create_client(
					array_merge(
						array(
							'id'            => 'good-id',
							'secret-sha256' => $this->hash(),
							'scopes'        => 'all',
						),
						$override
					)
				);
				$this->fail( $label . ' was accepted' );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( $path, $e->path(), $label );
			}
		}
		$this->assertNull( ( new Client_Repository() )->find( 'good-id' ) );
		$this->assertSame( array(), $this->audit_rows( 'client.create' ) );
	}

	public function test_duplicate_id_is_rejected() {
		$this->create( 'dup' );
		$this->expectException( Invalid_Input::class );
		$this->create( 'dup' );
	}

	public function test_origin_literal_null_and_input_class() {
		$spec = Client_Input::from_array(
			array(
				'id'            => 'x1',
				'secret_sha256' => $this->hash(),
				'scopes'        => array( 'state' ),
				'cors_origins'  => array( 'null' ),
			)
		);
		$this->assertSame( array( 'null' ), $spec->cors_origins );
	}

	public function test_update_rotates_secret_and_merges_bindings() {
		$this->create( 'rot', array( 'bind-actor-home-page' => 'https://lms.example.test' ) );
		$new = hash( 'sha256', 'second-secret' );
		Client_Command::update_client(
			'rot',
			array(
				'secret-sha256'  => $new,
				'bind-actor-ifi' => 'mbox::mailto:a@example.test',
				'scopes'         => 'state',
			)
		);
		$c = ( new Client_Repository() )->find( 'rot' );
		$this->assertSame( array( 'state' ), $c->scopes );
		$this->assertSame( 'https://lms.example.test', $c->bindings->to_array()['actor_home_page'] );
		$this->assertSame( 'mbox::mailto:a@example.test', $c->bindings->to_array()['actor_ifi'] );
		$this->assertSame( 'rot', $this->authenticate( 'rot', 'second-secret' )->client_id() );
		$this->expectException( Xapi_Error::class );
		$this->authenticate( 'rot', self::SECRET );
	}

	public function test_update_clears_a_binding_and_rejects_empty_or_unknown() {
		$this->create( 'clr', array( 'bind-actor-home-page' => 'https://lms.example.test' ) );
		Client_Command::update_client( 'clr', array( 'bind-actor-home-page' => '' ) );
		$this->assertSame( array(), ( new Client_Repository() )->find( 'clr' )->bindings->to_array() );

		foreach ( array( array(), array( 'nope' => '1' ) ) as $flags ) {
			try {
				Client_Command::update_client( 'clr', $flags );
				$this->fail( 'accepted' );
			} catch ( Invalid_Input $e ) {
				$this->assertNotSame( '', $e->error_code() );
			}
		}
		try {
			Client_Command::update_client( 'missing', array( 'name' => 'x' ) );
			$this->fail( 'accepted' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'not_found', $e->error_code() );
		}
	}

	public function test_revoke_stops_authentication() {
		$this->create( 'rev' );
		$this->assertSame( 'rev', $this->authenticate( 'rev', self::SECRET )->client_id() );
		\LearningLedger\Lrs\Clients\Clients_Module::revoke( 'rev', 'cli' );
		try {
			$this->authenticate( 'rev', self::SECRET );
			$this->fail( 'revoked client authenticated' );
		} catch ( Xapi_Error $e ) {
			$this->assertSame( 401, $e->status() );
		}
	}

	public function test_list_never_contains_a_hash() {
		$this->create( 'lst1' );
		$this->create( 'lst2', array( 'name' => 'Second' ) );
		\LearningLedger\Lrs\Clients\Clients_Module::revoke( 'lst2', 'cli' );
		$rows = Client_Command::rows();
		$json = wp_json_encode( $rows );
		$this->assertSame( 0, preg_match( '/[0-9a-f]{64}/i', $json ) );
		$this->assertStringNotContainsString( 'secret', strtolower( $json ) );
		$by_id = array_column( $rows, null, 'id' );
		$this->assertSame( 'active', $by_id['lst1']['status'] );
		$this->assertSame( 'revoked', $by_id['lst2']['status'] );
		$this->assertSame( 'all,void', $by_id['lst1']['scopes'] );
	}

	public function test_audit_rows_carry_no_hash() {
		$this->create( 'aud' );
		Client_Command::update_client(
			'aud',
			array(
				'secret-sha256' => hash( 'sha256', 'other' ),
				'name'          => 'N',
			)
		);
		\LearningLedger\Lrs\Clients\Clients_Module::revoke( 'aud', 'cli' );
		foreach ( array( 'client.create', 'client.update', 'client.revoke' ) as $event ) {
			$rows = $this->audit_rows( $event );
			$this->assertCount( 1, $rows, $event );
			$this->assertSame( 'cli', $rows[0]['actor'] );
			$this->assertSame( 'aud', json_decode( $rows[0]['data'], true )['client_id'] );
			$this->assertSame( 0, preg_match( '/[0-9a-f]{64}/i', $rows[0]['data'] ), $event );
		}
		$this->assertEqualsCanonicalizing( array( 'secret', 'name' ), json_decode( $this->audit_rows( 'client.update' )[0]['data'], true )['fields'] );
	}
}
