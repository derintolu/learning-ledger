<?php
/**
 * Site Health checks and the runtime report.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Kernel;

use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Secret_Box;
use LearningLedger\Db\Db_Requirements;
use LearningLedger\Lrs\Auth\Authenticator;
use LearningLedger\Lrs\Statements\Statement_Repository;
use LearningLedger\Player\Store\Content_Host;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Queue\Shutdown_Runner;
use LearningLedger\Util\Clock;

/**
 * Adds Learning Ledger's tests to Tools > Site Health, and the `runtime` block to the xAPI About
 * extension. Reports counts and versions only, never a secret.
 */
final class Site_Health implements Module {

	/**
	 * REST route namespace for the async tests.
	 */
	private const REST_NAMESPACE = 'learning-ledger/v1';

	/**
	 * Both roles on one install: notice above this many statements.
	 */
	public const ROLES_TOGETHER_THRESHOLD = 1000000;

	/**
	 * Seconds the queue may sit idle while work is due before cron counts as broken.
	 */
	public const CRON_STALE_SECONDS = 600;

	/**
	 * Async test name => REST path segment.
	 */
	private const ASYNC = array(
		'lledger_authorization_header' => 'authorization-header',
		'lledger_did_json'             => 'did-json',
	);

	/**
	 * Fixed, meaningless credential for the Authorization loopback. It never matches a client.
	 */
	private const PROBE_USER = 'lledger-site-health';
	private const PROBE_PASS = 'probe';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
		add_filter( 'learning_ledger/about_extension', array( $this, 'about_extension' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Add the direct and async tests.
	 *
	 * @param mixed $tests Tests by type.
	 *
	 * @return array
	 */
	public function tests( mixed $tests ): array {
		$tests = is_array( $tests ) ? $tests : array();

		$direct = array(
			'lledger_sodium'            => array( __( 'Learning Ledger: signing library', 'learning-ledger' ), 'test_sodium' ),
			'lledger_badge_key_storage' => array( __( 'Learning Ledger: badge key storage', 'learning-ledger' ), 'test_badge_key_storage' ),
			'lledger_roles_together'    => array( __( 'Learning Ledger: LRS and connector on one site', 'learning-ledger' ), 'test_roles_together' ),
			'lledger_cron'              => array( __( 'Learning Ledger: background queue', 'learning-ledger' ), 'test_cron' ),
			'lledger_shutdown'          => array( __( 'Learning Ledger: shutdown processing', 'learning-ledger' ), 'test_shutdown' ),
			'lledger_content_host'      => array( __( 'Learning Ledger: content host', 'learning-ledger' ), 'test_content_host' ),
			'lledger_db'                => array( __( 'Learning Ledger: database version', 'learning-ledger' ), 'test_db' ),
		);
		foreach ( $direct as $name => $def ) {
			$tests['direct'][ $name ] = array(
				'label' => $def[0],
				'test'  => array( $this, $def[1] ),
			);
		}

		$async = array(
			'lledger_authorization_header' => array( __( 'Learning Ledger: Authorization header', 'learning-ledger' ), 'test_authorization_header' ),
			'lledger_did_json'             => array( __( 'Learning Ledger: issuer DID document', 'learning-ledger' ), 'test_did_json' ),
		);
		foreach ( $async as $name => $def ) {
			$tests['async'][ $name ] = array(
				'label'             => $def[0],
				'test'              => rest_url( self::REST_NAMESPACE . '/site-health/' . self::ASYNC[ $name ] ),
				'has_rest'          => true,
				'async_direct_test' => array( $this, $def[1] ),
			);
		}
		return $tests;
	}

	/**
	 * Register the routes the async tests call.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/site-health/(?P<test>authorization-header|did-json)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_probe' ),
				'permission_callback' => static fn(): bool => current_user_can( 'view_site_health_checks' ),
			)
		);
	}

	/**
	 * Run one async test.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_probe( \WP_REST_Request $request ): \WP_REST_Response {
		$result = 'did-json' === $request->get_param( 'test' ) ? $this->test_did_json() : $this->test_authorization_header();
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Add the runtime report to the About extension.
	 *
	 * @param mixed $ext Extension value.
	 *
	 * @return array
	 */
	public function about_extension( mixed $ext ): array {
		$ext            = is_array( $ext ) ? $ext : array();
		$ext['runtime'] = $this->runtime();
		return $ext;
	}

	/**
	 * What the host did lately: signing backend, last shutdown and cron runs, queue backlog.
	 *
	 * @return array{sodium:string,shutdown_last:?string,shutdown_fastcgi:bool,cron_last:?string,queue_due:int}
	 */
	public function runtime(): array {
		$shutdown = get_option( Shutdown_Runner::LAST_RUN_OPTION, null );
		return array(
			'sodium'           => Ed25519::backend(),
			'shutdown_last'    => is_array( $shutdown ) && isset( $shutdown['at'] ) && is_string( $shutdown['at'] ) ? $shutdown['at'] : null,
			'shutdown_fastcgi' => $this->fastcgi_available(),
			'cron_last'        => Job_Queue::last_run(),
			'queue_due'        => Job_Queue::due_count(),
		);
	}

	/**
	 * Whether the response can be closed before queued work runs: what the last shutdown run saw,
	 * else what this request can do.
	 *
	 * @return boolean
	 */
	private function fastcgi_available(): bool {
		$shutdown = get_option( Shutdown_Runner::LAST_RUN_OPTION, null );
		if ( is_array( $shutdown ) && isset( $shutdown['fastcgi_finish_request'] ) ) {
			return (bool) $shutdown['fastcgi_finish_request'];
		}
		return function_exists( 'fastcgi_finish_request' );
	}

	/**
	 * Recommended when ext-sodium is missing and the slower pure-PHP backend is in use.
	 *
	 * @return array
	 */
	public function test_sodium(): array {
		if ( Ed25519::COMPAT !== Ed25519::backend() ) {
			return self::result( 'lledger_sodium', 'good', __( 'The PHP sodium extension is in use', 'learning-ledger' ), __( 'Signatures and hashing use the native libsodium extension.', 'learning-ledger' ) );
		}
		return self::result(
			'lledger_sodium',
			'recommended',
			__( 'The PHP sodium extension is not available', 'learning-ledger' ),
			__( 'Learning Ledger is using the slower pure-PHP fallback for badge signing and signature checks. It works, but ask your host to enable the sodium extension.', 'learning-ledger' )
		);
	}

	/**
	 * Recommended when badge keys are kept in the database.
	 *
	 * @return array
	 */
	public function test_badge_key_storage(): array {
		if ( Key_Store::TIER_DATABASE !== Key_Store::tier() ) {
			return self::result( 'lledger_badge_key_storage', 'good', __( 'Badge keys are stored outside the database', 'learning-ledger' ), __( 'The badge signing keys come from a wp-config constant or a key file.', 'learning-ledger' ) );
		}
		$source = Secret_Box::key_source();
		$why    = 'constant' === $source
			? __( 'They are encrypted with the key in LLEDGER_ENCRYPTION_KEY, but a database leak plus that constant would still expose them.', 'learning-ledger' )
			: __( 'They are encrypted with a key derived from the WordPress salts, so changing the salts makes them unreadable.', 'learning-ledger' );
		return self::result(
			'lledger_badge_key_storage',
			'recommended',
			__( 'Badge signing keys are stored in the database', 'learning-ledger' ),
			__( 'Anyone who can read the database and wp-config.php can sign badges as this site.', 'learning-ledger' ) . ' ' . $why,
			'<p>' . esc_html__( 'Set LLEDGER_BADGE_KEY in wp-config.php, or point LLEDGER_BADGE_KEY_FILE at a key file outside the web root.', 'learning-ledger' ) . '</p>'
		);
	}

	/**
	 * Recommended when both roles share an install and the LRS has outgrown it.
	 *
	 * @return array
	 */
	public function test_roles_together(): array {
		if ( Roles::is_enabled( 'lrs' ) && Roles::is_enabled( 'connector' ) ) {
			$count = ( new Statement_Repository() )->approx_count();
			if ( $count > self::ROLES_TOGETHER_THRESHOLD ) {
				return self::result(
					'lledger_roles_together',
					'recommended',
					__( 'The LRS and the connector share a large site', 'learning-ledger' ),
					sprintf(
						/* translators: %s: number of statements. */
						__( 'This site holds about %s xAPI statements and also runs the connector. A busy LRS can slow a site down, so run the LRS on its own WordPress install.', 'learning-ledger' ),
						number_format_i18n( $count )
					)
				);
			}
		}
		return self::result( 'lledger_roles_together', 'good', __( 'The LRS and connector roles are balanced', 'learning-ledger' ), __( 'No single site is carrying both a large LRS and the connector.', 'learning-ledger' ) );
	}

	/**
	 * Critical when work is due but the queue has not run for ten minutes.
	 *
	 * An idle queue records no run, so only a backlog counts as a stalled cron.
	 *
	 * @return array
	 */
	public function test_cron(): array {
		$due = Job_Queue::due_count();
		if ( $due > 0 ) {
			$last  = Job_Queue::last_run();
			$stale = true;
			if ( null !== $last ) {
				try {
					$stale = Clock::now()->getTimestamp() - ( new \DateTimeImmutable( $last ) )->getTimestamp() > self::CRON_STALE_SECONDS;
				} catch ( \Exception $e ) {
					$stale = true;
				}
			}
			if ( $stale ) {
				return self::result(
					'lledger_cron',
					'critical',
					__( 'The background queue is not running', 'learning-ledger' ),
					sprintf(
						/* translators: %s: number of jobs waiting. */
						_n( '%s job is waiting and the queue has not run for ten minutes. Webhooks, badges and imports are held up.', '%s jobs are waiting and the queue has not run for ten minutes. Webhooks, badges and imports are held up.', $due, 'learning-ledger' ),
						number_format_i18n( $due )
					),
					'<p>' . esc_html__( 'Make sure WP-Cron can run. If DISABLE_WP_CRON is set, call wp-cron.php or run "wp learning-ledger cron" from a system cron every minute.', 'learning-ledger' ) . '</p>'
				);
			}
		}
		return self::result( 'lledger_cron', 'good', __( 'The background queue is running', 'learning-ledger' ), __( 'Queued work is being picked up on time.', 'learning-ledger' ) );
	}

	/**
	 * Recommended when the response cannot be closed before queued work runs.
	 *
	 * @return array
	 */
	public function test_shutdown(): array {
		if ( $this->fastcgi_available() ) {
			return self::result( 'lledger_shutdown', 'good', __( 'Queued work runs after the response is sent', 'learning-ledger' ), __( 'fastcgi_finish_request() is available.', 'learning-ledger' ) );
		}
		return self::result(
			'lledger_shutdown',
			'recommended',
			__( 'Queued work cannot run after the response is sent', 'learning-ledger' ),
			__( 'fastcgi_finish_request() is not available on this server, so work is left to WP-Cron and runs up to a minute later than it could.', 'learning-ledger' )
		);
	}

	/**
	 * Critical when the configured content host would see WordPress cookies.
	 *
	 * @return array
	 */
	public function test_content_host(): array {
		$host = Content_Host::get();
		if ( null !== $host && ! Content_Host::is_safe( $host ) ) {
			return self::result(
				'lledger_content_host',
				'critical',
				__( 'The content host is not safe', 'learning-ledger' ),
				sprintf(
					/* translators: %s: configured host name. */
					__( 'The content host %s is invalid, or it is the site itself or inside the cookie domain. Course files served from it could read WordPress cookies.', 'learning-ledger' ),
					esc_html( $host )
				),
				'<p>' . esc_html__( 'Use a separate hostname that is not covered by the site cookies, or remove the setting to use the sandboxed fallback.', 'learning-ledger' ) . '</p>'
			);
		}
		return self::result( 'lledger_content_host', 'good', __( 'The content host is safe', 'learning-ledger' ), __( 'Course files are served from a separate host, or from the sandboxed fallback.', 'learning-ledger' ) );
	}

	/**
	 * Critical when the LRS role is on and the database server is too old.
	 *
	 * @return array
	 */
	public function test_db(): array {
		if ( Roles::is_enabled( 'lrs' ) ) {
			$check = Db_Requirements::check();
			if ( is_wp_error( $check ) ) {
				return self::result( 'lledger_db', 'critical', __( 'The database server is too old for the LRS', 'learning-ledger' ), esc_html( $check->get_error_message() ) );
			}
		}
		return self::result( 'lledger_db', 'good', __( 'The database server is supported', 'learning-ledger' ), __( 'The database server meets the LRS requirements.', 'learning-ledger' ) );
	}

	/**
	 * Loopback: does a Basic Authorization header reach PHP on /xapi/statements?
	 *
	 * @return array
	 */
	public function test_authorization_header(): array {
		$name = 'lledger_authorization_header';
		if ( ! Roles::is_enabled( 'lrs' ) ) {
			return self::result( $name, 'good', __( 'The Authorization header check is not needed', 'learning-ledger' ), __( 'The LRS role is off.', 'learning-ledger' ) );
		}

		$url      = home_url( '/xapi/statements' );
		$response = $this->loopback(
			$url,
			array(
				'Authorization'            => 'Basic ' . base64_encode( self::PROBE_USER . ':' . self::PROBE_PASS ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials.
				'X-Experience-API-Version' => '2.0.0',
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::result( $name, 'recommended', __( 'The Authorization header could not be checked', 'learning-ledger' ), esc_html( $response->get_error_message() ) );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$message = is_array( $decoded ) && isset( $decoded['message'] ) && is_string( $decoded['message'] ) ? $decoded['message'] : '';

		if ( 401 === $code && Authenticator::INVALID_MESSAGE === $message ) {
			return self::result( $name, 'good', __( 'The Authorization header reaches WordPress', 'learning-ledger' ), __( 'xAPI clients can send Basic credentials to this site.', 'learning-ledger' ) );
		}
		if ( 401 === $code && Authenticator::MISSING_MESSAGE === $message ) {
			return self::result(
				'lledger_authorization_header',
				'critical',
				__( 'The server strips the Authorization header', 'learning-ledger' ),
				__( 'A Basic Authorization header sent to /xapi/statements never reached PHP, so no xAPI client can sign in.', 'learning-ledger' ),
				'<p>' . esc_html__( 'On Apache, add this line to .htaccess above the WordPress rules:', 'learning-ledger' ) . '</p><p><code>' . esc_html( 'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]' ) . '</code></p>'
			);
		}
		if ( 429 === $code ) {
			return self::result( $name, 'recommended', __( 'The Authorization header could not be checked', 'learning-ledger' ), __( 'Too many failed sign-ins came from this server. Try again in a minute.', 'learning-ledger' ) );
		}
		return self::result(
			$name,
			'recommended',
			__( 'The xAPI address does not reach Learning Ledger', 'learning-ledger' ),
			sprintf(
				/* translators: 1: URL, 2: HTTP status code. */
				__( 'A request to %1$s answered with HTTP %2$d instead of an xAPI response.', 'learning-ledger' ),
				esc_html( $url ),
				$code
			),
			'<p>' . esc_html__( 'Turn on pretty permalinks and make sure the web server sends /xapi/ paths to WordPress.', 'learning-ledger' ) . '</p>'
		);
	}

	/**
	 * Loopback: does did.json reach WordPress?
	 *
	 * @return array
	 */
	public function test_did_json(): array {
		$name = 'lledger_did_json';
		if ( ! Roles::is_enabled( 'lrs' ) ) {
			return self::result( $name, 'good', __( 'The issuer DID document check is not needed', 'learning-ledger' ), __( 'The LRS role is off.', 'learning-ledger' ) );
		}

		$path_mode = 'path' === get_option( Did_Document::MODE_OPTION );
		$url       = home_url( $path_mode ? '/issuer/did.json' : '/.well-known/did.json' );
		$response  = $this->loopback( $url, array() );
		if ( is_wp_error( $response ) ) {
			return self::result( $name, 'recommended', __( 'The issuer DID document could not be checked', 'learning-ledger' ), esc_html( $response->get_error_message() ) );
		}

		try {
			$did = Did_Document::did();
		} catch ( \RuntimeException $e ) {
			$did = '';
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) && '' !== $did && is_array( $decoded ) && ( $decoded['id'] ?? null ) === $did ) {
			return self::result( $name, 'good', __( 'The issuer DID document is reachable', 'learning-ledger' ), __( 'Verifiers can fetch the issuer DID document from this site.', 'learning-ledger' ) );
		}

		$actions = $path_mode
			? ''
			: '<p>' . esc_html__( 'If the web server owns /.well-known/, serve the DID document at /issuer/did.json instead. Switch before issuing badges, because it changes the issuer DID:', 'learning-ledger' ) . '</p><p><code>wp learning-ledger badges did-mode path</code></p>';
		return self::result(
			$name,
			'recommended',
			__( 'The issuer DID document is not reachable', 'learning-ledger' ),
			sprintf(
				/* translators: %s: URL. */
				__( 'A request to %s did not return the issuer DID document, so verifiers cannot check badges from this site.', 'learning-ledger' ),
				esc_html( $url )
			),
			$actions
		);
	}

	/**
	 * Request this site's own URL.
	 *
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Request headers.
	 *
	 * @return array|\WP_Error
	 */
	private function loopback( string $url, array $headers ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => $headers,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter for loopback requests.
			)
		);
	}

	/**
	 * A result in the shape Site Health expects.
	 *
	 * @param string $test        Test name.
	 * @param string $status      One of good, recommended or critical.
	 * @param string $label       Headline.
	 * @param string $description Plain text or escaped HTML for the body.
	 * @param string $actions     HTML for the actions area.
	 *
	 * @return array
	 */
	private static function result( string $test, string $status, string $label, string $description, string $actions = '' ): array {
		$colors = array(
			'good'        => 'green',
			'recommended' => 'orange',
			'critical'    => 'red',
		);
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Learning Ledger', 'learning-ledger' ),
				'color' => $colors[ $status ],
			),
			'description' => '<p>' . $description . '</p>',
			'actions'     => $actions,
			'test'        => $test,
		);
	}
}
