<?php
/**
 * Tests for the CATAPULT LTS test-only routes.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- test fixtures.

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Connector\Pairing\Connector_Secret;
use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Player\Import\Player_Import_Module;
use LearningLedger\Player\Launch\Session_Repository;
use LearningLedger\Player\Session\Session_Module;
use LearningLedger\Player\Testing\Catapult_Routes;

require_once dirname( __DIR__ ) . '/Launch/test-launch.php';

/**
 * CATAPULT LTS test-only route tests.
 *
 * @requires function Sensei
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test class.
class Catapult_Routes_Test extends Lledger_Launch_Test_Case {

	const SECRET = 'a-test-secret-of-forty-characters-0123456';

	/**
	 * Directory standing in for the LTS package build.
	 *
	 * @var string
	 */
	private string $dist;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$this->dist = trailingslashit( get_temp_dir() ) . 'llcatapult-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dist );
		( new Player_Import_Module() )->register();
		( new Session_Module() )->register();
		( new Catapult_Routes( true, self::SECRET, $this->dist ) )->register();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- reset so the next dispatch registers the routes.
	}

	public function tear_down(): void {
		foreach ( (array) glob( $this->dist . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dist );
		parent::tear_down();
	}

	private function call( string $route, array $body, ?string $secret = self::SECRET ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/learning-ledger/v1/testing/catapult/' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( null !== $secret ) {
			$request->set_header( 'X-Learning-Ledger-Test', $secret );
		}
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_routes_exist_only_when_enabled() {
		global $wp_rest_server;
		remove_all_actions( 'rest_api_init' );
		( new Catapult_Routes( false, self::SECRET, $this->dist ) )->register();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- reset so the next dispatch registers the routes.
		$this->assertSame( 404, $this->call( 'setup', array() )->get_status() );

		// The default reads LLEDGER_CATAPULT_TEST_ROUTES, which the test site does not define.
		$this->assertFalse( defined( 'LLEDGER_CATAPULT_TEST_ROUTES' ) );
		remove_all_actions( 'rest_api_init' );
		( new Catapult_Routes() )->register();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- reset so the next dispatch registers the routes.
		$this->assertSame( 404, $this->call( 'setup', array() )->get_status() );
	}

	public function test_every_route_needs_the_secret() {
		foreach ( array( 'setup', 'import', 'launch-url', 'waive', 'abandon', 'cleanup' ) as $route ) {
			$this->assertSame( 403, $this->call( $route, array(), null )->get_status(), $route );
			$this->assertSame( 403, $this->call( $route, array(), self::SECRET . 'x' )->get_status(), $route );
			$this->assertSame( 403, $this->call( $route, array(), '' )->get_status(), $route );
		}
	}

	public function test_a_short_secret_never_authorises() {
		global $wp_rest_server;
		remove_all_actions( 'rest_api_init' );
		( new Catapult_Routes( true, 'short', $this->dist ) )->register();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- reset so the next dispatch registers the routes.
		$this->assertSame( 403, $this->call( 'setup', array(), 'short' )->get_status() );
	}

	public function test_setup_pairs_the_connector_with_this_sites_lrs_and_creates_the_learner() {
		$res = $this->call( 'setup', array() );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$user = (int) $res->get_data()['user'];
		$this->assertStringStartsWith( Catapult_Routes::LEARNER, get_userdata( $user )->user_login );
		$this->assertSame( array( 'subscriber' ), get_userdata( $user )->roles );

		$settings = Connector_Settings::get();
		$this->assertSame( home_url( '/wp-json/learning-ledger/xapi/' ), $settings['lrs_url'] );
		$this->assertSame( home_url( '/wp-json/learning-ledger/v1/' ), $settings['api_base'] );
		$this->assertSame( '', $settings['loopback_url'] );
		$this->assertSame( Catapult_Routes::CLIENT_ID, $settings['client_id'] );
		$client = ( new Client_Repository() )->find( Catapult_Routes::CLIENT_ID );
		$this->assertNotNull( $client );
		$this->assertTrue( $client->secret_matches( (string) Connector_Secret::get() ) );
		$this->assertTrue( $client->holds( 'tokens/mint' ) );

		$again = $this->call( 'setup', array() );
		$this->assertSame( 200, $again->get_status() );
		$this->assertNotSame( $user, (int) $again->get_data()['user'], 'each run gets a learner of its own' );
		$this->assertSame( Catapult_Routes::CLIENT_ID, Connector_Settings::get()['client_id'] );
	}

	public function test_setup_needs_both_roles() {
		update_option( 'lledger_roles', array( 'connector' ) );
		$this->assertSame( 409, $this->call( 'setup', array() )->get_status() );
	}

	public function test_import_reads_only_package_files_by_name() {
		foreach ( array( '../wp-config.php', 'a/b.zip', '.hidden', '', 'x..zip' ) as $name ) {
			$this->assertSame( 400, $this->call( 'import', array( 'package' => $name ) )->get_status(), $name );
		}
		$this->assertSame( 404, $this->call( 'import', array( 'package' => 'missing.zip' ) )->get_status() );
	}

	public function test_import_imports_extracts_and_enrols_the_learner() {
		$xml = (string) file_get_contents( self::FIX . '/002-allowed/cmi5.xml' );
		$zip = Package_Fixtures::zip(
			static function ( ZipArchive $z ) use ( $xml ) {
				$z->addFromString( 'cmi5.xml', $xml );
				$z->addFromString( 'index.html', (string) file_get_contents( self::FIX . '/index.html' ) );
			}
		);
		rename( $zip, $this->dist . '/002-allowed.zip' );
		file_put_contents( $this->dist . '/207-1-invalid-courseStructure.xml', (string) file_get_contents( self::FIX . '/207-1-invalid-courseStructure.xml' ) );

		$learner = (int) $this->call( 'setup', array() )->get_data()['user'];
		$res     = $this->call(
			'import',
			array(
				'package' => '002-allowed.zip',
				'user'    => $learner,
			)
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$program = (int) $res->get_data()['course'];
		$record  = ( new Program_Repository() )->get( $program );
		$this->assertNotNull( $record );
		$this->assertTrue( (bool) (int) get_post_meta( $program, '_lledger_player', true ) );
		foreach ( $record->course_ids as $course ) {
			$this->assertTrue( Sensei_Course::is_user_enrolled( $course, $learner ) );
		}

		// Only test learners are enrolled.
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->call(
			'import',
			array(
				'package' => '002-allowed.zip',
				'user'    => $other,
			)
		);
		$this->assertFalse( Sensei_Course::is_user_enrolled( $record->course_ids[0], $other ) );
		$url = ( new Program_Repository() )->structure( $program )->aus()[0]->url();
		$this->assertNotNull( LearningLedger\Player\Store\Au_Store::lookup( (int) get_post_meta( $program, '_lledger_cmi5_package', true ), 'index.html' ), 'the files are extracted before the answer: ' . $url );

		$bad = $this->call( 'import', array( 'package' => '207-1-invalid-courseStructure.xml' ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertNotEmpty( $bad->get_data()['errors'] );
	}

	public function test_launch_url_launches_the_au_at_an_index_in_any_mode() {
		$res = $this->call(
			'launch-url',
			array(
				'course'     => $this->program,
				'au'         => 0,
				'user'       => $this->learner,
				'launchMode' => 'Review',
			)
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$query = self::query_of( $res->get_data()['url'] );
		$this->assertSame( Au::activity_id( self::AU_ID ), $query['activityId'] );
		$session = ( new Session_Repository() )->open_for( $this->learner, self::AU_ID );
		$this->assertSame( 'Review', $session['launch_mode'], 'Review without the AU being satisfied: the mode rules are off' );
		$this->assertSame( $session['registration'], $query['registration'] );

		$same = $this->call(
			'launch-url',
			array(
				'course'       => $this->program,
				'au'           => 0,
				'user'         => $this->learner,
				'registration' => strtoupper( $query['registration'] ),
			)
		);
		$this->assertSame( 200, $same->get_status(), 'the same registration' );

		$other = $this->call(
			'launch-url',
			array(
				'course'       => $this->program,
				'au'           => 0,
				'user'         => $this->learner,
				'registration' => wp_generate_uuid4(),
			)
		);
		$this->assertSame( 409, $other->get_status() );
		$this->assertSame(
			404,
			$this->call(
				'launch-url',
				array(
					'course' => $this->program,
					'au'     => 1,
					'user'   => $this->learner,
				)
			)->get_status()
		);
	}

	public function test_waive_waives_the_au_at_an_index_for_the_registrations_learner() {
		$launch       = $this->call(
			'launch-url',
			array(
				'course' => $this->program,
				'au'     => 0,
				'user'   => $this->learner,
			)
		);
		$registration = self::query_of( $launch->get_data()['url'] )['registration'];

		$this->assertSame(
			400,
			$this->call(
				'waive',
				array(
					'registration' => $registration,
					'au'           => 0,
					'reason'       => 'Because',
				)
			)->get_status()
		);
		$this->assertSame(
			404,
			$this->call(
				'waive',
				array(
					'registration' => wp_generate_uuid4(),
					'au'           => 0,
					'reason'       => 'Administrative',
				)
			)->get_status()
		);
		$res = $this->call(
			'waive',
			array(
				'registration' => $registration,
				'au'           => 0,
				'reason'       => 'Administrative',
			)
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$waived = $this->statements( Vocab::WAIVED );
		$this->assertCount( 1, $waived );
		$this->assertSame( 'Administrative', $waived[0]['result']['extensions'][ Vocab::EXT_REASON ] );
		$this->assertSame( $registration, $waived[0]['context']['registration'] );
	}

	public function test_abandon_abandons_an_open_session_now() {
		$this->call(
			'launch-url',
			array(
				'course' => $this->program,
				'au'     => 0,
				'user'   => $this->learner,
			)
		);
		$session = ( new Session_Repository() )->open_for( $this->learner, self::AU_ID );

		$res = $this->call( 'abandon', array( 'session' => $session['session_id'] ) );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertTrue( $res->get_data()['abandoned'] );
		$this->assertSame( Session_Repository::ABANDONED, ( new Session_Repository() )->find( $session['session_id'] )['status'] );
		$abandoned = $this->statements( Vocab::ABANDONED );
		$this->assertCount( 1, $abandoned );
		$this->assertSame( $session['session_id'], $abandoned[0]['context']['extensions'][ Vocab::EXT_SESSIONID ] );

		$this->assertSame( 404, $this->call( 'abandon', array( 'session' => $session['session_id'] ) )->get_status(), 'no longer open' );
		$this->assertSame( 400, $this->call( 'abandon', array( 'session' => 'nope' ) )->get_status() );
	}

	public function test_cleanup_deletes_the_program_with_its_courses_and_lessons() {
		$record = ( new Program_Repository() )->get( $this->program );
		$res    = $this->call( 'cleanup', array( 'courses' => array( $this->program, 999999 ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( $this->program ), $res->get_data()['deleted'] );
		$this->assertNull( get_post( $this->program ) );
		foreach ( $record->course_ids as $course ) {
			$this->assertNull( get_post( $course ) );
		}
		$this->assertNull( get_post( $this->lesson ) );
	}
}
