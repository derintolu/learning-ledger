<?php
/**
 * cmi5 launch route tests.
 *
 * The LRS runs on the same install: both roles are on, and the connector's server-to-server
 * calls go to its loopback base, which a pre_http_request bridge dispatches in-process to the
 * LRS's own REST routes (so the test transaction sees every write).
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- test fixtures and table reads.
// phpcs:disable Generic.Files.OneObjectStructurePerFile -- the shared base class lives with the launch tests; Fetch_Test reuses it.

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Crypto\Tokens;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Client_Spec;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Http\Xapi_Response;
use LearningLedger\Lrs\Http\Xapi_Router;
use LearningLedger\Lrs\Tokens\Tokens_Module;
use LearningLedger\Player\Import\Player_Importer;
use LearningLedger\Player\Launch\Launch_Module;
use LearningLedger\Player\Launch\Lms_Statements;
use LearningLedger\Player\Launch\Session_Repository;
use LearningLedger\Player\Store\Au_Store;
use LearningLedger\Tests\Xapi_Test_Client;
use LearningLedger\Util\Clock;

require_once dirname( __DIR__, 3 ) . '/fixtures/packages/class-package-fixtures.php';

/**
 * Shared setup: a paired connector on the same install, an imported player course and an enrolled learner.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test base class.
abstract class Lledger_Launch_Test_Case extends WP_UnitTestCase {

	const LOOPBACK  = 'http://127.0.0.1:8080/';
	const AU_ID     = 'https://w3id.org/xapi/cmi5/catapult/lts/au/001-essentials';
	const AU_URL    = 'https://au.example.net/001/index.html?paramA=1&paramB=2';
	const CONNECTOR = 'connector-site';
	const FIX       = LEARNING_LEDGER_DIR . 'tests/fixtures/catapult';

	protected int $program;

	protected int $course;

	protected int $lesson;

	protected int $learner;

	/**
	 * Every outgoing HTTP request: url and args.
	 *
	 * @var array[]
	 */
	protected array $http = array();

	/**
	 * URL fragment whose requests fail with a transport error.
	 *
	 * @var string|null
	 */
	protected ?string $fail = null;

	/**
	 * Temp files.
	 *
	 * @var string[]
	 */
	private array $temp = array();

	/**
	 * Whether the LRS's statement transaction is open (as a savepoint).
	 *
	 * @var bool
	 */
	private bool $nested = false;

	public function set_up(): void {
		parent::set_up();
		// The statement store's START TRANSACTION would commit the test's transaction; nest it instead.
		add_filter( 'query', array( $this, 'nest_transactions' ) );
		Installer::install();
		global $wpdb, $wp_rest_server;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'documents', 'tokens', 'cmi5_sessions', 'registrations', 'cmi5_packages', 'cmi5_files', 'ratelimit', 'jobs' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) );
		}
		$wpdb->delete( Tables::name( 'clients' ), array( 'id' => self::CONNECTOR ) );
		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:00:00.000000', new DateTimeZone( 'UTC' ) ) );

		update_option( 'lledger_roles', array( 'lrs', 'connector' ) );
		( new Integration_Loader() )->load();
		$module = new Program_Module();
		$module->register_post_type();
		$module->register_meta();

		delete_option( Connector_Settings::OPTION );
		delete_option( Connector_Secret::OPTION );
		Connector_Settings::update(
			array(
				'lrs_url'      => home_url( '/xapi/' ),
				'loopback_url' => self::LOOPBACK . 'wp-json/learning-ledger/xapi/',
				'api_base'     => self::LOOPBACK . 'wp-json/learning-ledger/v1/',
				'client_id'    => self::CONNECTOR,
			)
		);
		Connector_Secret::generate();
		( new Client_Repository() )->create(
			new Client_Spec(
				self::CONNECTOR,
				'Connector',
				Tokens::hash( (string) Connector_Secret::get() ),
				array( Scopes::TOKENS_MINT, Scopes::VOID, Scopes::STATE, Scopes::PROFILE, Scopes::STATEMENTS_WRITE, Scopes::STATEMENTS_READ ),
				array(),
				array(),
				false,
				'default',
				false
			)
		);

		( new Xapi_Router() )->register();
		( new Tokens_Module() )->register();
		( new Launch_Module() )->register();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->http = array();
		$this->fail = null;
		add_filter( 'pre_http_request', array( $this, 'loopback' ), 10, 3 );

		$this->import_xml( str_replace( 'index.html?paramA=1&paramB=2', self::AU_URL, (string) file_get_contents( self::FIX . '/001-essentials/cmi5.xml' ) ) );

		$this->learner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertTrue( ( new Sensei_Adapter() )->enrol( $this->learner, $this->course ) );
		wp_set_current_user( $this->learner );
	}

	public function tear_down(): void {
		global $wpdb, $wp_rest_server;
		remove_filter( 'query', array( $this, 'nest_transactions' ) );
		remove_filter( 'pre_http_request', array( $this, 'loopback' ), 10 );
		foreach ( $wpdb->get_col( 'SELECT id FROM ' . Tables::name( 'cmi5_packages' ) ) as $id ) {
			Au_Store::delete( (int) $id );
		}
		foreach ( $this->temp as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		delete_option( 'lledger_roles' );
		Clock::freeze( null );
		parent::tear_down();
	}

	/**
	 * Turns the statement store's transaction into a savepoint inside the test's transaction.
	 */
	public function nest_transactions( $query ) {
		$sql = strtoupper( trim( (string) $query ) );
		if ( 'START TRANSACTION' === $sql ) {
			$this->nested = true;
			return 'SAVEPOINT lledger_store';
		}
		if ( $this->nested && ( 'COMMIT' === $sql || 'ROLLBACK' === $sql ) ) {
			$this->nested = false;
			return 'COMMIT' === $sql ? 'RELEASE SAVEPOINT lledger_store' : 'ROLLBACK TO SAVEPOINT lledger_store';
		}
		return $query;
	}

	/**
	 * Dispatches loopback requests to this install's REST routes; anything else fails.
	 */
	public function loopback( $pre, $args, $url ) {
		$this->http[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( ! str_starts_with( $url, self::LOOPBACK . 'wp-json/' ) ) {
			return new WP_Error( 'http_request_failed', 'Only the loopback is reachable in this test.' );
		}
		if ( null !== $this->fail && str_contains( $url, $this->fail ) ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: refused' );
		}
		$parts   = wp_parse_url( $url );
		$request = new WP_REST_Request( $args['method'], substr( $parts['path'], strlen( '/wp-json' ) ) );
		$query   = array();
		parse_str( $parts['query'] ?? '', $query );
		$request->set_query_params( $query );
		foreach ( (array) $args['headers'] as $name => $value ) {
			$request->set_header( $name, $value );
		}
		if ( isset( $args['body'] ) ) {
			$request->set_body( (string) $args['body'] );
		}
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		if ( $data instanceof Xapi_Response ) {
			$status  = $data->status();
			$headers = $data->headers();
			$body    = $data->body();
			if ( '' !== $data->content_type() ) {
				$headers['Content-Type'] = $data->content_type();
			}
		} else {
			$status  = $response->get_status();
			$headers = $response->get_headers();
			$body    = 204 === $status ? '' : (string) wp_json_encode( rest_get_server()->response_to_data( $response, false ) );
		}
		return array(
			'headers'  => array_change_key_case( $headers, CASE_LOWER ),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	protected function import_xml( string $xml ): void {
		$path = tempnam( sys_get_temp_dir(), 'llxml' );
		file_put_contents( $path, $xml );
		$this->temp[] = $path;
		$this->imported( ( new Player_Importer( new Sensei_Adapter() ) )->import( $path ) );
	}

	protected function import_zip(): void {
		$xml          = (string) file_get_contents( self::FIX . '/001-essentials/cmi5.xml' );
		$html         = (string) file_get_contents( self::FIX . '/index.html' );
		$path         = Package_Fixtures::zip(
			static function ( ZipArchive $z ) use ( $xml, $html ) {
				$z->addFromString( 'cmi5.xml', $xml );
				$z->addFromString( 'index.html', $html );
			}
		);
		$this->temp[] = $path;
		$this->imported( ( new Player_Importer( new Sensei_Adapter() ) )->import( $path ) );
	}

	private function imported( $report ): void {
		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );
		$this->program = $report->program_id;
		$this->course  = ( new Program_Repository() )->get( $this->program )->course_ids[0];
		$lessons       = get_posts(
			array(
				'post_type'   => 'lesson',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_key'    => Player_Importer::AU_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => self::AU_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$this->lesson  = (int) $lessons[0];
	}

	protected function launch( array $body, bool $nonce = true ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/learning-ledger/v1/cmi5/launch' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	protected function launch_ok( string $mode = 'Normal' ): array {
		$res = $this->launch(
			array(
				'lesson' => $this->lesson,
				'mode'   => $mode,
			)
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		return $res->get_data();
	}

	protected static function query_of( string $url ): array {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return $query;
	}

	protected static function fetch_token( string $launch_url ): string {
		$fetch = self::query_of( $launch_url )['fetch'];
		preg_match( '#cmi5/fetch/([A-Za-z0-9_-]+)$#', $fetch, $m );
		return $m[1];
	}

	protected function fetch( string $token, string $method = 'POST', array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/learning-ledger/v1/cmi5/fetch/' . $token );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	protected function actor( int $user ): array {
		return ( new Identity_Map() )->actor_for_user( $user );
	}

	/**
	 * Stored statements, oldest first, optionally of one verb.
	 */
	protected function statements( string $verb = '' ): array {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_col( 'SELECT body FROM ' . Tables::name( 'statements' ) . ' ORDER BY seq' ) as $body ) {
			$st = json_decode( $body, true );
			if ( '' === $verb || $verb === $st['verb']['id'] ) {
				$out[] = $st;
			}
		}
		return $out;
	}

	protected function as_connector(): Xapi_Test_Client {
		return Xapi_Test_Client::as( self::CONNECTOR, (string) Connector_Secret::get() );
	}

	protected function launch_data( int $user, string $registration ): Xapi_Response {
		return $this->as_connector()->request(
			'GET',
			'activities/state',
			array(
				'activityId'   => Au::activity_id( self::AU_ID ),
				'agent'        => wp_json_encode( $this->actor( $user ) ),
				'registration' => $registration,
				'stateId'      => 'LMS.LaunchData',
			)
		);
	}

	protected function preferences( int $user ): Xapi_Response {
		return $this->as_connector()->request(
			'GET',
			'agents/profile',
			array(
				'agent'     => wp_json_encode( $this->actor( $user ) ),
				'profileId' => 'cmi5LearnerPreferences',
			)
		);
	}

	protected function session_for( int $user ): array {
		$row = ( new Session_Repository() )->open_for( $user, self::AU_ID );
		$this->assertNotNull( $row );
		return $row;
	}
}

/**
 * cmi5 launch tests.
 *
 * @requires function Sensei
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test class.
class Launch_Test extends Lledger_Launch_Test_Case {

	public function test_launch_writes_launch_data_with_the_exact_keys() {
		$this->launch_ok();
		$session      = $this->session_for( $this->learner );
		$registration = ( new Registration_Map() )->find( $this->learner, 'cmi5:' . $this->program );
		$this->assertSame( $registration, $session['registration'] );

		$res = $this->launch_data( $this->learner, $registration );
		$this->assertSame( 200, $res->status() );
		$doc = json_decode( $res->body(), true );
		$this->assertEqualsCanonicalizing(
			array( 'contextTemplate', 'launchMode', 'launchParameters', 'masteryScore', 'moveOn', 'returnURL', 'entitlementKey' ),
			array_keys( $doc )
		);
		$this->assertSame( 'Normal', $doc['launchMode'] );
		$this->assertSame( 'sample string', $doc['launchParameters'] );
		$this->assertSame( 0.9, $doc['masteryScore'] );
		$this->assertSame( 'CompletedAndPassed', $doc['moveOn'] );
		$this->assertSame( rest_url( 'learning-ledger/v1/cmi5/return/' . $session['session_id'] ), $doc['returnURL'] );
		$this->assertSame( array( 'courseStructure' => 'sample value' ), $doc['entitlementKey'] );
		$this->assertSame(
			array(
				array(
					'objectType' => 'Activity',
					'id'         => self::AU_ID,
				),
			),
			$doc['contextTemplate']['contextActivities']['grouping']
		);
		$this->assertSame( $session['session_id'], $doc['contextTemplate']['extensions'][ Vocab::EXT_SESSIONID ] );
	}

	public function test_launch_data_leaves_out_values_the_au_does_not_define() {
		$xml = str_replace(
			array( 'masteryScore="0.9"', '<launchParameters>', '</launchParameters>', '<entitlementKey>', '</entitlementKey>', 'sample string', 'sample value' ),
			'',
			str_replace( 'index.html?paramA=1&paramB=2', 'https://au.example.net/bare/index.html', (string) file_get_contents( self::FIX . '/001-essentials/cmi5.xml' ) )
		);
		$xml = str_replace( 'lts/au/001-essentials', 'lts/au/001-bare', str_replace( 'lts/course/001-essentials', 'lts/course/001-bare', str_replace( 'lts/block/001-essentials', 'lts/block/001-bare', $xml ) ) );
		$this->import_xml( $xml );
		$lesson = (int) get_posts(
			array(
				'post_type'   => 'lesson',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_key'    => Player_Importer::AU_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => 'https://w3id.org/xapi/cmi5/catapult/lts/au/001-bare', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		)[0];
		$this->assertTrue( ( new Sensei_Adapter() )->enrol( $this->learner, $this->course ) );

		$res = $this->launch( array( 'lesson' => $lesson ) );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$query = self::query_of( $res->get_data()['launch_url'] );
		$doc   = json_decode(
			$this->as_connector()->request(
				'GET',
				'activities/state',
				array(
					'activityId'   => $query['activityId'],
					'agent'        => $query['actor'],
					'registration' => $query['registration'],
					'stateId'      => 'LMS.LaunchData',
				)
			)->body(),
			true
		);
		$this->assertEqualsCanonicalizing( array( 'contextTemplate', 'launchMode', 'moveOn', 'returnURL' ), array_keys( $doc ) );
		$this->assertArrayNotHasKey( Vocab::EXT_LAUNCHPARAMETERS, $this->statements( Vocab::LAUNCHED )[0]['context']['extensions'] );
	}

	public function test_preferences_are_created_from_the_locale_only_when_missing() {
		update_user_meta( $this->learner, 'locale', 'fr_FR' );
		$this->launch_ok();
		$res = $this->preferences( $this->learner );
		$this->assertSame( 200, $res->status() );
		// CATAPULT 001-essentials, cmi5 11.0.0.0-5: the document holds both properties.
		$this->assertSame(
			array(
				'languagePreference' => 'fr-FR',
				'audioPreference'    => 'on',
			),
			json_decode( $res->body(), true )
		);

		$puts = array_values(
			array_filter(
				$this->http,
				static fn( array $r ): bool => str_contains( $r['url'], 'agents/profile' )
			)
		);
		$this->assertCount( 1, $puts );
		$this->assertSame( 'PUT', $puts[0]['args']['method'] );
		$this->assertSame( '*', $puts[0]['args']['headers']['If-None-Match'] );

		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertTrue( ( new Sensei_Adapter() )->enrol( $other, $this->course ) );
		$existing = '{"languagePreference":"de-DE","audioPreference":"off"}';
		$put      = $this->as_connector()->request(
			'PUT',
			'agents/profile',
			array(
				'agent'     => wp_json_encode( $this->actor( $other ) ),
				'profileId' => 'cmi5LearnerPreferences',
			),
			$existing,
			array( 'Content-Type' => 'application/json' )
		);
		$this->assertSame( 204, $put->status() );
		wp_set_current_user( $other );
		$this->launch_ok();
		$this->assertSame( $existing, $this->preferences( $other )->body(), 'an existing document is untouched' );
	}

	public function test_launched_statement_carries_the_cmi5_context() {
		$data         = $this->launch_ok();
		$session      = $this->session_for( $this->learner );
		$registration = $session['registration'];
		$launched     = $this->statements( Vocab::LAUNCHED );
		$this->assertCount( 1, $launched );
		$st = $launched[0];

		$this->assertSame( $this->actor( $this->learner ), $st['actor'] );
		$this->assertSame( Au::activity_id( self::AU_ID ), $st['object']['id'] );
		$this->assertSame( $registration, $st['context']['registration'] );
		$this->assertSame( array( Vocab::CAT_CMI5 ), array_column( $st['context']['contextActivities']['category'], 'id' ) );
		$this->assertSame( array( self::AU_ID ), array_column( $st['context']['contextActivities']['grouping'], 'id' ) );
		$ext = $st['context']['extensions'];
		$this->assertSame( $session['session_id'], $ext[ Vocab::EXT_SESSIONID ] );
		$this->assertSame( 'Normal', $ext[ Vocab::EXT_LAUNCHMODE ] );
		$this->assertSame( self::AU_URL, $ext[ Vocab::EXT_LAUNCHURL ] );
		$this->assertSame( 'CompletedAndPassed', $ext[ Vocab::EXT_MOVEON ] );
		$this->assertSame( 'sample string', $ext[ Vocab::EXT_LAUNCHPARAMETERS ] );
		$this->assertSame( 'connector-site', $st['authority']['account']['name'], 'written with the LMS credential' );

		$structure = ( new Program_Repository() )->structure( $this->program );
		$expected  = ( new Lms_Statements() )->launched( $structure->find_au( self::AU_ID ), $this->actor( $this->learner ), $registration, $session['session_id'], 'Normal', self::AU_URL, 'sample string' );
		$this->assertSame( $expected['id'], $st['id'], 'deterministic id' );
		$this->assertSame( 'AnyWindow', $data['method'] );
	}

	public function test_launch_url_carries_the_parameters_and_the_public_endpoint() {
		$data    = $this->launch_ok();
		$url     = $data['launch_url'];
		$session = $this->session_for( $this->learner );
		$this->assertStringStartsWith( 'https://au.example.net/001/index.html?paramA=1&paramB=2&', $url );

		$query = self::query_of( $url );
		$this->assertSame( '1', $query['paramA'] );
		$this->assertSame( '2', $query['paramB'] );
		$this->assertSame( Connector_Settings::public_xapi_base(), $query['endpoint'] );
		$this->assertSame( home_url( '/xapi/' ), $query['endpoint'] );
		$this->assertStringNotContainsString( '127.0.0.1', $query['endpoint'], 'never the loopback' );
		$this->assertStringStartsWith( rest_url( 'learning-ledger/v1/cmi5/fetch/' ), $query['fetch'] );
		$this->assertSame( 43, strlen( self::fetch_token( $url ) ), '32 random bytes, base64url' );
		$this->assertSame( $this->actor( $this->learner ), json_decode( $query['actor'], true ) );
		$this->assertSame( $session['registration'], $query['registration'] );
		$this->assertSame( Au::activity_id( self::AU_ID ), $query['activityId'] );

		global $wpdb;
		$hash = $wpdb->get_var( $wpdb->prepare( 'SELECT HEX(fetch_hash) FROM ' . Tables::name( 'cmi5_sessions' ) . ' WHERE session_id = %s', $session['session_id'] ) );
		$this->assertSame( strtoupper( bin2hex( Tokens::hash( self::fetch_token( $url ) ) ) ), $hash, 'only the hash is stored' );
		$this->assertSame( '2026-10-27 10:05:00.000000', $session['fetch_expires_at'] );

		foreach ( $this->http as $call ) {
			$this->assertStringStartsWith( self::LOOPBACK, $call['url'], 'server-to-server calls use the loopback' );
		}
	}

	public function test_relaunch_abandons_the_open_session_with_its_duration() {
		$first   = $this->launch_ok();
		$old     = $this->session_for( $this->learner );
		$fetched = $this->fetch( self::fetch_token( $first['launch_url'] ) );
		$this->assertArrayHasKey( 'auth-token', $fetched->get_data() );
		$token = ( new Session_Repository() )->find( $old['session_id'] )['token_key'];
		$this->assertNotNull( $token );

		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:01:30.500000', new DateTimeZone( 'UTC' ) ) );
		$this->launch_ok();

		$lookups = array_filter(
			$this->http,
			static fn( array $r ): bool => 'GET' === $r['args']['method'] && str_contains( $r['url'], '/statements?' )
		);
		$this->assertCount( 1, $lookups, 'the LRS was asked for the session\'s terminated first' );
		$query = self::query_of( (string) reset( $lookups )['url'] );
		$this->assertSame( $old['registration'], $query['registration'] );
		$this->assertSame( Vocab::TERMINATED, $query['verb'] );

		$abandoned = $this->statements( Vocab::ABANDONED );
		$this->assertCount( 1, $abandoned );
		$this->assertSame( 'PT1M30.5S', $abandoned[0]['result']['duration'] );
		$this->assertSame( $old['session_id'], $abandoned[0]['context']['extensions'][ Vocab::EXT_SESSIONID ] );
		$this->assertSame( $old['registration'], $abandoned[0]['context']['registration'] );
		$this->assertSame( Au::activity_id( self::AU_ID ), $abandoned[0]['object']['id'] );

		$closed = ( new Session_Repository() )->find( $old['session_id'] );
		$this->assertSame( Session_Repository::ABANDONED, $closed['status'] );
		$this->assertSame( '2026-10-27 10:01:30.500000', $closed['abandoned_at'] );
		$this->assertNotSame( $old['session_id'], $this->session_for( $this->learner )['session_id'] );
		$this->assertSame( $old['registration'], $this->session_for( $this->learner )['registration'], 'same registration' );
		$this->assertCount( 2, $this->statements( Vocab::LAUNCHED ) );

		global $wpdb;
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SELECT revoked_at FROM ' . Tables::name( 'tokens' ) . ' WHERE token_key = %s', $token ) ), 'the old token is revoked' );
	}

	public function test_registration_is_created_once_and_announced() {
		$calls = array();
		add_action(
			'learning_ledger/cmi5_registration_created',
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			},
			10,
			3
		);
		$this->launch_ok();
		$this->launch_ok();
		$registration = ( new Registration_Map() )->find( $this->learner, 'cmi5:' . $this->program );
		$this->assertSame( array( array( $this->learner, $this->program, $registration ) ), $calls );
	}

	public function test_not_enrolled_is_403() {
		$stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $stranger );
		$res = $this->launch( array( 'lesson' => $this->lesson ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertNull( ( new Session_Repository() )->open_for( $stranger, self::AU_ID ) );
		$this->assertNull( ( new Registration_Map() )->find( $stranger, 'cmi5:' . $this->program ) );
		$this->assertSame( array(), $this->statements() );
	}

	public function test_nonce_and_login_are_required() {
		$this->assertSame( 403, $this->launch( array( 'lesson' => $this->lesson ), false )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->launch( array( 'lesson' => $this->lesson ) )->get_status() );
		$this->assertSame( array(), $this->statements() );
	}

	public function test_unknown_lesson_or_mode_is_refused() {
		$plain = self::factory()->post->create();
		$this->assertSame( 404, $this->launch( array( 'lesson' => $plain ) )->get_status() );

		wp_update_post(
			array(
				'ID'          => $this->lesson,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 404, $this->launch( array( 'lesson' => $this->lesson ) )->get_status(), 'an unpublished lesson cannot be launched in Normal mode' );
		$this->assertNull( ( new Session_Repository() )->open_for( $this->learner, self::AU_ID ) );
		$this->assertSame(
			400,
			$this->launch(
				array(
					'lesson' => $this->lesson,
					'mode'   => 'Sideways',
				)
			)->get_status()
		);
	}

	public function test_browse_by_an_editor_uses_a_preview_registration() {
		$this->assertSame(
			403,
			$this->launch(
				array(
					'lesson' => $this->lesson,
					'mode'   => 'Browse',
				)
			)->get_status(),
			'learners cannot browse'
		);

		$calls = 0;
		add_action(
			'learning_ledger/cmi5_registration_created',
			static function () use ( &$calls ) {
				++$calls;
			}
		);
		$editor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $editor );
		$this->assertTrue( current_user_can( 'edit_post', $this->lesson ) );
		$data    = $this->launch_ok( 'Browse' );
		$preview = ( new Registration_Map() )->find( $editor, 'preview:' . $this->program . ':' . $editor );
		$this->assertNotNull( $preview );
		$this->assertSame( $preview, self::query_of( $data['launch_url'] )['registration'] );
		$this->assertNull( ( new Registration_Map() )->find( $editor, 'cmi5:' . $this->program ), 'never the Sensei registration' );
		$this->assertSame( 0, $calls, 'a preview registration is not announced' );
		$doc = json_decode( $this->launch_data( $editor, $preview )->body(), true );
		$this->assertSame( 'Browse', $doc['launchMode'] );
		$this->assertSame( 'Browse', $this->statements( Vocab::LAUNCHED )[0]['context']['extensions'][ Vocab::EXT_LAUNCHMODE ] );
	}

	public function test_review_needs_a_satisfied_au() {
		$this->assertSame(
			403,
			$this->launch(
				array(
					'lesson' => $this->lesson,
					'mode'   => 'Review',
				)
			)->get_status()
		);
		Sensei_Utils::sensei_start_lesson( $this->lesson, $this->learner, true );
		$this->launch_ok( 'Review' );
		$this->assertSame( 'Review', $this->session_for( $this->learner )['launch_mode'] );
	}

	public function test_lrs_failure_is_502_and_opens_nothing_usable() {
		$this->fail = 'activities/state';
		$res        = $this->launch( array( 'lesson' => $this->lesson ) );
		$this->assertSame( 502, $res->get_status() );
		$this->assertArrayNotHasKey( 'launch_url', (array) $res->get_data() );

		$this->assertNull( ( new Session_Repository() )->open_for( $this->learner, self::AU_ID ), 'no open session' );
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT status, fetch_hash FROM ' . Tables::name( 'cmi5_sessions' ), ARRAY_A );
		$this->assertCount( 1, $rows );
		$this->assertSame( Session_Repository::ABANDONED, $rows[0]['status'], 'closed at once' );
		$this->assertNull( $rows[0]['fetch_hash'], 'no fetch URL was issued' );
		$this->assertSame( array(), $this->statements(), 'neither launched nor abandoned' );

		$this->fail = null;
		$this->launch_ok();
		$this->assertSame( array(), $this->statements( Vocab::ABANDONED ), 'the closed attempt is not abandoned later' );
	}

	public function test_abandon_retry_after_a_failed_token_revoke_resends_the_same_statement() {
		$first = $this->launch_ok();
		$old   = $this->session_for( $this->learner );
		$this->assertArrayHasKey( 'auth-token', $this->fetch( self::fetch_token( $first['launch_url'] ) )->get_data() );

		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:01:00.000000', new DateTimeZone( 'UTC' ) ) );
		$this->fail = 'v1/tokens/';
		$this->assertSame( 502, $this->launch( array( 'lesson' => $this->lesson ) )->get_status() );
		$this->assertSame( Session_Repository::OPEN, ( new Session_Repository() )->find( $old['session_id'] )['status'] );

		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:02:00.000000', new DateTimeZone( 'UTC' ) ) );
		$this->fail = null;
		$this->launch_ok();

		$abandoned = $this->statements( Vocab::ABANDONED );
		$this->assertCount( 1, $abandoned );
		$this->assertSame( 'PT1M', $abandoned[0]['result']['duration'], 'the duration of the first attempt' );
		$closed = ( new Session_Repository() )->find( $old['session_id'] );
		$this->assertSame( Session_Repository::ABANDONED, $closed['status'] );
		$this->assertSame( '2026-10-27 10:01:00.000000', $closed['abandoned_at'] );
		global $wpdb;
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SELECT revoked_at FROM ' . Tables::name( 'tokens' ) . ' WHERE token_key = %s', $closed['token_key'] ) ) );
	}

	public function test_relaunch_after_terminated_marks_the_session_terminated() {
		$first                = $this->launch_ok();
		$old                  = $this->session_for( $this->learner );
		list( $key, $secret ) = explode( ':', (string) base64_decode( $this->fetch( self::fetch_token( $first['launch_url'] ) )->get_data()['auth-token'], true ), 2 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the returned credential.
		$terminated           = array(
			'actor'     => $this->actor( $this->learner ),
			'verb'      => array( 'id' => Vocab::TERMINATED ),
			'object'    => array(
				'objectType' => 'Activity',
				'id'         => Au::activity_id( self::AU_ID ),
			),
			'context'   => array(
				'registration'      => $old['registration'],
				'contextActivities' => array(
					'category' => array( array( 'id' => Vocab::CAT_CMI5 ) ),
					'grouping' => array( array( 'id' => self::AU_ID ) ),
				),
				'extensions'        => array( Vocab::EXT_SESSIONID => $old['session_id'] ),
			),
			'timestamp' => '2026-10-27T10:00:40.000Z',
		);
		$posted               = Xapi_Test_Client::as( $key, $secret )->request( 'POST', 'statements', array(), (string) wp_json_encode( $terminated ), array( 'Content-Type' => 'application/json' ) );
		$this->assertSame( 200, $posted->status(), $posted->body() );

		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:03:00.000000', new DateTimeZone( 'UTC' ) ) );
		$this->launch_ok();

		$this->assertSame( array(), $this->statements( Vocab::ABANDONED ), 'never abandoned after terminated' );
		$closed = ( new Session_Repository() )->find( $old['session_id'] );
		$this->assertSame( Session_Repository::TERMINATED, $closed['status'] );
		$this->assertSame( '2026-10-27 10:00:40.000000', $closed['terminated_at'] );
		global $wpdb;
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SELECT revoked_at FROM ' . Tables::name( 'tokens' ) . ' WHERE token_key = %s', $key ) ) );
	}

	public function test_return_fires_the_hook_then_redirects_to_the_lesson() {
		$this->launch_ok();
		$session = $this->session_for( $this->learner )['session_id'];
		$seen    = array();
		add_action(
			'learning_ledger/cmi5_return',
			static function ( $id ) use ( &$seen ) {
				$seen[] = $id;
			}
		);
		wp_set_current_user( 0 );
		$res = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/learning-ledger/v1/cmi5/return/' . $session ) );
		$this->assertSame( array( $session ), $seen );
		$this->assertSame( 302, $res->get_status() );
		$this->assertSame( get_permalink( $this->lesson ), $res->get_headers()['Location'] );

		$this->assertSame( 404, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/learning-ledger/v1/cmi5/return/' . wp_generate_uuid4() ) )->get_status() );
		$this->assertCount( 1, $seen );

		wp_set_current_user( $this->learner );
		$this->launch_ok();
		$this->assertSame( Session_Repository::ABANDONED, ( new Session_Repository() )->find( $session )['status'] );
		$closed = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/learning-ledger/v1/cmi5/return/' . $session ) );
		$this->assertSame( 302, $closed->get_status(), 'a closed session still redirects' );
		$this->assertCount( 1, $seen, 'but the hook fires only for an open session' );
	}
}
