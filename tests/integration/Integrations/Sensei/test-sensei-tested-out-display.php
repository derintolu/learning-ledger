<?php
/**
 * Sensei "Tested out" display.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Db\Installer;
use LearningLedger\Integrations\Sensei\Sensei_Tested_Out_Display;
use LearningLedger\Util\Uuid;

/**
 * Sensei_Tested_Out_Display tests.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Sensei_Tested_Out_Display_Test extends WP_UnitTestCase {

	private int $user;
	private int $waived;
	private int $normal;

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		$this->user   = self::factory()->user->create();
		$this->waived = $this->lesson( 'Waived lesson' );
		$this->normal = $this->lesson( 'Normal lesson' );
		( new Waiver_Ledger() )->record( $this->user, $this->waived, Uuid::v4(), Uuid::v4(), 'Tested Out', false );
		wp_set_current_user( $this->user );
		new Sensei_Tested_Out_Display();
	}

	private function lesson( string $title ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'lesson',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	private function icon_for( int $lesson, string $status = 'complete' ): string {
		// Sensei builds the lesson link just before the icon; the display reads the lesson from that.
		get_permalink( $lesson );
		return apply_filters( 'sensei_learning_mode_lesson_status_icon', '<svg class="icon"></svg>', $status );
	}

	public function test_learning_mode_icon_labels_waived_lesson() {
		$html = $this->icon_for( $this->waived );
		$this->assertStringContainsString( '<svg class="icon"></svg>', $html );
		$this->assertStringContainsString( 'Tested out', $html );
	}

	public function test_learning_mode_icon_untouched_for_normal_lesson() {
		$this->assertSame( '<svg class="icon"></svg>', $this->icon_for( $this->normal ) );
	}

	public function test_learning_mode_icon_untouched_for_other_user() {
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( '<svg class="icon"></svg>', $this->icon_for( $this->waived ) );
	}

	private function render( string $html, string $name ): string {
		$block = new WP_Block( array( 'blockName' => $name ) );
		return apply_filters( 'render_block', $html, $block->parsed_block, $block );
	}

	private function outline( int $lesson ): string {
		return '<section><a href="' . esc_url( get_permalink( $lesson ) ) . '" class="wp-block-sensei-lms-course-outline-lesson completed"><svg></svg><span>Lesson</span></a></section>';
	}

	public function test_outline_appends_badge_for_waived_lesson() {
		$html = $this->render( $this->outline( $this->waived ) . $this->outline( $this->normal ), 'sensei-lms/course-outline' );
		$this->assertSame( 1, substr_count( $html, 'Tested out' ) );
		$this->assertMatchesRegularExpression( '#Tested out</span>\s*</a>#', $html );
		$this->assertStringContainsString( esc_url( get_permalink( $this->normal ) ) . '" class="wp-block-sensei-lms-course-outline-lesson completed"><svg></svg><span>Lesson</span></a>', $html );
	}

	public function test_outline_ignores_other_blocks() {
		$in = $this->outline( $this->waived );
		$this->assertSame( $in, $this->render( $in, 'core/paragraph' ) );
	}

	public function test_outline_untouched_when_logged_out() {
		wp_set_current_user( 0 );
		$in = $this->outline( $this->waived );
		$this->assertSame( $in, $this->render( $in, 'sensei-lms/course-outline' ) );
	}

	private function row( int $lesson, int $user, string $status = '<span class="">Not started</span>' ): array {
		$post = get_post( $lesson );
		apply_filters( 'sensei_analysis_course_user_lesson', array( 'post_id' => $lesson ), $post, $user );
		$data = apply_filters( 'sensei_analysis_course_column_data', array( 'user_status' => $status ), $post, new stdClass() );
		return $data;
	}

	public function test_analysis_status_for_waived_lesson() {
		$data = $this->row( $this->waived, $this->user, '<span class="graded">Completed</span>' );
		$this->assertSame( '<span class="graded">Complete (tested out)</span>', $data['user_status'] );
	}

	public function test_analysis_status_csv_is_plain_text() {
		$data = $this->row( $this->waived, $this->user, 'Completed' );
		$this->assertSame( 'Complete (tested out)', $data['user_status'] );
	}

	public function test_analysis_status_unchanged_for_normal_lesson() {
		$data = $this->row( $this->normal, $this->user, '<span class="graded">Completed</span>' );
		$this->assertSame( '<span class="graded">Completed</span>', $data['user_status'] );
	}

	public function test_analysis_uses_the_reported_user_not_the_current_one() {
		$other = self::factory()->user->create();
		$data  = $this->row( $this->waived, $other, '<span class="">Not started</span>' );
		$this->assertSame( '<span class="">Not started</span>', $data['user_status'] );
	}

	public function test_label_is_escaped() {
		$filter = static fn( $t, $text ) => 'Tested out' === $text ? '<script>x</script>' : $t;
		add_filter( 'gettext_learning-ledger', $filter, 10, 2 );
		$html   = $this->icon_for( $this->waived ) . $this->render( $this->outline( $this->waived ), 'sensei-lms/course-outline' );
		remove_filter( 'gettext_learning-ledger', $filter, 10 );
		$this->assertStringNotContainsString( '<script>', $html );
	}
}
