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
		$this->assertStringStartsWith( Service_Context::LOGIN_PREFIX, $user->user_login );
		$this->assertSame( '', $user->user_email );
		$this->assertTrue( Service_Context::is_safe( $id ) );
		$this->assertSame( array(), $user->roles );
		$this->assertSame( array(), $user->caps );
		$this->assertSame( '', get_user_meta( $id, $wpdb->get_blog_prefix() . 'capabilities', true ), 'no capabilities meta' );
		$this->assertSame( '*', $user->user_pass, 'no usable password' );
		$this->assertFalse( wp_check_password( '*', $user->user_pass, $id ) );
		$this->assertSame( 'false', get_user_meta( $id, 'show_admin_bar_front', true ) );
		$this->assertInstanceOf( WP_Error::class, wp_authenticate( $user->user_login, '*' ) );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $id );
		$again = Service_Context::service_user();
		$this->assertGreaterThan( 0, $again );
		$this->assertNotSame( $id, $again, 'recreated after deletion' );
		$this->assertSame( array(), get_userdata( $again )->roles );
	}

	public function test_an_account_with_the_service_login_is_never_adopted() {
		$squatter = self::factory()->user->create(
			array(
				'user_login' => 'learning-ledger-service',
				'user_email' => 'attacker@example.org',
				'role'       => 'subscriber',
			)
		);
		$id       = Service_Context::service_user();
		$this->assertNotSame( $squatter, $id );
		$this->assertStringStartsWith( Service_Context::LOGIN_PREFIX, get_userdata( $id )->user_login );
		$this->assertNotSame( 'learning-ledger-service', get_userdata( $id )->user_login );
	}

	public function test_a_service_user_that_gained_a_role_email_password_or_session_is_refused() {
		self::anonymous_host_filter();
		$id   = Service_Context::service_user();
		$log  = ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keeps the expected log line out of the test output.
		$runs = static fn(): int => Service_Context::run( static fn() => get_current_user_id() );
		try {
			foreach ( array(
				'role'     => static fn() => get_userdata( $id )->set_role( 'subscriber' ),
				'email'    => static fn() => wp_update_user(
					array(
						'ID'         => $id,
						'user_email' => 'someone@example.org',
					)
				),
				'password' => static fn() => wp_set_password( 'known', $id ),
				'session'  => static fn() => WP_Session_Tokens::get_instance( $id )->create( time() + 3600 ),
				'app'      => static fn() => WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'x' ) ),
			) as $what => $break ) {
				$this->assertSame( $id, $runs(), 'safe before: ' . $what );
				$break();
				$this->assertFalse( Service_Context::is_safe( $id ), $what );
				$this->assertSame( 0, $runs(), 'refused: ' . $what );
				// Make it safe again for the next case.
				global $wpdb;
				get_userdata( $id )->set_role( '' );
				delete_user_meta( $id, $wpdb->get_blog_prefix() . 'capabilities' );
				$wpdb->update( $wpdb->users, array( 'user_email' => '', 'user_pass' => '*' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				WP_Session_Tokens::get_instance( $id )->destroy_all();
				delete_user_meta( $id, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS );
				clean_user_cache( $id );
			}
		} finally {
			ini_set( 'error_log', (string) $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
	}

	public function test_nested_runs_restore_each_user() {
		self::anonymous_host_filter();
		$service = Service_Context::service_user();
		$seen    = Service_Context::run(
			static function () {
				$inner = Service_Context::run( static fn() => get_current_user_id() );
				try {
					Service_Context::run(
						static function () {
							throw new RuntimeException( 'inner' );
						}
					);
				} catch ( RuntimeException $e ) {
					unset( $e );
				}
				return array( $inner, get_current_user_id() );
			}
		);
		$this->assertSame( array( $service, $service ), $seen, 'the inner runs keep the outer service user' );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_a_filtered_comment_query_alone_switches() {
		self::factory()->comment->create( array( 'comment_post_ID' => self::factory()->post->create() ) );
		add_action(
			'pre_get_comments',
			static function ( $q ) {
				if ( ! is_user_logged_in() ) {
					$q->query_vars['comment__in'] = array( 0 );
				}
			}
		);
		Service_Context::reset();
		$this->assertTrue( Service_Context::host_filters_anonymous() );
		$this->assertSame( Service_Context::service_user(), Service_Context::run( static fn() => get_current_user_id() ) );
	}

	public function test_spam_and_trash_comments_are_not_probed() {
		$post = self::factory()->post->create();
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => 'spam',
			)
		);
		$this->assertNull( Service_Context::comments_visible(), 'only a spam comment: nothing to probe' );
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '0',
			)
		);
		$this->assertTrue( Service_Context::comments_visible() );
	}

	public function test_run_keeps_the_anonymous_user_on_a_host_that_does_not_filter() {
		$this->assertFalse( Service_Context::host_filters_anonymous() );
		$this->assertSame( 0, Service_Context::run( static fn() => get_current_user_id() ) );
		$this->assertFalse( get_option( Service_Context::OPTION ), 'no service user is made when none is needed' );
	}

	public function test_run_uses_the_service_user_on_a_filtering_host_and_restores_the_previous_user() {
		self::anonymous_host_filter();
		$this->assertTrue( Service_Context::host_filters_anonymous() );
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
		$this->assertNull( Service_Context::host_queries()['posts']['service_user'], 'no service user yet' );
		$this->assertFalse( get_option( Service_Context::OPTION ), 'the status probe never creates it' );
		Service_Context::service_user();
		$all = array(
			'anonymous'       => true,
			'service_user'    => true,
			'with_capability' => array(
				'read'           => true,
				'edit_posts'     => true,
				'manage_options' => true,
			),
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
			'anonymous'       => false,
			'service_user'    => true,
			'with_capability' => array(
				'read'           => true,
				'edit_posts'     => true,
				'manage_options' => true,
			),
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

	public function test_capability_probes_show_what_a_host_keys_on() {
		self::factory()->comment->create( array( 'comment_post_ID' => self::factory()->post->create() ) );
		$service = Service_Context::service_user();
		// A host that empties queries unless the current user can edit posts.
		foreach ( array( 'pre_get_posts' => 'post__in', 'pre_get_users' => 'include' ) as $hook => $var ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			add_action(
				$hook,
				static function ( $q ) use ( $var ) {
					if ( ! current_user_can( 'edit_posts' ) ) {
						$q->set( $var, array( 0 ) );
					}
				}
			);
		}
		add_action(
			'pre_get_comments',
			static function ( $q ) {
				if ( ! current_user_can( 'edit_posts' ) ) {
					$q->query_vars['comment__in'] = array( 0 );
				}
			}
		);
		$expected = array(
			'anonymous'       => false,
			'service_user'    => false,
			'with_capability' => array(
				'read'           => false,
				'edit_posts'     => true,
				'manage_options' => false,
			),
		);
		$this->assertSame(
			array(
				'posts'    => $expected,
				'users'    => $expected,
				'comments' => $expected,
			),
			Service_Context::host_queries()
		);
		$this->assertFalse( user_can( $service, 'edit_posts' ), 'the capability was in memory only' );
		$this->assertSame( array(), get_userdata( $service )->caps );
		$this->assertFalse( has_filter( 'user_has_cap' ) && self::grants_left(), 'no grant filter is left behind' );
	}

	private static function grants_left(): bool {
		global $wp_filter;
		return isset( $wp_filter['user_has_cap'] ) && isset( $wp_filter['user_has_cap']->callbacks[ PHP_INT_MAX ] );
	}
}
