<?php
/**
 * Registers the program post type, its meta and CLI.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Programs;

use LearningLedger\Kernel\Module;

/**
 * Program post type module.
 */
final class Program_Module implements Module {

	public const CAP  = 'lledger_manage_connector';
	public const MENU = 'learning-ledger';

	public const CUSTODY = array( 'lms-launched', 'author-lrs', 'server-kept', 'practice' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ), 5 );
		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'save_post_' . Program_Repository::POST_TYPE, array( $this, 'on_save' ), 10, 2 );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'learning-ledger program', Program_Command::class );
		}
	}

	/**
	 * Registers the post type.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		// Only primitive caps are mapped: aliasing the meta caps (edit_post...) to the connector cap would make that cap itself resolve as a meta cap.
		$caps = array();
		foreach ( array( 'edit_posts', 'edit_others_posts', 'delete_posts', 'publish_posts', 'read_private_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ) as $cap ) {
			$caps[ $cap ] = self::CAP;
		}
		register_post_type(
			Program_Repository::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Programs', 'learning-ledger' ),
					'singular_name' => __( 'Program', 'learning-ledger' ),
					'menu_name'     => __( 'Programs', 'learning-ledger' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => self::MENU,
				'show_in_rest'    => true,
				'capability_type' => 'lledger_program',
				'capabilities'    => $caps,
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'custom-fields' ),
			)
		);
	}

	/**
	 * Registers the top-level menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Learning Ledger', 'learning-ledger' ),
			__( 'Learning Ledger', 'learning-ledger' ),
			self::CAP,
			self::MENU,
			array( $this, 'render_menu' ),
			'dashicons-welcome-learn-more',
			58
		);
	}

	/**
	 * Renders the menu landing page.
	 *
	 * @return void
	 */
	public function render_menu(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'learning-ledger' ), 403 );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Learning Ledger', 'learning-ledger' ) . '</h1><p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Program_Repository::POST_TYPE ) ) . '">' . esc_html__( 'Programs', 'learning-ledger' ) . '</a></p></div>';
	}

	/**
	 * Fires the saved action.
	 *
	 * @param integer  $post_id Post id.
	 * @param \WP_Post $post    Post.
	 *
	 * @return void
	 */
	public function on_save( int $post_id, \WP_Post $post ): void {
		/**
		 * A program was saved.
		 *
		 * @param int      $post_id Program post id.
		 * @param \WP_Post $post    Program post.
		 */
		do_action( 'learning_ledger/program_saved', $post_id, $post ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * Registers post meta.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$auth = static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', (int) $post_id );
		$base = array(
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => $auth,
		);
		$pt   = Program_Repository::POST_TYPE;

		$reg = static function ( string $key, array $args ) use ( $pt, $base ): void {
			register_post_meta( $pt, $key, array_merge( $base, $args ) );
		};

		$str  = array( 'type' => 'string' );
		$json = array(
			'type'              => 'string',
			'sanitize_callback' => static fn( $v ) => ( '' === $v || null !== json_decode( (string) $v ) ) ? (string) $v : '',
		);

		$reg(
			'_lledger_iri_base',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_iri_base' ),
			)
		);
		$reg(
			'_lledger_course_iri',
			array(
				'type'              => 'string',
				'sanitize_callback' => static fn( $v ) => esc_url_raw( (string) $v ),
			)
		);
		$reg(
			'_lledger_courses',
			array(
				'type'              => 'array',
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
				'sanitize_callback' => static fn( $v ) => array_values( array_map( 'absint', (array) $v ) ),
			)
		);
		$reg(
			'_lledger_path_keys',
			array(
				'type'         => 'object',
				'show_in_rest' => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'string' ),
					),
				),
			)
		);
		$reg( '_lledger_placement', $json );
		$reg( '_lledger_rules', $str );
		$reg( '_lledger_badges', $json );
		$reg( '_lledger_badge_issuer', $json );
		$reg(
			'_lledger_custody',
			array(
				'type'              => 'string',
				'sanitize_callback' => static fn( $v ) => in_array( $v, self::CUSTODY, true ) ? $v : 'lms-launched',
			)
		);
		$reg(
			'_lledger_course_server_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => static fn( $v ) => esc_url_raw( (string) $v ),
			)
		);
		$reg(
			'_lledger_au_url_template',
			array(
				'type'              => 'string',
				'sanitize_callback' => static fn( $v ) => str_contains( (string) $v, '{key}' ) ? esc_url_raw( (string) $v, array( 'http', 'https' ) ) : '',
			)
		);
		$reg(
			'_lledger_waive_recommended',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => static fn( $v ) => $v ? 1 : 0,
			)
		);
		$reg( '_lledger_structure', $json );
		$reg( '_lledger_version', array( 'type' => 'integer' ) );
		$reg( '_lledger_etag', $str );
		$reg(
			'_lledger_player',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => static fn( $v ) => $v ? 1 : 0,
			)
		);

		/**
		 * Post types that carry a stable key.
		 *
		 * @param string[] $post_types Post types.
		 */
		$types = (array) apply_filters( 'learning_ledger/stable_key_post_types', array( $pt ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		foreach ( array_unique( array_merge( array( $pt ), $types ) ) as $type ) {
			register_post_meta(
				(string) $type,
				'_lledger_stable_key',
				array_merge(
					$base,
					array(
						'type'              => 'string',
						'sanitize_callback' => array( self::class, 'sanitize_stable_key' ),
					)
				)
			);
		}
	}

	/**
	 * Keeps a stable key to [a-z0-9-]{1,64}.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	public static function sanitize_stable_key( mixed $value ): string {
		$key = substr( preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $value ) ), 0, 64 );
		return (string) $key;
	}

	/**
	 * Requires an absolute http(s) IRI and a trailing slash.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	public static function sanitize_iri_base( mixed $value ): string {
		$url   = esc_url_raw( (string) $value, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! str_starts_with( (string) $value, (string) $parts['scheme'] . '://' ) ) {
			return '';
		}
		return trailingslashit( $url );
	}
}
