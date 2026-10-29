<?php
/**
 * Service_Context tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Kernel\Service_Context;

class Service_Context_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Service_Context::OPTION );
		Service_Context::reset();
		self::factory()->post->create(
			array(
				'post_type'   => 'lledger_program',
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		Service_Context::reset();
		parent::tear_down();
	}

	/**
	 * A host that empties post, user and comment queries while nobody is logged in.
	 */
	private static function anonymous_host_filter(): void {
		add_action(
			'pre_get_posts',
			static function ( $q ) {
				if ( ! is_user_logged_in() ) {
					$q->set( 'post__in', array( 0 ) );
				}
			}
		);
		add_action(
			'pre_get_users',
			static function ( $q ) {
				if ( ! is_user_logged_in() ) {
					$q->set( 'include', array( 0 ) );
				}
			}
		);
		add_action(
			'pre_get_comments',
			static function ( $q ) {
				if ( ! is_user_logged_in() ) {
					$q->query_vars['comment__in'] = array( 0 );
				}
			}
		);
		Service_Context::reset();
	}

	public function test_the_service_user_has_no_role_capabilities_or_password_and_is_recreated() {
		global $wpdb;
		$id = Service_Context::service_user();
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( $id, Service_Context::service_user(), 'made once' );
		$this->assertSame( $id, (int) get_option( Service_Context::OPTION ) );

		$user = get_userdata( $id );
		$this->assertSame( Service_Context::LOGIN, $user->user_login );
		$this->assertSame( array(), $user->roles );
		$this->assertSame( array(), $user->caps );
		$this->assertSame( '', get_user_meta( $id, $wpdb->get_blog_prefix() . 'capabilities', true ), 'no capabilities meta' );
		$this->assertSame( '*', $user->user_pass, 'no usable password' );
		$this->assertFalse( wp_check_password( '*', $user->user_pass, $id ) );
		$this->assertSame( 'false', get_user_meta( $id, 'show_admin_bar_front', true ) );
		$this->assertInstanceOf( WP_Error::class, wp_authenticate( Service_Context::LOGIN, '*' ) );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $id );
		$again = Service_Context::service_user();
		$this->assertGreaterThan( 0, $again );
		$this->assertNotSame( $id, $again, 'recreated after deletion' );
		$this->assertSame( array(), get_userdata( $again )->roles );
	}

	public function test_run_keeps_the_anonymous_user_on_a_host_that_does_not_filter() {
		$this->assertTrue( Service_Context::anonymous_posts_visible() );
		$this->assertSame( 0, Service_Context::run( static fn() => get_current_user_id() ) );
		$this->assertFalse( get_option( Service_Context::OPTION ), 'no service user is made when none is needed' );
	}

	public function test_run_uses_the_service_user_on_a_filtering_host_and_restores_the_previous_user() {
		self::anonymous_host_filter();
		$this->assertFalse( Service_Context::anonymous_posts_visible() );
		$service = Service_Context::service_user();

		$this->assertSame( $service, Service_Context::run( static fn() => get_current_user_id() ) );
		$this->assertSame( 0, get_current_user_id() );

		try {
			Service_Context::run(
				static function () {
					throw new RuntimeException( 'inside' );
				}
			);
			$this->fail( 'the exception is not swallowed' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'inside', $e->getMessage() );
		}
		$this->assertSame( 0, get_current_user_id(), 'restored after a throw' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->assertSame( $admin, Service_Context::run( static fn() => get_current_user_id() ), 'a logged-in user is kept' );
	}

	public function test_host_queries_report_what_each_query_finds() {
		self::factory()->comment->create( array( 'comment_post_ID' => self::factory()->post->create() ) );
		$all = array(
			'anonymous'    => true,
			'service_user' => true,
		);
		$this->assertSame(
			array(
				'posts'    => $all,
				'users'    => $all,
				'comments' => $all,
			),
			Service_Context::host_queries()
		);

		self::anonymous_host_filter();
		$filtered = array(
			'anonymous'    => false,
			'service_user' => true,
		);
		$this->assertSame(
			array(
				'posts'    => $filtered,
				'users'    => $filtered,
				'comments' => $filtered,
			),
			Service_Context::host_queries()
		);
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_no_comment_to_probe_is_null() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->comments}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNull( Service_Context::host_queries()['comments']['anonymous'] );
		$this->assertNull( Service_Context::comments_visible() );
	}
}
