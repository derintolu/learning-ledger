<?php
/**
 * Agent Profile and Activity Profile resource tests, through the xAPI router.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Http\Xapi_Router;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Tests\Xapi_Test_Client;
use LearningLedger\Util\Clock;

class Profile_Handlers_Test extends WP_UnitTestCase {

	const ACT = 'https://example.com/activities/a1';

	public function set_up() {
		parent::set_up();
		Installer::install();
		( new Xapi_Router() )->register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	public function tear_down() {
		Clock::freeze( null );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	private static function client( array $scopes, array $overrides = array() ): Xapi_Test_Client {
		$c = Client_Factory::create( $scopes, $overrides );
		return Xapi_Test_Client::as( $c['id'], $c['secret'] );
	}

	private static function agent_json( string $mbox = 'mailto:learner@example.com' ): string {
		return wp_json_encode(
			array(
				'objectType' => 'Agent',
				'mbox'       => $mbox,
			)
		);
	}

	/**
	 * Both profile resources, with the parameters that identify a document's context.
	 *
	 * @return array<string,array{0:string,1:array}>
	 */
	public static function resources(): array {
		return array(
			'agent profile'    => array( 'agents/profile', array( 'agent' => self::agent_json() ) ),
			'activity profile' => array( 'activities/profile', array( 'activityId' => self::ACT ) ),
		);
	}

	/**
	 * @dataProvider resources
	 */
	public function test_crud_with_etags( string $path, array $context ) {
		$c = self::client( array( Scopes::PROFILE ) );
		$p = array_merge( $context, array( 'profileId' => 'p1' ) );

		$this->assertSame(
			204,
			$c->request(
				'PUT',
				$path,
				$p,
				'{"a":1}',
				array(
					'Content-Type'  => 'application/json',
					'If-None-Match' => '*',
				)
			)->status()
		);
		$this->assertSame(
			412,
			$c->request(
				'PUT',
				$path,
				$p,
				'{"a":2}',
				array(
					'Content-Type'  => 'application/json',
					'If-None-Match' => '*',
				)
			)->status()
		);

		$res = $c->request( 'GET', $path, $p );
		$this->assertSame( 200, $res->status() );
		$this->assertSame( '{"a":1}', $res->body() );
		$this->assertSame( 'application/json', $res->content_type() );
		$etag = $res->headers()['ETag'];
		$this->assertSame( '"' . sha1( '{"a":1}' ) . '"', $etag );

		$this->assertSame(
			412,
			$c->request(
				'PUT',
				$path,
				$p,
				'{"a":2}',
				array(
					'Content-Type' => 'application/json',
					'If-Match'     => '"' . sha1( 'x' ) . '"',
				)
			)->status()
		);
		$this->assertSame(
			204,
			$c->request(
				'PUT',
				$path,
				$p,
				'{"a":2}',
				array(
					'Content-Type' => 'application/json',
					'If-Match'     => $etag,
				)
			)->status()
		);
		$this->assertSame( 204, $c->request( 'POST', $path, $p, '{"b":3}', array( 'Content-Type' => 'application/json' ) )->status() );
		$this->assertSame( '{"a":2,"b":3}', $c->request( 'GET', $path, $p )->body() );

		$this->assertSame( 412, $c->request( 'DELETE', $path, $p, null, array( 'If-Match' => $etag ) )->status() );
		$this->assertSame( 204, $c->request( 'DELETE', $path, $p )->status() );
		$this->assertSame( 404, $c->request( 'GET', $path, $p )->status() );

		// Profiles have no delete-all, and PUT/POST/DELETE need a profileId.
		$this->assertSame( 400, $c->request( 'DELETE', $path, $context )->status() );
		$this->assertSame( 400, $c->request( 'PUT', $path, $context, '{}', array( 'Content-Type' => 'application/json' ) )->status() );
		$this->assertSame( 400, $c->request( 'POST', $path, $context, '{}', array( 'Content-Type' => 'application/json' ) )->status() );
	}

	/**
	 * @dataProvider resources
	 */
	public function test_put_without_precondition_on_existing_409_in_both_versions( string $path, array $context ) {
		$c = self::client( array( Scopes::PROFILE ) );
		$p = array_merge( $context, array( 'profileId' => 'p1' ) );

		// Creating with no precondition is fine.
		$this->assertSame( 204, $c->request( 'PUT', $path, $p, 'one', array( 'Content-Type' => 'text/plain' ) )->status() );
		foreach ( array( '2.0.0', '1.0.3' ) as $version ) {
			$res = $c->request(
				'PUT',
				$path,
				$p,
				'two',
				array(
					'Content-Type'             => 'text/plain',
					'X-Experience-API-Version' => $version,
				)
			);
			$this->assertSame( 409, $res->status(), $version );
			$this->assertNotSame( '', $res->body() );
		}
		$this->assertSame( 'one', $c->request( 'GET', $path, $p )->body() );
	}

	/**
	 * XAPI-00273 (agent profile) and XAPI-00287 / XAPI-00293 (activity profile): under 1.0.3
	 * a PUT with neither If-Match nor If-None-Match is 400 for a new document (409 for an
	 * existing one, above), and nothing is stored. Under 2.0 creating without one stays allowed.
	 *
	 * @dataProvider resources
	 */
	public function test_xapi_00273_put_without_precondition_on_new_document_400_under_1_0_3( string $path, array $context ) {
		$c = self::client( array( Scopes::PROFILE ) );
		$p = array_merge( $context, array( 'profileId' => 'new-doc' ) );
		$h = array(
			'Content-Type'             => 'application/json',
			'X-Experience-API-Version' => '1.0.3',
		);

		$res = $c->request( 'PUT', $path, $p, '{"a":1}', $h );
		$this->assertSame( 400, $res->status() );
		$this->assertTrue( json_decode( $res->body(), true )['error'] );
		$this->assertSame( 404, $c->request( 'GET', $path, $p, null, array( 'X-Experience-API-Version' => '1.0.3' ) )->status(), 'nothing stored' );

		$this->assertSame( 204, $c->request( 'PUT', $path, $p, '{"a":1}', array_merge( $h, array( 'If-None-Match' => '*' ) ) )->status() );
		$this->assertSame( 409, $c->request( 'PUT', $path, $p, '{"a":2}', $h )->status() );

		$p2 = array_merge( $context, array( 'profileId' => 'new-doc-2' ) );
		$this->assertSame( 204, $c->request( 'PUT', $path, $p2, '{"a":1}', array( 'Content-Type' => 'application/json' ) )->status(), '2.0 creates' );
	}

	/**
	 * @dataProvider resources
	 */
	public function test_list_ids_since( string $path, array $context ) {
		$c = self::client( array( Scopes::PROFILE ) );
		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:00:00.000000', new DateTimeZone( 'UTC' ) ) );
		$c->request( 'PUT', $path, array_merge( $context, array( 'profileId' => 'old' ) ), 'a', array( 'Content-Type' => 'text/plain' ) );
		Clock::freeze( new DateTimeImmutable( '2026-10-27 10:00:05.000000', new DateTimeZone( 'UTC' ) ) );
		$c->request( 'PUT', $path, array_merge( $context, array( 'profileId' => 'new' ) ), 'b', array( 'Content-Type' => 'text/plain' ) );

		$res = $c->request( 'GET', $path, $context );
		$this->assertSame( 200, $res->status() );
		$ids = json_decode( $res->body(), true );
		sort( $ids );
		$this->assertSame( array( 'new', 'old' ), $ids );

		$res = $c->request( 'GET', $path, array_merge( $context, array( 'since' => '2026-10-27T12:00:01+02:00' ) ) );
		$this->assertSame( '["new"]', $res->body() );
	}

	public function test_agent_profiles_are_per_agent_and_activity_profiles_per_activity() {
		$c = self::client( array( Scopes::PROFILE ) );
		$c->request(
			'PUT',
			'agents/profile',
			array(
				'agent'     => self::agent_json(),
				'profileId' => 'p',
			),
			'a',
			array( 'Content-Type' => 'text/plain' )
		);
		$c->request(
			'PUT',
			'activities/profile',
			array(
				'activityId' => self::ACT,
				'profileId'  => 'p',
			),
			'b',
			array( 'Content-Type' => 'text/plain' )
		);

		$this->assertSame(
			404,
			$c->request(
				'GET',
				'agents/profile',
				array(
					'agent'     => self::agent_json( 'mailto:other@example.com' ),
					'profileId' => 'p',
				)
			)->status()
		);
		$this->assertSame(
			404,
			$c->request(
				'GET',
				'activities/profile',
				array(
					'activityId' => self::ACT . '/x',
					'profileId'  => 'p',
				)
			)->status()
		);
		$this->assertSame(
			'a',
			$c->request(
				'GET',
				'agents/profile',
				array(
					'agent'     => self::agent_json(),
					'profileId' => 'p',
				)
			)->body()
		);
		$this->assertSame(
			'b',
			$c->request(
				'GET',
				'activities/profile',
				array(
					'activityId' => self::ACT,
					'profileId'  => 'p',
				)
			)->body()
		);
	}

	public function test_scopes_and_bindings() {
		$owner = self::client( array( Scopes::PROFILE ) );
		$p     = array(
			'agent'     => self::agent_json(),
			'profileId' => 'p',
		);
		$owner->request( 'PUT', 'agents/profile', $p, 'a', array( 'Content-Type' => 'text/plain' ) );

		$reader = self::client( array( Scopes::ALL_READ ) );
		$this->assertSame( 200, $reader->request( 'GET', 'agents/profile', $p )->status() );
		$this->assertSame( 403, $reader->request( 'PUT', 'agents/profile', $p, 'b', array( 'If-Match' => '*' ) )->status() );

		$state_only = self::client( array( Scopes::STATE ) );
		$this->assertSame( 403, $state_only->request( 'GET', 'agents/profile', $p )->status() );
		$this->assertSame( 403, $state_only->request( 'GET', 'activities/profile', array( 'activityId' => self::ACT ) )->status() );

		$bound = self::client( array( Scopes::PROFILE ), array( 'bindings' => array( 'actor_ifi' => 'mbox:mailto:someone@example.com' ) ) );
		$this->assertSame( 403, $bound->request( 'GET', 'agents/profile', $p )->status() );
		$this->assertSame( 403, $bound->request( 'PUT', 'agents/profile', $p, 'b', array( 'If-Match' => '*' ) )->status() );

		$prefixed = self::client( array( Scopes::PROFILE ), array( 'bindings' => array( 'activity_prefixes' => array( 'https://elsewhere.example/' ) ) ) );
		$this->assertSame(
			403,
			$prefixed->request(
				'PUT',
				'activities/profile',
				array(
					'activityId' => self::ACT,
					'profileId'  => 'p',
				),
				'b'
			)->status()
		);
	}

	public function test_missing_or_bad_parameters_400() {
		$c = self::client( array( Scopes::PROFILE ) );
		$this->assertSame( 400, $c->request( 'GET', 'agents/profile', array( 'profileId' => 'p' ) )->status() );
		$this->assertSame( 400, $c->request( 'GET', 'agents/profile', array( 'agent' => '{"name":"no ifi"}' ) )->status() );
		$this->assertSame( 400, $c->request( 'GET', 'activities/profile', array( 'profileId' => 'p' ) )->status() );
		$this->assertSame(
			400,
			$c->request(
				'GET',
				'activities/profile',
				array(
					'activityId' => self::ACT,
					'since'      => 'yesterday',
				)
			)->status()
		);
	}
}
