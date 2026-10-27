<?php
/**
 * Document_Store tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Documents\Doc_Key;
use LearningLedger\Lrs\Documents\Document_Store;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Util\Clock;
use LearningLedger\Xapi\Agent_Identity;

class Document_Store_Test extends WP_UnitTestCase {

	const ACT  = 'https://example.com/activities/a1';
	const REG  = '6f1c3c1e-9a5e-4c1b-8d4e-2b7f0c9a1d11';
	const REG2 = '0b6c7a52-5d0e-4c3a-9f1e-6a2b3c4d5e6f';

	private Document_Store $store;

	public function set_up() {
		parent::set_up();
		Installer::install();
		$this->store = new Document_Store();
		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:00:00.000000', new DateTimeZone( 'UTC' ) ) );
	}

	public function tear_down() {
		Clock::freeze( null );
		delete_option( 'lledger_document_max_bytes' );
		parent::tear_down();
	}

	private static function agent( string $name = 'learner-1' ): Agent_Identity {
		return Agent_Identity::from_array(
			array(
				'objectType' => 'Agent',
				'account'    => array(
					'homePage' => 'https://lms.example',
					'name'     => $name,
				),
			)
		);
	}

	private static function state( ?string $registration, ?string $id = 'progress', ?Agent_Identity $agent = null ): Doc_Key {
		return new Doc_Key( 'state', self::ACT, $agent ?? self::agent(), $registration, $id );
	}

	private static function rows(): array {
		global $wpdb;
		$table = Tables::name( 'documents' );
		return $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	private function expect_status( int $status, callable $action ): Xapi_Error {
		try {
			$action();
		} catch ( Xapi_Error $e ) {
			$this->assertSame( $status, $e->status() );
			return $e;
		}
		$this->fail( "Expected Xapi_Error $status" );
	}

	public function test_state_without_registration_is_single_row() {
		$this->store->put( self::state( null ), '{"v":1}', 'application/json', null, null );
		$this->store->put( self::state( null ), '{"v":2}', 'application/json', null, null );

		$rows = self::rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( '{"v":2}', $rows[0]['content'] );
		$this->assertSame( str_repeat( "\0", 16 ), $rows[0]['registration'] );
		$this->assertSame( '{"v":2}', $this->store->get( self::state( null ) )->content );

		// Agent and activity profiles: absent parts are zero bytes too, so repeated writes stay one row each.
		$agent_profile    = new Doc_Key( 'agent_profile', null, self::agent(), null, 'prefs' );
		$activity_profile = new Doc_Key( 'activity_profile', self::ACT, null, null, 'meta' );
		foreach ( array( 'a', 'b' ) as $v ) {
			$this->store->put( $agent_profile, $v, 'text/plain', null, null );
			$this->store->put( $activity_profile, $v, 'text/plain', null, null );
		}
		$rows = self::rows();
		$this->assertCount( 3, $rows );
		foreach ( $rows as $row ) {
			if ( 'agent_profile' === $row['kind'] ) {
				$this->assertSame( str_repeat( "\0", 20 ), $row['activity_hash'] );
				$this->assertSame( 'b', $row['content'] );
			}
			if ( 'activity_profile' === $row['kind'] ) {
				$this->assertSame( str_repeat( "\0", 20 ), $row['agent_hash'] );
				$this->assertSame( 'b', $row['content'] );
			}
		}

		// A registration makes a different document.
		$this->store->put( self::state( self::REG ), '{"v":3}', 'application/json', null, null );
		$this->assertCount( 4, self::rows() );
		$this->assertSame( '{"v":2}', $this->store->get( self::state( null ) )->content );
	}

	public function test_etag_if_match_412_and_if_none_match_star() {
		$k   = self::state( self::REG );
		$doc = $this->store->put( $k, 'one', 'text/plain', null, '*' );
		$this->assertSame( sha1( 'one' ), $doc->etag );
		$this->assertSame( sha1( 'one' ), $this->store->get( $k )->etag );

		// If-None-Match: * on an existing document.
		$this->expect_status( 412, fn() => $this->store->put( $k, 'two', 'text/plain', null, '*' ) );
		// If-Match with a wrong ETag.
		$this->expect_status( 412, fn() => $this->store->put( $k, 'two', 'text/plain', '"' . sha1( 'nope' ) . '"', null ) );
		// If-Match on a document that does not exist.
		$this->expect_status( 412, fn() => $this->store->put( self::state( self::REG2 ), 'x', 'text/plain', '*', null ) );
		$this->assertNull( $this->store->get( self::state( self::REG2 ) ) );
		$this->assertSame( 'one', $this->store->get( $k )->content );

		// If-Match with the right ETag, quoted, and with `*`.
		$this->store->put( $k, 'two', 'text/plain', '"' . sha1( 'one' ) . '"', null );
		$this->assertSame( 'two', $this->store->get( $k )->content );
		$this->store->put( $k, 'three', 'text/plain', '*', null );
		$this->assertSame( 'three', $this->store->get( $k )->content );

		// If-None-Match with the current ETag fails; with another one it succeeds.
		$this->expect_status( 412, fn() => $this->store->put( $k, 'four', 'text/plain', null, '"' . sha1( 'three' ) . '"' ) );
		$this->store->put( $k, 'four', 'text/plain', null, '"' . sha1( 'other' ) . '"' );
		$this->assertSame( 'four', $this->store->get( $k )->content );

		// DELETE with a wrong ETag keeps the document; with the right one removes it.
		$this->expect_status( 412, fn() => $this->store->delete( $k, '"' . sha1( 'three' ) . '"' ) );
		$this->assertNotNull( $this->store->get( $k ) );
		$this->store->delete( $k, '"' . sha1( 'four' ) . '"' );
		$this->assertNull( $this->store->get( $k ) );
	}

	public function test_concurrent_writers_one_wins() {
		$k   = self::state( self::REG );
		$old = $this->store->put( $k, '{"n":0}', 'application/json', null, null )->etag;

		// Both writers read the same ETag; the first wins, the second gets 412 and changes nothing.
		$this->store->put( $k, '{"n":1}', 'application/json', '"' . $old . '"', null );
		$this->expect_status( 412, fn() => $this->store->put( $k, '{"n":2}', 'application/json', '"' . $old . '"', null ) );
		$this->expect_status( 412, fn() => $this->store->post_merge( $k, '{"m":2}', 'application/json', '"' . $old . '"', null ) );

		$doc = $this->store->get( $k );
		$this->assertSame( '{"n":1}', $doc->content );
		$this->assertSame( sha1( '{"n":1}' ), $doc->etag );
		$this->assertCount( 1, self::rows() );

		// Writing the same content again under its own ETag succeeds (no row changes, still a match).
		$this->store->put( $k, '{"n":1}', 'application/json', '"' . sha1( '{"n":1}' ) . '"', null );
		$this->assertSame( '{"n":1}', $this->store->get( $k )->content );
	}

	public function test_post_merges_json_objects_only() {
		$k = self::state( self::REG );

		// POST to a missing document stores it as sent.
		$this->store->post_merge( $k, '{"a":1,"keep":{},"list":[]}', 'application/json', null, null );
		$this->assertSame( '{"a":1,"keep":{},"list":[]}', $this->store->get( $k )->content );

		// Top-level merge: new keys added, existing keys replaced whole, empty objects stay objects.
		$doc = $this->store->post_merge( $k, '{"a":{"x":1},"b":{}}', 'application/json; charset=utf-8', null, null );
		$this->assertSame( '{"a":{"x":1},"keep":{},"list":[],"b":{}}', $doc->content );
		$this->assertSame( sha1( $doc->content ), $doc->etag );
		$this->assertSame( 'application/json', $doc->content_type );

		// The new document must be a JSON object.
		$this->expect_status( 400, fn() => $this->store->post_merge( $k, '[1,2]', 'application/json', null, null ) );
		$this->expect_status( 400, fn() => $this->store->post_merge( $k, '{"a":', 'application/json', null, null ) );
		$this->expect_status( 400, fn() => $this->store->post_merge( $k, 'abc', 'text/plain', null, null ) );
		$this->assertSame( '{"a":{"x":1},"keep":{},"list":[],"b":{}}', $this->store->get( $k )->content );

		// The stored document must be a JSON object.
		$bin = self::state( self::REG2 );
		$this->store->put( $bin, 'not json', 'application/octet-stream', null, null );
		$this->expect_status( 400, fn() => $this->store->post_merge( $bin, '{"a":1}', 'application/json', null, null ) );
		$this->assertSame( 'not json', $this->store->get( $bin )->content );

		$arr = self::state( null, 'arr' );
		$this->store->put( $arr, '[1]', 'application/json', null, null );
		$this->expect_status( 400, fn() => $this->store->post_merge( $arr, '{"a":1}', 'application/json', null, null ) );

		// Preconditions apply to POST too.
		$this->expect_status( 412, fn() => $this->store->post_merge( $k, '{"c":1}', 'application/json', null, '*' ) );
		$this->store->post_merge( $k, '{"c":1}', 'application/json', '"' . $doc->etag . '"', null );
		$this->assertSame( '{"a":{"x":1},"keep":{},"list":[],"b":{},"c":1}', $this->store->get( $k )->content );
	}

	public function test_list_ids_since() {
		$t0 = new DateTimeImmutable( '2026-10-27 10:00:00.000000', new DateTimeZone( 'UTC' ) );
		Clock::freeze( $t0 );
		$this->store->put( self::state( self::REG, 'old' ), 'a', 'text/plain', null, null );
		Clock::freeze( $t0->modify( '+1 second' ) );
		$this->store->put( self::state( self::REG, 'new' ), 'b', 'text/plain', null, null );
		$this->store->put( self::state( self::REG2, 'other-reg' ), 'c', 'text/plain', null, null );
		$this->store->put( self::state( null, 'no-reg' ), 'd', 'text/plain', null, null );
		$this->store->put( self::state( self::REG, 'other-agent', self::agent( 'learner-2' ) ), 'e', 'text/plain', null, null );

		$ids = $this->store->list_ids( self::state( self::REG, null ), null );
		sort( $ids );
		$this->assertSame( array( 'new', 'old' ), $ids );
		$this->assertSame( array( 'new' ), $this->store->list_ids( self::state( self::REG, null ), $t0->modify( '+500 milliseconds' ) ) );
		$this->assertSame( array(), $this->store->list_ids( self::state( self::REG, null ), $t0->modify( '+1 second' ) ) );

		// Without a registration, the list covers every registration for the activity and agent.
		$ids = $this->store->list_ids( self::state( null, null ), null );
		sort( $ids );
		$this->assertSame( array( 'new', 'no-reg', 'old', 'other-reg' ), $ids );

		// Profiles list by their own key.
		$this->store->put( new Doc_Key( 'agent_profile', null, self::agent(), null, 'p1' ), 'x', 'text/plain', null, null );
		$this->store->put( new Doc_Key( 'activity_profile', self::ACT, null, null, 'p2' ), 'x', 'text/plain', null, null );
		$this->assertSame( array( 'p1' ), $this->store->list_ids( new Doc_Key( 'agent_profile', null, self::agent(), null, null ), null ) );
		$this->assertSame( array( 'p2' ), $this->store->list_ids( new Doc_Key( 'activity_profile', self::ACT, null, null, null ), null ) );
	}

	public function test_delete_all_state_for_registration() {
		$this->store->put( self::state( self::REG, 's1' ), 'a', 'text/plain', null, null );
		$this->store->put( self::state( self::REG, 's2' ), 'b', 'text/plain', null, null );
		$this->store->put( self::state( self::REG2, 's1' ), 'c', 'text/plain', null, null );
		$this->store->put( self::state( null, 's1' ), 'd', 'text/plain', null, null );
		$this->store->put( new Doc_Key( 'agent_profile', null, self::agent(), null, 's1' ), 'e', 'text/plain', null, null );

		$this->assertSame( 2, $this->store->delete_all( self::state( self::REG, null ) ) );
		$this->assertNull( $this->store->get( self::state( self::REG, 's1' ) ) );
		$this->assertNull( $this->store->get( self::state( self::REG, 's2' ) ) );
		$this->assertSame( 'c', $this->store->get( self::state( self::REG2, 's1' ) )->content );
		$this->assertSame( 'd', $this->store->get( self::state( null, 's1' ) )->content );

		// Without a registration: every remaining state for the activity and agent, nothing else.
		$this->assertSame( 2, $this->store->delete_all( self::state( null, null ) ) );
		$this->assertCount( 1, self::rows() );
		$this->assertSame( 'agent_profile', self::rows()[0]['kind'] );

		$this->expect_status( 400, fn() => $this->store->delete_all( new Doc_Key( 'agent_profile', null, self::agent(), null, null ) ) );
	}

	public function test_over_2mb_413() {
		$k = self::state( self::REG );
		$this->expect_status( 413, fn() => $this->store->put( $k, str_repeat( 'a', 2097153 ), 'text/plain', null, null ) );
		$this->assertNull( $this->store->get( $k ) );
		$this->store->put( $k, str_repeat( 'a', 2097152 ), 'text/plain', null, null );
		$this->assertSame( 2097152, strlen( $this->store->get( $k )->content ) );

		update_option( 'lledger_document_max_bytes', 10 );
		$this->expect_status( 413, fn() => $this->store->put( $k, '01234567890', 'text/plain', '*', null ) );
		$this->store->delete( $k, null );
		$this->store->post_merge( $k, '{"a":"12"}', 'application/json', null, null );
		// The merged result counts too.
		$this->expect_status( 413, fn() => $this->store->post_merge( $k, '{"b":1}', 'application/json', null, null ) );
		$this->assertSame( '{"a":"12"}', $this->store->get( $k )->content );
	}

	public function test_binary_content_round_trip_with_content_type() {
		$bytes = '';
		for ( $i = 0; $i < 256; $i++ ) {
			$bytes .= chr( $i );
		}
		$k = self::state( self::REG, 'blob' );
		$this->store->put( $k, $bytes, 'image/png', null, null );

		$doc = $this->store->get( $k );
		$this->assertSame( $bytes, $doc->content );
		$this->assertSame( 'image/png', $doc->content_type );
		$this->assertSame( sha1( $bytes ), $doc->etag );
		$this->assertSame( '2026-10-27 10:00:00.000000', $doc->updated->format( 'Y-m-d H:i:s.u' ) );
	}

	public function test_delete_for_agent_removes_state_and_agent_profile_only() {
		$this->store->put( self::state( self::REG ), 'a', 'text/plain', null, null );
		$this->store->put( new Doc_Key( 'agent_profile', null, self::agent(), null, 'p' ), 'b', 'text/plain', null, null );
		$this->store->put( self::state( self::REG, 'progress', self::agent( 'learner-2' ) ), 'c', 'text/plain', null, null );
		$this->store->put( new Doc_Key( 'activity_profile', self::ACT, null, null, 'p' ), 'd', 'text/plain', null, null );

		$this->assertSame( 2, $this->store->delete_for_agent( self::agent() ) );
		$this->assertCount( 2, self::rows() );
		$this->assertSame( 'c', $this->store->get( self::state( self::REG, 'progress', self::agent( 'learner-2' ) ) )->content );
	}

	public function test_doc_key_rejects_bad_parts() {
		$this->expectException( \LearningLedger\Util\Invalid_Input::class );
		new Doc_Key( 'state', self::ACT, self::agent(), 'not-a-uuid', 'x' );
	}
}
