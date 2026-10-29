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
 * On such a host only (an anonymous post or comment query misses a row that exists), this work
 * runs with a dedicated user logged in for its duration.
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
	 * Start of the service user's login; a random suffix follows, so nobody can register it first.
	 */
	public const LOGIN_PREFIX = 'learning-ledger-service-';

	/**
	 * Capabilities the diagnostics grant the service user in memory, one probe each.
	 */
	public const PROBE_CAPABILITIES = array( 'read', 'edit_posts', 'manage_options' );

	/**
	 * Whether anonymous post and comment queries find a row that exists, this request's probe
	 * (null for a query with nothing to probe).
	 *
	 * @var array{posts:?bool,comments:?bool}|null
	 */
	private static ?array $anonymous = null;

	/**
	 * Run a callable, as the service user when nobody is logged in and this host filters anonymous
	 * post or comment queries; the previous current user is restored afterwards.
	 *
	 * Callers run it only after they have authorised the request: an unauthenticated request never
	 * probes, creates or runs as the service user.
	 *
	 * @param callable $cb Work.
	 *
	 * @return mixed What $cb returns.
	 */
	public static function run( callable $cb ): mixed {
		if ( is_user_logged_in() || ! self::host_filters_anonymous() ) {
			return $cb();
		}
		$service = self::service_user();
		if ( $service <= 0 || ! self::is_safe( $service ) ) {
			error_log( 'Learning Ledger: the service user ' . $service . ' is missing or holds a role, capability, email, password, application password or session; not switching to it.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operators must know why the work runs anonymously.
			return $cb();
		}
		$previous = get_current_user_id();
		// Posts written meanwhile (imported courses and lessons) must not belong to the service user:
		// deleting it without reassigning would delete them.
		$author = static function ( $data ) use ( $service ) {
			if ( is_array( $data ) && (int) ( $data['post_author'] ?? 0 ) === $service ) {
				$data['post_author'] = 0;
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $author, PHP_INT_MAX );
		wp_set_current_user( $service );
		try {
			return $cb();
		} finally {
			wp_set_current_user( $previous );
			remove_filter( 'wp_insert_post_data', $author, PHP_INT_MAX );
		}
	}

	/**
	 * Whether an anonymous post or comment query misses a row that exists, cached for the request.
	 *
	 * @return boolean
	 */
	public static function host_filters_anonymous(): bool {
		if ( null === self::$anonymous ) {
			self::$anonymous = array(
				'posts'    => self::as_user( 0, array( self::class, 'posts_visible' ) ),
				'comments' => self::as_user( 0, array( self::class, 'comments_visible' ) ),
			);
		}
		return false === self::$anonymous['posts'] || false === self::$anonymous['comments'];
	}

	/**
	 * Forget the cached probe (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$anonymous = null;
	}

	/**
	 * For the signed status: whether post, user and comment queries find a row that exists,
	 * with no current user and with the service user. Null where there is no row or the probe failed.
	 *
	 * @return array<string,array{anonymous:?bool,service_user:?bool,with_capability:array<string,?bool>}>
	 */
	public static function host_queries(): array {
		// Never creates the service user: without a usable one, its column is null.
		$service = self::existing_service_user();
		$service = $service > 0 && self::is_safe( $service ) ? $service : 0;
		$out     = array();
		foreach ( array(
			'posts'    => array( self::class, 'posts_visible' ),
			'users'    => array( self::class, 'users_visible' ),
			'comments' => array( self::class, 'comments_visible' ),
		) as $name => $probe ) {
			$with = array();
			foreach ( self::PROBE_CAPABILITIES as $cap ) {
				$with[ $cap ] = $service > 0 ? self::with_capability( $service, $cap, $probe ) : null;
			}
			$out[ $name ] = array(
				'anonymous'       => self::as_user( 0, $probe ),
				'service_user'    => $service > 0 ? self::as_user( $service, $probe ) : null,
				'with_capability' => $with,
			);
		}
		return $out;
	}

	/**
	 * A probe as the service user while it holds one capability, granted in memory only by a
	 * `user_has_cap` filter added and removed around the probe; nothing is stored.
	 *
	 * @param integer  $service Service user id.
	 * @param string   $cap     Capability.
	 * @param callable $probe   Probe.
	 *
	 * @return boolean|null
	 */
	private static function with_capability( int $service, string $cap, callable $probe ): ?bool {
		$grant = static function ( $allcaps, $caps, $args, $user ) use ( $service, $cap ) {
			unset( $caps, $args );
			if ( $user instanceof \WP_User && $service === (int) $user->ID ) {
				$allcaps         = is_array( $allcaps ) ? $allcaps : array();
				$allcaps[ $cap ] = true;
			}
			return $allcaps;
		};
		add_filter( 'user_has_cap', $grant, PHP_INT_MAX, 4 );
		try {
			return self::as_user( $service, $probe );
		} finally {
			remove_filter( 'user_has_cap', $grant, PHP_INT_MAX );
		}
	}

	/**
	 * The service user stored in the option, 0 when there is none (or it was deleted).
	 *
	 * @return integer
	 */
	public static function existing_service_user(): int {
		$id = (int) get_option( self::OPTION, 0 );
		return $id > 0 && get_userdata( $id ) ? $id : 0;
	}

	/**
	 * The service user's id, created (or recreated) on first use; 0 when it cannot be made.
	 * Only the user id the option holds is ever used: an existing account is never adopted.
	 *
	 * @return integer
	 */
	public static function service_user(): int {
		$id = self::existing_service_user();
		if ( $id > 0 ) {
			return $id;
		}
		$made = wp_insert_user(
			array(
				'user_login'           => self::LOGIN_PREFIX . bin2hex( random_bytes( 6 ) ),
				'user_pass'            => wp_generate_password( 64, true, true ),
				'user_email'           => '',
				'display_name'         => 'Learning Ledger service',
				'role'                 => '',
				'show_admin_bar_front' => 'false',
			)
		);
		if ( is_wp_error( $made ) || (int) $made <= 0 ) {
			return 0;
		}
		$id = (int) $made;
		self::strip( $id );
		update_option( self::OPTION, $id, false );
		return $id;
	}

	/**
	 * Whether a user is still fit to be the service user: no role, no capability, no email, the
	 * unusable password, no application password, no session and not a super admin.
	 *
	 * @param integer $id User id.
	 *
	 * @return boolean
	 */
	public static function is_safe( int $id ): bool {
		global $wpdb;
		clean_user_cache( $id );
		$user = get_userdata( $id );
		if ( ! $user || array() !== (array) $user->roles || array() !== (array) $user->caps || '' !== (string) $user->user_email || '*' !== (string) $user->user_pass ) {
			return false;
		}
		if ( '' !== get_user_meta( $id, $wpdb->get_blog_prefix() . 'capabilities', true ) || is_super_admin( $id ) ) {
			return false;
		}
		if ( class_exists( '\WP_Application_Passwords' ) && array() !== \WP_Application_Passwords::get_user_application_passwords( $id ) ) {
			return false;
		}
		return array() === \WP_Session_Tokens::get_instance( $id )->get_all();
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
	 * Whether get_comments() finds the first approved or held comment (of its own type), null when there is none.
	 *
	 * @return boolean|null
	 */
	public static function comments_visible(): ?bool {
		global $wpdb;
		// Approved or held only: get_comments( status all ) leaves spam and trash out, which is no filter.
		$row = $wpdb->get_row( "SELECT comment_ID, comment_type FROM {$wpdb->comments} WHERE comment_approved IN ('0', '1') ORDER BY comment_ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
