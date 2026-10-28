<?php
/**
 * Isolated tests must not reinstall WordPress under the running suite.
 *
 * @package LearningLedger
 */

/**
 * A test run in a separate process boots the test bootstrap again. If that
 * reinstalls WordPress, auto-increment IDs restart mid-suite and later tests
 * reuse user and post IDs that third-party static caches (Sensei's learner
 * terms) still remember.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Process_Isolation_Test extends WP_UnitTestCase {

	public function test_user_created_before_an_isolated_test(): int {
		$id = self::factory()->user->create();
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_isolated_process_sees_the_installed_site() {
		$this->assertTrue( is_blog_installed() );
	}

	/**
	 * @depends test_user_created_before_an_isolated_test
	 *
	 * @param int $before User ID created before the isolated test.
	 */
	public function test_ids_keep_increasing_after_an_isolated_test( int $before ) {
		$this->assertGreaterThan( $before, self::factory()->user->create(), 'the isolated test reinstalled WordPress and reset the user IDs' );
	}
}
