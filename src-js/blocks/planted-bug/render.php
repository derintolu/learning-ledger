<?php
/**
 * Planted-bug front end: file name and code only. The hint, explanation and
 * fix checks stay in post_content and are never rendered.
 *
 * @package LearningLedger
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$lledger_file = isset( $attributes['file'] ) ? (string) $attributes['file'] : '';
$lledger_code = isset( $attributes['code'] ) ? (string) $attributes['code'] : '';
$lledger_key  = isset( $attributes['key'] ) ? (string) $attributes['key'] : '';
?>
<figure <?php echo get_block_wrapper_attributes( array( 'data-planted-bug' => $lledger_key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core. ?>>
	<figcaption class="lledger-planted-bug__file"><?php echo esc_html( $lledger_file ); ?></figcaption>
	<pre class="lledger-planted-bug__code"><code><?php echo esc_html( $lledger_code ); ?></code></pre>
</figure>
