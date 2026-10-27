<?php
/**
 * Scope tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Util\Invalid_Input;

class Scopes_Test extends WP_UnitTestCase {

	const OURS = array( 'void', 'courses/write', 'badges/issue', 'badges/claim-links', 'records/view-links', 'actors/erase', 'tokens/mint' );
	const XAPI = array( 'statements/write', 'statements/read', 'statements/read/mine', 'state', 'profile', 'define', 'all/read', 'all' );

	public function test_constants_are_the_scope_names() {
		$this->assertSame(
			self::XAPI,
			array( Scopes::STATEMENTS_WRITE, Scopes::STATEMENTS_READ, Scopes::STATEMENTS_READ_MINE, Scopes::STATE, Scopes::PROFILE, Scopes::DEFINE, Scopes::ALL_READ, Scopes::ALL )
		);
		$this->assertSame(
			self::OURS,
			array( Scopes::VOID, Scopes::COURSES_WRITE, Scopes::BADGES_ISSUE, Scopes::BADGES_CLAIM_LINKS, Scopes::RECORDS_VIEW_LINKS, Scopes::ACTORS_ERASE, Scopes::TOKENS_MINT )
		);
	}

	public function test_all_does_not_imply_void_or_ours() {
		foreach ( self::XAPI as $s ) {
			$this->assertTrue( Scopes::implies( Scopes::ALL, $s ), $s );
		}
		foreach ( self::OURS as $s ) {
			$this->assertFalse( Scopes::implies( Scopes::ALL, $s ), $s );
			$this->assertFalse( Scopes::implies( Scopes::ALL_READ, $s ), $s );
			$this->assertTrue( Scopes::implies( $s, $s ), $s );
		}
	}

	public function test_read_scopes() {
		$this->assertTrue( Scopes::implies( Scopes::ALL_READ, Scopes::STATEMENTS_READ ) );
		$this->assertTrue( Scopes::implies( Scopes::ALL_READ, Scopes::STATEMENTS_READ_MINE ) );
		$this->assertTrue( Scopes::implies( Scopes::STATEMENTS_READ, Scopes::STATEMENTS_READ_MINE ) );
		$this->assertFalse( Scopes::implies( Scopes::STATEMENTS_READ_MINE, Scopes::STATEMENTS_READ ) );
		$this->assertFalse( Scopes::implies( Scopes::STATEMENTS_READ, Scopes::STATEMENTS_WRITE ) );
		$this->assertFalse( Scopes::implies( Scopes::STATEMENTS_WRITE, Scopes::STATEMENTS_READ_MINE ) );
		$this->assertFalse( Scopes::implies( Scopes::ALL_READ, Scopes::ALL ) );
		$this->assertFalse( Scopes::implies( Scopes::ALL_READ, Scopes::STATEMENTS_WRITE ) );
		// all/read gives read access to documents, which the document handlers check by name; it never grants the write scopes.
		$this->assertFalse( Scopes::implies( Scopes::ALL_READ, Scopes::STATE ) );
		$this->assertFalse( Scopes::implies( Scopes::ALL_READ, Scopes::PROFILE ) );
		$this->assertFalse( Scopes::implies( Scopes::ALL_READ, Scopes::DEFINE ) );
	}

	public function test_validate() {
		Scopes::validate( array_merge( self::XAPI, self::OURS ) );
		Scopes::validate( array() );

		foreach ( array( array( 'statements/write', 'statements/delete' ), array( 'ALL' ), array( 7 ) ) as $bad ) {
			try {
				Scopes::validate( $bad );
				$this->fail( 'Expected Invalid_Input' );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'unknown_scope', $e->error_code() );
			}
		}
	}
}
