<?php
/**
 * Bitstring Status List repository and route tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Badges\Signing\Eddsa_Rdfc_2022;
use LearningLedger\Badges\Status\Bitstring;
use LearningLedger\Badges\Status\Status_List_Module;
use LearningLedger\Badges\Status\Status_List_Repository;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Invalid_Input;

class Status_List_Repository_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Tables::name( 'keys' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . Tables::name( 'status_lists' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lledger\\_status\\_list\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		wp_cache_flush();
		Key_Store::generate();
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	private static function verifies( array $credential ): bool {
		return Eddsa_Rdfc_2022::verify(
			$credential,
			static function ( string $vm ): ?string {
				foreach ( Key_Store::published() as $key ) {
					if ( Did_Document::verification_method_for( $key ) === $vm ) {
						return $key->public;
					}
				}
				return null;
			}
		);
	}

	private static function list_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'status_lists' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_allocate_returns_index_in_a_full_size_list() {
		$repo = new Status_List_Repository();
		$a    = $repo->allocate();
		$this->assertSame( array( 'list_id', 'index' ), array_keys( $a ) );
		$this->assertIsInt( $a['list_id'] );
		$this->assertGreaterThanOrEqual( 0, $a['index'] );
		$this->assertLessThan( Bitstring::SIZE, $a['index'] );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT purpose, size, used, LENGTH(bits) AS len FROM ' . Tables::name( 'status_lists' ) . ' WHERE id = %d', $a['list_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame(
			array(
				'purpose' => 'revocation',
				'size'    => '131072',
				'used'    => '1',
				'len'     => '16384',
			),
			$row
		);
	}

	public function test_allocate_never_repeats_an_index() {
		$repo = new Status_List_Repository();
		$seen = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$a   = $repo->allocate();
			$key = $a['list_id'] . ':' . $a['index'];
			$this->assertArrayNotHasKey( $key, $seen );
			$seen[ $key ] = true;
		}
		$this->assertSame( 1, self::list_count() );
	}

	public function test_allocate_never_repeats_an_index_across_small_lists() {
		$small = new Status_List_Repository( 64 );
		$by    = array();
		for ( $i = 0; $i < 120; $i++ ) {
			$a = $small->allocate();
			$this->assertGreaterThanOrEqual( 0, $a['index'] );
			$this->assertLessThan( 64, $a['index'] );
			$by[ $a['list_id'] ][] = $a['index'];
		}
		$this->assertSame( array( 51, 51, 18 ), array_map( 'count', array_values( $by ) ) );
		foreach ( $by as $indexes ) {
			$this->assertSame( count( $indexes ), count( array_unique( $indexes ) ) );
		}
	}

	public function test_indexes_are_not_sequential() {
		$repo    = new Status_List_Repository( 1024 );
		$indexes = array();
		for ( $i = 0; $i < 32; $i++ ) {
			$indexes[] = $repo->allocate()['index'];
		}
		$this->assertNotSame( range( 0, 31 ), $indexes );
		$sorted = $indexes;
		sort( $sorted );
		$this->assertNotSame( $sorted, $indexes );
	}

	public function test_new_list_starts_at_80_percent() {
		$repo  = new Status_List_Repository( 40 );
		$lists = array();
		for ( $i = 0; $i < 32; $i++ ) {
			$lists[] = $repo->allocate()['list_id'];
		}
		$this->assertCount( 1, array_unique( $lists ) );
		$next = $repo->allocate();
		$this->assertNotSame( $lists[0], $next['list_id'] );
		$this->assertSame( 2, self::list_count() );
		global $wpdb;
		$used = $wpdb->get_col( 'SELECT used FROM ' . Tables::name( 'status_lists' ) . ' ORDER BY id' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( array( '32', '1' ), $used );
	}

	public function test_credential_shape_and_signature() {
		$repo = new Status_List_Repository();
		$a    = $repo->allocate();
		$url  = rest_url( 'learning-ledger/v1/status-lists/' . $a['list_id'] );
		$cred = $repo->credential( $a['list_id'] );

		$this->assertSame( array( 'https://www.w3.org/ns/credentials/v2' ), $cred['@context'] );
		$this->assertSame( $url, $cred['id'] );
		$this->assertSame( array( 'VerifiableCredential', 'BitstringStatusListCredential' ), $cred['type'] );
		$this->assertSame( Did_Document::did(), $cred['issuer'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $cred['validFrom'] );
		$this->assertSame(
			array(
				'id'            => $url . '#list',
				'type'          => 'BitstringStatusList',
				'statusPurpose' => 'revocation',
				'encodedList'   => Bitstring::encode( str_repeat( "\0", Bitstring::SIZE / 8 ) ),
				'ttl'           => 300000,
			),
			$cred['credentialSubject']
		);
		$this->assertSame( Did_Document::verification_method_for( Key_Store::active() ), $cred['proof']['verificationMethod'] );
		$this->assertTrue( self::verifies( $cred ) );
	}

	public function test_entry_shape() {
		$repo = new Status_List_Repository();
		$a    = $repo->allocate();
		$url  = rest_url( 'learning-ledger/v1/status-lists/' . $a['list_id'] );
		$this->assertSame(
			array(
				'id'                   => $url . '#' . $a['index'],
				'type'                 => 'BitstringStatusListEntry',
				'statusPurpose'        => 'revocation',
				'statusListIndex'      => (string) $a['index'],
				'statusListCredential' => $url,
			),
			$repo->entry( $a['list_id'], $a['index'] )
		);
	}

	public function test_set_revoked_changes_encoded_list_and_resigns() {
		$repo   = new Status_List_Repository();
		$a      = $repo->allocate();
		$other  = $repo->allocate();
		$before = $repo->credential( $a['list_id'] );

		$repo->set_revoked( $a['list_id'], $a['index'] );
		$after = $repo->credential( $a['list_id'] );

		$this->assertNotSame( $before['credentialSubject']['encodedList'], $after['credentialSubject']['encodedList'] );
		$bits = Bitstring::decode( $after['credentialSubject']['encodedList'] );
		$this->assertTrue( Bitstring::get( $bits, $a['index'] ) );
		$this->assertFalse( Bitstring::get( $bits, $other['index'] ) );
		$this->assertTrue( self::verifies( $after ) );

		$tampered                                     = $after;
		$tampered['credentialSubject']['encodedList'] = $before['credentialSubject']['encodedList'];
		$this->assertFalse( self::verifies( $tampered ) );

		$repo->set_revoked( $a['list_id'], $other['index'] );
		$bits = Bitstring::decode( $repo->credential( $a['list_id'] )['credentialSubject']['encodedList'] );
		$this->assertTrue( Bitstring::get( $bits, $a['index'] ) );
		$this->assertTrue( Bitstring::get( $bits, $other['index'] ) );
	}

	public function test_unknown_list_and_out_of_range_index_are_rejected() {
		$repo = new Status_List_Repository();
		$a    = $repo->allocate();
		foreach (
			array(
				static fn() => $repo->credential( $a['list_id'] + 1000 ),
				static fn() => $repo->set_revoked( $a['list_id'] + 1000, 0 ),
				static fn() => $repo->set_revoked( $a['list_id'], Bitstring::SIZE ),
				static fn() => $repo->set_revoked( $a['list_id'], -1 ),
			) as $call
		) {
			try {
				$call();
				$this->fail( 'Expected Invalid_Input' );
			} catch ( Invalid_Input $e ) {
				$this->assertContains( $e->error_code(), array( 'not_found', 'invalid' ) );
			}
		}
	}

	private function cors_priority(): int {
		global $wp_filter;
		foreach ( $wp_filter['rest_pre_serve_request']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof \LearningLedger\Badges\Status\Status_List_Controller ) {
					return (int) $priority;
				}
			}
		}
		return -1;
	}

	public function test_route_is_public_cacheable_and_cors_open() {
		$repo = new Status_List_Repository();
		$a    = $repo->allocate();
		( new Status_List_Module() )->register();
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/learning-ledger/v1/status-lists/' . $a['list_id'] ) );
		$this->assertSame( 200, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertSame( 'public, max-age=60', $headers['Cache-Control'] );
		$this->assertSame( '*', $headers['Access-Control-Allow-Origin'] );
		$this->assertSame( $repo->credential( $a['list_id'] ), $response->get_data() );
		$this->assertTrue( self::verifies( $response->get_data() ) );

		$this->assertGreaterThan( has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ), $this->cors_priority() );

		$missing = rest_do_request( new WP_REST_Request( 'GET', '/learning-ledger/v1/status-lists/' . ( $a['list_id'] + 1000 ) ) );
		$this->assertSame( 404, $missing->get_status() );
	}
}
