<?php
/**
 * The claim page behind a claim link.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Claim;

use LearningLedger\Badges\Issuer\Issuer_Profile;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Crypto\Hmac;
use LearningLedger\Crypto\Tokens;
use LearningLedger\Kernel\Page_Route;
use LearningLedger\Lrs\Links\Link_Repository;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;

/**
 * `/badges/claim/<token>`: a public page gated only by the claim link's token.
 *
 * GET shows the achievement and a form for the learner's email (plus a "share publicly"
 * choice). POST, carrying a nonce and the token, redeems the link through
 * Claim_Handler::claim() and shows the result: the JSON and PNG downloads behind a
 * short-lived signed parameter (Task 62 serves them) and, when shared, the public link to
 * copy. An unknown, used or expired token gets a 410. Every response is `no-store`,
 * `noindex` and `no-referrer`. `GET|POST /learning-ledger/v1/page/claim/<token>` is its REST
 * twin; the form still posts to the pretty URL.
 *
 * Not final so tests can capture the status and headers.
 */
class Claim_Page {

	/**
	 * Query variable set by the rewrite.
	 */
	const QUERY_VAR = 'lledger_claim';

	/**
	 * Rewrite rule: a 43-character base64url token.
	 */
	const RULE = '^badges/claim/([A-Za-z0-9_-]{43})/?$';

	/**
	 * Lifetime of the signed download links, in seconds.
	 */
	const DOWNLOAD_TTL = 900;

	/**
	 * Hmac purpose of the signed download links (Task 62 verifies with the same).
	 */
	const DOWNLOAD_PURPOSE = 'badge-download';

	/**
	 * Form field names. The nonce is not `_wpnonce`: on a REST request WordPress checks a
	 * `_wpnonce` parameter as a REST cookie nonce and refuses the request.
	 */
	const FIELD_TOKEN = 'lledger_token';
	const FIELD_EMAIL = 'lledger_email';
	const FIELD_SHARE = 'lledger_share';
	const FIELD_NONCE = 'lledger_nonce';

	/**
	 * Headers on every response.
	 */
	const PRIVATE_HEADERS = array(
		'Cache-Control'          => 'no-store',
		'X-Robots-Tag'           => 'noindex',
		'Referrer-Policy'        => 'no-referrer',
		'X-Content-Type-Options' => 'nosniff',
	);

	/**
	 * Links.
	 *
	 * @var Link_Repository
	 */
	private Link_Repository $links;

	/**
	 * Badges.
	 *
	 * @var Badge_Repository
	 */
	private Badge_Repository $badges;

	/**
	 * Claims.
	 *
	 * @var Claim_Handler
	 */
	private Claim_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param Link_Repository|null  $links   Links.
	 * @param Badge_Repository|null $badges  Badges.
	 * @param Claim_Handler|null    $handler Claims.
	 */
	public function __construct( ?Link_Repository $links = null, ?Badge_Repository $badges = null, ?Claim_Handler $handler = null ) {
		$this->links   = $links ?? new Link_Repository();
		$this->badges  = $badges ?? new Badge_Repository();
		$this->handler = $handler ?? new Claim_Handler( $this->badges, $this->links );
	}

	/**
	 * The page URL for a token.
	 *
	 * @param string $token Token.
	 *
	 * @return string
	 */
	public static function url( string $token ): string {
		return home_url( '/badges/claim/' . $token );
	}

	/**
	 * Nonce action of the form for one token.
	 *
	 * @param string $token Token.
	 *
	 * @return string
	 */
	public static function nonce_action( string $token ): string {
		return 'lledger_claim_' . bin2hex( Tokens::hash( $token ) );
	}

	/**
	 * A signed download link: `/badges/<uuid>.<ext>?exp=<unix>&sig=<base64url HMAC of "<uuid>|<exp>">`.
	 *
	 * @param string  $badge_id Badge id.
	 * @param string  $ext      Either `json` or `png`.
	 * @param integer $exp      Expiry, Unix time.
	 *
	 * @return string
	 */
	public static function download_url( string $badge_id, string $ext, int $exp ): string {
		$sig = rtrim( strtr( base64_encode( Hmac::sign( $badge_id . '|' . $exp, self::DOWNLOAD_PURPOSE ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of a MAC.
		return home_url( '/badges/' . $badge_id . '.' . $ext ) . '?exp=' . $exp . '&sig=' . $sig;
	}

	/**
	 * Add the rewrite rule (on `init`).
	 *
	 * @return void
	 */
	public function add_rewrite(): void {
		add_rewrite_rule( self::RULE, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Add the query variable.
	 *
	 * @param mixed $vars Public query variables.
	 *
	 * @return array
	 */
	public function query_vars( mixed $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve the page when the query variable is set (on `template_redirect`).
	 *
	 * @return void
	 */
	public function maybe_serve(): void {
		global $wp;
		// Only the page's own rewrite serves it, not the public query variable on any URL.
		if ( ! $wp instanceof \WP || self::RULE !== $wp->matched_rule ) {
			return;
		}
		$token = get_query_var( self::QUERY_VAR );
		if ( ! is_string( $token ) || '' === $token ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified in serve(); posted() sanitizes each field.
		$this->serve( $token, $method, 'POST' === $method ? self::posted( wp_unslash( $_POST ) ) : array() );
	}

	/**
	 * `GET|POST page/claim/<token>`: the REST twin of the rewrite.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return void
	 */
	public function serve_rest( \WP_REST_Request $r ): void {
		Page_Route::start();
		$method = strtoupper( $r->get_method() );
		$this->serve( (string) $r['token'], $method, 'POST' === $method ? self::posted( $r->get_body_params() ) : array() );
	}

	/**
	 * Register the REST twin (on `rest_api_init`).
	 *
	 * @return void
	 */
	public function register_route(): void {
		Page_Route::register( 'claim/(?P<token>[A-Za-z0-9_-]{43})', 'GET, POST', array( $this, 'serve_rest' ) );
	}

	/**
	 * The form fields of a POST.
	 *
	 * @param array $post Unslashed POST fields.
	 *
	 * @return array{token:string,nonce:string,email:string,share:bool}
	 */
	public static function posted( array $post ): array {
		return array(
			'token' => isset( $post[ self::FIELD_TOKEN ] ) && is_string( $post[ self::FIELD_TOKEN ] ) ? sanitize_text_field( $post[ self::FIELD_TOKEN ] ) : '',
			'nonce' => isset( $post[ self::FIELD_NONCE ] ) && is_string( $post[ self::FIELD_NONCE ] ) ? sanitize_text_field( $post[ self::FIELD_NONCE ] ) : '',
			// Not sanitize_text_field(): Claim_Handler trims, lowercases and checks it with is_email(), then only hashes it; the form escapes it when showing it again.
			'email' => isset( $post[ self::FIELD_EMAIL ] ) && is_string( $post[ self::FIELD_EMAIL ] ) ? $post[ self::FIELD_EMAIL ] : '',
			'share' => isset( $post[ self::FIELD_SHARE ] ) && '1' === $post[ self::FIELD_SHARE ],
		);
	}

	/**
	 * Answer one request for a token, then end it.
	 *
	 * @param string $token  Token from the path.
	 * @param string $method HTTP method; anything but POST shows the form.
	 * @param array  $fields For POST: `token`, `nonce`, `email` (strings) and `share` (bool).
	 *
	 * @return void
	 */
	public function serve( string $token, string $method, array $fields ): void {
		$link  = 1 === preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $token ) ? $this->links->resolve( $token, 'claim' ) : null;
		$badge = null === $link ? null : $this->badges->get( $link['subject'] );
		if ( null === $link || null === $badge || ! hash_equals( $badge['actor_hash'], $link['actor_hash'] ) ) {
			$this->respond( 410, array( 'state' => 'gone' ) );
			return;
		}
		if ( Badge_Repository::PENDING !== $badge['status'] ) {
			$this->respond( 409, array( 'state' => 'conflict' ) + self::about( $badge ) );
			return;
		}

		if ( 'POST' !== $method ) {
			$this->respond( 200, $this->form( $token, $badge, '', '' ) );
			return;
		}

		$email = is_string( $fields['email'] ?? null ) ? $fields['email'] : '';
		$sent  = is_string( $fields['token'] ?? null ) ? $fields['token'] : '';
		$nonce = is_string( $fields['nonce'] ?? null ) ? $fields['nonce'] : '';
		if ( ! hash_equals( $token, $sent ) || false === wp_verify_nonce( $nonce, self::nonce_action( $token ) ) ) {
			$this->respond( 403, $this->form( $token, $badge, $email, __( 'This form has expired. Please submit it again.', 'learning-ledger' ) ) );
			return;
		}

		$share = true === ( $fields['share'] ?? false );
		try {
			$credential = $this->handler->claim( $token, $email, $share );
		} catch ( Invalid_Input $e ) {
			if ( Claim_Handler::INVALID_EMAIL === $e->error_code() ) {
				$this->respond( 400, $this->form( $token, $badge, $email, __( 'Enter a valid email address.', 'learning-ledger' ) ) );
			} elseif ( Claim_Handler::CONFLICT === $e->error_code() ) {
				$this->respond( 409, array( 'state' => 'conflict' ) + self::about( $badge ) );
			} else {
				$this->respond( 410, array( 'state' => 'gone' ) );
			}
			return;
		} catch ( \RuntimeException $e ) {
			$this->respond( 500, array( 'state' => 'error' ) + self::about( $badge ) );
			return;
		}

		$exp = Clock::now()->getTimestamp() + self::DOWNLOAD_TTL;
		$this->respond(
			200,
			array(
				'state'      => 'issued',
				'json_url'   => self::download_url( $badge['id'], 'json', $exp ),
				'png_url'    => self::download_url( $badge['id'], 'png', $exp ),
				'share_url'  => $share ? home_url( '/badges/' . $badge['id'] ) : '',
				'valid_from' => is_string( $credential['validFrom'] ?? null ) ? $credential['validFrom'] : '',
			) + self::about( $badge )
		);
	}

	/**
	 * View values of the form.
	 *
	 * @param string $token Token.
	 * @param array  $badge Badge row.
	 * @param string $email Email to show again ('' for none).
	 * @param string $error Error message ('' for none).
	 *
	 * @return array
	 */
	private function form( string $token, array $badge, string $email, string $error ): array {
		return array(
			'state'        => 'form',
			'action'       => self::url( $token ),
			'token'        => $token,
			'nonce_action' => self::nonce_action( $token ),
			'email'        => $email,
			'error'        => $error,
		) + self::about( $badge );
	}

	/**
	 * What the page shows about a badge. Values are raw; the template escapes them.
	 *
	 * @param array $badge Badge row.
	 *
	 * @return array{name:string,description:string,criteria:string,image:string,issuer:string}
	 */
	private static function about( array $badge ): array {
		$a = $badge['achievement'];
		return array(
			'name'        => is_string( $a['name'] ?? null ) ? $a['name'] : '',
			'description' => is_string( $a['description'] ?? null ) ? $a['description'] : '',
			'criteria'    => is_string( $a['criteria']['narrative'] ?? null ) ? $a['criteria']['narrative'] : '',
			'image'       => is_string( $a['image'] ?? null ) && str_starts_with( $a['image'], 'https://' ) ? $a['image'] : '',
			'issuer'      => (string) Issuer_Profile::document()['name'],
		);
	}

	/**
	 * Send the page and end the request.
	 *
	 * @param integer $status       HTTP status.
	 * @param array   $lledger_view View values (raw; the template escapes them).
	 *
	 * @return void
	 */
	private function respond( int $status, array $lledger_view ): void {
		$lledger_view['script_nonce'] = rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- CSP nonce.
		$this->send_headers(
			$status,
			self::PRIVATE_HEADERS + array(
				'Content-Type'            => 'text/html; charset=utf-8',
				'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src https:; script-src 'nonce-" . $lledger_view['script_nonce'] . "'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
			)
		);
		require __DIR__ . '/templates/claim-page.php';
		$this->finish();
	}

	/**
	 * Send the status and headers, unless output has already started.
	 *
	 * @param integer               $status  HTTP status.
	 * @param array<string, string> $headers Headers.
	 *
	 * @return void
	 */
	protected function send_headers( int $status, array $headers ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page-cache convention; caches must not keep a claim page.
		}
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		status_header( $status );
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * End the request.
	 *
	 * @return void
	 */
	protected function finish(): void {
		exit;
	}
}
