<?php
/**
 * Program repository tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Cmi5\Course_Structure_Parser;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;

class Program_Repository_Test extends WP_UnitTestCase {

	private Program_Repository $repo;

	public function set_up() {
		parent::set_up();
		$module = new Program_Module();
		$module->register();
		$module->register_post_type();
		$module->register_meta();
		$this->repo = new Program_Repository();
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	private function make( string $key, string $base, array $meta = array() ): int {
		$id   = self::factory()->post->create(
			array(
				'post_type'   => 'lledger_program',
				'post_status' => 'publish',
				'post_title'  => $key,
			)
		);
		$meta = array_merge(
			array(
				'_lledger_stable_key' => $key,
				'_lledger_iri_base'   => $base,
			),
			$meta
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		return $id;
	}

	public function test_get_defaults_and_fields() {
		$id = $this->make( 'prog', 'https://a/x/' );
		$p  = $this->repo->get( $id );
		$this->assertSame( 'prog', $p->stable_key );
		$this->assertSame( 'https://a/x/', $p->iri_base );
		$this->assertSame( 'https://a/x', $p->course_iri );
		$this->assertTrue( $p->waive_recommended );
		$this->assertSame( 0, $p->version );
		$this->assertSame( array(), $p->course_ids );
		$this->assertSame( array(), $p->path_keys );
		$this->assertNull( $this->repo->for_lms_course( 0 ) );
		$this->assertNull( $this->repo->get( self::factory()->post->create() ) );
		$this->assertNull( $this->repo->get( 999999 ) );

		update_post_meta( $id, '_lledger_course_iri', 'https://a/x/course' );
		update_post_meta( $id, '_lledger_waive_recommended', false );
		update_post_meta( $id, '_lledger_courses', array( 7, 9 ) );
		update_post_meta( $id, '_lledger_path_keys', array( '7' => 'alpha' ) );
		$p = $this->repo->get( $id );
		$this->assertSame( 'https://a/x/course', $p->course_iri );
		$this->assertFalse( $p->waive_recommended );
		$this->assertSame( array( 7, 9 ), $p->course_ids );
		$this->assertSame( 'alpha', $p->path_keys[7] );
	}

	public function test_find_by_key_and_lms_course() {
		$a = $this->make( 'one', 'https://a/one/', array( '_lledger_courses' => array( 5, 15 ) ) );
		$b = $this->make( 'two', 'https://a/two/', array( '_lledger_courses' => array( 25 ) ) );
		$this->assertSame( $a, $this->repo->find_by_key( 'one' ) );
		$this->assertNull( $this->repo->find_by_key( 'nope' ) );
		$this->assertSame( $a, $this->repo->for_lms_course( 15 ) );
		$this->assertSame( $b, $this->repo->for_lms_course( 25 ) );
		$this->assertNull( $this->repo->for_lms_course( 1 ) );
	}

	public function test_find_by_key_ignores_a_host_that_empties_post_queries() {
		$id = $this->make( 'hosted', 'https://a/hosted/' );
		add_action( 'pre_get_posts', static fn( $q ) => $q->set( 'post__in', array( 0 ) ) );
		$this->assertSame( array(), get_posts( array( 'post_type' => 'lledger_program' ) ) );
		$this->assertSame( $id, $this->repo->find_by_key( 'hosted' ) );
		wp_trash_post( $id );
		$this->assertNull( $this->repo->find_by_key( 'hosted' ) );
	}

	public function test_for_activity_matches_longest_base() {
		$short = $this->make( 'short', 'https://a/x/' );
		$long  = $this->make( 'long', 'https://a/x/y/' );
		$this->assertSame( $long, $this->repo->for_activity( 'https://a/x/y/lessons/k/au' ) );
		$this->assertSame( $short, $this->repo->for_activity( 'https://a/x/lessons/k/au' ) );
		$this->assertNull( $this->repo->for_activity( 'https://b/x/lessons/k/au' ) );
	}

	public function test_meta_auth_denies_subscriber() {
		$id = $this->make( 'prog', 'https://a/x/' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/lledger_program/' . $id );
		$request->set_body_params( array( 'meta' => array( '_lledger_rules' => 'x' ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( '', (string) get_post_meta( $id, '_lledger_rules', true ) );
	}

	public function test_meta_auth_callback_checks_edit_post() {
		$id = $this->make( 'prog', 'https://a/x/' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $id, '_lledger_rules' ) );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $user )->add_cap( 'lledger_manage_connector' );
		wp_set_current_user( $user );
		$this->assertTrue( current_user_can( 'edit_post_meta', $id, '_lledger_rules' ) );
	}

	public function test_meta_auth_allows_connector_manager() {
		$id   = $this->make( 'prog', 'https://a/x/' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $user )->add_cap( 'lledger_manage_connector' );
		wp_set_current_user( $user );
		$request = new WP_REST_Request( 'POST', '/wp/v2/lledger_program/' . $id );
		$request->set_body_params( array( 'meta' => array( '_lledger_rules' => 'x' ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'x', get_post_meta( $id, '_lledger_rules', true ) );
	}

	public function test_post_type_is_ui_only_and_capability_gated() {
		$pt = get_post_type_object( 'lledger_program' );
		$this->assertFalse( $pt->public );
		$this->assertTrue( $pt->show_ui );
		$this->assertTrue( $pt->show_in_rest );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertFalse( user_can( $editor, $pt->cap->edit_posts ) );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $user )->add_cap( 'lledger_manage_connector' );
		$this->assertTrue( user_can( $user, $pt->cap->edit_posts ) );
	}

	public function test_bump_version_only_on_new_etag() {
		$id = $this->make( 'prog', 'https://a/x/' );
		$this->assertSame( 1, $this->repo->bump_version( $id, 'e1' ) );
		$this->assertSame( 1, $this->repo->bump_version( $id, 'e1' ) );
		$this->assertSame( 2, $this->repo->bump_version( $id, 'e2' ) );
		$p = $this->repo->get( $id );
		$this->assertSame( 2, $p->version );
		$this->assertSame( 'e2', $p->etag );
	}

	public function test_structure_round_trip() {
		$id = $this->make( 'prog', 'https://a/x/' );
		$this->assertNull( $this->repo->structure( $id ) );
		$s = Course_Structure_Parser::parse(
			(string) file_get_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				dirname( __DIR__, 3 ) . '/fixtures/cmi5/nested-blocks.xml'
			)
		);
		$this->repo->set_structure( $id, $s );
		$back = $this->repo->structure( $id );
		$this->assertEquals( $s->to_array(), $back->to_array() );
	}

	public function test_stable_key_registered_for_filtered_post_types() {
		add_filter(
			'learning_ledger/stable_key_post_types',
			static fn( $t ) => array_merge( $t, array( 'page' ) )
		);
		( new Program_Module() )->register_meta();
		$this->assertArrayHasKey( '_lledger_stable_key', get_registered_meta_keys( 'post', 'page' ) );
	}

	public function test_saved_action_fires() {
		$fired = 0;
		add_action(
			'learning_ledger/program_saved',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$this->make( 'prog', 'https://a/x/' );
		$this->assertGreaterThan( 0, $fired );
	}
}
