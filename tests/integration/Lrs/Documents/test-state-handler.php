<?php
/**
 * State resource tests, through the xAPI router.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Auth\Token_Repository;
use LearningLedger\Lrs\Auth\Token_Spec;
use LearningLedger\Lrs\Http\Xapi_Router;
use LearningLedger\Tests\Client_Factory;
use LearningLedger\Tests\Xapi_Test_Client;

class State_Handler_Test extends WP_UnitTestCase {

	const ACT     = 'https://lms.example/au/1';
	const REG     = '6f1c3c1e-9a5e-4c1b-8d4e-2b7f0c9a1d11';
	const REG2    = '0b6c7a52-5d0e-4c3a-9f1e-6a2b3c4d5e6f';
	const SESSION = '1d2c3b4a-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

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

	private static function actor( string $name = 'learner-1' ): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => 'https://lms.example',
				'name'     => $name,
			),
		);
	}

	private static function params( array $extra = array(), string $name = 'learner-1' ): array {
		return array_merge(
			array(
				'activityId' => self::ACT,
				'agent'      => wp_json_encode( self::actor( $name ) ),
			),
			$extra
		);
	}

	private static function client( array $scopes, array $overrides = array() ): Xapi_Test_Client {
		$c = Client_Factory::create( $scopes, $overrides );
		return Xapi_Test_Client::as( $c['id'], $c['secret'] );
	}

	/**
	 * A child token for learner-1, REG and ACT, minted by a parent holding state, profile and tokens/mint.
	 */
	private static function child(): Xapi_Test_Client {
		$c      = Client_Factory::create( array( Scopes::STATE, Scopes::PROFILE, Scopes::STATEMENTS_WRITE, Scopes::TOKENS_MINT ) );
		$parent = ( new Client_Repository() )->find( $c['id'] );
		$t      = ( new Token_Repository() )->mint( $parent, new Token_Spec( self::REG, self::actor(), self::ACT, self::SESSION, 'Normal', 600 ) );
		return Xapi_Test_Client::as( $t['key'], $t['secret'] );
	}

	public function test_put_get_list_delete_round_trip() {
		$lms = self::client( array( Scopes::STATE ) );

		$res = $lms->request(
			'PUT',
			'activities/state',
			self::params(
				array(
					'stateId'      => 's1',
					'registration' => self::REG,
				)
			),
			'{"a":1}',
			array( 'Content-Type' => 'application/json' )
		);
		$this->assertSame( 204, $res->status() );
		$this->assertSame( '', $res->body() );

		$res = $lms->request(
			'GET',
			'activities/state',
			self::params(
				array(
					'stateId'      => 's1',
					'registration' => self::REG,
				)
			)
		);
		$this->assertSame( 200, $res->status() );
		$this->assertSame( '{"a":1}', $res->body() );
		$this->assertSame( 'application/json', $res->content_type() );
		$this->assertSame( '"' . sha1( '{"a":1}' ) . '"', $res->headers()['ETag'] );
		$this->assertMatchesRegularExpression( '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $res->headers()['Last-Modified'] );

		$res = $lms->request( 'GET', 'activities/state', self::params( array( 'registration' => self::REG ) ) );
		$this->assertSame( 200, $res->status() );
		$this->assertSame( '["s1"]', $res->body() );

		$res = $lms->request(
			'GET',
			'activities/state',
			self::params(
				array(
					'stateId'      => 'missing',
					'registration' => self::REG,
				)
			)
		);
		$this->assertSame( 404, $res->status() );

		$res = $lms->request( 'DELETE', 'activities/state', self::params( array( 'registration' => self::REG ) ) );
		$this->assertSame( 204, $res->status() );
		$res = $lms->request( 'GET', 'activities/state', self::params( array( 'registration' => self::REG ) ) );
		$this->assertSame( '[]', $res->body() );
	}

	public function test_missing_or_bad_parameters_400() {
		$lms = self::client( array( Scopes::STATE ) );
		$this->assertSame(
			400,
			$lms->request(
				'PUT',
				'activities/state',
				array(
					'agent'   => wp_json_encode( self::actor() ),
					'stateId' => 's',
				),
				'x'
			)->status()
		);
		$this->assertSame(
			400,
			$lms->request(
				'PUT',
				'activities/state',
				array(
					'activityId' => self::ACT,
					'stateId'    => 's',
				),
				'x'
			)->status()
		);
		$this->assertSame( 400, $lms->request( 'PUT', 'activities/state', self::params(), 'x' )->status() );
		$this->assertSame( 400, $lms->request( 'POST', 'activities/state', self::params(), '{}' )->status() );
		$this->assertSame( 400, $lms->request( 'GET', 'activities/state', self::params( array( 'agent' => '{"mbox":' ) ) )->status() );
		$this->assertSame( 400, $lms->request( 'GET', 'activities/state', self::params( array( 'registration' => 'nope' ) ) )->status() );
		$this->assertSame( 400, $lms->request( 'GET', 'activities/state', self::params( array( 'since' => 'not a timestamp' ) ) )->status() );
		$this->assertSame( 400, $lms->request( 'GET', 'activities/state', self::params( array( 'activityId' => 'not an iri' ) ) )->status() );
	}

	public function test_put_without_precondition_on_existing_409_in_20_overwrites_in_103() {
		$lms = self::client( array( Scopes::STATE ) );
		$p   = self::params( array( 'stateId' => 's1' ) );

		$this->assertSame( 204, $lms->request( 'PUT', 'activities/state', $p, 'one', array( 'Content-Type' => 'text/plain' ) )->status() );
		$res = $lms->request( 'PUT', 'activities/state', $p, 'two', array( 'Content-Type' => 'text/plain' ) );
		$this->assertSame( 409, $res->status() );
		$this->assertNotSame( '', $res->body() );
		$this->assertSame( 'one', $lms->request( 'GET', 'activities/state', $p )->body() );

		$res = $lms->request(
			'PUT',
			'activities/state',
			$p,
			'two',
			array(
				'Content-Type' => 'text/plain',
				'If-Match'     => '"' . sha1( 'one' ) . '"',
			)
		);
		$this->assertSame( 204, $res->status() );

		$v103 = array(
			'Content-Type'             => 'text/plain',
			'X-Experience-API-Version' => '1.0.3',
		);
		$this->assertSame( 204, $lms->request( 'PUT', 'activities/state', $p, 'three', $v103 )->status() );
		$this->assertSame( 'three', $lms->request( 'GET', 'activities/state', $p )->body() );

		// POST merges without a precondition in both versions.
		$q = self::params( array( 'stateId' => 'merge' ) );
		$this->assertSame( 204, $lms->request( 'POST', 'activities/state', $q, '{"a":1}', array( 'Content-Type' => 'application/json' ) )->status() );
		$this->assertSame( 204, $lms->request( 'POST', 'activities/state', $q, '{"b":2}', array( 'Content-Type' => 'application/json' ) )->status() );
		$this->assertSame( '{"a":1,"b":2}', $lms->request( 'GET', 'activities/state', $q )->body() );
		$this->assertSame( 400, $lms->request( 'POST', 'activities/state', $q, 'abc', array( 'Content-Type' => 'text/plain' ) )->status() );
	}

	public function test_scopes_writes_need_state_reads_accept_all_read() {
		$owner = self::client( array( Scopes::STATE ) );
		$p     = self::params( array( 'stateId' => 's1' ) );
		$owner->request( 'PUT', 'activities/state', $p, 'one', array( 'Content-Type' => 'text/plain' ) );

		$reader = self::client( array( Scopes::ALL_READ ) );
		$this->assertSame( 200, $reader->request( 'GET', 'activities/state', $p )->status() );
		$this->assertSame( 403, $reader->request( 'PUT', 'activities/state', $p, 'x', array( 'If-Match' => '*' ) )->status() );
		$this->assertSame( 403, $reader->request( 'DELETE', 'activities/state', $p )->status() );

		$writer = self::client( array( Scopes::STATEMENTS_WRITE ) );
		$this->assertSame( 403, $writer->request( 'GET', 'activities/state', $p )->status() );

		$all = self::client( array( Scopes::ALL ) );
		$this->assertSame( 204, $all->request( 'PUT', 'activities/state', $p, 'two', array( 'If-Match' => '*' ) )->status() );

		// Bindings apply: an actor-bound client cannot touch another learner's state.
		$bound = self::client( array( Scopes::STATE ), array( 'bindings' => array( 'actor_home_page' => 'https://other.example' ) ) );
		$this->assertSame( 403, $bound->request( 'GET', 'activities/state', $p )->status() );
		$prefixed = self::client( array( Scopes::STATE ), array( 'bindings' => array( 'activity_prefixes' => array( 'https://elsewhere.example/' ) ) ) );
		$this->assertSame( 403, $prefixed->request( 'GET', 'activities/state', $p )->status() );
	}

	public function test_child_token_cannot_write_launchdata_or_profile() {
		$lms    = self::client( array( Scopes::STATE, Scopes::PROFILE ) );
		$launch = self::params(
			array(
				'stateId'      => 'LMS.LaunchData',
				'registration' => self::REG,
			)
		);
		$lms->request( 'PUT', 'activities/state', $launch, '{"launchMode":"Normal"}', array( 'Content-Type' => 'application/json' ) );
		$lms->request(
			'PUT',
			'agents/profile',
			array(
				'agent'     => wp_json_encode( self::actor() ),
				'profileId' => 'cmi5LearnerPreferences',
			),
			'{"languagePreference":"en-US"}',
			array( 'Content-Type' => 'application/json' )
		);

		$au = self::child();

		// LaunchData: readable, never writable.
		$res = $au->request( 'GET', 'activities/state', $launch );
		$this->assertSame( 200, $res->status() );
		$this->assertSame( '{"launchMode":"Normal"}', $res->body() );
		$this->assertSame(
			403,
			$au->request(
				'PUT',
				'activities/state',
				$launch,
				'{}',
				array(
					'Content-Type' => 'application/json',
					'If-Match'     => '*',
				)
			)->status()
		);
		$this->assertSame( 403, $au->request( 'POST', 'activities/state', $launch, '{"launchMode":"Browse"}', array( 'Content-Type' => 'application/json' ) )->status() );
		$this->assertSame( 403, $au->request( 'DELETE', 'activities/state', $launch )->status() );
		$this->assertSame( 403, $au->request( 'DELETE', 'activities/state', self::params( array( 'registration' => self::REG ) ) )->status() );
		$this->assertSame( '{"launchMode":"Normal"}', $lms->request( 'GET', 'activities/state', $launch )->body() );

		// Other state of its own session is writable.
		$own = self::params(
			array(
				'stateId'      => 'suspend',
				'registration' => self::REG,
			)
		);
		$this->assertSame( 204, $au->request( 'PUT', 'activities/state', $own, 'data', array( 'Content-Type' => 'text/plain' ) )->status() );

		// Agent profile: readable, not writable (cmi5 allows read-only).
		$prefs = array(
			'agent'     => wp_json_encode( self::actor() ),
			'profileId' => 'cmi5LearnerPreferences',
		);
		$this->assertSame( 200, $au->request( 'GET', 'agents/profile', $prefs )->status() );
		$this->assertSame(
			403,
			$au->request(
				'PUT',
				'agents/profile',
				$prefs,
				'{}',
				array(
					'Content-Type' => 'application/json',
					'If-Match'     => '*',
				)
			)->status()
		);
		$this->assertSame( 403, $au->request( 'POST', 'agents/profile', $prefs, '{"x":1}', array( 'Content-Type' => 'application/json' ) )->status() );
		$this->assertSame( 403, $au->request( 'DELETE', 'agents/profile', $prefs )->status() );

		// Activity profile: read-only.
		$ap = array(
			'activityId' => self::ACT,
			'profileId'  => 'p',
		);
		$this->assertSame( 403, $au->request( 'PUT', 'activities/profile', $ap, '{}', array( 'Content-Type' => 'application/json' ) )->status() );
		$this->assertSame( 404, $au->request( 'GET', 'activities/profile', $ap )->status() );
	}

	public function test_child_token_limited_to_own_registration() {
		$lms = self::client( array( Scopes::STATE ) );
		$lms->request(
			'PUT',
			'activities/state',
			self::params(
				array(
					'stateId'      => 's',
					'registration' => self::REG2,
				)
			),
			'theirs',
			array( 'Content-Type' => 'text/plain' )
		);
		$lms->request(
			'PUT',
			'activities/state',
			self::params(
				array(
					'stateId'      => 's',
					'registration' => self::REG,
				)
			),
			'mine',
			array( 'Content-Type' => 'text/plain' )
		);

		$au = self::child();

		$this->assertSame(
			'mine',
			$au->request(
				'GET',
				'activities/state',
				self::params(
					array(
						'stateId'      => 's',
						'registration' => self::REG,
					)
				)
			)->body()
		);
		// Uppercase registration is the same registration.
		$this->assertSame(
			'mine',
			$au->request(
				'GET',
				'activities/state',
				self::params(
					array(
						'stateId'      => 's',
						'registration' => strtoupper( self::REG ),
					)
				)
			)->body()
		);

		// Another registration, no registration, another actor or another activity: 403.
		$this->assertSame(
			403,
			$au->request(
				'GET',
				'activities/state',
				self::params(
					array(
						'stateId'      => 's',
						'registration' => self::REG2,
					)
				)
			)->status()
		);
		$this->assertSame(
			403,
			$au->request(
				'PUT',
				'activities/state',
				self::params(
					array(
						'stateId'      => 's',
						'registration' => self::REG2,
					)
				),
				'x',
				array( 'If-Match' => '*' )
			)->status()
		);
		$this->assertSame( 403, $au->request( 'GET', 'activities/state', self::params( array( 'stateId' => 's' ) ) )->status() );
		$this->assertSame( 403, $au->request( 'GET', 'activities/state', self::params() )->status() );
		$this->assertSame(
			403,
			$au->request(
				'GET',
				'activities/state',
				self::params(
					array(
						'stateId'      => 's',
						'registration' => self::REG,
					),
					'learner-2'
				)
			)->status()
		);
		$this->assertSame(
			403,
			$au->request(
				'GET',
				'activities/state',
				array(
					'activityId'   => 'https://lms.example/au/2',
					'agent'        => wp_json_encode( self::actor() ),
					'stateId'      => 's',
					'registration' => self::REG,
				)
			)->status()
		);

		$this->assertSame(
			'theirs',
			$lms->request(
				'GET',
				'activities/state',
				self::params(
					array(
						'stateId'      => 's',
						'registration' => self::REG2,
					)
				)
			)->body()
		);
	}

	public function test_binary_content_round_trip_with_content_type() {
		$bytes = '';
		for ( $i = 255; $i >= 0; $i-- ) {
			$bytes .= chr( $i );
		}
		$lms = self::client( array( Scopes::STATE ) );
		$p   = self::params( array( 'stateId' => 'blob' ) );

		$this->assertSame( 204, $lms->request( 'PUT', 'activities/state', $p, $bytes, array( 'Content-Type' => 'application/octet-stream' ) )->status() );
		$res = $lms->request( 'GET', 'activities/state', $p );
		$this->assertSame( 200, $res->status() );
		$this->assertSame( $bytes, $res->body() );
		$this->assertSame( 'application/octet-stream', $res->content_type() );
		$this->assertSame( '"' . sha1( $bytes ) . '"', $res->headers()['ETag'] );
	}

	public function test_over_limit_413() {
		update_option( 'lledger_document_max_bytes', 8 );
		$lms = self::client( array( Scopes::STATE ) );
		$res = $lms->request( 'PUT', 'activities/state', self::params( array( 'stateId' => 'big' ) ), '123456789', array( 'Content-Type' => 'text/plain' ) );
		delete_option( 'lledger_document_max_bytes' );
		$this->assertSame( 413, $res->status() );
	}
}
