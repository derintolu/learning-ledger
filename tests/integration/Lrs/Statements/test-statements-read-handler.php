<?php
/**
 * Statements read handler tests.
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
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Lrs\Statements\Statements_Read_Handler;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Json;

class Statements_Read_Handler_Test extends WP_UnitTestCase {

	private const NOW = '2026-10-27T09:00:00Z';

	private Principal $writer;

	private Principal $reader;

	private ?string $request_uri;

	public function set_up() {
		parent::set_up();
		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$this->writer = self::principal( array( 'statements/write', 'void' ) );
		$this->reader = self::principal( array( 'statements/read' ) );
	}

	public function tear_down() {
		Clock::freeze( null );
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		parent::tear_down();
	}

	private static function principal( array $scopes, array $overrides = array() ): Principal {
		$c = Client_Factory::create( $scopes, $overrides );
		return Principal::for_client( ( new Client_Repository() )->find( $c['id'] ) );
	}

	private static function statement( string $actor = 'u1' ): array {
		return array(
			'actor'  => array(
				'objectType' => 'Agent',
				'name'       => 'Learner ' . $actor,
				'account'    => array(
					'homePage' => 'https://site.example',
					'name'     => $actor,
				),
			),
			'verb'   => array(
				'id'      => 'http://adlnet.gov/expapi/verbs/completed',
				'display' => array(
					'en-US' => 'completed',
					'fr-FR' => 'termine',
				),
			),
			'object' => array(
				'objectType' => 'Activity',
				'id'         => 'https://site.example/act/1',
				'definition' => array(
					'name' => array(
						'de' => 'Lektion',
						'en' => 'Lesson',
						'fr' => 'Lecon',
					),
				),
			),
		);
	}

	private function store_at( string $at, array $statements, ?Principal $p = null ): array {
		Clock::freeze( new DateTimeImmutable( $at ) );
		return ( new Statement_Store() )->store( $statements, $p ?? $this->writer, '2.0.0' );
	}

	private function get( array $query, array $headers = array(), ?Principal $p = null, string $version = '2.0.0' ): Xapi_Response {
		Clock::freeze( new DateTimeImmutable( self::NOW ) );
		$r = ( new Xapi_Request( 'GET', 'statements', $query, $headers, '', $version ) )->with_principal( $p ?? $this->reader );
		try {
			return ( new Statements_Read_Handler() )->handle( $r );
		} catch ( Xapi_Error $e ) {
			return $e->to_response();
		}
	}

	private static function body( string $id ): string {
		return ( new Statement_Repository() )->find( $id )->body;
	}

	public function test_single_statement_is_the_stored_bytes() {
		[ $id ] = $this->store_at( '2026-10-27T08:00:00.250000Z', array( self::statement() ) );

		$res = $this->get( array( 'statementId' => strtoupper( $id ) ) );
		$this->assertSame( 200, $res->status() );
		$this->assertSame( 'application/json', $res->content_type() );
		$this->assertSame( self::body( $id ), $res->body() );
		$this->assertSame( '2026-10-27T08:59:59.000000Z', $res->headers()['X-Experience-API-Consistent-Through'] );
		$this->assertSame( 'Tue, 27 Oct 2026 08:00:00 GMT', $res->headers()['Last-Modified'] );

		$res = $this->get( array( 'statementId' => $id ), array(), null, '1.0.3' );
		$this->assertSame( self::body( $id ), $res->body() );
		$this->assertArrayNotHasKey( 'Last-Modified', $res->headers() );
		$this->assertArrayHasKey( 'X-Experience-API-Consistent-Through', $res->headers() );

		$this->assertSame( 404, $this->get( array( 'statementId' => '00000000-0000-4000-8000-000000000000' ) )->status() );
		$this->assertSame( 400, $this->get( array( 'statementId' => 'nope' ) )->status() );
	}

	public function test_list_splices_exact_bytes() {
		[ $a, $b ] = $this->store_at( '2026-10-27T08:00:00Z', array( self::statement( 'u1' ), self::statement( 'u2' ) ) );
		[ $c ]     = $this->store_at( '2026-10-27T08:00:05.5Z', array( self::statement( 'u3' ) ) );

		$res = $this->get( array() );
		$this->assertSame( 200, $res->status() );
		$this->assertSame( '{"statements":[' . self::body( $c ) . ',' . self::body( $b ) . ',' . self::body( $a ) . '],"more":""}', $res->body() );
		$this->assertSame( 'Tue, 27 Oct 2026 08:00:05 GMT', $res->headers()['Last-Modified'] );
		$this->assertSame( '2026-10-27T08:59:59.000000Z', $res->headers()['X-Experience-API-Consistent-Through'] );

		$empty = $this->get( array( 'verb' => 'http://adlnet.gov/expapi/verbs/none' ) );
		$this->assertSame( '{"statements":[],"more":""}', $empty->body() );
		$this->assertArrayNotHasKey( 'Last-Modified', $empty->headers() );
	}

	public function test_more_link_pages_through_and_tampered_more_is_400() {
		$this->store_at( '2026-10-27T08:00:00Z', array( self::statement( 'u1' ), self::statement( 'u2' ), self::statement( 'u3' ) ) );

		$this->set_permalink_structure( '' );
		$plain = json_decode( $this->get( array( 'limit' => '2' ) )->body(), true )['more'];
		$this->assertMatchesRegularExpression( '#\A/index\.php\?rest_route=%2Flearning-ledger%2Fxapi%2Fstatements&more=[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z#', $plain, 'plain permalinks: the REST route' );

		$this->set_permalink_structure( '/%postname%/' );
		$_SERVER['REQUEST_URI'] = '/xapi/statements?limit=2';
		$first                  = json_decode( $this->get( array( 'limit' => '2' ) )->body(), true );
		$this->assertCount( 2, $first['statements'] );
		$this->assertMatchesRegularExpression( '#\A/xapi/statements\?more=[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z#', $first['more'], 'a relative IRL on the public base' );

		parse_str( (string) wp_parse_url( $first['more'], PHP_URL_QUERY ), $query );
		$second = json_decode( $this->get( $query )->body(), true );
		$this->assertCount( 1, $second['statements'] );
		$this->assertSame( '', $second['more'] );
		$this->assertSame( 'u1', $second['statements'][0]['actor']['account']['name'] );

		$token = $query['more'];
		$this->assertSame( 400, $this->get( array( 'more' => 'x' . $token ) )->status() );
		$this->assertSame( 400, $this->get( array( 'more' => substr( $token, 0, -2 ) ) )->status() );
		$this->assertSame(
			400,
			$this->get(
				array(
					'more'  => $token,
					'limit' => '5',
				)
			)->status(),
			'more stands alone'
		);
		$this->assertSame( 400, $this->get( $query, array(), self::principal( array( 'statements/read' ) ) )->status(), 'another client cannot follow it' );
	}

	public function test_voided_statement_by_voided_statement_id_only() {
		[ $target, $other ] = $this->store_at( '2026-10-27T08:00:00Z', array( self::statement(), self::statement( 'u2' ) ) );
		$voiding            = self::statement();
		$voiding['verb']    = array( 'id' => 'http://adlnet.gov/expapi/verbs/voided' );
		$voiding['object']  = array(
			'objectType' => 'StatementRef',
			'id'         => $target,
		);
		[ $voider ]         = $this->store_at( '2026-10-27T08:00:01Z', array( $voiding ) );

		$this->assertSame( 404, $this->get( array( 'statementId' => $target ) )->status() );
		$res = $this->get( array( 'voidedStatementId' => $target ) );
		$this->assertSame( 200, $res->status() );
		$this->assertSame( self::body( $target ), $res->body() );
		$this->assertSame( 404, $this->get( array( 'voidedStatementId' => $other ) )->status() );

		$ids = array_column( json_decode( $this->get( array() )->body(), true )['statements'], 'id' );
		$this->assertSame( array( $voider, $other ), $ids );
	}

	public function test_statement_id_with_other_filters_is_400() {
		[ $id ] = $this->store_at( '2026-10-27T08:00:00Z', array( self::statement() ) );
		$this->assertSame(
			400,
			$this->get(
				array(
					'statementId' => $id,
					'verb'        => 'http://adlnet.gov/expapi/verbs/completed',
				)
			)->status()
		);
		$this->assertSame(
			400,
			$this->get(
				array(
					'voidedStatementId' => $id,
					'limit'             => '1',
				)
			)->status()
		);
		$this->assertSame(
			400,
			$this->get(
				array(
					'statementId'       => $id,
					'voidedStatementId' => $id,
				)
			)->status()
		);
		$this->assertSame(
			200,
			$this->get(
				array(
					'statementId' => $id,
					'format'      => 'ids',
					'attachments' => 'false',
				)
			)->status()
		);
	}

	public function test_invalid_parameters_are_400() {
		foreach ( array(
			array( 'agent' => '{"name":"no ifi"}' ),
			array( 'agent' => 'not json' ),
			array( 'agent' => '{"objectType":"Group","member":[{"mbox":"mailto:a@example.test"}]}' ),
			array( 'verb' => 'not an iri' ),
			array( 'activity' => 'not an iri' ),
			array( 'registration' => 'nope' ),
			array( 'since' => 'yesterday' ),
			array( 'until' => '2026-10-27' ),
			array( 'limit' => '-1' ),
			array( 'limit' => 'ten' ),
			array( 'format' => 'pretty' ),
			array( 'ascending' => 'maybe' ),
			array( 'related_agents' => 'yes' ),
			array( 'unknown' => '1' ),
		) as $query ) {
			$this->assertSame( 400, $this->get( $query )->status(), wp_json_encode( $query ) );
		}
		$this->assertSame( 200, $this->get( array( 'attachments' => 'true' ) )->status() );
		$this->assertSame( 'application/json', $this->get( array( 'attachments' => 'true' ) )->content_type() );
	}

	public function test_format_ids_and_canonical() {
		[ $id ] = $this->store_at( '2026-10-27T08:00:00Z', array( self::statement() ) );

		$ids = json_decode( $this->get( array( 'format' => 'ids' ) )->body(), true )['statements'][0];
		$this->assertSame( $id, $ids['id'] );
		$this->assertSame(
			array(
				'objectType' => 'Agent',
				'account'    => array(
					'homePage' => 'https://site.example',
					'name'     => 'u1',
				),
			),
			$ids['actor']
		);
		$this->assertSame(
			array(
				'objectType' => 'Activity',
				'id'         => 'https://site.example/act/1',
			),
			$ids['object']
		);

		$canonical = json_decode( $this->get( array( 'format' => 'canonical' ), array( 'Accept-Language' => 'fr, en;q=0.5' ) )->body(), true )['statements'][0];
		$this->assertSame( array( 'fr' => 'Lecon' ), $canonical['object']['definition']['name'] );
		$this->assertSame( array( 'fr-FR' => 'termine' ), $canonical['verb']['display'] );
		$this->assertSame( 'Learner u1', $canonical['actor']['name'] );

		$single_canonical = array(
			'statementId' => $id,
			'format'      => 'canonical',
		);
		$single           = json_decode( $this->get( $single_canonical, array( 'Accept-Language' => 'en' ) )->body(), true );
		$this->assertSame( array( 'en' => 'Lesson' ), $single['object']['definition']['name'] );
	}

	public function test_formatted_output_keeps_empty_objects() {
		$st           = self::statement();
		$st['result'] = array( 'extensions' => new stdClass() );
		[ $id ]       = $this->store_at( '2026-10-27T08:00:00Z', array( $st ) );

		$this->assertStringContainsString( '"extensions":{}', self::body( $id ) );
		$this->assertStringContainsString( '"extensions":{}', $this->get( array( 'format' => 'ids' ) )->body() );
		$single_canonical = array(
			'statementId' => $id,
			'format'      => 'canonical',
		);
		$this->assertStringContainsString( '"extensions":{}', $this->get( $single_canonical )->body() );
	}

	public function test_read_scopes() {
		$mine  = self::principal( array( 'statements/write', 'statements/read/mine' ) );
		[ $m ] = $this->store_at( '2026-10-27T08:00:00Z', array( self::statement() ), $mine );
		[ $t ] = $this->store_at( '2026-10-27T08:00:01Z', array( self::statement() ) );

		$this->assertSame( array( $m ), array_column( json_decode( $this->get( array(), array(), $mine )->body(), true )['statements'], 'id' ) );
		$this->assertSame( 200, $this->get( array( 'statementId' => $m ), array(), $mine )->status() );
		$this->assertSame( 404, $this->get( array( 'statementId' => $t ), array(), $mine )->status() );

		$bound = self::principal( array( 'statements/read' ), array( 'bindings' => array( 'actor_ifi' => 'account:https://site.example|u2' ) ) );
		$this->assertSame( 404, $this->get( array( 'statementId' => $t ), array(), $bound )->status() );

		$this->assertSame( 403, $this->get( array(), array(), self::principal( array( 'statements/write' ) ) )->status() );
	}
	public function test_more_link_keeps_the_arrival_base() {
		$this->store_at( '2026-10-27T08:00:00Z', array( self::statement( 'u1' ), self::statement( 'u2' ) ) );
		$this->set_permalink_structure( '/%postname%/' );
		$token = '[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+';

		$_SERVER['REQUEST_URI'] = '/xapi/statements?limit=1';
		$this->assertMatchesRegularExpression( '#\A/xapi/statements\?more=' . $token . '\z#', json_decode( $this->get( array( 'limit' => '1' ) )->body(), true )['more'] );

		$_SERVER['REQUEST_URI'] = '/wp-json/learning-ledger/xapi/statements?limit=1';
		$this->assertMatchesRegularExpression( '#\A/wp-json/learning-ledger/xapi/statements\?more=' . $token . '\z#', json_decode( $this->get( array( 'limit' => '1' ) )->body(), true )['more'] );

		$this->set_permalink_structure( '' );
		$_SERVER['REQUEST_URI'] = '/?rest_route=/learning-ledger/xapi/statements&limit=1';
		$this->assertMatchesRegularExpression( '#\A/index\.php\?rest_route=%2Flearning-ledger%2Fxapi%2Fstatements&more=' . $token . '\z#', json_decode( $this->get( array( 'limit' => '1' ) )->body(), true )['more'] );
	}

	/**
	 * XAPI-00153 (Communication 2.1.3.s2.b5): every GET on the Statements resource returns
	 * X-Experience-API-Consistent-Through, whatever the status code.
	 */
	public function test_xapi_00153_consistent_through_on_every_get_response() {
		[ $id ] = $this->store_at( '2026-10-27T08:00:00Z', array( self::statement() ) );
		$cases  = array(
			'statementId with a filter (400)' => array(
				array(
					'statementId' => $id,
					'agent'       => '{"mbox":"mailto:a@example.test"}',
				),
				null,
				400,
			),
			'invalid parameter (400)'         => array( array( 'since' => 'yesterday' ), null, 400 ),
			'tampered more (400)'             => array( array( 'more' => 'x.y' ), null, 400 ),
			'no such statement (404)'         => array( array( 'statementId' => '00000000-0000-4000-8000-000000000000' ), null, 404 ),
			'no read scope (403)'             => array( array(), self::principal( array( 'statements/write' ) ), 403 ),
		);
		foreach ( $cases as $label => list( $query, $p, $status ) ) {
			foreach ( array( '1.0.3', '2.0.0' ) as $version ) {
				$res = $this->get( $query, array(), $p, $version );
				$this->assertSame( $status, $res->status(), $label . ' ' . $version );
				$this->assertSame( '2026-10-27T08:59:59.000000Z', $res->headers()['X-Experience-API-Consistent-Through'] ?? null, $label . ' ' . $version );
			}
		}
	}
}
