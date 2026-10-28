<?php
/**
 * Public badge page template. Every value is escaped here.
 *
 * Expects `$lledger_view` (see Badge_Page::page()): `name`, `description`, `criteria`,
 * `issuer`, `issued_iso` and `issued_human` ('' when unknown), `page_url`, `json_url`,
 * `png_url` and `script_nonce`. Values are raw. Nothing about the recipient is shown.
 *
 * @package LearningLedger
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="<?php echo esc_attr( (string) get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( (string) $lledger_view['name'] ); ?></title>
<style>
	:root { color-scheme: light dark; --ink: #1d2327; --muted: #50575e; --line: #dcdcde; --panel: #f6f7f7; --link: #2271b1; --bg: #fff; }
	@media (prefers-color-scheme: dark) { :root { --ink: #f0f0f1; --muted: #c3c4c7; --line: #3c434a; --panel: #1d2327; --link: #72aee6; --bg: #101517; } }
	* { box-sizing: border-box; }
	body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
	main { max-width: 40rem; margin: 0 auto; padding: 2rem 1rem 4rem; }
	h1 { font-size: 1.75rem; line-height: 1.2; margin: 0 0 .5rem; overflow-wrap: anywhere; }
	p { margin: 0 0 1rem; overflow-wrap: anywhere; }
	.muted { color: var(--muted); }
	a { color: var(--link); }
	.badge { display: flex; gap: 1.25rem; align-items: flex-start; padding: 1.25rem; margin: 0 0 1.5rem; background: var(--panel); border: 1px solid var(--line); border-radius: 4px; }
	.badge img { width: 128px; height: 128px; object-fit: contain; flex: none; }
	.badge div { min-width: 0; }
	dl { display: grid; grid-template-columns: auto 1fr; gap: .25rem 1rem; margin: 0 0 1.5rem; }
	dt { font-weight: 600; }
	dd { margin: 0; overflow-wrap: anywhere; }
	label { display: block; font-weight: 600; margin: 0 0 .25rem; }
	input[type="url"] { width: 100%; padding: .5rem .6rem; font: inherit; color: inherit; background: var(--bg); border: 1px solid var(--muted); border-radius: 4px; }
	button, .actions a { display: inline-block; padding: .55rem 1rem; font: inherit; border-radius: 4px; cursor: pointer; text-decoration: none; }
	button { color: #fff; background: #2271b1; border: 1px solid #2271b1; }
	.actions { display: flex; flex-wrap: wrap; gap: .75rem; margin: 1.5rem 0 0; padding: 0; list-style: none; }
	.actions a { border: 1px solid var(--line); }
	.copy { display: flex; gap: .5rem; }
	.copy input { flex: 1; min-width: 0; }
	@media (max-width: 30rem) { .badge { flex-direction: column; } }
</style>
</head>
<body>
<main>
	<section class="badge" aria-label="<?php esc_attr_e( 'Achievement', 'learning-ledger' ); ?>">
		<img src="<?php echo esc_url( (string) $lledger_view['png_url'] ); ?>" alt="" width="128" height="128">
		<div>
			<h1><?php echo esc_html( (string) $lledger_view['name'] ); ?></h1>
			<?php if ( '' !== $lledger_view['description'] ) : ?>
				<p><?php echo esc_html( (string) $lledger_view['description'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $lledger_view['criteria'] ) : ?>
				<p class="muted"><?php echo esc_html( (string) $lledger_view['criteria'] ); ?></p>
			<?php endif; ?>
		</div>
	</section>

	<dl>
		<dt><?php esc_html_e( 'Issued by', 'learning-ledger' ); ?></dt>
		<dd><?php echo esc_html( (string) $lledger_view['issuer'] ); ?></dd>
		<?php if ( '' !== $lledger_view['issued_iso'] ) : ?>
			<dt><?php esc_html_e( 'Issued on', 'learning-ledger' ); ?></dt>
			<dd><time datetime="<?php echo esc_attr( (string) $lledger_view['issued_iso'] ); ?>"><?php echo esc_html( (string) $lledger_view['issued_human'] ); ?></time></dd>
		<?php endif; ?>
	</dl>

	<label for="lledger-share-url"><?php esc_html_e( 'Badge link (for LinkedIn and elsewhere)', 'learning-ledger' ); ?></label>
	<div class="copy">
		<input type="url" id="lledger-share-url" readonly value="<?php echo esc_attr( (string) $lledger_view['page_url'] ); ?>">
		<button type="button" id="lledger-copy" data-copied="<?php esc_attr_e( 'Copied', 'learning-ledger' ); ?>"><?php esc_html_e( 'Copy link', 'learning-ledger' ); ?></button>
	</div>

	<ul class="actions">
		<li><a href="<?php echo esc_url( (string) $lledger_view['json_url'] ); ?>"><?php esc_html_e( 'Download JSON credential', 'learning-ledger' ); ?></a></li>
		<li><a href="<?php echo esc_url( (string) $lledger_view['png_url'] ); ?>"><?php esc_html_e( 'Download badge image (PNG)', 'learning-ledger' ); ?></a></li>
	</ul>

	<script nonce="<?php echo esc_attr( (string) $lledger_view['script_nonce'] ); ?>">
		document.getElementById( 'lledger-copy' ).addEventListener( 'click', function () {
			var input = document.getElementById( 'lledger-share-url' );
			var button = this;
			input.select();
			var done = function () { button.textContent = button.getAttribute( 'data-copied' ); };
			var fallback = function () {
				if ( document.execCommand( 'copy' ) ) {
					done();
				}
			};
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( input.value ).then( done, fallback );
			} else {
				fallback();
			}
		} );
	</script>
</main>
</body>
</html>
