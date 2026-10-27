<?php
/**
 * Binding tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Lrs\Auth\Bindings;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Xapi\Agent_Identity;

class Bindings_Test extends WP_UnitTestCase {

	const REG = '6f1c3c1e-9a5e-4c1b-8d4e-2b7f0c9a1d11';

	private static function account( string $home, string $name ): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => $home,
				'name'     => $name,
			),
		);
	}

	private static function statement( array $actor, ?string $registration, array $obj = array( 'id' => 'https://site.example/course/a' ) ): array {
		$st = array(
			'actor'  => $actor,
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/completed' ),
			'object' => $obj,
		);
		if ( null !== $registration ) {
			$st['context'] = array( 'registration' => $registration );
		}
		return $st;
	}

	public function test_empty_bindings_allow_everything() {
		$b = Bindings::from_array( array() );
		$this->assertTrue( $b->allows_statement( self::statement( array( 'mbox' => 'mailto:a@b.example' ), null ) ) );
		$this->assertTrue( $b->allows_actor( Agent_Identity::from_array( array( 'mbox' => 'mailto:a@b.example' ) ) ) );
		$this->assertTrue( $b->allows_document( 'https://x.example/a', null, null ) );
		$this->assertSame( array(), $b->read_filter() );
		$this->assertSame( array(), $b->to_array() );
	}

	public function test_bindings_home_page_and_registration() {
		$b = Bindings::from_array(
			array(
				'actor_home_page' => 'https://site.example',
				'registration'    => strtoupper( self::REG ),
			)
		);

		$this->assertTrue( $b->allows_statement( self::statement( self::account( 'https://site.example', 'u1' ), self::REG ) ) );
		$this->assertTrue( $b->allows_statement( self::statement( self::account( 'https://site.example', 'u2' ), strtoupper( self::REG ) ) ) );
		// Exact homePage: trailing slash is a different home page.
		$this->assertFalse( $b->allows_statement( self::statement( self::account( 'https://site.example/', 'u1' ), self::REG ) ) );
		$this->assertFalse( $b->allows_statement( self::statement( self::account( 'https://other.example', 'u1' ), self::REG ) ) );
		$this->assertFalse( $b->allows_statement( self::statement( array( 'mbox' => 'mailto:a@b.example' ), self::REG ) ) );
		// Registration binding: missing or different registration is refused.
		$this->assertFalse( $b->allows_statement( self::statement( self::account( 'https://site.example', 'u1' ), null ) ) );
		$this->assertFalse( $b->allows_statement( self::statement( self::account( 'https://site.example', 'u1' ), '00000000-0000-4000-8000-000000000000' ) ) );
		// Anonymous group actor has no IFI.
		$this->assertFalse(
			$b->allows_statement(
				self::statement(
					array(
						'objectType' => 'Group',
						'member'     => array(),
					),
					self::REG
				)
			)
		);

		$this->assertTrue( $b->allows_actor( Agent_Identity::from_array( self::account( 'https://site.example', 'u9' ) ) ) );
		$this->assertFalse( $b->allows_actor( Agent_Identity::from_array( array( 'mbox' => 'mailto:a@b.example' ) ) ) );

		$this->assertTrue( $b->allows_document( 'https://x.example/a', Agent_Identity::from_array( self::account( 'https://site.example', 'u1' ) ), self::REG ) );
		$this->assertFalse( $b->allows_document( 'https://x.example/a', Agent_Identity::from_array( self::account( 'https://other.example', 'u1' ) ), self::REG ) );
		$this->assertFalse( $b->allows_document( 'https://x.example/a', Agent_Identity::from_array( self::account( 'https://site.example', 'u1' ) ), '00000000-0000-4000-8000-000000000000' ) );
		$this->assertTrue( $b->allows_document( 'https://x.example/a', Agent_Identity::from_array( self::account( 'https://site.example', 'u1' ) ), null ) );
	}

	public function test_actor_ifi_binding() {
		$id = Agent_Identity::from_array( self::account( 'https://site.example', 'u1' ) );
		$b  = Bindings::from_array( array( 'actor_ifi' => $id->ifi_key() ) );

		$this->assertTrue( $b->allows_statement( self::statement( self::account( 'https://site.example', 'u1' ), null ) ) );
		$this->assertFalse( $b->allows_statement( self::statement( self::account( 'https://site.example', 'u2' ), null ) ) );
		$this->assertTrue( $b->allows_actor( $id ) );
		$this->assertFalse( $b->allows_actor( Agent_Identity::from_array( self::account( 'https://site.example', 'u2' ) ) ) );
		$this->assertFalse( $b->allows_document( '', Agent_Identity::from_array( self::account( 'https://site.example', 'u2' ) ), null ) );
		$this->assertTrue( $b->allows_document( '', $id, null ) );
		$this->assertFalse( $b->allows_statement( array( 'verb' => array( 'id' => 'x' ) ) ) );
	}

	public function test_activity_prefixes() {
		$b     = Bindings::from_array(
			array(
				'activity_prefixes'       => array( 'https://site.example/course/' ),
				'write_activity_prefixes' => array( 'https://site.example/course/a' ),
			)
		);
		$actor = array( 'mbox' => 'mailto:a@b.example' );

		$this->assertTrue( $b->allows_statement( self::statement( $actor, null, array( 'id' => 'https://site.example/course/a/au/1' ) ) ) );
		$this->assertTrue(
			$b->allows_statement(
				self::statement(
					$actor,
					null,
					array(
						'objectType' => 'Activity',
						'id'         => 'https://site.example/course/a',
					)
				)
			)
		);
		$this->assertFalse( $b->allows_statement( self::statement( $actor, null, array( 'id' => 'https://site.example/course/b' ) ) ) );
		$this->assertFalse( $b->allows_statement( self::statement( $actor, null, array( 'id' => 'https://evil.example/course/a' ) ) ) );
		// Non-Activity objects are not prefix-checked here.
		$this->assertTrue(
			$b->allows_statement(
				self::statement(
					$actor,
					null,
					array(
						'objectType' => 'StatementRef',
						'id'         => self::REG,
					)
				)
			)
		);

		// Documents use activity_prefixes only.
		$this->assertTrue( $b->allows_document( 'https://site.example/course/b', null, null ) );
		$this->assertFalse( $b->allows_document( 'https://evil.example/', null, null ) );
		$this->assertTrue( $b->allows_document( '', null, null ) );

		// Activity prefixes never filter reads.
		$this->assertSame( array(), $b->read_filter() );
	}

	public function test_read_filter_shapes() {
		$id = Agent_Identity::from_array( array( 'mbox' => 'mailto:a@b.example' ) );

		$this->assertSame(
			array( 'actor_hash' => $id->hash() ),
			Bindings::from_array( array( 'actor_ifi' => $id->ifi_key() ) )->read_filter()
		);
		$this->assertSame(
			array( 'actor_home_hash' => Agent_Identity::home_hash( 'https://site.example' ) ),
			Bindings::from_array( array( 'actor_home_page' => 'https://site.example' ) )->read_filter()
		);
		$this->assertSame(
			array( 'registration' => self::REG ),
			Bindings::from_array( array( 'registration' => strtoupper( self::REG ) ) )->read_filter()
		);
		$all = Bindings::from_array(
			array(
				'actor_ifi'         => $id->ifi_key(),
				'actor_home_page'   => 'https://site.example',
				'registration'      => self::REG,
				'activity_prefixes' => array( 'https://site.example/' ),
			)
		)->read_filter();
		$this->assertSame( array( 'actor_hash', 'actor_home_hash', 'registration' ), array_keys( $all ) );
		$this->assertSame( 20, strlen( $all['actor_hash'] ) );
		$this->assertSame( 20, strlen( $all['actor_home_hash'] ) );
	}

	public function test_invalid_bindings_rejected() {
		$bad = array(
			array( 'actor' => 'x' ),
			array( 'actor_ifi' => 7 ),
			array( 'actor_ifi' => '' ),
			array( 'registration' => 'not-a-uuid' ),
			array( 'activity_prefixes' => 'https://x/' ),
			array( 'activity_prefixes' => array( 'relative/path' ) ),
			array( 'write_activity_prefixes' => array( 7 ) ),
			array( 'actor_home_page' => 'not an iri' ),
		);
		foreach ( $bad as $b ) {
			try {
				Bindings::from_array( $b );
				$this->fail( 'Expected Invalid_Input for ' . wp_json_encode( $b ) );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'invalid_binding', $e->error_code() );
			}
		}
	}

	public function test_round_trip() {
		$in = array(
			'actor_ifi'               => 'mbox:mailto:a@b.example',
			'actor_home_page'         => 'https://site.example',
			'registration'            => self::REG,
			'activity_prefixes'       => array( 'https://site.example/' ),
			'write_activity_prefixes' => array( 'https://site.example/w/' ),
		);
		$this->assertSame( $in, Bindings::from_array( $in )->to_array() );
	}
}
