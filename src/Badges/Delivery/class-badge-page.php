<?php
/**
 * Public badge page and badge downloads.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Delivery;

use LearningLedger\Badges\Claim\Claim_Page;
use LearningLedger\Badges\Issuer\Issuer_Profile;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Crypto\Hmac;
use LearningLedger\Kernel\Page_Route;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Uuid;
use LearningLedger\Kernel\No_Page_Cache;

/**
 * `/badges/<uuid>`, `/badges/<uuid>.json` and `/badges/<uuid>.png`.
 *
 * Only an issued badge is served. The page and both downloads are public when the learner
 * chose to share the badge; otherwise they are a 404, except that the downloads also open
 * for the signed `exp` and `sig` parameters the claim result page adds (Claim_Page::download_url(),
 * valid until `exp`, at most 15 minutes ahead). The page shows the achievement, the issuer
 * and the issue date, never the recipient; the downloads carry the credential, whose only
 * recipient identity is the salted hash. `GET /learning-ledger/v1/page/badge/<uuid>` and
 * `.../page/badge/<uuid>.json|.png` are their REST twins.
 *
 * Not final so tests can capture the status and headers.
 */
class Badge_Page {

	/**
	 * Query variable holding the badge id.
	 */
	const QUERY_VAR = 'lledger_badge';

	/**
	 * Query variable holding the download format (`json` or `png`).
	 */
	const FORMAT_VAR = 'lledger_badge_format';

	/**
	 * Rewrite rule of the page.
	 */
	const PAGE_RULE = '^badges/([0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})/?$';

	/**
	 * Rewrite rule of the downloads.
	 */
	const FILE_RULE = '^badges/([0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})\.(json|png)$';

	/**
	 * Image baked when the achievement has no PNG attachment on this site (repo-root path).
	 */
	const DEFAULT_IMAGE = 'resources/badges/default-badge.png';

	/**
	 * Badges.
	 *
	 * @var Badge_Repository
	 */
	private Badge_Repository $badges;

	/**
	 * Constructor.
	 *
	 * @param Badge_Repository|null $badges Badges.
	 */
	public function __construct( ?Badge_Repository $badges = null ) {
		$this->badges = $badges ?? new Badge_Repository();
	}

	/**
	 * Add the rewrite rules (on `init`).
	 *
	 * @return void
	 */
	public function add_rewrite(): void {
		add_rewrite_rule( self::PAGE_RULE, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		add_rewrite_rule( self::FILE_RULE, 'index.php?' . self::QUERY_VAR . '=$matches[1]&' . self::FORMAT_VAR . '=$matches[2]', 'top' );
	}

	/**
	 * Add the query variables.
	 *
	 * @param mixed $vars Public query variables.
	 *
	 * @return array
	 */
	public function query_vars( mixed $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		$vars[] = self::FORMAT_VAR;
		return $vars;
	}

	/**
	 * Serve the page or a download when one of the rules matched (on `template_redirect`).
	 *
	 * @return void
	 */
	public function maybe_serve(): void {
		global $wp;
		// Only the page's own rewrites serve it, not the public query variables on any URL.
		if ( ! $wp instanceof \WP || ! in_array( $wp->matched_rule, array( self::PAGE_RULE, self::FILE_RULE ), true ) ) {
			return;
		}
		$id = get_query_var( self::QUERY_VAR );
		if ( ! is_string( $id ) || '' === $id ) {
			return;
		}
		$format = self::FILE_RULE === $wp->matched_rule ? get_query_var( self::FORMAT_VAR ) : '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a signed link, verified with an HMAC in serve().
		$exp = isset( $_GET['exp'] ) && is_string( $_GET['exp'] ) ? sanitize_text_field( wp_unslash( $_GET['exp'] ) ) : '';
		$sig = isset( $_GET['sig'] ) && is_string( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$this->serve( $id, is_string( $format ) ? $format : '', $exp, $sig );
	}

	/**
	 * `GET page/badge/<uuid>` and `GET page/badge/<uuid>.<format>`: the REST twins of the rewrites.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return \WP_REST_Response The 404, when serve() did not end the request.
	 */
	public function serve_rest( \WP_REST_Request $r ): \WP_REST_Response {
		Page_Route::start();
		$exp = $r->get_param( 'exp' );
		$sig = $r->get_param( 'sig' );
		$this->serve(
			(string) $r['id'],
			is_string( $r['format'] ) ? $r['format'] : '',
			is_string( $exp ) ? sanitize_text_field( $exp ) : '',
			is_string( $sig ) ? sanitize_text_field( $sig ) : ''
		);
		return Page_Route::not_found();
	}

	/**
	 * Register the REST twins (on `rest_api_init`).
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$uuid = '(?P<id>[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})';
		Page_Route::register( 'badge/' . $uuid, 'GET', array( $this, 'serve_rest' ) );
		Page_Route::register( 'badge/' . $uuid . '\.(?P<format>json|png)', 'GET', array( $this, 'serve_rest' ) );
	}

	/**
	 * Answer one request, then end it (or leave a 404 to the theme).
	 *
	 * @param string $id     Badge id from the path.
	 * @param string $format Empty for the page, `json` or `png` for a download.
	 * @param string $exp    The `exp` parameter, '' for none.
	 * @param string $sig    The `sig` parameter, '' for none.
	 *
	 * @return void
	 */
	public function serve( string $id, string $format, string $exp, string $sig ): void {
		// Private, or revocable: never kept by a page cache (see No_Page_Cache).
		No_Page_Cache::apply();
		$id    = strtolower( $id );
		$badge = Uuid::is_valid( $id ) ? $this->badges->get( $id ) : null;
		if ( null === $badge || Badge_Repository::ISSUED !== $badge['status'] || ( ! is_array( $badge['credential'] ) && ! is_string( $badge['credential'] ) ) ) {
			$this->not_found();
			return;
		}
		$shared = true === $badge['shared'];
		if ( ! $shared && ( '' === $format || ! self::signed( $id, $exp, $sig ) ) ) {
			$this->not_found();
			return;
		}

		if ( 'json' === $format ) {
			$this->download( $badge, $shared );
		} elseif ( 'png' === $format ) {
			$this->download_png( $badge, $shared );
		} elseif ( '' === $format ) {
			$this->page( $badge );
		} else {
			$this->not_found();
		}
	}

	/**
	 * Whether `exp` and `sig` are a valid, unexpired signed link for the badge.
	 *
	 * @param string $id  Badge id, lowercase.
	 * @param string $exp Expiry, Unix time.
	 * @param string $sig The HMAC, unpadded base64url.
	 *
	 * @return boolean
	 */
	private static function signed( string $id, string $exp, string $sig ): bool {
		if ( 1 !== preg_match( '/\A[0-9]{1,12}\z/', $exp ) || '' === $sig ) {
			return false;
		}
		$now = Clock::now()->getTimestamp();
		if ( (int) $exp < $now || (int) $exp > $now + Claim_Page::DOWNLOAD_TTL ) {
			return false;
		}
		$expected = rtrim( strtr( base64_encode( Hmac::sign( $id . '|' . $exp, Claim_Page::DOWNLOAD_PURPOSE ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of a MAC.
		return hash_equals( $expected, $sig );
	}

	/**
	 * The credential as text: its JSON, or the VC-JWT as stored.
	 *
	 * @param array $badge Badge row.
	 *
	 * @return string
	 */
	private static function credential_text( array $badge ): string {
		return is_string( $badge['credential'] ) ? $badge['credential'] : Json::encode_exact( $badge['credential'] );
	}

	/**
	 * Send the credential.
	 *
	 * @param array   $badge  Badge row.
	 * @param boolean $shared Whether the badge is shared.
	 *
	 * @return void
	 */
	private function download( array $badge, bool $shared ): void {
		$jwt = is_string( $badge['credential'] );
		$this->send_headers(
			200,
			self::file_headers( $shared ) + array(
				'Content-Type'        => $jwt ? 'application/vc+jwt' : 'application/ld+json; charset=utf-8',
				'Content-Disposition' => 'attachment; filename="badge-' . $badge['id'] . ( $jwt ? '.jwt' : '.json' ) . '"',
			)
		);
		echo self::credential_text( $badge ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON or JWT download, not HTML; sent with nosniff.
		$this->finish();
	}

	/**
	 * Send the baked PNG.
	 *
	 * @param array   $badge  Badge row.
	 * @param boolean $shared Whether the badge is shared.
	 *
	 * @return void
	 */
	private function download_png( array $badge, bool $shared ): void {
		$text  = self::credential_text( $badge );
		$image = self::attachment_png( $badge['achievement']['image'] ?? null );
		try {
			$png = Png_Baker::bake( $image ?? self::default_png(), $text );
		} catch ( Invalid_Input $e ) {
			$png = Png_Baker::bake( self::default_png(), $text );
		}
		$this->send_headers(
			200,
			self::file_headers( $shared ) + array(
				'Content-Type'        => 'image/png',
				'Content-Disposition' => 'attachment; filename="badge-' . $badge['id'] . '.png"',
			)
		);
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PNG bytes, sent as image/png with nosniff.
		$this->finish();
	}

	/**
	 * The bytes of the achievement image when it is a PNG media attachment on this site.
	 * Nothing is fetched over HTTP.
	 *
	 * @param mixed $url Achievement image URL.
	 *
	 * @return string|null
	 */
	private static function attachment_png( mixed $url ): ?string {
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$attachment = attachment_url_to_postid( $url );
		if ( $attachment < 1 || 'image/png' !== get_post_mime_type( $attachment ) ) {
			return null;
		}
		$file = get_attached_file( $attachment );
		if ( ! is_string( $file ) || ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}
		$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local upload.
		return is_string( $bytes ) ? $bytes : null;
	}

	/**
	 * The bundled default badge image.
	 *
	 * @return string
	 */
	private static function default_png(): string {
		return (string) file_get_contents( LEARNING_LEDGER_DIR . self::DEFAULT_IMAGE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a bundled file.
	}

	/**
	 * Headers of a download: cacheable when shared, private behind a signed link.
	 *
	 * @param boolean $shared Whether the badge is shared.
	 *
	 * @return array<string, string>
	 */
	private static function file_headers( bool $shared ): array {
		if ( $shared ) {
			// Not public caching: unsharing or revoking the badge must take effect at once.
			return array(
				'Cache-Control'          => No_Page_Cache::HEADERS['Cache-Control'],
				'X-Content-Type-Options' => 'nosniff',
			);
		}
		return Claim_Page::PRIVATE_HEADERS;
	}

	/**
	 * Send the public page.
	 *
	 * @param array $badge Badge row.
	 *
	 * @return void
	 */
	private function page( array $badge ): void {
		$a          = $badge['achievement'];
		$credential = $badge['credential'];
		$issuer     = is_array( $credential ) && is_string( $credential['issuer']['name'] ?? null ) ? $credential['issuer']['name'] : (string) Issuer_Profile::document()['name'];
		$issued     = (string) ( $badge['valid_from'] ?? $badge['issued_at'] ?? '' );
		$time       = '' === $issued ? null : new \DateTimeImmutable( $issued );

		$lledger_view = array(
			'name'         => is_string( $a['name'] ?? null ) ? $a['name'] : '',
			'description'  => is_string( $a['description'] ?? null ) ? $a['description'] : '',
			'criteria'     => is_string( $a['criteria']['narrative'] ?? null ) ? $a['criteria']['narrative'] : '',
			'issuer'       => $issuer,
			'issued_iso'   => null === $time ? '' : $time->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'issued_human' => null === $time ? '' : (string) wp_date( (string) get_option( 'date_format' ), $time->getTimestamp() ),
			'page_url'     => home_url( '/badges/' . $badge['id'] ),
			'json_url'     => home_url( '/badges/' . $badge['id'] . '.json' ),
			'png_url'      => home_url( '/badges/' . $badge['id'] . '.png' ),
			'script_nonce' => rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- CSP nonce.
		);
		$this->send_headers(
			200,
			array(
				'Cache-Control'           => No_Page_Cache::HEADERS['Cache-Control'],
				'X-Content-Type-Options'  => 'nosniff',
				'Referrer-Policy'         => 'no-referrer',
				'X-Robots-Tag'            => 'noindex',
				'Content-Type'            => 'text/html; charset=utf-8',
				'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; script-src 'nonce-" . $lledger_view['script_nonce'] . "'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'",
			)
		);
		require __DIR__ . '/templates/badge-page.php';
		$this->finish();
	}

	/**
	 * Mark the request a 404 and let the theme render it.
	 *
	 * @return void
	 */
	protected function not_found(): void {
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		if ( ! headers_sent() ) {
			status_header( 404 );
			nocache_headers();
		}
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
		if ( 'no-store' === ( $headers['Cache-Control'] ?? '' ) && ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page-cache convention; caches must not keep a signed download.
		}
		if ( headers_sent() ) {
			return;
		}
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
