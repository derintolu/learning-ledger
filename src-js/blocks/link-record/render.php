<?php
/**
 * Link-record front end: a form for signed-in learners.
 *
 * @package LearningLedger
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	printf( '<p %s>%s</p>', get_block_wrapper_attributes(), esc_html__( 'Sign in to link your record.', 'learning-ledger' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core.
	return;
}

$lledger_form_id = wp_unique_id( 'lledger-link-' );
$lledger_program = isset( $attributes['program'] ) ? (int) $attributes['program'] : 0;
?>
<form <?php echo get_block_wrapper_attributes( array( 'id' => $lledger_form_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core. ?> data-program="<?php echo esc_attr( (string) $lledger_program ); ?>" data-endpoint="<?php echo esc_url( rest_url( 'learning-ledger/v1/identity/link' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<label for="<?php echo esc_attr( $lledger_form_id ); ?>-code"><?php esc_html_e( 'One-time code', 'learning-ledger' ); ?></label>
	<input id="<?php echo esc_attr( $lledger_form_id ); ?>-code" name="code" type="text" required autocomplete="off" maxlength="64" pattern="[A-Za-z0-9\-]{4,64}">
	<button type="submit"><?php esc_html_e( 'Link my record', 'learning-ledger' ); ?></button>
	<p role="status" aria-live="polite" class="lledger-link__status"></p>
</form>
<?php
wp_print_inline_script_tag(
	'(function(){var f=document.getElementById(' . wp_json_encode( $lledger_form_id ) . ');if(!f){return;}' .
	'var s=f.querySelector(".lledger-link__status");' .
	'f.addEventListener("submit",function(e){e.preventDefault();s.textContent="";' .
	'fetch(f.dataset.endpoint,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-WP-Nonce":f.dataset.nonce},' .
	'body:JSON.stringify({code:f.elements.code.value,program:parseInt(f.dataset.program,10)||0})})' .
	'.then(function(r){return r.json().then(function(d){return{ok:r.ok,d:d};});})' .
	'.then(function(x){s.textContent=x.ok?' . wp_json_encode( __( 'Your record is linked. Earlier progress will appear shortly.', 'learning-ledger' ) ) . ':(x.d&&x.d.message)||' . wp_json_encode( __( 'That code did not work.', 'learning-ledger' ) ) . ';if(x.ok){f.reset();}})' .
	'.catch(function(){s.textContent=' . wp_json_encode( __( 'Something went wrong. Try again.', 'learning-ledger' ) ) . ';});});})();'
);
