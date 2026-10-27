<?php
/**
 * Checkpoint front end: the question only. Checks, branches, fallbacks and the
 * rubric stay in post_content and are never rendered.
 *
 * @package LearningLedger
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$lledger_question = isset( $attributes['question'] ) ? (string) $attributes['question'] : '';
$lledger_key      = isset( $attributes['key'] ) ? (string) $attributes['key'] : '';
?>
<div <?php echo get_block_wrapper_attributes( array( 'data-checkpoint' => $lledger_key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core. ?>>
	<p class="lledger-checkpoint__question"><?php echo esc_html( $lledger_question ); ?></p>
</div>
