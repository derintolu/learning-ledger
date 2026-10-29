<?php
/**
 * Claim page template. Every value is escaped here.
 *
 * Expects `$lledger_view` (see Claim_Page::serve()): `state` (`form`, `issued`, `conflict`,
 * `error` or `gone`) and `script_nonce`; unless gone, `name`, `description`, `criteria`,
 * `image` ('' for none) and `issuer`; for `form`, `action`, `token`, `nonce_action`, `email`
 * and `error`; for `issued`, `json_url`, `png_url`, `share_url` ('' when not shared) and
 * `valid_from`.
 *
 * @package LearningLedger
 */

defined( 'ABSPATH' ) || exit;

$lledger_state  = (string) $lledger_view['state'];
$lledger_titles = array(
	'form'     => __( 'Claim your badge', 'learning-ledger' ),
	'issued'   => __( 'Your badge is ready', 'learning-ledger' ),
	'conflict' => __( 'This badge has already been claimed', 'learning-ledger' ),
	'error'    => __( 'The badge could not be issued', 'learning-ledger' ),
	'gone'     => __( 'This link has expired', 'learning-ledger' ),
);
$lledger_title  = $lledger_titles[ $lledger_state ] ?? $lledger_titles['gone'];
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
	:root { color-scheme: light dark; --ink: #1d2327; --muted: #50575e; --line: #dcdcde; --panel: #f6f7f7; --link: #2271b1; --bg: #fff; --error: #b32d2e; }
	@media (prefers-color-scheme: dark) { :root { --ink: #f0f0f1; --muted: #c3c4c7; --line: #3c434a; --panel: #1d2327; --link: #72aee6; --bg: #101517; --error: #f86368; } }
	* { box-sizing: border-box; }
	body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
	main { max-width: 40rem; margin: 0 auto; padding: 2rem 1rem 4rem; }
	h1 { font-size: 1.75rem; line-height: 1.2; margin: 0 0 1rem; }
	h2 { font-size: 1.25rem; margin: 0 0 .5rem; overflow-wrap: anywhere; }
	p { margin: 0 0 1rem; overflow-wrap: anywhere; }
	.muted { color: var(--muted); }
	a { color: var(--link); }
	.badge { display: flex; gap: 1rem; align-items: flex-start; padding: 1rem; margin: 0 0 1.5rem; background: var(--panel); border: 1px solid var(--line); border-radius: 4px; }
	.badge img { width: 96px; height: 96px; object-fit: contain; flex: none; }
	.badge div { min-width: 0; }
	label { display: block; font-weight: 600; margin: 0 0 .25rem; }
	input[type="email"], input[type="url"] { width: 100%; padding: .5rem .6rem; font: inherit; color: inherit; background: var(--bg); border: 1px solid var(--muted); border-radius: 4px; }
	.check { display: flex; gap: .5rem; align-items: flex-start; font-weight: 400; margin: 1rem 0; }
	.check input { margin-top: .3rem; }
	.error { color: var(--error); font-weight: 600; }
	button, .actions a { display: inline-block; padding: .55rem 1rem; font: inherit; border-radius: 4px; cursor: pointer; text-decoration: none; }
	button { color: #fff; background: #2271b1; border: 1px solid #2271b1; }
	.actions { display: flex; flex-wrap: wrap; gap: .75rem; margin: 1rem 0 1.5rem; padding: 0; list-style: none; }
	.actions a { border: 1px solid var(--line); }
	.copy { display: flex; gap: .5rem; }
	.copy input { flex: 1; min-width: 0; }
</style>
</head>
<body>
<main>
<h1><?php echo esc_html( $lledger_title ); ?></h1>
<?php if ( 'gone' === $lledger_state ) : ?>
	<p><?php esc_html_e( 'Claim links work once, for 15 minutes. Go back to the course site to get a new one.', 'learning-ledger' ); ?></p>
<?php else : ?>
	<section class="badge" aria-label="<?php esc_attr_e( 'Achievement', 'learning-ledger' ); ?>">
		<?php if ( '' !== $lledger_view['image'] ) : ?>
			<img src="<?php echo esc_url( (string) $lledger_view['image'] ); ?>" alt="">
		<?php endif; ?>
		<div>
			<h2><?php echo esc_html( (string) $lledger_view['name'] ); ?></h2>
			<?php if ( '' !== $lledger_view['description'] ) : ?>
				<p><?php echo esc_html( (string) $lledger_view['description'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $lledger_view['criteria'] ) : ?>
				<p class="muted"><?php echo esc_html( (string) $lledger_view['criteria'] ); ?></p>
			<?php endif; ?>
			<p class="muted">
				<?php
				/* translators: %s: issuer name. */
				echo esc_html( sprintf( __( 'Issued by %s', 'learning-ledger' ), (string) $lledger_view['issuer'] ) );
				?>
			</p>
		</div>
	</section>

	<?php if ( 'form' === $lledger_state ) : ?>
		<p><?php esc_html_e( 'Enter the email address the badge should be issued to. The badge stores only a salted hash of it, never the address itself, so anyone checking the badge needs you to tell them the address.', 'learning-ledger' ); ?></p>
		<?php if ( '' !== $lledger_view['error'] ) : ?>
			<p class="error" role="alert"><?php echo esc_html( (string) $lledger_view['error'] ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( (string) $lledger_view['action'] ); ?>">
			<?php wp_nonce_field( (string) $lledger_view['nonce_action'], 'lledger_nonce', false ); ?>
			<input type="hidden" name="lledger_token" value="<?php echo esc_attr( (string) $lledger_view['token'] ); ?>">
			<label for="lledger-email"><?php esc_html_e( 'Email address', 'learning-ledger' ); ?></label>
			<input type="email" id="lledger-email" name="lledger_email" value="<?php echo esc_attr( (string) $lledger_view['email'] ); ?>" required autocomplete="email" maxlength="254">
			<label class="check"><input type="checkbox" name="lledger_share" value="1"> <span><?php esc_html_e( 'Share publicly: anyone with the badge link can see the badge page.', 'learning-ledger' ); ?></span></label>
			<button type="submit"><?php esc_html_e( 'Claim badge', 'learning-ledger' ); ?></button>
		</form>
	<?php elseif ( 'issued' === $lledger_state ) : ?>
		<p><?php esc_html_e( 'Your badge has been signed. Download it now: these download links work for 15 minutes.', 'learning-ledger' ); ?></p>
		<ul class="actions">
			<li><a href="<?php echo esc_url( (string) $lledger_view['json_url'] ); ?>"><?php esc_html_e( 'Download JSON credential', 'learning-ledger' ); ?></a></li>
			<li><a href="<?php echo esc_url( (string) $lledger_view['png_url'] ); ?>"><?php esc_html_e( 'Download badge image (PNG)', 'learning-ledger' ); ?></a></li>
		</ul>
		<?php if ( '' !== $lledger_view['share_url'] ) : ?>
			<label for="lledger-share-url"><?php esc_html_e( 'Public badge link (for LinkedIn and elsewhere)', 'learning-ledger' ); ?></label>
			<div class="copy">
				<input type="url" id="lledger-share-url" readonly value="<?php echo esc_attr( (string) $lledger_view['share_url'] ); ?>">
				<button type="button" id="lledger-copy" data-copied="<?php esc_attr_e( 'Copied', 'learning-ledger' ); ?>"><?php esc_html_e( 'Copy link', 'learning-ledger' ); ?></button>
			</div>
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
		<?php else : ?>
			<p class="muted"><?php esc_html_e( 'You chose not to share this badge, so it has no public page.', 'learning-ledger' ); ?></p>
		<?php endif; ?>
	<?php elseif ( 'conflict' === $lledger_state ) : ?>
		<p><?php esc_html_e( 'This badge has already been issued, or can no longer be claimed.', 'learning-ledger' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Something went wrong while signing the badge. Go back to the course site to get a new claim link and try again.', 'learning-ledger' ); ?></p>
	<?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
