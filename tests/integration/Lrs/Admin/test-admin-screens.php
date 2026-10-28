<?php
/**
 * wp-admin screens tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\Integration_State;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Pairing\Pairing_Module;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Kernel\Capabilities;
use LearningLedger\Lrs\Admin\Admin_Module;
use LearningLedger\Lrs\Admin\Badges_Screen;
use LearningLedger\Lrs\Admin\Clients_Screen;
use LearningLedger\Lrs\Admin\Connector_Screen;
use LearningLedger\Lrs\Admin\Erase_Screen;
use LearningLedger\Lrs\Admin\Learner_Screen;
use LearningLedger\Lrs\Admin\Statements_Screen;
use LearningLedger\Lrs\Admin\Subscriptions_Screen;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Notify\Subscription_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Tests\Recording_Sink;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

/**
 * Admin_Screens_Test.
 */
class Admin_Screens_Test extends WP_UnitTestCase {

	const HOME   = 'https://site.example';
	const COURSE = 'https://example.org/courses/admin';
	const STORED = '2026-10-27T10:00:00.000000Z';
	const LATER  = '2026-10-27T10:00:05.000000Z';

	/**
	 * Administrator id.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Subscriber id (no plugin capabilities).
	 *
	 * @var int
	 */
	private int $subscriber;

	/**
	 * Statement writer.
	 *
	 * @var Principal
	 */
	private Principal $writer;

	public function set_up() {
		parent::set_up();
		Installer::install();
		Capabilities::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'documents', 'tokens', 'badges', 'links', 'jobs', 'clients', 'subscriptions', 'outbox', 'applied', 'audit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		delete_option( Integration_State::OPTION );
		delete_option( Connector_Settings::OPTION );
		wp_cache_flush();
		update_option( 'lledger_roles', array( 'lrs', 'connector' ) );

		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		Clock::freeze( new DateTimeImmutable( self::STORED ) );
		$client       = Client_Factory::create( array( Scopes::STATEMENTS_WRITE ) );
		$this->writer = Principal::for_client( ( new Client_Repository() )->find( $client['id'] ) );

		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		remove_all_filters( 'learning_ledger/integration_dirs' );
		remove_all_filters( 'learning_ledger/event_sink' );
		Clock::freeze( null );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}

	/**
	 * Throw instead of redirecting, so the handler's exit is never reached.
	 *
	 * @param string $location Location.
	 *
	 * @throws RuntimeException Always.
	 */
	public function stop_redirect( $location ) {
		throw new RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test signal, never output.
	}

	private static function actor( string $name ): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => self::HOME,
				'name'     => $name,
			),
		);
	}

	private function store( array $statements ): array {
		return ( new Statement_Store() )->store( $statements, $this->writer, '1.0.3' );
	}

	private static function statement( string $name, string $verb = 'http://adlnet.gov/expapi/verbs/experienced', array $extra = array() ): array {
		return array_merge(
			array(
				'actor'  => self::actor( $name ),
				'verb'   => array( 'id' => $verb ),
				'object' => array( 'id' => self::COURSE ),
			),
			$extra
		);
	}

	private function badge( string $name, string $status = Badge_Repository::PENDING ): string {
		return (string) ( new Badge_Repository() )->create_pending(
			array(
				'id'   => 'https://example.org/achievements/' . wp_generate_password( 8, false ),
				'name' => 'Badge for ' . $name,
			),
			Uuid::v4(),
			Agent_Identity::from_array( self::actor( $name ) )->hash(),
			self::COURSE,
			Uuid::v4(),
			'Did the work.',
			0,
			$status
		);
	}

	/**
	 * Register the module for the current roles, as boot() does.
	 */
	private function boot_module(): void {
		( new Admin_Module() )->register();
	}

	/**
	 * Render a screen with GET parameters.
	 *
	 * @param object $screen Screen.
	 * @param array  $get    Query parameters.
	 *
	 * @return string
	 */
	private function render( $screen, array $get = array() ): string {
		wp_set_current_user( $this->admin );
		$_GET = $get;
		ob_start();
		$screen->render();
		return (string) ob_get_clean();
	}

	/**
	 * Every action: name => [admin-post action, fixture builder].
	 *
	 * Each builder returns [request fields, nonce action, capability, effect check].
	 */
	public static function actions(): array {
		return array(
			'revoke client'       => array( 'revoke_client' ),
			'delete subscription' => array( 'delete_subscription' ),
			'revoke badge'        => array( 'revoke_badge' ),
			'erase actor'         => array( 'erase_actor' ),
			'confirm major'       => array( 'confirm_major' ),
		);
	}

	private function fixture_revoke_client(): array {
		$id = Client_Factory::create( array( Scopes::STATEMENTS_READ ) )['id'];
		return array(
			Clients_Screen::ACTION,
			array( 'client' => $id ),
			Clients_Screen::ACTION . '_' . $id,
			Capabilities::MANAGE,
			static function () use ( $id ): bool {
				wp_cache_flush();
				return null !== ( new Client_Repository() )->find( $id )->revoked_at;
			},
		);
	}

	private function fixture_delete_subscription(): array {
		$id = ( new Subscription_Repository() )->create( 'https://93.184.215.14/hook', array() );
		return array(
			Subscriptions_Screen::ACTION,
			array( 'subscription' => (string) $id ),
			Subscriptions_Screen::ACTION . '_' . $id,
			Capabilities::MANAGE,
			static fn(): bool => null === ( new Subscription_Repository() )->find( $id ),
		);
	}

	private function fixture_revoke_badge(): array {
		$id = $this->badge( 'learner-1' );
		return array(
			Badges_Screen::ACTION,
			array(
				'badge'  => $id,
				'reason' => 'Issued in error',
			),
			Badges_Screen::ACTION . '_' . $id,
			Capabilities::MANAGE_BADGES,
			static fn(): bool => Badge_Repository::REVOKED === ( new Badge_Repository() )->get( $id )['status'],
		);
	}

	private function fixture_erase_actor(): array {
		$this->store( array( self::statement( 'erase-me' ) ) );
		$actor = (string) wp_json_encode( self::actor( 'erase-me' ) );
		$hash  = Agent_Identity::from_array( self::actor( 'erase-me' ) )->hash();
		return array(
			Erase_Screen::ACTION,
			array(
				'actor'   => $actor,
				'confirm' => Erase_Screen::CONFIRM,
			),
			Erase_Screen::nonce_action( $hash ),
			Capabilities::MANAGE,
			static function () use ( $hash ): bool {
				global $wpdb;
				return 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE actor_hash = UNHEX(%s)', Tables::name( 'statements' ), bin2hex( $hash ) ) );
			},
		);
	}

	private function fixture_confirm_major(): array {
		add_filter( 'learning_ledger/integration_dirs', static fn() => array( dirname( __DIR__, 3 ) . '/fixtures/integrations' ) );
		add_filter( 'learning_ledger/event_sink', static fn() => new Recording_Sink() );
		( new Integration_Loader() )->load();
		Integration_State::pause( 'fake', Integration_State::MAJOR );
		return array(
			Connector_Screen::ACTION,
			array( 'integration' => 'fake' ),
			Connector_Screen::ACTION . '_fake',
			Capabilities::MANAGE_CONNECTOR,
			static function (): bool {
				$entry = get_option( Integration_State::OPTION )['fake'] ?? array();
				return 1 === ( $entry['confirmed_major'] ?? null ) && Integration_State::ACTIVE === Integration_State::status( 'fake' );
			},
		);
	}

	/**
	 * Run an admin-post action as a user.
	 *
	 * @param string      $action Action.
	 * @param array       $fields Request fields.
	 * @param string|null $nonce  Nonce action to sign, or null for no nonce.
	 * @param int         $user   User.
	 */
	private function post( string $action, array $fields, ?string $nonce, int $user ): void {
		wp_set_current_user( $user );
		if ( null !== $nonce ) {
			$fields['_wpnonce'] = wp_create_nonce( $nonce );
		}
		$_POST                     = $fields;
		$_REQUEST                  = $fields;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		do_action( 'admin_post_' . $action );
	}

	/**
	 * @dataProvider actions
	 */
	public function test_action_without_nonce_dies( string $name ) {
		[ $action, $fields, , , $done ] = $this->{'fixture_' . $name}();
		$this->boot_module();
		try {
			$this->post( $action, $fields, null, $this->admin );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'link you followed has expired', $e->getMessage() );
		}
		$this->assertFalse( $done(), 'nothing changed' );
	}

	/**
	 * @dataProvider actions
	 */
	public function test_action_with_wrong_nonce_dies( string $name ) {
		[ $action, $fields, , , $done ] = $this->{'fixture_' . $name}();
		$this->boot_module();
		$this->expectException( WPDieException::class );
		try {
			$this->post( $action, $fields, 'some_other_action', $this->admin );
		} finally {
			$this->assertFalse( $done(), 'nothing changed' );
		}
	}

	/**
	 * @dataProvider actions
	 */
	public function test_action_without_capability_dies( string $name ) {
		[ $action, $fields, $nonce, $cap, $done ] = $this->{'fixture_' . $name}();
		$this->boot_module();
		$this->assertFalse( user_can( $this->subscriber, $cap ) );
		try {
			$this->post( $action, $fields, $nonce, $this->subscriber );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'not allowed', $e->getMessage() );
		}
		$this->assertFalse( $done(), 'nothing changed' );
	}

	/**
	 * @dataProvider actions
	 */
	public function test_action_with_nonce_and_capability_takes_effect( string $name ) {
		[ $action, $fields, $nonce, , $done ] = $this->{'fixture_' . $name}();
		$this->boot_module();
		$this->assertFalse( $done(), 'not done before' );
		try {
			$this->post( $action, $fields, $nonce, $this->admin );
			$this->fail( 'Expected a redirect.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringStartsWith( 'redirect:' . admin_url( 'admin.php' ), $e->getMessage() );
			$this->assertStringContainsString( 'lledger_done=', $e->getMessage() );
		}
		$this->assertTrue( $done(), 'done after' );
	}

	public function test_erase_without_confirmation_erases_nothing() {
		[ $action, $fields, $nonce, , $done ] = $this->fixture_erase_actor();
		unset( $fields['confirm'] );
		$this->boot_module();
		try {
			$this->post( $action, $fields, $nonce, $this->admin );
			$this->fail( 'Expected a redirect.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'lledger_error=confirm', $e->getMessage() );
		}
		$this->assertFalse( $done() );
	}

	public function test_erase_reports_failure_and_keeps_everything_when_a_delete_fails() {
		[ $action, $fields, $nonce, , $done ] = $this->fixture_erase_actor();
		global $wpdb;
		$failing  = 'DELETE FROM ' . Tables::name( 'agents' );
		$failer   = static fn( string $q ): string => str_starts_with( $q, $failing ) ? 'DELETE FROM lledger_no_such_table_fx9' : $q;
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $failer );
		$this->boot_module();
		try {
			$this->post( $action, $fields, $nonce, $this->admin );
			$this->fail( 'Expected a redirect.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'lledger_error=failed', $e->getMessage() );
			$this->assertStringNotContainsString( 'lledger_done=', $e->getMessage() );
		} finally {
			remove_filter( 'query', $failer );
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertFalse( $done(), 'the statements are rolled back, not erased' );
	}

	public function test_erase_nonce_is_bound_to_the_actor() {
		[ $action, $fields ] = $this->fixture_erase_actor();
		$other               = Erase_Screen::nonce_action( Agent_Identity::from_array( self::actor( 'someone-else' ) )->hash() );
		$this->boot_module();
		$this->expectException( WPDieException::class );
		$this->post( $action, $fields, $other, $this->admin );
	}

	public function test_erase_confirmation_step_shows_actor_and_form() {
		$actor = (string) wp_json_encode( self::actor( 'erase-me' ) );
		$html  = $this->render( new Erase_Screen(), array( 'actor' => $actor ) );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
		$this->assertStringContainsString( 'name="action" value="' . Erase_Screen::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="confirm" value="' . Erase_Screen::CONFIRM . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( esc_html( 'erase-me' ), $html );

		$first = $this->render( new Erase_Screen() );
		$this->assertStringNotContainsString( 'name="confirm"', $first, 'first step has no erase form' );
	}

	public function test_statement_json_is_escaped_in_pre() {
		$this->store(
			array(
				self::statement(
					'learner-1',
					'http://adlnet.gov/expapi/verbs/answered',
					array( 'result' => array( 'response' => '</pre><script>alert(1)</script>' ) )
				),
			)
		);
		Clock::freeze( new DateTimeImmutable( self::LATER ) );
		$html = $this->render( new Statements_Screen() );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringNotContainsString( '</pre><script>', $html );
		$this->assertStringContainsString( '&lt;/pre&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertSame( 1, substr_count( $html, '<pre' ) );
		$this->assertSame( 1, substr_count( $html, '</pre>' ) );
	}

	public function test_statements_page_shows_50_and_links_the_next_page() {
		$batch = array();
		for ( $i = 0; $i < 51; $i++ ) {
			$batch[] = self::statement( 'learner-' . $i );
		}
		$this->store( $batch );
		Clock::freeze( new DateTimeImmutable( self::LATER ) );

		$html = $this->render( new Statements_Screen() );
		$this->assertSame( 50, substr_count( $html, '<pre' ) );
		$this->assertSame( 1, preg_match( '/href="([^"]*more=[^"]+)"/', $html, $m ) );

		$query = array();
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $query );
		$this->assertSame( Statements_Screen::SLUG, $query['page'] );
		$next = $this->render( new Statements_Screen(), $query );
		$this->assertSame( 1, substr_count( $next, '<pre' ) );
		$this->assertStringContainsString( 'learner-0', $next, 'oldest is last' );
		$this->assertStringNotContainsString( 'more=', $next );
	}

	public function test_statements_filters_by_verb_registration_agent_and_activity() {
		$registration = Uuid::v4();
		$this->store(
			array(
				self::statement( 'a', 'http://adlnet.gov/expapi/verbs/completed', array( 'context' => array( 'registration' => $registration ) ) ),
				self::statement( 'b', 'http://adlnet.gov/expapi/verbs/completed' ),
				self::statement( 'c', 'http://adlnet.gov/expapi/verbs/attempted' ),
				array(
					'actor'  => self::actor( 'd' ),
					'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/attempted' ),
					'object' => array( 'id' => 'https://example.org/other' ),
				),
			)
		);
		Clock::freeze( new DateTimeImmutable( self::LATER ) );
		$screen = new Statements_Screen();

		$verb = $this->render( $screen, array( 'verb' => 'http://adlnet.gov/expapi/verbs/completed' ) );
		$this->assertSame( 2, substr_count( $verb, '<pre' ) );

		$reg = $this->render( $screen, array( 'registration' => $registration ) );
		$this->assertSame( 1, substr_count( $reg, '<pre' ) );
		$this->assertStringContainsString( '&quot;name&quot;: &quot;a&quot;', $reg );

		$agent = $this->render( $screen, array( 'agent' => (string) wp_json_encode( self::actor( 'c' ) ) ) );
		$this->assertSame( 1, substr_count( $agent, '<pre' ) );
		$this->assertStringContainsString( '&quot;name&quot;: &quot;c&quot;', $agent );

		$activity = $this->render( $screen, array( 'activity' => 'https://example.org/other' ) );
		$this->assertSame( 1, substr_count( $activity, '<pre' ) );
		$this->assertStringContainsString( '&quot;name&quot;: &quot;d&quot;', $activity );

		$bad = $this->render( $screen, array( 'registration' => 'not-a-uuid' ) );
		$this->assertSame( 0, substr_count( $bad, '<pre' ) );
		$this->assertStringContainsString( 'notice-error', $bad );
		$this->assertStringContainsString( 'registration must be a UUID.', $bad );
	}

	public function test_learner_screen_shows_only_that_actors_statements_and_badges() {
		$this->store( array( self::statement( 'alice' ), self::statement( 'bob' ) ) );
		$alice_badge = $this->badge( 'alice' );
		$bob_badge   = $this->badge( 'bob' );
		Clock::freeze( new DateTimeImmutable( self::LATER ) );

		$html = $this->render( new Learner_Screen(), array( 'agent' => (string) wp_json_encode( self::actor( 'alice' ) ) ) );
		$this->assertSame( 1, substr_count( $html, '<pre' ) );
		$this->assertStringContainsString( '&quot;name&quot;: &quot;alice&quot;', $html );
		$this->assertStringNotContainsString( '&quot;name&quot;: &quot;bob&quot;', $html );
		$this->assertStringContainsString( $alice_badge, $html );
		$this->assertStringNotContainsString( $bob_badge, $html );
	}

	public function test_badges_screen_lists_badges_and_held_cases_with_revoke_forms() {
		$pending = $this->badge( 'alice' );
		$held    = $this->badge( 'bob', Badge_Repository::HELD );
		$gone    = $this->badge( 'carol' );
		( new Badge_Repository() )->set_status( $gone, Badge_Repository::REVOKED );

		$html = $this->render( new Badges_Screen() );
		$this->assertStringContainsString( $pending, $html );
		$this->assertStringContainsString( $gone, $html );
		$start        = (int) strpos( $html, 'id="lledger-held"' );
		$held_section = substr( $html, $start, (int) strpos( $html, 'id="lledger-all"' ) - $start );
		$this->assertStringContainsString( $held, $held_section );
		$this->assertStringNotContainsString( $pending, $held_section, 'only held badges in the held section' );
		$this->assertSame( 2, substr_count( $html, 'name="badge" value="' . $held . '"' ), 'held badge revocable in both lists' );
		$this->assertSame( 1, substr_count( $html, 'name="badge" value="' . $pending . '"' ) );
		$this->assertSame( 0, substr_count( $html, 'name="badge" value="' . $gone . '"' ), 'revoked badge has no revoke form' );
	}

	public function test_clients_and_subscriptions_screens_list_rows_without_secrets() {
		$client = Client_Factory::create( array( Scopes::STATEMENTS_READ ), array( 'name' => 'Course <b>server</b>' ) );
		$sub    = ( new Subscription_Repository() )->create( 'https://93.184.215.14/hook', array() );

		$clients = $this->render( new Clients_Screen() );
		$this->assertStringContainsString( esc_html( 'Course <b>server</b>' ), $clients );
		$this->assertStringNotContainsString( 'Course <b>server</b>', $clients );
		$this->assertStringContainsString( 'name="client" value="' . $client['id'] . '"', $clients );
		$this->assertStringNotContainsString( $client['secret'], $clients );

		$subs = $this->render( new Subscriptions_Screen() );
		$this->assertStringContainsString( 'https://93.184.215.14/hook', $subs );
		$this->assertStringContainsString( 'name="subscription" value="' . $sub . '"', $subs );
		$this->assertStringContainsString( Subscription_Repository::ACTIVE, $subs );
	}

	public function test_connector_screen_shows_pairing_paused_integrations_and_flagged_results() {
		( new Pairing_Module() )->register();
		Connector_Settings::update(
			array(
				'lrs_url'   => 'https://lrs.example/xapi/',
				'client_id' => 'connector-1',
			)
		);
		update_option( Integration_State::OPTION, array() );
		Integration_State::pause( 'tutor', Integration_State::MAJOR );
		Integration_State::pause( 'sensei', 'RuntimeException: <boom>' );
		$statement = Uuid::v4();
		$ledger    = new Apply_Ledger();
		$ledger->record( $this->subscriber, new Lms_Object( 'lesson', 42 ), 'completed', $statement );
		$ledger->flag_void( $statement );

		$html = $this->render( new Connector_Screen() );
		$this->assertStringContainsString( 'https://lrs.example/xapi/', $html );
		$this->assertStringContainsString( 'connector-1', $html );
		$this->assertStringNotContainsString( 'secret_sha256', $html );
		$this->assertSame( 1, substr_count( $html, 'name="integration" value="tutor"' ), 'confirm offered for the major-version pause' );
		$this->assertSame( 0, substr_count( $html, 'name="integration" value="sensei"' ), 'no confirm for other pauses' );
		$this->assertStringContainsString( esc_html( 'RuntimeException: <boom>' ), $html );
		$this->assertStringContainsString( $statement, $html );
	}

	public function test_screens_and_actions_follow_enabled_roles() {
		global $menu, $submenu;
		wp_set_current_user( $this->admin );

		update_option( 'lledger_roles', array( 'lrs' ) );
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->boot_module();
		do_action( 'admin_menu' );
		$this->assertContains( Admin_Module::MENU, array_column( $menu, 2 ), 'parent registered when the connector role is off' );
		$slugs = array_column( $submenu[ Admin_Module::MENU ], 2 );
		foreach ( array( Statements_Screen::SLUG, Learner_Screen::SLUG, Clients_Screen::SLUG, Subscriptions_Screen::SLUG, Badges_Screen::SLUG, Erase_Screen::SLUG ) as $slug ) {
			$this->assertContains( $slug, $slugs );
		}
		$this->assertNotContains( Connector_Screen::SLUG, $slugs );
		$this->assertNotFalse( has_action( 'admin_post_' . Clients_Screen::ACTION ) );
		$this->assertFalse( has_action( 'admin_post_' . Connector_Screen::ACTION ) );

		$this->reset_hooks();
		update_option( 'lledger_roles', array( 'connector' ) );
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->boot_module();
		do_action( 'admin_menu' );
		$this->assertNotContains( Admin_Module::MENU, array_column( $menu, 2 ), 'Task 12 owns the parent when the connector role is on' );
		$this->assertSame( array( Connector_Screen::SLUG ), array_column( $submenu[ Admin_Module::MENU ], 2 ) );
		$this->assertFalse( has_action( 'admin_post_' . Clients_Screen::ACTION ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Connector_Screen::ACTION ) );

		$this->reset_hooks();
		update_option( 'lledger_roles', array() );
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->boot_module();
		do_action( 'admin_menu' );
		$this->assertSame( array(), $menu );
		$this->assertSame( array(), $submenu );
	}

	public function test_screen_capabilities_match_the_brief() {
		$this->assertSame( Capabilities::VIEW_RECORDS, Statements_Screen::CAP );
		$this->assertSame( Capabilities::VIEW_RECORDS, Learner_Screen::CAP );
		$this->assertSame( Capabilities::MANAGE, Clients_Screen::CAP );
		$this->assertSame( Capabilities::MANAGE, Subscriptions_Screen::CAP );
		$this->assertSame( Capabilities::MANAGE_BADGES, Badges_Screen::CAP );
		$this->assertSame( Capabilities::MANAGE, Erase_Screen::CAP );
		$this->assertSame( Capabilities::MANAGE_CONNECTOR, Connector_Screen::CAP );
	}

	public function test_render_without_capability_dies() {
		wp_set_current_user( $this->subscriber );
		$this->expectException( WPDieException::class );
		( new Statements_Screen() )->render();
	}

	/**
	 * Drop the module's admin hooks between role switches.
	 */
	private function reset_hooks(): void {
		remove_all_actions( 'admin_menu' );
		foreach ( array( Clients_Screen::ACTION, Subscriptions_Screen::ACTION, Badges_Screen::ACTION, Erase_Screen::ACTION, Connector_Screen::ACTION ) as $action ) {
			remove_all_actions( 'admin_post_' . $action );
		}
	}
}
