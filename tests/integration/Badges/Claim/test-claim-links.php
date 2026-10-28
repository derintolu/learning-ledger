<?php
/**
 * Claim link route tests (POST /learning-ledger/v1/badges/{id}/claim-links).
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Claim\Claim_Module;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Crypto\Tokens;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Links\Link_Repository;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;

class Claim_Links_Test extends WP_UnitTestCase {

	const COURSE = 'https://example.org/courses/badges';
	const HOME   = 'https://site.example';

	private string $badge_id;

	private \DateTimeImmutable $t0;

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'statements', 'statement_refs', 'pending_voids', 'activities', 'agents', 'badges', 'links', 'jobs', 'ratelimit' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$this->t0 = new \DateTimeImmutable( gmdate( 'Y-m-d\TH:i:s' ) . '.000000Z' );
		Clock::freeze( $this->t0 );

		$this->badge_id = self::pending_badge( self::actor() );

		( new Claim_Module() )->register();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Clock::freeze( null );
		parent::tear_down();
	}

	private static function actor( string $name = 'learner-1', string $home = self::HOME ): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => $home,
				'name'     => $name,
			),
		);
	}

	/**
	 * Store a trusted satisfied statement and a pending badge for it; returns the badge id.
	 */
	private static function pending_badge( array $actor, string $status = Badge_Repository::PENDING ): string {
		$registration = Uuid::v4();
		$writer       = Client_Factory::create( array( Scopes::STATEMENTS_WRITE ), array( 'trusted' => true ) );
		[ $trigger ]  = ( new Statement_Store() )->store(
			array(
				array(
					'actor'   => $actor,
					'verb'    => array( 'id' => 'https://w3id.org/xapi/adl/verbs/satisfied' ),
					'object'  => array( 'id' => self::COURSE ),
					'context' => array( 'registration' => $registration ),
				),
			),
			Principal::for_client( ( new Client_Repository() )->find( $writer['id'] ) ),
			'1.0.3'
		);
		return (string) ( new Badge_Repository() )->create_pending(
			array(
				'id'          => 'https://example.org/badges/one',
				'name'        => 'Badge one',
				'description' => 'Earned one.',
				'criteria'    => array( 'narrative' => 'Do one.' ),
			),
			$registration,
			( new Statement_Repository() )->find( $trigger )->actor_hash,
			self::COURSE,
			$trigger,
			'1 of 1 lessons completed',
			0,
			$status
		);
	}

	private static function minter( array $scopes = array( Scopes::BADGES_CLAIM_LINKS ), array $bindings = array( 'actor_home_page' => self::HOME ) ): array {
		return Client_Factory::create( $scopes, array( 'bindings' => $bindings ) );
	}

	private function post( array $client, mixed $body, ?string $badge_id = null ): WP_REST_Response {
		$r = new WP_REST_Request( 'POST', '/learning-ledger/v1/badges/' . ( $badge_id ?? $this->badge_id ) . '/claim-links' );
		if ( array() !== $client ) {
			$r->set_header( 'Authorization', 'Basic ' . base64_encode( $client['id'] . ':' . $client['secret'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $r );
	}

	private static function links(): array {
		return (array) $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Tables::name( 'links' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function token_of( WP_REST_Response $res ): string {
		$m = array();
		preg_match( '#/badges/claim/([A-Za-z0-9_-]{43})$#', (string) $res->get_data()['url'], $m );
		return $m[1] ?? '';
	}

	public function test_without_credentials_is_401_with_www_authenticate() {
		$res = $this->post( array(), array( 'actor' => self::actor() ) );

		$this->assertSame( 401, $res->get_status() );
		$this->assertArrayHasKey( 'WWW-Authenticate', $res->get_headers() );
		$this->assertSame( array(), self::links() );
	}

	public function test_requires_the_badges_claim_links_scope() {
		$res = $this->post( self::minter( array( Scopes::ALL, Scopes::BADGES_ISSUE, Scopes::RECORDS_VIEW_LINKS ) ), array( 'actor' => self::actor() ) );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( array(), self::links() );
	}

	public function test_actor_outside_the_binding_is_403() {
		$other = self::pending_badge( self::actor( 'learner-1', 'https://other.example' ) );
		$res   = $this->post( self::minter(), array( 'actor' => self::actor( 'learner-1', 'https://other.example' ) ), $other );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'actor', $res->get_data()['data']['field'] );
		$this->assertSame( array(), self::links() );
	}

	public function test_actor_who_is_not_the_badges_actor_is_404() {
		$res = $this->post( self::minter(), array( 'actor' => self::actor( 'learner-2' ) ) );

		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( array(), self::links() );
	}

	public function test_unknown_badge_is_404() {
		$res = $this->post( self::minter(), array( 'actor' => self::actor() ), Uuid::v4() );

		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( array(), self::links() );
	}

	public function test_without_a_valid_actor_is_400() {
		foreach ( array( array(), array( 'actor' => 'learner-1' ), array( 'actor' => array( 'name' => 'no identifier' ) ) ) as $body ) {
			$res = $this->post( self::minter(), $body );
			$this->assertSame( 400, $res->get_status(), (string) wp_json_encode( $body ) );
		}
		$this->assertSame( array(), self::links() );
	}

	public function test_mints_a_single_use_claim_link_for_fifteen_minutes() {
		$client = self::minter();
		$res    = $this->post( $client, array( 'actor' => self::actor() ) );

		$this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'no-store', $res->get_headers()['Cache-Control'] );
		$data = $res->get_data();
		$this->assertSame( array( 'url', 'expires_at' ), array_keys( $data ) );
		$this->assertSame( Timestamp::to_xapi( $this->t0->modify( '+900 seconds' ) ), $data['expires_at'] );
		$this->assertSame( 1, preg_match( '#\A' . preg_quote( home_url( '/badges/claim/' ), '#' ) . '([A-Za-z0-9_-]{43})\z#', $data['url'], $m ) );
		$token = $m[1];

		$rows = self::links();
		$this->assertCount( 1, $rows );
		$this->assertSame( Tokens::hash( $token ), $rows[0]['token_hash'] );
		$this->assertSame( 'claim', $rows[0]['kind'] );
		$this->assertSame( Agent_Identity::from_array( self::actor() )->hash(), $rows[0]['actor_hash'] );
		$this->assertSame( $this->badge_id, $rows[0]['subject'] );
		$this->assertSame( 'client:' . $client['id'], $rows[0]['created_by'] );
		$this->assertNull( $rows[0]['used_at'] );
		foreach ( $rows[0] as $value ) {
			$this->assertStringNotContainsString( $token, (string) $value );
		}

		$links = new Link_Repository();
		$this->assertNotNull( $links->resolve( $token, 'claim' ) );
		Clock::freeze( $this->t0->modify( '+900 seconds' ) );
		$this->assertNull( $links->resolve( $token, 'claim' ) );
	}

	public function test_minting_again_invalidates_the_first_link() {
		$client = self::minter();
		$first  = self::token_of( $this->post( $client, array( 'actor' => self::actor() ) ) );
		$second = self::token_of( $this->post( $client, array( 'actor' => self::actor() ) ) );

		$this->assertNotSame( '', $first );
		$this->assertNotSame( $first, $second );
		$links = new Link_Repository();
		$this->assertNull( $links->resolve( $first, 'claim' ) );
		$this->assertNotNull( $links->resolve( $second, 'claim' ) );
		$this->assertCount( 1, self::links() );
	}

	public function test_issued_badge_is_409() {
		( new Badge_Repository() )->set_status( $this->badge_id, Badge_Repository::ISSUED );

		$res = $this->post( self::minter(), array( 'actor' => self::actor() ) );

		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( array(), self::links() );
	}

	public function test_held_or_revoked_badge_is_409() {
		$held = self::pending_badge( self::actor( 'learner-3' ), Badge_Repository::HELD );
		$this->assertSame( 409, $this->post( self::minter(), array( 'actor' => self::actor( 'learner-3' ) ), $held )->get_status() );

		( new Badge_Repository() )->set_status( $this->badge_id, Badge_Repository::REVOKED );
		$this->assertSame( 409, $this->post( self::minter(), array( 'actor' => self::actor() ) )->get_status() );
		$this->assertSame( array(), self::links() );
	}

	public function test_registration_bound_client_cannot_mint_for_another_registration() {
		$client = self::minter(
			array( Scopes::BADGES_CLAIM_LINKS ),
			array(
				'actor_home_page' => self::HOME,
				'registration'    => Uuid::v4(),
			)
		);

		$res = $this->post( $client, array( 'actor' => self::actor() ) );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( array(), self::links() );
	}
}
