<?php
/**
 * Shows lessons a learner tested out of in Sensei's outline, learning mode and reports.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Sensei;

use LearningLedger\Connector\Ledger\Waiver_Ledger;

/**
 * Adds a "Tested out" marker to waived lessons.
 */
final class Sensei_Tested_Out_Display {

	/**
	 * Waiver ledger.
	 *
	 * @var Waiver_Ledger
	 */
	private Waiver_Ledger $ledger;

	/**
	 * Lesson whose permalink was built last (learning mode builds the link just before the icon).
	 *
	 * @var integer
	 */
	private int $last_lesson = 0;

	/**
	 * Lesson IDs reported waived by the course analysis filter, awaiting their row data.
	 *
	 * @var array<int,bool>
	 */
	private array $analysis_waived = array();

	/**
	 * Hook into Sensei.
	 *
	 * @param Waiver_Ledger|null $ledger Waiver ledger.
	 */
	public function __construct( ?Waiver_Ledger $ledger = null ) {
		$this->ledger = $ledger ?? new Waiver_Ledger();

		add_filter( 'post_type_link', array( $this, 'remember_lesson' ), 10, 2 );
		add_filter( 'sensei_learning_mode_lesson_status_icon', array( $this, 'status_icon' ), 10, 2 );
		add_filter( 'render_block', array( $this, 'outline_badge' ), 10, 2 );
		add_filter( 'sensei_analysis_course_user_lesson', array( $this, 'analysis_lesson' ), 10, 3 );
		add_filter( 'sensei_analysis_course_column_data', array( $this, 'analysis_row' ), 10, 2 );
	}

	/**
	 * Note which lesson a permalink was built for. Passes the URL through.
	 *
	 * @param mixed $link Permalink.
	 * @param mixed $post Post.
	 *
	 * @return mixed
	 */
	public function remember_lesson( mixed $link, mixed $post ): mixed {
		$this->last_lesson = ( $post instanceof \WP_Post && 'lesson' === $post->post_type ) ? $post->ID : 0;
		return $link;
	}

	/**
	 * Learning mode: label and mark a waived lesson's status icon.
	 *
	 * @param mixed $icon   Icon HTML.
	 * @param mixed $status Lesson status.
	 *
	 * @return mixed
	 */
	public function status_icon( mixed $icon, mixed $status = '' ): mixed {
		unset( $status );
		if ( ! is_string( $icon ) || ! $this->is_waived_for_current_user( $this->last_lesson ) ) {
			return $icon;
		}
		$label = __( 'Tested out', 'learning-ledger' );
		return '<span class="learning-ledger-tested-out" title="' . esc_attr( $label ) . '">' . $icon
			. '<span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
	}

	/**
	 * Course outline: append a badge to each waived lesson link.
	 *
	 * @param mixed $html  Block HTML.
	 * @param mixed $block Parsed block.
	 *
	 * @return mixed
	 */
	public function outline_badge( mixed $html, mixed $block ): mixed {
		if ( ! is_string( $html ) || ! is_array( $block ) || 'sensei-lms/course-outline' !== ( $block['blockName'] ?? '' ) ) {
			return $html;
		}
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return $html;
		}
		$urls = array();
		foreach ( $this->ledger->for_user( $user ) as $waiver ) {
			$url = get_permalink( $waiver['lesson_id'] );
			if ( $url ) {
				$urls[ esc_url( $url ) ] = true;
			}
		}
		if ( ! $urls ) {
			return $html;
		}
		$badge = '<span class="wp-block-sensei-lms-course-outline-lesson__badge learning-ledger-tested-out">'
			. esc_html__( 'Tested out', 'learning-ledger' ) . '</span>';

		$out = preg_replace_callback(
			'#<a href="([^"]*)"([^>]*wp-block-sensei-lms-course-outline-lesson[^>]*)>(.*?)</a>#s',
			static function ( array $m ) use ( $urls, $badge ): string {
				return isset( $urls[ $m[1] ] ) ? '<a href="' . $m[1] . '"' . $m[2] . '>' . $m[3] . $badge . '</a>' : $m[0];
			},
			$html
		);
		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Course analysis, one user's lessons: remember whether the row's lesson is waived for that user.
	 * Arguments are returned unchanged.
	 *
	 * @param mixed $args    Lesson status query arguments.
	 * @param mixed $item    Lesson post.
	 * @param mixed $user_id User being reported on.
	 *
	 * @return mixed
	 */
	public function analysis_lesson( mixed $args, mixed $item = null, mixed $user_id = 0 ): mixed {
		if ( $item instanceof \WP_Post && $this->ledger->is_waived( (int) $user_id, $item->ID ) ) {
			$this->analysis_waived[ $item->ID ] = true;
		}
		return $args;
	}

	/**
	 * Course analysis: show "Complete (tested out)" as the status of a waived lesson.
	 *
	 * @param mixed $data Row data.
	 * @param mixed $item Lesson post.
	 *
	 * @return mixed
	 */
	public function analysis_row( mixed $data, mixed $item = null ): mixed {
		if ( ! $item instanceof \WP_Post || ! isset( $this->analysis_waived[ $item->ID ] ) ) {
			return $data;
		}
		unset( $this->analysis_waived[ $item->ID ] );
		if ( ! is_array( $data ) || ! isset( $data['user_status'] ) || ! is_string( $data['user_status'] ) ) {
			return $data;
		}
		$label = __( 'Complete (tested out)', 'learning-ledger' );
		if ( wp_strip_all_tags( $data['user_status'] ) === $data['user_status'] ) {
			$data['user_status'] = $label;
		} else {
			$data['user_status'] = (string) preg_replace_callback(
				'#>[^<]*<#',
				static fn(): string => '>' . esc_html( $label ) . '<',
				$data['user_status'],
				1
			);
		}
		return $data;
	}

	/**
	 * Whether a lesson is waived for the current user.
	 *
	 * @param integer $lesson Lesson ID.
	 *
	 * @return boolean
	 */
	private function is_waived_for_current_user( int $lesson ): bool {
		$user = get_current_user_id();
		return $lesson > 0 && $user > 0 && $this->ledger->is_waived( $user, $lesson );
	}
}
