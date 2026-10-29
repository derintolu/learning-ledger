<?php
/**
 * Issuer module: did.json, issuer profile route, WP-CLI.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Issuer;

use LearningLedger\Kernel\Module;
use LearningLedger\Kernel\No_Page_Cache;
use LearningLedger\Kernel\Page_Route;

/**
 * Serves did.json at `/.well-known/did.json` and `/issuer/did.json` (and its REST twin
 * `GET /learning-ledger/v1/page/did`), and the issuer Profile at `GET /learning-ledger/v1/issuer`.
 */
class Issuer_Module implements Module {

	/**
	 * Query variable set by the did.json rewrites.
	 */
	const QUERY_VAR = 'lledger_did';

	/**
	 * Headers sent with did.json.
	 */
	const DID_HEADERS = array(
		'Content-Type'                => 'application/json',
		'Cache-Control'               => No_Page_Cache::SHORT_CACHE_CONTROL,
		'Access-Control-Allow-Origin' => '*',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrites' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_did' ), 0 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'learning_ledger/about_extension', array( $this, 'about_extension' ) );
		add_filter( 'learning_ledger/status', array( $this, 'status' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'learning-ledger badges', Badges_Command::class );
		}
	}

	/**
	 * Rewrite rules for both did.json locations.
	 *
	 * @return void
	 */
	public function add_rewrites(): void {
		add_rewrite_rule( '^\.well-known/did\.json$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		add_rewrite_rule( '^issuer/did\.json$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
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
	 * Serve did.json when the query variable is set.
	 *
	 * @return void
	 */
	public function maybe_serve_did(): void {
		if ( '' === (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		$this->serve_did();
	}

	/**
	 * `GET page/did`: the REST twin of the did.json rewrites.
	 *
	 * @return void
	 */
	public function serve_did_rest(): void {
		Page_Route::start();
		$this->serve_did();
	}

	/**
	 * Send did.json and end the request.
	 *
	 * @return void
	 */
	public function serve_did(): void {
		// It changes when a key is rotated or removed: never kept by a page cache, and briefly by others.
		No_Page_Cache::apply( false );
		try {
			$body = wp_json_encode( Did_Document::document(), JSON_UNESCAPED_SLASHES );
		} catch ( \RuntimeException $e ) {
			$body = false;
		}
		if ( ! is_string( $body ) ) {
			$this->send_headers( 500, array( 'Cache-Control' => 'no-store' ) );
			$this->finish();
			return;
		}
		$this->send_headers( 200, self::DID_HEADERS );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document served as application/json.
		$this->finish();
	}

	/**
	 * Register the issuer profile route and the did.json twin.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'learning-ledger/v1',
			'/issuer',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'issuer_profile' ),
				'permission_callback' => '__return_true',
			)
		);
		Page_Route::register( 'did', 'GET', array( $this, 'serve_did_rest' ) );
	}

	/**
	 * Issuer profile response.
	 *
	 * @return \WP_REST_Response
	 */
	public function issuer_profile(): \WP_REST_Response {
		$response = new \WP_REST_Response( Issuer_Profile::document() );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * Add the issuer to the xAPI About extension.
	 *
	 * @param mixed $ext Extension value.
	 *
	 * @return array
	 */
	public function about_extension( mixed $ext ): array {
		$ext           = is_array( $ext ) ? $ext : array();
		$ext['badges'] = array(
			'did'            => Did_Document::did(),
			'issuer_profile' => rest_url( 'learning-ledger/v1/issuer' ),
			'key_tier'       => Key_Store::tier(),
		);
		return $ext;
	}

	/**
	 * Add the key tier to the status payload.
	 *
	 * @param mixed $payload Status payload.
	 *
	 * @return array
	 */
	public function status( mixed $payload ): array {
		$payload                   = is_array( $payload ) ? $payload : array();
		$payload['badge_key_tier'] = Key_Store::tier();
		return $payload;
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
