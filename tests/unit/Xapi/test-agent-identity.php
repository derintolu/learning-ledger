<?php
/**
 * Agent_Identity tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Xapi\Agent_Identity;

class Agent_Identity_Test extends \PHPUnit\Framework\TestCase {
	private function assert_invalid( callable $callback, string $code, ?string $path = null ) {
		try {
			$callback();
			$this->fail( 'Expected Invalid_Input ' . $code );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( $code, $e->error_code() );
			if ( null !== $path ) {
				$this->assertSame( $path, $e->path() );
			}
		}
	}

	public function test_account() {
		$a = Agent_Identity::from_array(
			array(
				'account' => array(
					'homePage' => 'https://site.example',
					'name'     => 'u-1',
				),
			)
		);
		$this->assertSame( 'account:https://site.example|u-1', $a->ifi_key() );
		$this->assertSame( 20, strlen( $a->hash() ) );
		$this->assertSame( sha1( 'account:https://site.example|u-1', true ), $a->hash() );
		$this->assertSame( 'account', $a->kind() );
		$this->assertSame( 'https://site.example', $a->home_page() );
		$this->assertSame(
			array(
				'objectType' => 'Agent',
				'account'    => array(
					'homePage' => 'https://site.example',
					'name'     => 'u-1',
				),
			),
			$a->to_array()
		);
	}

	public function test_mbox() {
		$a = Agent_Identity::from_array(
			array(
				'objectType' => 'Agent',
				'name'       => 'A',
				'mbox'       => 'mailto:a@b.org',
			)
		);
		$this->assertSame( 'mbox:mailto:a@b.org', $a->ifi_key() );
		$this->assertSame( 'mbox', $a->kind() );
		$this->assertNull( $a->home_page() );
		$this->assertSame(
			array(
				'objectType' => 'Agent',
				'mbox'       => 'mailto:a@b.org',
			),
			$a->to_array()
		);
	}

	public function test_mbox_sha1sum_lowercased() {
		$a = Agent_Identity::from_array( array( 'mbox_sha1sum' => 'ABCDEF0123456789ABCDEF0123456789ABCDEF01' ) );
		$this->assertSame( 'mbox_sha1sum:abcdef0123456789abcdef0123456789abcdef01', $a->ifi_key() );
		$this->assertSame( 'mbox_sha1sum', $a->kind() );
	}

	public function test_openid() {
		$a = Agent_Identity::from_array( array( 'openid' => 'https://id.example/u/1' ) );
		$this->assertSame( 'openid:https://id.example/u/1', $a->ifi_key() );
		$this->assertSame( 'openid', $a->kind() );
		$this->assertNull( $a->home_page() );
	}

	public function test_no_case_folding_or_slash_trimming() {
		$a = Agent_Identity::from_array(
			array(
				'account' => array(
					'homePage' => 'https://Site.example/',
					'name'     => 'U-1',
				),
			)
		);
		$b = Agent_Identity::from_array(
			array(
				'account' => array(
					'homePage' => 'https://site.example',
					'name'     => 'u-1',
				),
			)
		);
		$this->assertSame( 'account:https://Site.example/|U-1', $a->ifi_key() );
		$this->assertNotSame( $a->hash(), $b->hash() );
		$m = Agent_Identity::from_array( array( 'mbox' => 'mailto:A@B.org' ) );
		$this->assertSame( 'mbox:mailto:A@B.org', $m->ifi_key() );
	}

	public function test_home_hash() {
		$this->assertSame( sha1( 'home:https://site.example', true ), Agent_Identity::home_hash( 'https://site.example' ) );
		$this->assertSame( 20, strlen( Agent_Identity::home_hash( 'https://site.example' ) ) );
	}

	public function test_two_ifis_throw_ifi() {
		$this->assert_invalid(
			fn() => Agent_Identity::from_array(
				array(
					'mbox'    => 'mailto:a@b.org',
					'account' => array(
						'homePage' => 'https://site.example',
						'name'     => 'u-1',
					),
				)
			),
			'ifi'
		);
	}

	public function test_no_ifi_throws_ifi() {
		$this->assert_invalid( fn() => Agent_Identity::from_array( array( 'name' => 'A' ) ), 'ifi' );
	}

	public function test_mbox_without_mailto_throws() {
		$this->assert_invalid( fn() => Agent_Identity::from_array( array( 'mbox' => 'a@b.org' ) ), 'mbox', 'mbox' );
	}

	public function test_bad_sha1_throws() {
		$this->assert_invalid( fn() => Agent_Identity::from_array( array( 'mbox_sha1sum' => 'xyz' ) ), 'sha1', 'mbox_sha1sum' );
	}

	public function test_bad_openid_throws() {
		$this->assert_invalid( fn() => Agent_Identity::from_array( array( 'openid' => 'not an iri' ) ), 'iri', 'openid' );
	}

	public function test_bad_account_throws() {
		$this->assert_invalid( fn() => Agent_Identity::from_array( array( 'account' => 'x' ) ), 'type', 'account' );
		$this->assert_invalid( fn() => Agent_Identity::from_array( array( 'account' => array( 'name' => 'u' ) ) ), 'iri', 'account.homePage' );
		$this->assert_invalid( fn() => Agent_Identity::from_array( array( 'account' => array( 'homePage' => 'https://s' ) ) ), 'type', 'account.name' );
		$this->assert_invalid(
			fn() => Agent_Identity::from_array(
				array(
					'account' => array(
						'homePage' => 'https://s',
						'name'     => 'u',
						'foo'      => 1,
					),
				)
			),
			'unknown_property',
			'account.foo'
		);
	}
}
