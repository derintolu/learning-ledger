<?php
/**
 * My learning record front end: the signed-in learner's own completed and tested-out lessons.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Result;

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	return;
}

$lledger_user = get_current_user_id();

// Tested-out lessons, then completed lessons that are not already listed as tested out. A
// completion that a later voiding statement flagged is not shown.
$lledger_tested_out = array();
foreach ( ( new Waiver_Ledger() )->for_user( $lledger_user ) as $lledger_waiver ) {
	$lledger_tested_out[ $lledger_waiver['lesson_id'] ] = true;
}
$lledger_ledger    = new Apply_Ledger();
$lledger_completed = array();
foreach ( $lledger_ledger->for_user( $lledger_user ) as $lledger_row ) {
	if ( 'lesson' !== $lledger_row['object_kind'] || $lledger_row['flagged']
		|| ! in_array( $lledger_row['status'], array( Result::LESSON_COMPLETED, Result::LESSON_PASSED ), true )
		|| isset( $lledger_tested_out[ $lledger_row['object_id'] ] ) ) {
		continue;
	}
	$lledger_completed[ $lledger_row['object_id'] ] = true;
}

// Lessons that no longer exist are dropped.
$lledger_completed  = array_filter( array_keys( $lledger_completed ), 'get_post' );
$lledger_tested_out = array_filter( array_keys( $lledger_tested_out ), 'get_post' );

$lledger_has_record = '' !== (string) get_user_meta( $lledger_user, Identity_Map::META, true );
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core. ?>>
	<h2><?php esc_html_e( 'My learning record', 'learning-ledger' ); ?></h2>
	<?php if ( array() === $lledger_completed && array() === $lledger_tested_out ) : ?>
		<p><?php esc_html_e( 'Nothing here yet. Completed and tested-out lessons will appear as you work through your courses.', 'learning-ledger' ); ?></p>
	<?php endif; ?>
	<?php if ( array() !== $lledger_completed ) : ?>
		<h3><?php esc_html_e( 'Completed lessons', 'learning-ledger' ); ?></h3>
		<ul class="lledger-record__completed">
			<?php foreach ( $lledger_completed as $lledger_lesson ) : ?>
				<?php $lledger_score = $lledger_ledger->score( $lledger_user, (int) $lledger_lesson ); ?>
				<li>
					<?php echo esc_html( get_the_title( (int) $lledger_lesson ) ); ?>
					<?php if ( is_array( $lledger_score ) && null !== $lledger_score['score_scaled'] ) : ?>
						<span class="lledger-record__score">
							<?php
							/* translators: %d: score as a percentage. */
							echo esc_html( sprintf( __( '%d%%', 'learning-ledger' ), (int) round( $lledger_score['score_scaled'] * 100 ) ) );
							?>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( array() !== $lledger_tested_out ) : ?>
		<h3><?php esc_html_e( 'Tested out', 'learning-ledger' ); ?></h3>
		<ul class="lledger-record__tested-out">
			<?php foreach ( $lledger_tested_out as $lledger_lesson ) : ?>
				<li><?php echo esc_html( get_the_title( (int) $lledger_lesson ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( $lledger_has_record ) : ?>
		<?php $lledger_button = wp_unique_id( 'lledger-record-' ); ?>
		<p>
			<button type="button" id="<?php echo esc_attr( $lledger_button ); ?>" data-endpoint="<?php echo esc_url( rest_url( 'learning-ledger/v1/my-record/view-link' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"><?php esc_html_e( 'View my full record', 'learning-ledger' ); ?></button>
		</p>
		<p role="status" aria-live="polite" id="<?php echo esc_attr( $lledger_button ); ?>-status"></p>
		<?php
		$lledger_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		wp_print_inline_script_tag(
			'(function(){var b=document.getElementById(' . wp_json_encode( $lledger_button, $lledger_flags ) . ');if(!b){return;}' .
			'var s=document.getElementById(' . wp_json_encode( $lledger_button . '-status', $lledger_flags ) . ');' .
			'b.addEventListener("click",function(){b.disabled=true;s.textContent="";' .
			'fetch(b.dataset.endpoint,{method:"POST",credentials:"same-origin",headers:{"X-WP-Nonce":b.dataset.nonce}})' .
			'.then(function(r){return r.json().then(function(d){return{ok:r.ok,d:d};});})' .
			'.then(function(x){if(x.ok&&x.d&&/^https?:\/\//.test(x.d.url)){window.location.assign(x.d.url);return;}' .
			'b.disabled=false;s.textContent=(x.d&&x.d.message)||' . wp_json_encode( __( 'Your record could not be opened. Try again later.', 'learning-ledger' ), $lledger_flags ) . ';})' .
			'.catch(function(){b.disabled=false;s.textContent=' . wp_json_encode( __( 'Something went wrong. Try again.', 'learning-ledger' ), $lledger_flags ) . ';});});})();'
		);
		?>
	<?php endif; ?>
</div>
