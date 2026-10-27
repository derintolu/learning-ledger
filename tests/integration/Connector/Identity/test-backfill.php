<?php
/**
 * Backfill tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Identity\Backfill;
use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Uuid;

class Backfill_Test extends WP_UnitTestCase {

	/** @var array<int,array{url:string,args:array}> */
	private array $calls = array();

	/** @var array[] Replies in order. */
	private array $replies = array();

	private array $actor = array(
		'objectType' => 'Agent',
		'account'    => array(
			'homePage' => 'https://server.test',
			'name'     => 'learner-42',
		),
	);

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Tables::name( 'conn_inbox' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		delete_option( Connector_Settings::OPTION );
		delete_option( Connector_Secret::OPTION );
		Connector_Settings::update(
			array(
				'lrs_url'   => 'https://lrs.test/xapi/',
				'client_id' => 'conn-1',
			)
		);
		Connector_Secret::generate();
		$this->calls   = array();
		$this->replies = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		parent::tear_down();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->calls[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return array_shift( $this->replies ) ?? array(
			'response' => array( 'code' => 500 ),
			'headers'  => array(),
			'body'     => '',
		);
	}

	private function page( array $statements, string $more = '' ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'statements' => $statements,
					'more'       => $more,
				)
			),
		);
	}

	private function statement(): array {
		return array(
			'id'      => Uuid::v4(),
			'actor'   => $this->actor,
			'verb'    => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object'  => array(
				'objectType' => 'Activity',
				'id'         => 'https://example.org/act/' . wp_rand(),
			),
			'context' => array( 'registration' => Uuid::v4() ),
			'stored'  => '2026-10-27T10:00:00.123456Z',
		);
	}

	public function test_pages_through_more_and_adds_rows_with_source_backfill() {
		$user          = self::factory()->user->create();
		$a             = $this->statement();
		$b             = $this->statement();
		$c             = $this->statement();
		$this->replies = array(
			$this->page( array( $a, $b ), '/xapi/statements?cursor=2' ),
			$this->page( array( $c, $a ) ),
		);

		$added = ( new Backfill() )->run( $user, $this->actor );

		$this->assertSame( 3, $added, 'a repeated statement id is not counted twice' );
		$this->assertCount( 2, $this->calls );
		$this->assertStringStartsWith( 'https://lrs.test/xapi/statements?', $this->calls[0]['url'] );
		parse_str( (string) wp_parse_url( $this->calls[0]['url'], PHP_URL_QUERY ), $q );
		$this->assertSame( $this->actor, json_decode( $q['agent'], true ) );
		$this->assertStringContainsString( 'cursor=2', $this->calls[1]['url'] );

		global $wpdb;
		$table = Tables::name( 'conn_inbox' );
		$this->assertSame( array( 'backfill' => '3' ), array_column( $wpdb->get_results( "SELECT source, COUNT(*) n FROM `{$table}` GROUP BY source", ARRAY_A ), 'n', 'source' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array(), ( new Inbox_Repository() )->pending( 10 ), 'process_pending ran' );
	}

	public function test_only_gets_are_sent_to_the_lrs() {
		$this->replies = array( $this->page( array( $this->statement() ) ) );
		( new Backfill() )->run( self::factory()->user->create(), $this->actor );
		foreach ( $this->calls as $call ) {
			$this->assertSame( 'GET', strtoupper( $call['args']['method'] ) );
		}
	}

	public function test_a_foreign_more_link_is_not_followed_and_the_run_fails() {
		$this->replies = array( $this->page( array( $this->statement() ), 'https://evil.test/xapi/statements?x=1' ) );
		try {
			( new Backfill() )->run( self::factory()->user->create(), $this->actor );
			$this->fail( 'expected a RuntimeException' );
		} catch ( RuntimeException $e ) {
			$this->assertCount( 1, $this->calls, 'the foreign link was not requested' );
		}
	}

	public function test_hitting_the_page_cap_fails_the_run() {
		$this->replies = array_fill( 0, Backfill::MAX_PAGES + 1, $this->page( array(), '/xapi/statements?cursor=n' ) );
		$this->expectException( RuntimeException::class );
		( new Backfill() )->run( self::factory()->user->create(), $this->actor );
	}

	public function test_an_lrs_error_throws_so_the_job_is_retried() {
		$this->replies = array(
			array(
				'response' => array( 'code' => 503 ),
				'headers'  => array(),
				'body'     => '',
			),
		);
		$this->expectException( RuntimeException::class );
		( new Backfill() )->run( self::factory()->user->create(), $this->actor );
	}
}
