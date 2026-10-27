<?php
/**
 * Beat front end: inner content plus the question. The goal is never output.
 *
 * @package LearningLedger
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Rendered inner blocks.
 */

defined( 'ABSPATH' ) || exit;

$lledger_question = isset( $attributes['question'] ) ? (string) $attributes['question'] : '';
$lledger_beat_id  = isset( $attributes['id'] ) ? (string) $attributes['id'] : '';
?>
<div <?php echo get_block_wrapper_attributes( array( 'data-beat' => $lledger_beat_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core. ?>>
	<?php echo wp_kses_post( $content ); ?>
	<?php if ( '' !== $lledger_question ) : ?>
		<p class="lledger-beat__question"><?php echo esc_html( $lledger_question ); ?></p>
	<?php endif; ?>
</div>
