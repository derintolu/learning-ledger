<?php
/**
 * Cmi5 lesson front end: the learner's status and the launch, review and browse controls.
 *
 * In the block editor's server-side preview (`context=edit`, for someone who can edit the lesson)
 * it renders a static summary and, when the lesson has a Sensei quiz that must be passed, a warning.
 *
 * @package LearningLedger
 *
 * @var array    $attributes Block attributes.
 * @var WP_Block $block      Block instance.
 */

use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Db\Tables;

defined( 'ABSPATH' ) || exit;

global $wpdb;

$lledger_lesson = (int) ( $block->context['postId'] ?? get_the_ID() );
$lledger_au     = (string) ( $attributes['auId'] ?? '' );
if ( '' === $lledger_au || 'lesson' !== get_post_type( $lledger_lesson ) ) {
	return;
}
$lledger_adapter = Integration_Loader::adapter( 'sensei' );
if ( null === $lledger_adapter ) {
	return;
}
$lledger_course   = (int) get_post_meta( $lledger_lesson, '_lesson_course', true );
$lledger_can_edit = current_user_can( 'edit_post', $lledger_lesson );

// The editor's server-side preview.
$lledger_context = isset( $_GET['context'] ) ? sanitize_key( wp_unslash( $_GET['context'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display switch, gated by the edit capability.
if ( 'edit' === $lledger_context && $lledger_can_edit ) {
	$lledger_quiz = false;
	foreach ( $lledger_adapter->course_tree( $lledger_course )->lessons as $lledger_node ) {
		if ( $lledger_node->id === $lledger_lesson ) {
			$lledger_quiz = $lledger_node->has_pass_required_quiz;
		}
	}
	?>
	<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core. ?>>
		<p>
			<?php
			/* translators: %s: assignable unit id. */
			echo esc_html( sprintf( __( 'cmi5 lesson: %s', 'learning-ledger' ), $lledger_au ) );
			?>
		</p>
		<?php if ( $lledger_quiz ) : ?>
			<p class="lledger-cmi5-lesson__warning" role="alert" data-lledger-warning="pass-required-quiz">
				<?php esc_html_e( 'This lesson also has a Sensei quiz that must be passed. A learner has to satisfy the cmi5 unit and pass the quiz, so remove the quiz or its pass requirement.', 'learning-ledger' ); ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
	return;
}

if ( ! is_user_logged_in() ) {
	?>
	<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core. ?>>
		<p>
			<?php esc_html_e( 'Sign in to start this lesson.', 'learning-ledger' ); ?>
			<a href="<?php echo esc_url( wp_login_url( (string) get_permalink( $lledger_lesson ) ) ); ?>"><?php esc_html_e( 'Sign in', 'learning-ledger' ); ?></a>
		</p>
	</div>
	<?php
	return;
}

$lledger_user     = get_current_user_id();
$lledger_enrolled = $lledger_course > 0 && class_exists( '\Sensei_Course' ) && \Sensei_Course::is_user_enrolled( $lledger_course, $lledger_user );
$lledger_waiver   = ( new Waiver_Ledger() )->get( $lledger_user, $lledger_lesson );
$lledger_progress = $lledger_adapter->progress( $lledger_user, $lledger_course );

// Waived first (a later real completion clears the waiver), then satisfied, then in progress.
if ( null !== $lledger_waiver ) {
	$lledger_state = 'waived';
} elseif ( in_array( $lledger_lesson, $lledger_progress->completed_lesson_ids, true ) ) {
	$lledger_state = 'satisfied';
} elseif ( (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a read of the launch sessions, which have no "ever launched" query.
	$wpdb->prepare(
		'SELECT 1 FROM %i WHERE user_id = %d AND au_hash = UNHEX(%s) AND launch_mode = %s LIMIT 1',
		Tables::name( 'cmi5_sessions' ),
		$lledger_user,
		sha1( $lledger_au ),
		'Normal'
	)
) ) {
	// A Normal launch has been made, whether or not the session is still open.
	$lledger_state = 'in-progress';
} else {
	$lledger_state = 'not-started';
}
$lledger_done = in_array( $lledger_state, array( 'waived', 'satisfied' ), true );

$lledger_buttons = array();
if ( $lledger_enrolled ) {
	if ( $lledger_done ) {
		$lledger_buttons['Review'] = __( 'Review', 'learning-ledger' );
	} else {
		$lledger_buttons['Normal'] = __( 'Launch', 'learning-ledger' );
	}
}
if ( $lledger_can_edit ) {
	$lledger_buttons['Browse'] = __( 'Browse', 'learning-ledger' );
}

$lledger_wrapper = array();
if ( array() !== $lledger_buttons ) {
	$lledger_wrapper = array(
		'data-endpoint' => rest_url( 'learning-ledger/v1/cmi5/launch' ),
		'data-nonce'    => wp_create_nonce( 'wp_rest' ),
		'data-lesson'   => (string) $lledger_lesson,
		'data-failed'   => __( 'The lesson could not be launched. Try again.', 'learning-ledger' ),
	);
}
?>
<div <?php echo get_block_wrapper_attributes( $lledger_wrapper ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core. ?>>
	<p class="lledger-cmi5-lesson__status" data-lledger-status="<?php echo esc_attr( $lledger_state ); ?>">
		<?php
		if ( 'waived' === $lledger_state ) {
			$lledger_reason = (string) $lledger_waiver['reason'];
			if ( '' === $lledger_reason ) {
				esc_html_e( 'Waived', 'learning-ledger' );
			} else {
				/* translators: %s: why the lesson was waived. */
				echo esc_html( sprintf( __( 'Waived: %s', 'learning-ledger' ), $lledger_reason ) );
			}
		} elseif ( 'satisfied' === $lledger_state ) {
			esc_html_e( 'Satisfied', 'learning-ledger' );
		} elseif ( 'in-progress' === $lledger_state ) {
			esc_html_e( 'In progress', 'learning-ledger' );
		} else {
			esc_html_e( 'Not started', 'learning-ledger' );
		}
		?>
	</p>
	<?php if ( ! $lledger_enrolled && ! $lledger_done ) : ?>
		<p>
			<?php
			$lledger_course_url = $lledger_course > 0 ? get_permalink( $lledger_course ) : false;
			if ( $lledger_course_url ) :
				?>
				<a href="<?php echo esc_url( $lledger_course_url ); ?>"><?php esc_html_e( 'Enrol in this course to start this lesson.', 'learning-ledger' ); ?></a>
			<?php else : ?>
				<?php esc_html_e( 'Enrol in this course to start this lesson.', 'learning-ledger' ); ?>
			<?php endif; ?>
		</p>
	<?php endif; ?>
	<?php if ( array() !== $lledger_buttons ) : ?>
		<div class="wp-block-buttons">
			<?php foreach ( $lledger_buttons as $lledger_mode => $lledger_label ) : ?>
				<div class="wp-block-button">
					<button type="button" class="wp-block-button__link wp-element-button" data-lledger-mode="<?php echo esc_attr( $lledger_mode ); ?>"><?php echo esc_html( $lledger_label ); ?></button>
				</div>
			<?php endforeach; ?>
		</div>
		<p role="status" aria-live="polite" data-lledger-message></p>
		<div data-lledger-frame></div>
	<?php endif; ?>
</div>
