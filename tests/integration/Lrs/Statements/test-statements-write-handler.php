<?php
/**
 * Statements write handler tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Http\Xapi_Response;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Stored_Statement;
use LearningLedger\Lrs\Statements\Statements_Write_Handler;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

class Statements_Write_Handler_Test extends WP_UnitTestCase {

	private Principal $p;

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$c       = Client_Factory::create( array( 'statements/write' ) );
		$this->p = Principal::for_client( ( new Client_Repository() )->find( $c['id'] ) );
	}

	public function tear_down() {
		Clock::freeze( null );
		remove_all_filters( 'query' );
		remove_all_actions( 'learning_ledger/statements_stored' );
		remove_all_actions( 'learning_ledger/statements_committed' );
		parent::tear_down();
	}

	private static function statement( string $activity = 'https://site.example/act/1' ): array {
		return array(
			'actor'  => array(
				'objectType' => 'Agent',
				'account'    => array(
					'homePage' => 'https://site.example',
					'name'     => 'u1',
				),
			),
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object' => array(
				'objectType' => 'Activity',
				'id'         => $activity,
			),
		);
	}

	private function send( string $method, array $query, string $body, array $headers = array( 'Content-Type' => 'application/json' ) ): Xapi_Response {
		$r = ( new Xapi_Request( $method, 'statements', $query, $headers, $body, '2.0.0' ) )->with_principal( $this->p );
		try {
			return ( new Statements_Write_Handler() )->handle( $r );
		} catch ( Xapi_Error $e ) {
			return $e->to_response();
		}
	}

	private static function count_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'statements' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_post_single_and_array_return_ids() {
		$res = $this->send( 'POST', array(), Json::encode_exact( self::statement() ) );
		$this->assertSame( 200, $res->status() );
		$ids = json_decode( $res->body(), true );
		$this->assertCount( 1, $ids );
		$this->assertTrue( Uuid::is_valid( $ids[0] ) );

		$given       = self::statement();
		$given['id'] = Uuid::v4();
		$res         = $this->send( 'POST', array(), Json::encode_exact( array( $given, self::statement() ) ) );
		$this->assertSame( 200, $res->status() );
		$ids = json_decode( $res->body(), true );
		$this->assertCount( 2, $ids );
		$this->assertSame( $given['id'], $ids[0] );
		$this->assertSame( 3, self::count_rows() );
	}

	public function test_put_identical_204_different_409() {
		$id  = Uuid::v4();
		$st  = self::statement();
		$res = $this->send( 'PUT', array( 'statementId' => $id ), Json::encode_exact( $st ) );
		$this->assertSame( 204, $res->status() );
		$this->assertSame( '', $res->body() );

		// Identical content, later: the LRS-assigned timestamp is ignored because the client sent none.
		Clock::freeze( new DateTimeImmutable( '+1 hour' ) );
		$this->assertSame( 204, $this->send( 'PUT', array( 'statementId' => $id ), Json::encode_exact( $st ) )->status() );
		$this->assertSame( 409, $this->send( 'PUT', array( 'statementId' => $id ), Json::encode_exact( self::statement( 'https://site.example/act/2' ) ) )->status() );
		$this->assertSame( 1, self::count_rows() );

		// A timestamp the client sends is part of the content.
		$timed              = self::statement();
		$timed['timestamp'] = '2026-10-27T10:00:00Z';
		$id2                = Uuid::v4();
		$this->assertSame( 204, $this->send( 'PUT', array( 'statementId' => $id2 ), Json::encode_exact( $timed ) )->status() );
		$timed['timestamp'] = '2026-10-27T12:00:00+02:00';
		$this->assertSame( 204, $this->send( 'PUT', array( 'statementId' => $id2 ), Json::encode_exact( $timed ) )->status() );
		$timed['timestamp'] = '2026-10-27T11:00:00Z';
		$this->assertSame( 409, $this->send( 'PUT', array( 'statementId' => $id2 ), Json::encode_exact( $timed ) )->status() );
	}

	public function test_single_statement_put_hands_listeners_a_row_list() {
		$seen = array();
		add_action(
			'learning_ledger/statements_stored',
			static function ( array $rows, Principal $p ) use ( &$seen ) {
				$seen['stored']    = $rows;
				$seen['principal'] = $p->client_id();
			},
			10,
			2
		);
		add_action(
			'learning_ledger/statements_committed',
			static function ( array $rows ) use ( &$seen ) {
				$seen['committed'] = $rows;
			}
		);
		$id = Uuid::v4();
		$this->assertSame( 204, $this->send( 'PUT', array( 'statementId' => $id ), Json::encode_exact( self::statement() ) )->status() );
		$this->assertSame( $this->p->client_id(), $seen['principal'] );
		foreach ( array( 'stored', 'committed' ) as $hook ) {
			$this->assertCount( 1, $seen[ $hook ], $hook );
			$this->assertInstanceOf( Stored_Statement::class, $seen[ $hook ][0], $hook );
			$this->assertSame( $id, $seen[ $hook ][0]->id, $hook );
		}
	}

	public function test_put_needs_matching_statement_id() {
		$this->assertSame( 400, $this->send( 'PUT', array(), Json::encode_exact( self::statement() ) )->status() );
		$this->assertSame( 400, $this->send( 'PUT', array( 'statementId' => 'nope' ), Json::encode_exact( self::statement() ) )->status() );
		$st       = self::statement();
		$st['id'] = Uuid::v4();
		$this->assertSame( 400, $this->send( 'PUT', array( 'statementId' => Uuid::v4() ), Json::encode_exact( $st ) )->status() );
		$this->assertSame( 204, $this->send( 'PUT', array( 'statementId' => strtoupper( $st['id'] ) ), Json::encode_exact( $st ) )->status() );
		$this->assertSame( 1, self::count_rows() );
	}

	/**
	 * Review Focus 3: another writer inserts the same statementId after the existence
	 * check but before our insert. Identical content succeeds, different content is 409,
	 * never a 500 from the duplicate key.
	 */
	public function test_duplicate_key_race_maps_to_204_or_409() {
		foreach ( array(
			'identical' => array( self::statement(), 204 ),
			'different' => array( self::statement( 'https://site.example/act/other' ), 409 ),
		) as $label => [ $racer, $expected ] ) {
			$id    = Uuid::v4();
			$fired = 0;
			$hook  = function ( $sql ) use ( $id, $racer, &$fired, &$hook ) {
				if ( 'START TRANSACTION' === $sql ) {
					remove_filter( 'query', $hook );
					++$fired;
					// The concurrent writer commits its row (autocommit, separate statement).
					( new Statements_Write_Handler() )->handle(
						( new Xapi_Request( 'PUT', 'statements', array( 'statementId' => $id ), array( 'Content-Type' => 'application/json' ), Json::encode_exact( $racer ), '2.0.0' ) )->with_principal( $this->p )
					);
				}
				return $sql;
			};
			add_filter( 'query', $hook );

			$res = $this->send( 'PUT', array( 'statementId' => $id ), Json::encode_exact( self::statement() ) );
			$this->assertSame( 1, $fired, $label );
			$this->assertSame( $expected, $res->status(), $label . ': ' . $res->body() );
			$this->assertNotNull( ( new Statement_Repository() )->find( $id ), $label );
		}
		$this->assertSame( 2, self::count_rows() );
	}

	public function test_exact_body_bytes_kept() {
		Clock::freeze( new DateTimeImmutable( '2026-10-27T10:00:00.5Z' ) );
		$id   = Uuid::v4();
		$body = '{"actor":{"objectType":"Agent","account":{"homePage":"https://site.example","name":"Zoë/ü"}},'
			. '"verb":{"id":"http://adlnet.gov/expapi/verbs/completed","display":{"en-US":"completed ✓"}},'
			. '"object":{"id":"https://site.example/act/1","definition":{"extensions":{}}},'
			. '"result":{"score":{"scaled":1.0},"extensions":{}},'
			. '"context":{"contextActivities":{"parent":{"id":"https://site.example/course"}},"extensions":{"https://x.example/list":[]}}}';
		$this->assertSame( 204, $this->send( 'PUT', array( 'statementId' => $id ), $body )->status() );

		$stored = ( new Statement_Repository() )->find( $id )->body;
		$this->assertStringContainsString( '"name":"Zoë/ü"', $stored );
		$this->assertStringContainsString( '"homePage":"https://site.example"', $stored );
		$this->assertStringContainsString( '"display":{"en-US":"completed ✓"}', $stored );
		$this->assertStringContainsString( '"definition":{"extensions":{}}', $stored );
		$this->assertStringContainsString( '"result":{"score":{"scaled":1.0},"extensions":{}}', $stored );
		$this->assertStringContainsString( '"https://x.example/list":[]', $stored );
		$this->assertStringContainsString( '"parent":[{"id":"https://site.example/course"}]', $stored );
		$this->assertStringContainsString( '"stored":"' . Timestamp::to_xapi( Clock::now() ) . '"', $stored );
		$this->assertStringNotContainsString( '\\/', $stored );
		$this->assertStringNotContainsString( '\\u', $stored );
	}

	public function test_multipart_rejected() {
		$res = $this->send( 'POST', array(), "--abc\r\n\r\n--abc--", array( 'Content-Type' => 'multipart/mixed; boundary=abc' ) );
		$this->assertSame( 400, $res->status() );
		$this->assertSame( 'multipart attachments are not supported by this LRS', json_decode( $res->body(), true )['message'] );
	}

	public function test_non_json_and_oversize_rejected() {
		$this->assertSame( 400, $this->send( 'POST', array(), 'not json' )->status() );
		$this->assertSame( 400, $this->send( 'POST', array(), '"a string"' )->status() );
		$this->assertSame( 400, $this->send( 'POST', array(), '[1]' )->status() );
		$big           = self::statement();
		$big['result'] = array( 'response' => str_repeat( 'a', 1048576 ) );
		$this->assertSame( 413, $this->send( 'POST', array(), Json::encode_exact( $big ) )->status() );
		$this->assertSame( 413, $this->send( 'POST', array(), Json::encode_exact( array_fill( 0, 501, self::statement() ) ) )->status() );
		$this->assertSame( 0, self::count_rows() );
	}

	public function test_validation_error_message_carries_path() {
		$st                  = self::statement();
		$st['actor']['mbox'] = 'mailto:a@b.example';
		$res                 = $this->send( 'POST', array(), Json::encode_exact( array( self::statement(), $st ) ) );
		$this->assertSame( 400, $res->status() );
		$this->assertStringContainsString( '[1].actor', json_decode( $res->body(), true )['message'] );
	}
}
