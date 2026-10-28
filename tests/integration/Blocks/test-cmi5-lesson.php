<?php
/**
 * cmi5 lesson block tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- test fixtures.

use LearningLedger\Blocks\Blocks_Module;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
use LearningLedger\Player\Launch\Session_Repository;

/**
 * The learning-ledger/cmi5-lesson block: status, launch buttons and the editor warning.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test class.
class Cmi5_Lesson_Test extends WP_UnitTestCase {

	const AU_ID = 'https://w3id.org/xapi/cmi5/catapult/lts/au/001-essentials';

	private int $course;

	private int $lesson;

	private int $learner;

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::name( 'cmi5_sessions' ) ) );
		update_option( 'lledger_roles', array( 'lrs', 'connector' ) );
		( new Integration_Loader() )->load();
		( new Blocks_Module() )->register_blocks();

		$this->course  = self::factory()->post->create(
			array(
				'post_type'   => 'course',
				'post_status' => 'publish',
			)
		);
		$this->lesson  = self::factory()->post->create(
			array(
				'post_type'    => 'lesson',
				'post_status'  => 'publish',
				'post_content' => self::markup( self::AU_ID ),
			)
		);
		$this->learner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_post_meta( $this->lesson, '_lesson_course', $this->course );
		update_post_meta( $this->lesson, '_order_' . $this->course, 1 );
		update_post_meta( $this->course, '_lesson_order', (string) $this->lesson );
		unset( $_GET['context'] );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		unset( $_GET['context'] );
		$GLOBALS['post'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		delete_option( 'lledger_roles' );
		parent::tear_down();
	}

	private static function markup( string $au ): string {
		return '<!-- wp:learning-ledger/cmi5-lesson ' . wp_json_encode( array( 'auId' => $au ) ) . ' /-->';
	}

	/**
	 * Render the lesson's block for the current user the way the lesson page does.
	 */
	private function render(): string {
		$GLOBALS['post'] = get_post( $this->lesson ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		return do_blocks( get_post( $this->lesson )->post_content );
	}

	private function enrol(): void {
		$this->assertTrue( ( new Sensei_Adapter() )->enrol( $this->learner, $this->course ) );
	}

	private function open_session(): void {
		( new Session_Repository() )->open( $this->learner, self::AU_ID, wp_generate_uuid4(), $this->lesson, 'Normal' );
	}

	public function test_registered_with_au_id_attribute() {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'learning-ledger/cmi5-lesson' );
		$this->assertNotNull( $type );
		$this->assertArrayHasKey( 'auId', $type->attributes );
		$this->assertSame( 'string', $type->attributes['auId']['type'] );
		$this->assertNotEmpty( $type->view_script_handles, 'view.js is the block view script' );
	}

	public function test_logged_out_sees_a_sign_in_prompt_and_no_launch_control() {
		$html = $this->render();
		$this->assertStringContainsString( 'wp-login.php', $html );
		$this->assertStringContainsString( 'Sign in', $html );
		$this->assertStringNotContainsString( 'data-lledger-mode', $html );
		$this->assertStringNotContainsString( 'data-nonce', $html );
		$this->assertStringNotContainsString( '<button', $html );
	}

	public function test_not_enrolled_shows_not_started_and_no_launch() {
		wp_set_current_user( $this->learner );
		$html = $this->render();
		$this->assertStringContainsString( 'Not started', $html );
		$this->assertStringContainsString( 'Enrol in this course', $html );
		$this->assertStringNotContainsString( 'data-lledger-mode="Normal"', $html );
		$this->assertStringNotContainsString( 'data-lledger-mode="Review"', $html );
		$this->assertStringNotContainsString( 'data-lledger-mode="Browse"', $html );
	}

	public function test_enrolled_not_started_offers_launch_with_the_rest_nonce() {
		$this->enrol();
		wp_set_current_user( $this->learner );
		$html = $this->render();
		$this->assertStringContainsString( 'Not started', $html );
		$this->assertStringContainsString( 'data-lledger-mode="Normal"', $html );
		$this->assertStringNotContainsString( 'data-lledger-mode="Review"', $html );
		$this->assertStringContainsString( 'data-lesson="' . $this->lesson . '"', $html );
		$this->assertStringContainsString( 'data-endpoint="' . esc_url( rest_url( 'learning-ledger/v1/cmi5/launch' ) ) . '"', $html );
		$this->assertSame( 1, preg_match( '/data-nonce="([^"]+)"/', $html, $m ) );
		$this->assertSame( 1, wp_verify_nonce( $m[1], 'wp_rest' ) );
	}

	public function test_open_session_shows_in_progress() {
		$this->enrol();
		$this->open_session();
		wp_set_current_user( $this->learner );
		$html = $this->render();
		$this->assertStringContainsString( 'In progress', $html );
		$this->assertStringNotContainsString( 'Not started', $html );
		$this->assertStringContainsString( 'data-lledger-mode="Normal"', $html );
	}

	public function test_closed_normal_session_is_still_in_progress_but_browse_is_not() {
		$this->enrol();
		wp_set_current_user( $this->learner );
		$sessions = new Session_Repository();
		$browse   = $sessions->open( $this->learner, self::AU_ID, wp_generate_uuid4(), $this->lesson, 'Browse' );
		$this->assertStringContainsString( 'Not started', $this->render() );
		$normal = $sessions->open( $this->learner, self::AU_ID, wp_generate_uuid4(), $this->lesson, 'Normal' );
		$sessions->mark( $normal, Session_Repository::TERMINATED );
		$sessions->mark( $browse, Session_Repository::TERMINATED );
		$this->assertStringContainsString( 'In progress', $this->render() );
	}

	public function test_satisfied_shows_review_and_no_launch() {
		$this->enrol();
		\Sensei_Utils::sensei_start_lesson( $this->lesson, $this->learner, true );
		wp_set_current_user( $this->learner );
		$html = $this->render();
		$this->assertStringContainsString( 'Satisfied', $html );
		$this->assertStringContainsString( 'data-lledger-mode="Review"', $html );
		$this->assertStringNotContainsString( 'data-lledger-mode="Normal"', $html );
	}

	public function test_waived_shows_the_reason_escaped() {
		$this->enrol();
		( new Waiver_Ledger() )->record( $this->learner, $this->lesson, wp_generate_uuid4(), wp_generate_uuid4(), 'Prior <script>alert(1)</script> training', false );
		wp_set_current_user( $this->learner );
		$html = $this->render();
		$this->assertStringContainsString( 'Waived', $html );
		$this->assertStringContainsString( 'Prior &lt;script&gt;alert(1)&lt;/script&gt; training', $html );
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringContainsString( 'data-lledger-mode="Review"', $html );
		$this->assertStringNotContainsString( 'data-lledger-mode="Normal"', $html );
	}

	public function test_browse_is_offered_to_lesson_editors_only() {
		$this->enrol();
		wp_set_current_user( $this->learner );
		$this->assertStringNotContainsString( 'data-lledger-mode="Browse"', $this->render() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = $this->render();
		$this->assertStringContainsString( 'data-lledger-mode="Browse"', $html );
		$this->assertStringNotContainsString( 'data-lledger-mode="Normal"', $html, 'an editor who is not enrolled cannot launch normally' );
	}

	public function test_output_is_escaped() {
		$evil = '"><img src=x onerror=alert(1)>';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) ); // Saves without content filtering.
		wp_update_post(
			array(
				'ID'           => $this->lesson,
				'post_content' => wp_slash( self::markup( $evil ) ),
			)
		);
		$this->enrol();
		wp_set_current_user( $this->learner );
		$html = $this->render();
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( 'Lesson content:', $html );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$preview = $this->editor_preview( true, $evil );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $preview );
		$this->assertStringNotContainsString( '<img src=x', $preview );
	}

	public function test_return_to_lesson_fallback_link_and_frame_title_data() {
		$this->enrol();
		wp_set_current_user( $this->learner );
		$html = $this->render();
		$this->assertStringContainsString( 'data-lledger-return hidden', $html );
		$this->assertStringContainsString( 'Return to lesson', $html );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $this->lesson ) ) . '"', $html );
		$this->assertStringContainsString( 'data-frame-title="Lesson content: ', $html );
	}

	public function test_edit_context_outside_a_rest_request_renders_the_front_end() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->pass_required_quiz();
		$_GET['context'] = 'edit';
		$html            = $this->render();
		$this->assertStringNotContainsString( 'data-lledger-warning', $html );
		$this->assertStringContainsString( 'data-lledger-mode="Browse"', $html );
	}

	public function test_no_au_id_renders_nothing() {
		wp_update_post(
			array(
				'ID'           => $this->lesson,
				'post_content' => self::markup( '' ),
			)
		);
		$this->enrol();
		wp_set_current_user( $this->learner );
		$this->assertSame( '', trim( $this->render() ) );
	}

	private function pass_required_quiz(): void {
		$quiz = self::factory()->post->create(
			array(
				'post_type'   => 'quiz',
				'post_status' => 'publish',
				'post_parent' => $this->lesson,
			)
		);
		update_post_meta( $quiz, '_pass_required', 'on' );
		update_post_meta( $this->lesson, '_quiz_has_questions', 1 );
	}

	/**
	 * The block-renderer response the editor preview is built from.
	 */
	private function editor_preview( bool $edit_context = true, string $au = self::AU_ID ): string {
		if ( $edit_context ) {
			$_GET['context'] = 'edit';
		}
		$req = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/learning-ledger/cmi5-lesson' );
		$req->set_param( 'context', $edit_context ? 'edit' : 'view' );
		$req->set_param( 'post_id', $this->lesson );
		$req->set_param( 'attributes', array( 'auId' => $au ) );
		$res = rest_get_server()->dispatch( $req );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		return (string) $res->get_data()['rendered'];
	}

	public function test_editor_preview_flags_a_pass_required_quiz() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->pass_required_quiz();
		$html = $this->editor_preview();
		$this->assertStringContainsString( 'data-lledger-warning="pass-required-quiz"', $html );
		$this->assertStringContainsString( 'Sensei quiz', $html );
		$this->assertStringNotContainsString( '<button', $html, 'the preview has no launch controls' );
	}

	public function test_editor_preview_has_no_flag_without_a_quiz() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringNotContainsString( 'data-lledger-warning', $this->editor_preview() );
	}

	public function test_warning_flag_is_not_shown_on_the_front_end() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->pass_required_quiz();
		$this->assertStringNotContainsString( 'data-lledger-warning', $this->render() );
	}

	public function test_warning_flag_needs_edit_rights() {
		$this->pass_required_quiz();
		wp_set_current_user( $this->learner );
		$_GET['context'] = 'edit';
		$this->assertStringNotContainsString( 'data-lledger-warning', $this->render() );
	}
}
