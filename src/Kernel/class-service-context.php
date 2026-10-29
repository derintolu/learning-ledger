<?php
/**
 * Runs the plugin's own background and signed-request work as a capability-less service user
 * on hosts that filter anonymous queries.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Kernel;

/**
 * Some hosts empty post, user and comment queries for anonymous requests (no logged-in user).
 * Inbox jobs, signed course-server requests and content pings are anonymous, and Sensei keeps
 * lesson progress in comments it reads through WP_Comment_Query, which plugin code cannot replace.
 * On such a host only, this work runs with a dedicated user logged in for its duration.
 *
 * The service user has no role and no capabilities, cannot log in, and is never relied on for
 * permission: every route still authorises its caller itself.
 */
final class Service_Context {

	/**
	 * Option holding the service user's id.
	 */
	public const OPTION = 'lledger_service_user';

	/**
	 * The service user's login.
	 */
	public const LOGIN = 'learning-ledger-service';

	/**
	 * Whether an anonymous post query finds a row that exists (null: nothing to probe).
	 *
	 * @var boolean|null
	 */
	private static ?bool $anonymous_posts = null;

	/**
	 * Whether $anonymous_posts holds this request's probe.
	 *
	 * @var boolean
	 */
	private static bool $probed = false;

	/**
	 * Run a callable, as the service user when anonymous post queries are filtered on this host
	 * and nobody is logged in; the previous current user is restored afterwards.
	 *
	 * @param callable $cb Work.
	 *
	 * @return mixed What $cb returns.
	 */
	public static function run( callable $cb ): mixed {
		if ( is_user_logged_in() || false !== self::anonymous_posts_visible() ) {
			return $cb();
		}
		$service = self::service_user();
		if ( $service <= 0 ) {
			return $cb();
		}
		$previous = get_current_user_id();
		wp_set_current_user( $service );
		try {
			return $cb();
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * A REST callback wrapped in run().
	 *
	 * @param callable $cb Callback taking the request.
	 *
	 * @return \Closure
	 */
	public static function wrap( callable $cb ): \Closure {
		return static fn( \WP_REST_Request $r ) => self::run( static fn() => $cb( $r ) );
	}

	/**
	 * Whether a post query with no current user finds a post that exists (null when none exists
	 * to probe), cached for the request.
	 *
	 * @return boolean|null
	 */
	public static function anonymous_posts_visible(): ?bool {
		if ( ! self::$probed ) {
			self::$anonymous_posts = self::as_user( 0, array( self::class, 'posts_visible' ) );
			self::$probed          = true;
		}
		return self::$anonymous_posts;
	}

	/**
	 * Forget the cached probe (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$anonymous_posts = null;
		self::$probed          = false;
	}

	/**
	 * For the signed status: whether post, user and comment queries find a row that exists,
	 * with no current user and with the service user. Null where there is no row or the probe failed.
	 *
	 * @return array<string,array{anonymous:?bool,service_user:?bool}>
	 */
	public static function host_queries(): array {
		$service = self::service_user();
		$out     = array();
		foreach ( array(
			'posts'    => array( self::class, 'posts_visible' ),
			'users'    => array( self::class, 'users_visible' ),
			'comments' => array( self::class, 'comments_visible' ),
		) as $name => $probe ) {
			$out[ $name ] = array(
				'anonymous'    => self::as_user( 0, $probe ),
				'service_user' => $service > 0 ? self::as_user( $service, $probe ) : null,
			);
		}
		return $out;
	}

	/**
	 * Sensei's lesson progress storage: `comments` or `tables`, null without Sensei.
	 *
	 * @return string|null
	 */
	public static function sensei_progress_storage(): ?string {
		$settings = '\Sensei\Internal\Services\Progress_Storage_Settings';
		try {
			if ( ! class_exists( $settings ) || ! method_exists( $settings, 'is_tables_repository' ) ) {
				return null;
			}
			return $settings::is_tables_repository() ? 'tables' : 'comments';
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The service user's id, created (or recreated) on first use; 0 when it cannot be made.
	 *
	 * @return integer
	 */
	public static function service_user(): int {
		$id = (int) get_option( self::OPTION, 0 );
		if ( $id > 0 && get_userdata( $id ) ) {
			return $id;
		}
		$existing = get_user_by( 'login', self::LOGIN );
		if ( $existing ) {
			$id = (int) $existing->ID;
		} else {
			$made = wp_insert_user(
				array(
					'user_login'           => self::LOGIN,
					'user_pass'            => wp_generate_password( 64, true, true ),
					'display_name'         => 'Learning Ledger service',
					'role'                 => '',
					'show_admin_bar_front' => 'false',
				)
			);
			if ( is_wp_error( $made ) ) {
				$existing = get_user_by( 'login', self::LOGIN );
				if ( ! $existing ) {
					return 0;
				}
				$made = $existing->ID;
			}
			$id = (int) $made;
		}
		self::strip( $id );
		update_option( self::OPTION, $id, false );
		return $id;
	}

	/**
	 * Remove every role, capability and the usable password from the service user.
	 *
	 * @param integer $id User id.
	 *
	 * @return void
	 */
	private static function strip( int $id ): void {
		global $wpdb;
		delete_user_meta( $id, $wpdb->get_blog_prefix() . 'capabilities' );
		delete_user_meta( $id, $wpdb->get_blog_prefix() . 'user_level' );
		update_user_meta( $id, 'show_admin_bar_front', 'false' );
		// No password hash ever matches '*', so the user cannot log in.
		$wpdb->update( $wpdb->users, array( 'user_pass' => '*' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_user_cache( $id );
	}

	/**
	 * Run a probe as a given current user, restoring the previous one; null on any error.
	 *
	 * @param integer  $user  User id (0 for none).
	 * @param callable $probe Probe.
	 *
	 * @return boolean|null
	 */
	private static function as_user( int $user, callable $probe ): ?bool {
		$previous = get_current_user_id();
		try {
			if ( $previous !== $user ) {
				wp_set_current_user( $user );
			}
			return $probe();
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			if ( get_current_user_id() !== $previous ) {
				wp_set_current_user( $previous );
			}
		}
	}

	/**
	 * Whether get_posts() finds a program post (or any published post) a direct read proves exists.
	 *
	 * @return boolean|null
	 */
	public static function posts_visible(): ?bool {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE post_type = 'lledger_program' AND post_status = 'publish' ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $row ? $row : $wpdb->get_row( "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE post_status = 'publish' ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $row ) {
			return null;
		}
		$found = get_posts(
			array(
				'post_type'      => (string) $row->post_type,
				'post_status'    => (string) $row->post_status,
				'include'        => array( (int) $row->ID ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		return in_array( (int) $row->ID, array_map( 'intval', $found ), true );
	}

	/**
	 * Whether get_users() finds the first user.
	 *
	 * @return boolean|null
	 */
	public static function users_visible(): ?bool {
		global $wpdb;
		$id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $id <= 0 ) {
			return null;
		}
		$found = get_users(
			array(
				'include' => array( $id ),
				'blog_id' => 0,
				'fields'  => 'ID',
				'number'  => 1,
			)
		);
		return in_array( $id, array_map( 'intval', $found ), true );
	}

	/**
	 * Whether get_comments() finds the first comment (of its own type), null when there is none.
	 *
	 * @return boolean|null
	 */
	public static function comments_visible(): ?bool {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT comment_ID, comment_type FROM {$wpdb->comments} ORDER BY comment_ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $row ) {
			return null;
		}
		$found = get_comments(
			array(
				'comment__in'               => array( (int) $row->comment_ID ),
				'type'                      => (string) $row->comment_type,
				'status'                    => 'all',
				'fields'                    => 'ids',
				'number'                    => 1,
				'update_comment_meta_cache' => false,
			)
		);
		return in_array( (int) $row->comment_ID, array_map( 'intval', (array) $found ), true );
	}
}
