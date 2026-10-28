<?php
/**
 * Record page template. Every value is escaped here; statement JSON only ever reaches the page
 * through esc_html().
 *
 * Expects `$lledger_view` (see Record_Page::serve()): `gone`, and unless gone `statements`
 * (id, stored, verb, object, json), `expires_at` (xAPI time), `expires_label`, `ndjson_url`,
 * `json_url`, `newest_url` and `next_url` ('' when absent).
 *
 * @package LearningLedger
 */

defined( 'ABSPATH' ) || exit;

$lledger_gone  = ! empty( $lledger_view['gone'] );
$lledger_title = $lledger_gone ? __( 'This link has expired', 'learning-ledger' ) : __( 'Your learning record', 'learning-ledger' );
?>
<!doctype html>
<html lang="<?php echo esc_attr( (string) get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html( $lledger_title ); ?></title>
<style>
	:root { color-scheme: light dark; --ink: #1d2327; --muted: #50575e; --line: #dcdcde; --panel: #f6f7f7; --link: #2271b1; --bg: #fff; }
	@media (prefers-color-scheme: dark) { :root { --ink: #f0f0f1; --muted: #c3c4c7; --line: #3c434a; --panel: #1d2327; --link: #72aee6; --bg: #101517; } }
	* { box-sizing: border-box; }
	body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
	main { max-width: 56rem; margin: 0 auto; padding: 2rem 1rem 4rem; }
	h1 { font-size: 1.75rem; line-height: 1.2; margin: 0 0 .5rem; }
	p { margin: 0 0 1rem; }
	.muted { color: var(--muted); }
	a { color: var(--link); }
	.actions { display: flex; flex-wrap: wrap; gap: .75rem; margin: 1.5rem 0; padding: 0; list-style: none; }
	.actions a { display: inline-block; padding: .5rem .9rem; border: 1px solid var(--line); border-radius: 4px; text-decoration: none; }
	.statement { border-top: 1px solid var(--line); padding: 1rem 0; }
	.statement h2 { font-size: 1rem; margin: 0 0 .25rem; overflow-wrap: anywhere; }
	.statement p { font-size: .875rem; margin: 0 0 .5rem; overflow-wrap: anywhere; }
	pre { margin: 0; padding: .75rem; background: var(--panel); border: 1px solid var(--line); border-radius: 4px; overflow-x: auto; font-size: .8125rem; line-height: 1.45; }
	nav { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 1.5rem; }
</style>
</head>
<body>
<main>
<h1><?php echo esc_html( $lledger_title ); ?></h1>
<?php if ( $lledger_gone ) : ?>
	<p><?php esc_html_e( 'Record links work for 15 minutes. Ask the site that sent you here for a new one.', 'learning-ledger' ); ?></p>
<?php else : ?>
	<p class="muted">
		<?php esc_html_e( 'A read-only copy of the learning statements stored for you.', 'learning-ledger' ); ?>
		<?php
		printf(
			/* translators: %s: expiry date and time in UTC. */
			esc_html__( 'This link expires at %s.', 'learning-ledger' ),
			'<time datetime="' . esc_attr( (string) $lledger_view['expires_at'] ) . '">' . esc_html( (string) $lledger_view['expires_label'] ) . '</time>'
		);
		?>
	</p>
	<ul class="actions">
		<li><a href="<?php echo esc_url( (string) $lledger_view['ndjson_url'] ); ?>"><?php esc_html_e( 'Download NDJSON', 'learning-ledger' ); ?></a></li>
		<li><a href="<?php echo esc_url( (string) $lledger_view['json_url'] ); ?>"><?php esc_html_e( 'Download JSON', 'learning-ledger' ); ?></a></li>
	</ul>
	<?php if ( array() === $lledger_view['statements'] ) : ?>
		<p><?php esc_html_e( 'No statements are stored for you yet.', 'learning-ledger' ); ?></p>
	<?php endif; ?>
	<?php foreach ( $lledger_view['statements'] as $lledger_statement ) : ?>
		<article class="statement">
			<h2><?php echo esc_html( $lledger_statement['verb'] . ' ' . $lledger_statement['object'] ); ?></h2>
			<p class="muted">
				<?php
				printf(
					/* translators: 1: statement id, 2: time the LRS stored it. */
					esc_html__( 'Statement %1$s, stored %2$s', 'learning-ledger' ),
					esc_html( $lledger_statement['id'] ),
					'<time datetime="' . esc_attr( $lledger_statement['stored'] ) . '">' . esc_html( $lledger_statement['stored'] ) . '</time>'
				);
				?>
			</p>
			<pre><?php echo esc_html( $lledger_statement['json'] ); ?></pre>
		</article>
	<?php endforeach; ?>
	<?php if ( '' !== $lledger_view['newest_url'] || '' !== $lledger_view['next_url'] ) : ?>
		<nav aria-label="<?php esc_attr_e( 'Statement pages', 'learning-ledger' ); ?>">
			<?php if ( '' !== $lledger_view['newest_url'] ) : ?>
				<a href="<?php echo esc_url( (string) $lledger_view['newest_url'] ); ?>"><?php esc_html_e( 'Newest statements', 'learning-ledger' ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $lledger_view['next_url'] ) : ?>
				<a href="<?php echo esc_url( (string) $lledger_view['next_url'] ); ?>"><?php esc_html_e( 'Older statements', 'learning-ledger' ); ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
