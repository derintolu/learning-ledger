<?php
/**
 * Lesson and course extension fields (post meta) and their editor panel.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Blocks;

use LearningLedger\Kernel\Module;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;

/**
 * Registers the lesson and course meta, validates it, and enqueues the editor panel.
 */
final class Lesson_Fields_Module implements Module {

	private const MAX_BYTES = 1048576;

	private const LESSON_JSON = array(
		'_lledger_starter_files' => 'files',
		'_lledger_sources'       => 'sources',
	);

	private const COURSE_JSON = array(
		'_lledger_preview'     => 'preview',
		'_lledger_final_files' => 'files',
		'_lledger_landing'     => 'object',
	);

	/**
	 * Course meta readable over REST only by users who can edit the course (final files are answers).
	 */
	private const COURSE_PRIVATE = array( '_lledger_final_files' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'validate_request' ), 10, 3 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Register the meta for each filtered post type.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$lessons = (array) apply_filters( 'learning_ledger/lesson_post_types', array() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Slash-namespaced hook name is the plugin convention.
		$courses = (array) apply_filters( 'learning_ledger/course_post_types', array() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Slash-namespaced hook name is the plugin convention.

		foreach ( $lessons as $type ) {
			$this->register_for( (string) $type, self::LESSON_JSON );
			$this->register_key( (string) $type, '_lledger_minutes', 'integer', 'absint' );
			$this->register_key( (string) $type, '_lledger_licence', 'string', 'sanitize_text_field' );
		}
		foreach ( $courses as $type ) {
			$this->register_for( (string) $type, self::COURSE_JSON );
			if ( post_type_exists( (string) $type ) ) {
				add_filter( 'rest_prepare_' . $type, array( $this, 'hide_private_meta' ), 10, 2 );
			}
		}
	}

	/**
	 * Drop private course meta from a REST response unless the current user can edit the post.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Post.
	 *
	 * @return \WP_REST_Response
	 */
	public function hide_private_meta( \WP_REST_Response $response, \WP_Post $post ): \WP_REST_Response {
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) || current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}
		foreach ( self::COURSE_PRIVATE as $key ) {
			unset( $data['meta'][ $key ] );
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Register JSON-string meta keys for a post type.
	 *
	 * @param string                $type Post type.
	 * @param array<string, string> $keys Meta key => shape.
	 *
	 * @return void
	 */
	private function register_for( string $type, array $keys ): void {
		foreach ( $keys as $key => $shape ) {
			$this->register_key(
				$type,
				$key,
				'string',
				static fn( $value ) => self::sanitize( $value, $shape )
			);
		}
	}

	/**
	 * Register one REST-exposed meta key, editable by users who can edit the post.
	 *
	 * @param string   $type     Post type.
	 * @param string   $key      Meta key.
	 * @param string   $kind     Meta type.
	 * @param callable $sanitize Sanitize callback.
	 *
	 * @return void
	 */
	private function register_key( string $type, string $key, string $kind, callable $sanitize ): void {
		if ( ! post_type_exists( $type ) ) {
			return;
		}
		add_post_type_support( $type, 'custom-fields' );
		register_post_meta(
			$type,
			$key,
			array(
				'type'              => $kind,
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => $sanitize,
				'auth_callback'     => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', (int) $post_id ),
			)
		);
	}

	/**
	 * Reject REST writes carrying invalid JSON meta with a 400.
	 *
	 * @param mixed $response Response so far.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request.
	 *
	 * @return mixed
	 */
	public function validate_request( mixed $response, mixed $handler, mixed $request ): mixed {
		unset( $handler );
		if ( ! $request instanceof \WP_REST_Request || ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return $response;
		}
		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) ) {
			return $response;
		}
		$shapes = self::LESSON_JSON + self::COURSE_JSON;
		foreach ( $shapes as $key => $shape ) {
			if ( isset( $meta[ $key ] ) && ! self::valid( $meta[ $key ], $shape ) ) {
				return new \WP_Error(
					'rest_invalid_param',
					/* translators: %s: meta key. */
					sprintf( __( 'Invalid JSON for %s.', 'learning-ledger' ), $key ),
					array( 'status' => 400 )
				);
			}
		}
		return $response;
	}

	/**
	 * Enqueue the editor panel script.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$asset_file = LEARNING_LEDGER_DIR . 'build/fields/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script(
			'learning-ledger-fields',
			plugins_url( 'build/fields/index.js', LEARNING_LEDGER_DIR . 'learning-ledger.php' ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
	}

	/**
	 * Sanitize callback: canonical JSON when valid, otherwise an empty string.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $shape Expected shape.
	 *
	 * @return string
	 */
	private static function sanitize( mixed $value, string $shape ): string {
		$clean = self::normalise( $value, $shape );
		return null === $clean ? '' : Json::encode_exact( $clean );
	}

	/**
	 * Whether a value is empty (clearing the field) or valid JSON of the shape.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $shape Expected shape.
	 *
	 * @return boolean
	 */
	private static function valid( mixed $value, string $shape ): bool {
		return '' === $value || null !== self::normalise( $value, $shape );
	}

	/**
	 * Decode and shape-check a JSON string.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $shape One of files, sources, preview, object.
	 *
	 * @return mixed|null Normalised value, or null when invalid.
	 */
	private static function normalise( mixed $value, string $shape ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		try {
			$data = Json::decode_strict( $value, self::MAX_BYTES );
		} catch ( Invalid_Input $e ) {
			return null;
		}
		switch ( $shape ) {
			case 'files':
				return self::rows( $data, array( 'path', 'content' ) );
			case 'sources':
				return self::rows( $data, array( 'title', 'url' ) );
			case 'preview':
				$files = is_array( $data ) && isset( $data['files'] ) ? self::rows( $data['files'], array( 'path', 'content' ) ) : null;
				if ( null === $files || ! is_string( $data['kind'] ?? null ) ) {
					return null;
				}
				return array(
					'kind'  => $data['kind'],
					'files' => $files,
				);
			default:
				return is_array( $data ) && ( array() === $data || ! Json::is_list( $data ) ) ? ( array() === $data ? (object) array() : $data ) : null;
		}
	}

	/**
	 * A list of objects whose given fields are all strings; other fields are dropped.
	 *
	 * @param mixed    $data   Decoded value.
	 * @param string[] $fields Required string fields.
	 *
	 * @return array|null
	 */
	private static function rows( mixed $data, array $fields ): ?array {
		if ( ! is_array( $data ) || ! Json::is_list( $data ) ) {
			return null;
		}
		$out = array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				return null;
			}
			$clean = array();
			foreach ( $fields as $f ) {
				if ( ! isset( $row[ $f ] ) || ! is_string( $row[ $f ] ) ) {
					return null;
				}
				$clean[ $f ] = $row[ $f ];
			}
			$out[] = $clean;
		}
		return $out;
	}
}
