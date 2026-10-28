<?php
/**
 * Lesson and course extension field tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Blocks\Lesson_Fields_Module;

class Lesson_Fields_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		register_post_type(
			'lledger_lesson',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'custom-fields' ),
			)
		);
		register_post_type(
			'lledger_path',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'custom-fields' ),
			)
		);
		add_filter( 'learning_ledger/lesson_post_types', array( $this, 'lesson_types' ) );
		add_filter( 'learning_ledger/course_post_types', array( $this, 'course_types' ) );
		$module = new Lesson_Fields_Module();
		$module->register();
		$module->register_meta();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	public function tear_down() {
		remove_filter( 'learning_ledger/lesson_post_types', array( $this, 'lesson_types' ) );
		remove_filter( 'learning_ledger/course_post_types', array( $this, 'course_types' ) );
		foreach ( array( '_lledger_starter_files', '_lledger_sources', '_lledger_minutes', '_lledger_licence' ) as $k ) {
			unregister_meta_key( 'post', $k, 'lledger_lesson' );
		}
		foreach ( array( '_lledger_preview', '_lledger_final_files', '_lledger_landing' ) as $k ) {
			unregister_meta_key( 'post', $k, 'lledger_path' );
		}
		unregister_post_type( 'lledger_lesson' );
		unregister_post_type( 'lledger_path' );
		parent::tear_down();
	}

	public function lesson_types() {
		return array( 'lledger_lesson' );
	}

	public function course_types() {
		return array( 'lledger_path' );
	}

	private function update( int $post, string $type, array $meta ) {
		$req = new WP_REST_Request( 'POST', "/wp/v2/{$type}/$post" );
		$req->set_body_params( array( 'meta' => $meta ) );
		return rest_get_server()->dispatch( $req );
	}

	private function editor(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	public function test_default_post_types_are_empty() {
		remove_filter( 'learning_ledger/lesson_post_types', array( $this, 'lesson_types' ) );
		$this->assertSame( array(), apply_filters( 'learning_ledger/lesson_post_types', array() ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Slash-namespaced hook.
	}

	public function test_meta_registered_for_filtered_types() {
		foreach ( array( '_lledger_starter_files', '_lledger_sources', '_lledger_minutes', '_lledger_licence' ) as $k ) {
			$this->assertTrue( registered_meta_key_exists( 'post', $k, 'lledger_lesson' ), $k );
			$this->assertFalse( registered_meta_key_exists( 'post', $k, 'lledger_path' ), $k );
		}
		foreach ( array( '_lledger_preview', '_lledger_final_files', '_lledger_landing' ) as $k ) {
			$this->assertTrue( registered_meta_key_exists( 'post', $k, 'lledger_path' ), $k );
			$this->assertFalse( registered_meta_key_exists( 'post', $k, 'lledger_lesson' ), $k );
		}
		$this->assertFalse( registered_meta_key_exists( 'post', '_lledger_minutes', 'post' ) );
	}

	public function test_editor_can_save_valid_values() {
		$this->editor();
		$post  = self::factory()->post->create( array( 'post_type' => 'lledger_lesson' ) );
		$files = '[{"path":"index.html","content":"<p>hi</p>"}]';
		$res   = $this->update(
			$post,
			'lledger_lesson',
			array(
				'_lledger_starter_files' => $files,
				'_lledger_sources'       => '[{"title":"MDN","url":"https://developer.mozilla.org/"}]',
				'_lledger_minutes'       => 25,
				'_lledger_licence'       => 'CC-BY-4.0',
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $files, get_post_meta( $post, '_lledger_starter_files', true ) );
		$this->assertSame( 25, (int) get_post_meta( $post, '_lledger_minutes', true ) );
		$this->assertSame( 'CC-BY-4.0', get_post_meta( $post, '_lledger_licence', true ) );
	}

	public function test_course_fields_save() {
		$this->editor();
		$post = self::factory()->post->create( array( 'post_type' => 'lledger_path' ) );
		$res  = $this->update(
			$post,
			'lledger_path',
			array(
				'_lledger_preview'     => '{"kind":"html","files":[{"path":"a.html","content":"x"}]}',
				'_lledger_final_files' => '[{"path":"a.html","content":"y"}]',
				'_lledger_landing'     => '{"anything":{"goes":[1,2]}}',
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '{"anything":{"goes":[1,2]}}', get_post_meta( $post, '_lledger_landing', true ) );
	}

	public function test_final_files_are_read_only_by_users_who_can_edit_the_course() {
		$post  = self::factory()->post->create(
			array(
				'post_type'   => 'lledger_path',
				'post_status' => 'publish',
			)
		);
		$files = '[{"path":"a.html","content":"answer"}]';
		update_post_meta( $post, '_lledger_final_files', $files );
		update_post_meta( $post, '_lledger_landing', '{"a":1}' );
		$get = static function ( string $context ) use ( $post ): WP_REST_Response {
			$req = new WP_REST_Request( 'GET', "/wp/v2/lledger_path/$post" );
			$req->set_param( 'context', $context );
			return rest_get_server()->dispatch( $req );
		};

		wp_set_current_user( 0 );
		$anon = $get( 'view' );
		$this->assertSame( 200, $anon->get_status() );
		$this->assertArrayNotHasKey( '_lledger_final_files', $anon->get_data()['meta'], 'anonymous' );
		$this->assertSame( '{"a":1}', $anon->get_data()['meta']['_lledger_landing'], 'other course meta stays public' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertArrayNotHasKey( '_lledger_final_files', $get( 'view' )->get_data()['meta'], 'subscriber' );

		$collection = new WP_REST_Request( 'GET', '/wp/v2/lledger_path' );
		$listed     = rest_get_server()->dispatch( $collection )->get_data();
		$this->assertArrayNotHasKey( '_lledger_final_files', $listed[0]['meta'], 'subscriber, collection' );

		$this->editor();
		$edit = $get( 'edit' );
		$this->assertSame( 200, $edit->get_status() );
		$this->assertSame( $files, $edit->get_data()['meta']['_lledger_final_files'], 'editor' );
	}

	public function test_subscriber_cannot_update() {
		$post = self::factory()->post->create( array( 'post_type' => 'lledger_lesson' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$res = $this->update( $post, 'lledger_lesson', array( '_lledger_minutes' => 5 ) );
		$this->assertContains( $res->get_status(), array( 401, 403 ) );
		$this->assertSame( '', get_post_meta( $post, '_lledger_minutes', true ) );
	}

	public function test_malformed_json_rejected_with_400() {
		$this->editor();
		$post = self::factory()->post->create( array( 'post_type' => 'lledger_lesson' ) );
		$res  = $this->update( $post, 'lledger_lesson', array( '_lledger_starter_files' => '{not json' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( '', get_post_meta( $post, '_lledger_starter_files', true ) );
	}

	public function test_wrong_shapes_rejected() {
		$this->editor();
		$post = self::factory()->post->create( array( 'post_type' => 'lledger_lesson' ) );
		foreach ( array( '{"path":"a","content":"b"}', '[{"path":"a"}]', '[{"path":1,"content":"b"}]', '"str"' ) as $bad ) {
			$res = $this->update( $post, 'lledger_lesson', array( '_lledger_starter_files' => $bad ) );
			$this->assertSame( 400, $res->get_status(), $bad );
		}
		$res = $this->update( $post, 'lledger_lesson', array( '_lledger_sources' => '[{"title":"x"}]' ) );
		$this->assertSame( 400, $res->get_status() );
		$path = self::factory()->post->create( array( 'post_type' => 'lledger_path' ) );
		$res  = $this->update( $path, 'lledger_path', array( '_lledger_preview' => '{"kind":"html"}' ) );
		$this->assertSame( 400, $res->get_status() );
		$res = $this->update( $path, 'lledger_path', array( '_lledger_landing' => '[1,2]' ) );
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_oversize_rejected() {
		$this->editor();
		$post = self::factory()->post->create( array( 'post_type' => 'lledger_lesson' ) );
		$big  = '[{"path":"a","content":"' . str_repeat( 'x', 1048577 ) . '"}]';
		$res  = $this->update( $post, 'lledger_lesson', array( '_lledger_starter_files' => $big ) );
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_sanitize_callback_empties_invalid_values() {
		$post = self::factory()->post->create( array( 'post_type' => 'lledger_lesson' ) );
		update_post_meta( $post, '_lledger_starter_files', 'garbage' );
		$this->assertSame( '', get_post_meta( $post, '_lledger_starter_files', true ) );
	}

	public function test_enqueues_fields_script_on_editor_assets() {
		$m = new Lesson_Fields_Module();
		$m->register();
		$this->assertNotFalse( has_action( 'enqueue_block_editor_assets', array( $m, 'enqueue' ) ) );
	}
}
