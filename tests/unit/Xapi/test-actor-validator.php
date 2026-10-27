<?php
/**
 * Actor_Validator tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Xapi\Actor_Validator;

class Actor_Validator_Test extends \PHPUnit\Framework\TestCase {
	private function assert_invalid( $actor, string $code, string $path, string $version = '2.0.0' ) {
		try {
			Actor_Validator::validate( $actor, $version );
			$this->fail( 'Expected Invalid_Input ' . $code . ' at ' . $path );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( $code, $e->error_code(), $e->getMessage() );
			$this->assertSame( $path, $e->path(), $e->getMessage() );
		}
	}

	private function assert_valid( $actor ) {
		foreach ( array( '1.0.3', '2.0.0' ) as $version ) {
			Actor_Validator::validate( $actor, $version );
		}
		$this->addToAssertionCount( 1 );
	}

	public function test_valid_agents() {
		$this->assert_valid( array( 'mbox' => 'mailto:a@b.org' ) );
		$this->assert_valid(
			array(
				'objectType' => 'Agent',
				'name'       => 'A',
				'account'    => array(
					'homePage' => 'https://site.example',
					'name'     => 'u-1',
				),
			)
		);
	}

	public function test_valid_groups() {
		$this->assert_valid(
			array(
				'objectType' => 'Group',
				'member'     => array( array( 'mbox' => 'mailto:a@b.org' ), array( 'openid' => 'https://id.example/1' ) ),
			)
		);
		$this->assert_valid(
			array(
				'objectType' => 'Group',
				'name'       => 'G',
				'mbox'       => 'mailto:g@b.org',
			)
		);
		$this->assert_valid(
			array(
				'objectType' => 'Group',
				'mbox'       => 'mailto:g@b.org',
				'member'     => array(
					array(
						'objectType' => 'Agent',
						'mbox'       => 'mailto:a@b.org',
					),
				),
			)
		);
	}

	public function test_anonymous_group_without_member_throws() {
		$this->assert_invalid(
			array(
				'objectType' => 'Group',
				'name'       => 'G',
			),
			'member',
			'actor.member'
		);
	}

	public function test_anonymous_group_with_empty_member_throws() {
		$this->assert_invalid(
			array(
				'objectType' => 'Group',
				'member'     => array(),
			),
			'member',
			'actor.member'
		);
	}

	public function test_group_member_that_is_group_throws() {
		$this->assert_invalid(
			array(
				'objectType' => 'Group',
				'member'     => array(
					array( 'mbox' => 'mailto:a@b.org' ),
					array(
						'objectType' => 'Group',
						'mbox'       => 'mailto:g@b.org',
					),
				),
			),
			'object_type',
			'actor.member[1].objectType'
		);
	}

	public function test_member_not_list_throws() {
		$this->assert_invalid(
			array(
				'objectType' => 'Group',
				'member'     => array( 'a' => array( 'mbox' => 'mailto:a@b.org' ) ),
			),
			'type',
			'actor.member'
		);
	}

	public function test_member_agent_errors_carry_path() {
		$this->assert_invalid(
			array(
				'objectType' => 'Group',
				'member'     => array( array( 'mbox' => 'a@b.org' ) ),
			),
			'mbox',
			'actor.member[0].mbox'
		);
	}

	public function test_extra_key_throws_with_path() {
		$this->assert_invalid(
			array(
				'mbox' => 'mailto:a@b.org',
				'foo'  => 'bar',
			),
			'unknown_property',
			'actor.foo'
		);
	}

	public function test_member_on_agent_is_unknown_property() {
		$this->assert_invalid(
			array(
				'mbox'   => 'mailto:a@b.org',
				'member' => array( array( 'mbox' => 'mailto:b@b.org' ) ),
			),
			'unknown_property',
			'actor.member'
		);
	}

	public function test_agent_ifi_rules() {
		$this->assert_invalid( array( 'name' => 'A' ), 'ifi', 'actor' );
		$this->assert_invalid(
			array(
				'mbox'   => 'mailto:a@b.org',
				'openid' => 'https://id.example/1',
			),
			'ifi',
			'actor'
		);
		$this->assert_invalid(
			array(
				'objectType' => 'Group',
				'mbox'       => 'mailto:a@b.org',
				'openid'     => 'https://id.example/1',
			),
			'ifi',
			'actor'
		);
		$this->assert_invalid( array( 'account' => array( 'name' => 'u' ) ), 'iri', 'actor.account.homePage' );
	}

	public function test_name_must_be_string() {
		$this->assert_invalid(
			array(
				'mbox' => 'mailto:a@b.org',
				'name' => 5,
			),
			'type',
			'actor.name'
		);
	}

	public function test_object_type_values() {
		$this->assert_invalid(
			array(
				'objectType' => 'Person',
				'mbox'       => 'mailto:a@b.org',
			),
			'object_type',
			'actor.objectType'
		);
		$this->assert_invalid(
			array(
				'objectType' => 'agent',
				'mbox'       => 'mailto:a@b.org',
			),
			'object_type',
			'actor.objectType'
		);
	}

	public function test_not_an_object_throws() {
		$this->assert_invalid( 'mailto:a@b.org', 'type', 'actor' );
		$this->assert_invalid( array( array( 'mbox' => 'mailto:a@b.org' ) ), 'type', 'actor' );
	}

	public function test_custom_path() {
		try {
			Actor_Validator::validate(
				array(
					'mbox' => 'mailto:a@b.org',
					'foo'  => 1,
				),
				'1.0.3',
				'object'
			);
			$this->fail( 'Expected Invalid_Input' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'object.foo', $e->path() );
		}
	}
}
