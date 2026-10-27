<?php
/**
 * Catch-up poller tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Inbox\Catch_Up_Poller;
use LearningLedger\Connector\Inbox\Inbox_Module;
use LearningLedger\Connector\Inbox\Inbox_Repository;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Uuid;

class Catch_Up_Poller_Test extends WP_UnitTestCase {

	/** @var array[] */
	private array $requests = array();

	/** @var array[] Responses by call order: {code, body}. */
	private array $responses = array();

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'conn_inbox', 'conn_cursor' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		delete_option( Connector_Settings::OPTION );
		delete_option( Connector_Secret::OPTION );
		Connector_Settings::update(
			array(
				'lrs_url'   => 'https://lrs.example.test/xapi/',
				'client_id' => 'connector-site',
			)
		);
		Connector_Secret::generate();
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		parent::tear_down();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		$next             = array_shift( $this->responses ) ?? array(
			'code' => 200,
			'body' => '{"statements":[],"more":""}',
		);
		return array(
			'headers'  => array_merge( array( 'content-type' => 'application/json' ), $next['headers'] ?? array() ),
			'body'     => $next['body'],
			'response' => array(
				'code'    => $next['code'],
				'message' => 'x',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function st( string $id, string $stored ): array {
		return array(
			'id'      => $id,
			'actor'   => array(
				'objectType' => 'Agent',
				'mbox'       => 'mailto:a@example.org',
			),
			'verb'    => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object'  => array( 'id' => 'https://example.org/act' ),
			'context' => array( 'registration' => Uuid::v4() ),
			'stored'  => $stored,
		);
	}

	private function page( array $statements, string $more ): array {
		return array(
			'code' => 200,
			'body' => (string) wp_json_encode(
				array(
					'statements' => $statements,
					'more'       => $more,
				)
			),
		);
	}

	private function query( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		return $q;
	}

	private function set_cursor( string $value ): void {
		$GLOBALS['wpdb']->replace(
			Tables::name( 'conn_cursor' ),
			array(
				'name'  => 'poll_since',
				'value' => $value,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function cursor(): ?string {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM ' . Tables::name( 'conn_cursor' ) . ' WHERE name = %s', 'poll_since' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return null === $v ? null : (string) $v;
	}

	public function test_follows_more_dedupes_with_webhook_rows_and_advances_cursor() {
		$repo = new Inbox_Repository();
		$dup  = $this->st( Uuid::v4(), '2026-10-27T10:00:02.000000Z' );
		$this->assertTrue( $repo->add( $dup, 'webhook' ) );

		$poller = new Catch_Up_Poller();
		$this->set_cursor( '2026-10-27T09:00:00.000000Z' );

		$a                 = $this->st( Uuid::v4(), '2026-10-27T10:00:01.000000Z' );
		$b                 = $this->st( Uuid::v4(), '2026-10-27T10:00:03.500000+00:00' );
		$this->responses[] = $this->page( array( $a, $dup ), '/xapi/statements?more=page-2%2Bx' );
		$this->responses[] = $this->page( array( $b ), '' );

		$this->assertSame( 2, $poller->run(), 'two new rows' );

		$this->assertCount( 2, $this->requests );
		$first = $this->query( $this->requests[0]['url'] );
		$this->assertStringStartsWith( 'https://lrs.example.test/xapi/statements?', $this->requests[0]['url'] );
		$this->assertSame( '2026-10-27T08:59:00.000000Z', $first['since'], 'the query reaches 60 s behind the cursor' );
		$this->assertSame( '500', $first['limit'] );
		$this->assertSame( 'GET', $this->requests[0]['args']['method'] );
		$this->assertStringStartsWith( 'Basic ', $this->requests[0]['args']['headers']['Authorization'] );

		$this->assertStringStartsWith( 'https://lrs.example.test/xapi/statements?', $this->requests[1]['url'] );
		$this->assertSame( 'page-2+x', $this->query( $this->requests[1]['url'] )['more'] );

		$rows = $repo->pending( 10 );
		$this->assertCount( 3, $rows );
		$sources = array_column( $rows, 'source', 'statement_id' );
		$this->assertSame( 'webhook', $sources[ $dup['id'] ] );
		$this->assertSame( 'poll', $sources[ $a['id'] ] );
		$this->assertSame( 'poll', $sources[ $b['id'] ] );

		$this->assertSame( '2026-10-27T10:00:03.500000Z', $this->cursor(), 'newest stored seen' );

		$this->responses[] = $this->page( array(), '' );
		$this->assertSame( 0, $poller->run() );
		$this->assertSame( '2026-10-27T09:59:03.500000Z', $this->query( $this->requests[2]['url'] )['since'] );
		$this->assertSame( '2026-10-27T10:00:03.500000Z', $this->cursor(), 'an empty page leaves the cursor' );
	}

	public function test_page_cap_mid_equal_stored_group_fetches_the_rest_next_run() {
		$same = '2026-10-27T10:00:00.000000Z';
		$a    = $this->st( Uuid::v4(), $same );
		$b    = $this->st( Uuid::v4(), $same );
		$c    = $this->st( Uuid::v4(), $same );

		$poller            = new Catch_Up_Poller( null, null, 1 );
		$this->responses[] = $this->page( array( $a, $b ), '/xapi/statements?more=next' );
		$this->assertSame( 2, $poller->run() );
		$this->assertCount( 1, $this->requests, 'stopped at the page cap' );
		$this->assertSame( $same, $this->cursor() );

		// The LRS's next page held c with the same stored value; the overlap reaches it.
		$this->responses[] = $this->page( array( $a, $b, $c ), '' );
		$this->assertSame( 1, $poller->run() );
		$this->assertSame( '2026-10-27T09:59:00.000000Z', $this->query( $this->requests[1]['url'] )['since'] );
		$ids = array_column( ( new Inbox_Repository() )->pending( 10 ), 'statement_id' );
		$this->assertEqualsCanonicalizing( array( $a['id'], $b['id'], $c['id'] ), $ids );
	}

	public function test_failed_insert_keeps_the_cursor_behind_it() {
		global $wpdb;
		$this->set_cursor( '2026-10-27T09:00:00.000000Z' );
		$a   = $this->st( Uuid::v4(), '2026-10-27T10:00:01.000000Z' );
		$bad = $this->st( Uuid::v4(), '2026-10-27T10:00:02.000000Z' );
		$c   = $this->st( Uuid::v4(), '2026-10-27T10:00:03.000000Z' );

		$break = static function ( $q ) use ( $bad ) {
			return false !== strpos( $q, 'INSERT IGNORE INTO' ) && false !== strpos( $q, $bad['id'] ) ? 'SELECT lledger_broken(' : $q;
		};
		add_filter( 'query', $break );
		$suppress          = $wpdb->suppress_errors( true );
		$this->responses[] = $this->page( array( $a, $bad, $c ), '/xapi/statements?more=next' );
		$poller            = new Catch_Up_Poller();
		$this->assertSame( 2, $poller->run() );
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );

		$this->assertCount( 1, $this->requests, 'the run stops at the failure' );
		$this->assertSame( '2026-10-27T10:00:02.000000Z', $this->cursor(), 'not past the failed statement' );
		$this->assertTrue( $poller->recovery_needed() );

		$this->responses[] = $this->page( array( $bad, $c ), '' );
		$this->assertSame( 1, $poller->run() );
		$this->assertSame( '2026-10-27T09:59:02.000000Z', $this->query( $this->requests[1]['url'] )['since'] );
		$this->assertCount( 3, ( new Inbox_Repository() )->pending( 10 ) );
		$this->assertSame( '2026-10-27T10:00:03.000000Z', $this->cursor() );
	}

	public function test_cursor_is_capped_at_consistent_through() {
		$this->set_cursor( '2026-10-27T09:00:00.000000Z' );
		$page              = $this->page( array( $this->st( Uuid::v4(), '2026-10-27T10:00:05.000000Z' ) ), '' );
		$page['headers']   = array( 'X-Experience-API-Consistent-Through' => '2026-10-27T10:00:00.000000Z' );
		$this->responses[] = $page;
		$this->assertSame( 1, ( new Catch_Up_Poller() )->run() );
		$this->assertSame( '2026-10-27T10:00:00.000000Z', $this->cursor() );

		$page              = $this->page( array( $this->st( Uuid::v4(), '2026-10-27T09:30:00.000000Z' ) ), '' );
		$page['headers']   = array( 'X-Experience-API-Consistent-Through' => '2026-10-27T08:00:00.000000Z' );
		$this->responses[] = $page;
		( new Catch_Up_Poller() )->run();
		$this->assertSame( '2026-10-27T10:00:00.000000Z', $this->cursor(), 'never moves backwards' );
	}

	public function test_first_run_without_cursor_sends_no_since() {
		$this->responses[] = $this->page( array( $this->st( Uuid::v4(), '2026-10-27T10:00:00Z' ) ), '' );
		$this->assertSame( 1, ( new Catch_Up_Poller() )->run() );
		$q = $this->query( $this->requests[0]['url'] );
		$this->assertArrayNotHasKey( 'since', $q );
		$this->assertSame( '500', $q['limit'] );
		$this->assertSame( '2026-10-27T10:00:00.000000Z', $this->cursor() );
	}

	public function test_lrs_failure_keeps_cursor_and_rows() {
		$poller = new Catch_Up_Poller();
		$this->set_cursor( '2026-10-27T09:00:00.000000Z' );
		$this->responses[] = $this->page( array( $this->st( Uuid::v4(), '2026-10-27T10:00:00Z' ) ), '/xapi/statements?more=2' );
		$this->responses[] = array(
			'code' => 500,
			'body' => 'oops',
		);
		$this->assertSame( 1, $poller->run() );
		$this->assertSame( '2026-10-27T10:00:00.000000Z', $this->cursor(), 'pages before the failure count' );
		$this->assertTrue( $poller->recovery_needed() );

		$this->responses[] = $this->page( array(), '' );
		$poller->run();
		$this->assertFalse( $poller->recovery_needed(), 'a clean run clears the flag' );
	}

	public function test_more_pointing_at_another_host_is_not_followed() {
		$this->responses[] = $this->page( array( $this->st( Uuid::v4(), '2026-10-27T10:00:00Z' ) ), 'https://evil.example/xapi/statements?more=1' );
		$this->assertSame( 1, ( new Catch_Up_Poller() )->run() );
		$this->assertCount( 1, $this->requests, 'the credential never goes to another host' );
	}

	public function test_unpaired_connector_does_nothing() {
		delete_option( Connector_Settings::OPTION );
		$this->assertSame( 0, ( new Catch_Up_Poller() )->run() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_quarter_hour_hook_runs_the_poll() {
		( new Inbox_Module() )->register();
		do_action( 'learning_ledger/cron_quarter_hour' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( 'limit=500', $this->requests[0]['url'] );
	}

	public function test_daily_prune_drops_old_unmatched_rows() {
		$repo = new Inbox_Repository();
		$old  = $this->st( Uuid::v4(), '2026-10-27T10:00:00Z' );
		$new  = $this->st( Uuid::v4(), '2026-10-27T10:00:00Z' );
		$repo->add( $old, 'poll' );
		$repo->add( $new, 'poll' );
		$repo->mark( $old['id'], 'unmatched' );
		$repo->mark( $new['id'], 'unmatched' );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Tables::name( 'conn_inbox' ) . ' SET received_at = %s WHERE statement_id = %s', gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ), Uuid::to_bin( $old['id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		( new Inbox_Module() )->register();
		do_action( Inbox_Module::DAILY_EVENT ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		$ids = array_column( $repo->for_registration( $new['context']['registration'] ), 'statement_id' );
		$this->assertSame( array( $new['id'] ), $ids );
		$this->assertSame( array(), $repo->for_registration( $old['context']['registration'] ) );
	}
}
