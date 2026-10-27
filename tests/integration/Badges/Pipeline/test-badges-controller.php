<?php
/**
 * Badges_Controller tests (GET /learning-ledger/v1/badges).
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Badges\Pipeline\Pipeline_Module;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

class Badges_Controller_Test extends WP_UnitTestCase {

	const ROUTE  = '/learning-ledger/v1/badges';
	const COURSE = 'https://example.org/courses/badges';
	const HOME   = 'https://site.example';

	private string $registration;

	private string $badge_id;

	private array $server_backup;

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'badges', 'jobs' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$this->server_backup = $_SERVER;

		$this->registration = Uuid::v4();
		$writer             = Client_Factory::create( array( Scopes::STATEMENTS_WRITE ), array( 'trusted' => true ) );
		[ $trigger ]        = ( new Statement_Store() )->store(
			array(
				array(
					'actor'   => self::actor(),
					'verb'    => array( 'id' => 'https://w3id.org/xapi/adl/verbs/satisfied' ),
					'object'  => array( 'id' => self::COURSE ),
					'context' => array( 'registration' => $this->registration ),
				),
			),
			Principal::for_client( ( new Client_Repository() )->find( $writer['id'] ) ),
			'1.0.3'
		);
		$this->badge_id     = ( new Badge_Repository() )->create_pending(
			array(
				'id'              => 'https://example.org/badges/one',
				'name'            => 'Badge one',
				'description'     => 'Earned one.',
				'criteria'        => array( 'narrative' => 'Do one.' ),
				'image'           => 'https://example.org/img/one.png',
				'achievementType' => 'Badge',
			),
			$this->registration,
			( new Statement_Repository() )->find( $trigger )->actor_hash,
			self::COURSE,
			$trigger,
			'1 of 1 lessons completed',
			0
		);

		( new Pipeline_Module() )->register();
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		$_SERVER                   = $this->server_backup;
		$GLOBALS['wp_rest_server'] = null;
		remove_all_actions( 'learning_ledger/statements_committed' );
		remove_all_actions( 'learning_ledger/jobs/badge_evaluate' );
		parent::tear_down();
	}

	private static function actor( string $name = 'learner-1' ): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => self::HOME,
				'name'     => $name,
			),
		);
	}

	private function get( array $client, ?string $registration = null ): WP_REST_Response {
		$r = new WP_REST_Request( 'GET', self::ROUTE );
		$r->set_header( 'Authorization', 'Basic ' . base64_encode( $client['id'] . ':' . $client['secret'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$r->set_query_params( array( 'registration' => $registration ?? $this->registration ) );
		return rest_get_server()->dispatch( $r );
	}

	private static function keys_deep( array $a ): array {
		$keys = array();
		foreach ( $a as $k => $v ) {
			$keys[] = (string) $k;
			if ( is_array( $v ) ) {
				$keys = array_merge( $keys, self::keys_deep( $v ) );
			}
		}
		return $keys;
	}

	public function test_lists_the_pending_badge_without_any_url_field() {
		$c   = Client_Factory::create( array( Scopes::BADGES_CLAIM_LINKS ) );
		$res = $this->get( $c );

		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data  = $res->get_data();
		$badge = ( new Badge_Repository() )->get( $this->badge_id );
		$this->assertSame(
			array(
				array(
					'id'          => $this->badge_id,
					'achievement' => array(
						'id'    => 'https://example.org/badges/one',
						'name'  => 'Badge one',
						'image' => 'https://example.org/img/one.png',
					),
					'status'      => 'pending',
					'created_at'  => $badge['created_at'],
				),
			),
			$data
		);
		foreach ( self::keys_deep( $data ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/url|link|claim|token/i', $key );
		}
		$this->assertSame( 'no-store', $res->get_headers()['Cache-Control'] );
	}

	public function test_badges_issue_scope_is_also_accepted() {
		$c = Client_Factory::create( array( Scopes::BADGES_ISSUE ) );
		$this->assertSame( 200, $this->get( $c )->get_status() );
	}

	public function test_without_a_badge_scope_is_403_and_bad_secret_is_401() {
		$c = Client_Factory::create( array( Scopes::ALL ) );
		$this->assertSame( 403, $this->get( $c )->get_status() );

		$c           = Client_Factory::create( array( Scopes::BADGES_CLAIM_LINKS ) );
		$c['secret'] = 'wrong';
		$res         = $this->get( $c );
		$this->assertSame( 401, $res->get_status() );
		$this->assertArrayHasKey( 'WWW-Authenticate', $res->get_headers() );
	}

	public function test_registration_actor_outside_the_binding_is_403() {
		$other = Client_Factory::create(
			array( Scopes::BADGES_CLAIM_LINKS ),
			array( 'bindings' => array( 'actor_ifi' => Agent_Identity::from_array( self::actor( 'someone-else' ) )->ifi_key() ) )
		);
		$this->assertSame( 403, $this->get( $other )->get_status() );

		$foreign_site = Client_Factory::create( array( Scopes::BADGES_CLAIM_LINKS ), array( 'bindings' => array( 'actor_home_page' => 'https://other.example' ) ) );
		$this->assertSame( 403, $this->get( $foreign_site )->get_status() );

		$other_registration = Client_Factory::create( array( Scopes::BADGES_CLAIM_LINKS ), array( 'bindings' => array( 'registration' => Uuid::v4() ) ) );
		$this->assertSame( 403, $this->get( $other_registration )->get_status() );

		$same = Client_Factory::create( array( Scopes::BADGES_CLAIM_LINKS ), array( 'bindings' => array( 'actor_home_page' => self::HOME ) ) );
		$res  = $this->get( $same );
		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 1, $res->get_data() );
	}

	public function test_unknown_registration_is_an_empty_list_and_invalid_one_is_400() {
		$c   = Client_Factory::create( array( Scopes::BADGES_CLAIM_LINKS ) );
		$res = $this->get( $c, Uuid::v4() );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array(), $res->get_data() );

		$this->assertSame( 400, $this->get( $c, 'not-a-uuid' )->get_status() );
	}
}
