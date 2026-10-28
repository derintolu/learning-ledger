<?php
/**
 * Activities and Agents resource tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Auth\Token_Repository;
use LearningLedger\Lrs\Auth\Token_Spec;
use LearningLedger\Lrs\Http\Xapi_Router;
use LearningLedger\Lrs\Resources\Activity_Repository;
use LearningLedger\Lrs\Resources\Agent_Repository;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Tests\Xapi_Test_Client;
use LearningLedger\Xapi\Agent_Identity;

class Activities_Agents_Handlers_Test extends WP_UnitTestCase {

	const IRI = 'https://content.example/act/1';
	const REG = '6f1c3c1e-9a5e-4c1b-8d4e-2b7f0c9a1d11';

	public function set_up() {
		parent::set_up();
		Installer::install();
		( new Xapi_Router() )->register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	private static function learner( string $name ): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => 'https://lms.example',
				'name'     => $name,
			),
		);
	}

	private function reader(): Xapi_Test_Client {
		$c = Client_Factory::create( array( Scopes::STATEMENTS_READ ) );
		return Xapi_Test_Client::as( $c['id'], $c['secret'] );
	}

	public function test_both_require_authentication() {
		$this->assertSame( 401, Xapi_Test_Client::request( 'GET', 'activities', array( 'activityId' => self::IRI ) )->status() );
		$this->assertSame( 401, Xapi_Test_Client::request( 'GET', 'agents', array( 'agent' => wp_json_encode( self::learner( 'a' ) ) ) )->status() );
	}

	public function test_activity_returns_the_merged_definition() {
		$repo = new Activity_Repository();
		$repo->merge( self::IRI, array( 'name' => array( 'en-US' => 'One' ) ), true );
		$repo->merge( self::IRI, array( 'name' => array( 'de' => 'Eins' ) ), true );

		$res = $this->reader()->request( 'GET', 'activities', array( 'activityId' => self::IRI ) );

		$this->assertSame( 200, $res->status() );
		$this->assertSame(
			array(
				'objectType' => 'Activity',
				'id'         => self::IRI,
				'definition' => array(
					'name' => array(
						'en-US' => 'One',
						'de'    => 'Eins',
					),
				),
			),
			json_decode( $res->body(), true )
		);
	}

	public function test_unknown_activity_is_minimal() {
		$res = $this->reader()->request( 'GET', 'activities', array( 'activityId' => 'https://content.example/nope' ) );

		$this->assertSame( 200, $res->status() );
		$this->assertSame(
			array(
				'objectType' => 'Activity',
				'id'         => 'https://content.example/nope',
			),
			json_decode( $res->body(), true )
		);
	}

	public function test_activity_id_is_required() {
		$this->assertSame( 400, $this->reader()->request( 'GET', 'activities' )->status() );
	}

	public function test_agent_returns_a_person_with_the_ifi() {
		$a = Agent_Identity::from_array( self::learner( 'learner-1' ) );
		( new Agent_Repository() )->record( $a );

		$res    = $this->reader()->request( 'GET', 'agents', array( 'agent' => wp_json_encode( self::learner( 'learner-1' ) ) ) );
		$person = json_decode( $res->body(), true );

		$this->assertSame( 200, $res->status() );
		$this->assertSame( 'Person', $person['objectType'] );
		$this->assertSame( array( self::learner( 'learner-1' )['account'] ), $person['account'] );
		$this->assertSame( array(), $person['mbox'] );
	}

	public function test_bound_client_sees_only_linked_ifis_inside_its_bindings() {
		$repo    = new Agent_Repository();
		$mine    = Agent_Identity::from_array( self::learner( 'learner-1' ) );
		$mbox    = Agent_Identity::from_array( array( 'mbox' => 'mailto:learner@other.example' ) );
		$foreign = Agent_Identity::from_array(
			array(
				'account' => array(
					'homePage' => 'https://other-lms.example',
					'name'     => 'learner-1',
				),
			)
		);
		global $wpdb;
		$table = Tables::name( 'agents' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array( $mine, $mbox, $foreign ) as $a ) {
			$repo->record( $a );
		}
		$person = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE ifi_hash = UNHEX(%s)', $table, bin2hex( $mine->hash() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET person_id = %d', $table, $person ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$query = array( 'agent' => wp_json_encode( self::learner( 'learner-1' ) ) );

		$all = json_decode( $this->reader()->request( 'GET', 'agents', $query )->body(), true );
		$this->assertSame( array( 'mailto:learner@other.example' ), $all['mbox'], 'an unbound reader sees the whole person' );
		$this->assertCount( 2, $all['account'] );

		$c     = Client_Factory::create( array( Scopes::STATEMENTS_READ ), array( 'bindings' => array( 'actor_home_page' => 'https://lms.example' ) ) );
		$res   = Xapi_Test_Client::as( $c['id'], $c['secret'] )->request( 'GET', 'agents', $query );
		$bound = json_decode( $res->body(), true );
		$this->assertSame( 200, $res->status() );
		$this->assertSame( array( self::learner( 'learner-1' )['account'] ), $bound['account'] );
		$this->assertSame( array(), $bound['mbox'] );
	}

	public function test_account_stored_with_reversed_key_order_is_listed_once() {
		$a = Agent_Identity::from_array( self::learner( 'learner-1' ) );
		( new Agent_Repository() )->record( $a );
		// MySQL 8 JSON columns hand an account back with its keys sorted by length: {name, homePage}.
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE %i SET ifi = %s WHERE ifi_hash = UNHEX(%s)',
				Tables::name( 'agents' ),
				'{"account":{"name":"learner-1","homePage":"https://lms.example"}}',
				bin2hex( $a->hash() )
			)
		);

		$res    = $this->reader()->request( 'GET', 'agents', array( 'agent' => wp_json_encode( self::learner( 'learner-1' ) ) ) );
		$person = json_decode( $res->body(), true );

		$this->assertSame( 200, $res->status() );
		$this->assertCount( 1, $person['account'] );
	}

	public function test_unknown_agent_still_gets_its_own_ifi() {
		$res    = $this->reader()->request( 'GET', 'agents', array( 'agent' => wp_json_encode( array( 'mbox' => 'mailto:nobody@example.com' ) ) ) );
		$person = json_decode( $res->body(), true );

		$this->assertSame( 200, $res->status() );
		$this->assertSame( array( 'mailto:nobody@example.com' ), $person['mbox'] );
	}

	public function test_malformed_agent_is_400() {
		$this->assertSame( 400, $this->reader()->request( 'GET', 'agents', array( 'agent' => '{nope' ) )->status() );
		$this->assertSame( 400, $this->reader()->request( 'GET', 'agents', array( 'agent' => '{"objectType":"Agent"}' ) )->status() );
		$this->assertSame( 400, $this->reader()->request( 'GET', 'agents' )->status() );
	}

	public function test_child_token_reads_only_its_own_agent() {
		$c      = Client_Factory::create( array( Scopes::TOKENS_MINT, Scopes::STATEMENTS_READ_MINE ) );
		$minted = ( new Token_Repository() )->mint(
			( new Client_Repository() )->find( $c['id'] ),
			new Token_Spec( self::REG, self::learner( 'learner-1' ), 'https://lms.example/au/1', '0b6c7a52-5d0e-4c3a-9f1e-6a2b3c4d5e6f', 'Normal', 600, array() )
		);
		$child  = Xapi_Test_Client::as( $minted['key'], $minted['secret'] );

		$own = $child->request( 'GET', 'agents', array( 'agent' => wp_json_encode( self::learner( 'learner-1' ) ) ) );
		$this->assertSame( 200, $own->status() );

		$other = $child->request( 'GET', 'agents', array( 'agent' => wp_json_encode( self::learner( 'learner-2' ) ) ) );
		$this->assertSame( 403, $other->status() );
		$this->assertTrue( json_decode( $other->body(), true )['error'] );

		$this->assertSame( 200, $child->request( 'GET', 'activities', array( 'activityId' => self::IRI ) )->status() );
	}
}
