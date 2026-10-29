<?php
/**
 * Read-only record page behind a view link.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Links;

use LearningLedger\Db\Tables;
use LearningLedger\Kernel\Page_Route;
use LearningLedger\Lrs\Auth\Bindings;
use LearningLedger\Lrs\Auth\Client;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Export\Ndjson_Exporter;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Statements\More_Cursor;
use LearningLedger\Lrs\Statements\Query_Params;
use LearningLedger\Lrs\Statements\Statement_Query;
use LearningLedger\Lrs\Statements\Stored_Statement;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;
use LearningLedger\Xapi\Agent_Identity;
use LearningLedger\Xapi\Formatter;

/**
 * `/records/<token>`: the statements of the view link's actor, newest first, 100 per page, each
 * as escaped JSON in a `<pre>`; `?download=ndjson` and `?download=json` give the whole record as
 * the NDJSON exporter writes it. The link works any number of times until it expires; an expired
 * or unknown token gets a 410 page. Every response is `no-store`, `noindex` and `no-referrer`.
 * `GET /learning-ledger/v1/page/record/<token>` is its REST twin; its links stay pretty.
 *
 * Not final so tests can capture the status and headers.
 */
class Record_Page {

	/**
	 * Query variable set by the rewrite.
	 */
	const QUERY_VAR = 'lledger_record';

	/**
	 * Rewrite rule: a 43-character base64url token.
	 */
	const RULE = '^records/([A-Za-z0-9_-]{43})/?$';

	/**
	 * Statements per page.
	 */
	const PER_PAGE = 100;

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
	 * Headers on HTML responses: no scripts, frames, forms or external resources.
	 */
	const HTML_HEADERS = array(
		'Content-Type'            => 'text/html; charset=utf-8',
		'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
	);

	/**
	 * Links.
	 *
	 * @var Link_Repository
	 */
	private Link_Repository $links;

	/**
	 * Statement reads.
	 *
	 * @var Statement_Query
	 */
	private Statement_Query $query;

	/**
	 * Constructor.
	 *
	 * @param Link_Repository|null $links Links.
	 * @param Statement_Query|null $query Statement reads.
	 */
	public function __construct( ?Link_Repository $links = null, ?Statement_Query $query = null ) {
		$this->links = $links ?? new Link_Repository();
		$this->query = $query ?? new Statement_Query();
	}

	/**
	 * The page URL for a token.
	 *
	 * @param string $token Token.
	 *
	 * @return string
	 */
	public static function url( string $token ): string {
		return home_url( '/records/' . $token );
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
		$token = get_query_var( self::QUERY_VAR );
		if ( ! is_string( $token ) || '' === $token ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only page; the token in the path is the authorisation.
		$download = isset( $_GET['download'] ) && is_string( $_GET['download'] ) ? sanitize_key( wp_unslash( $_GET['download'] ) ) : '';
		$more     = isset( $_GET['more'] ) && is_string( $_GET['more'] ) ? sanitize_text_field( wp_unslash( $_GET['more'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$this->serve( $token, $download, $more );
	}

	/**
	 * `GET page/record/<token>`: the REST twin of the rewrite.
	 *
	 * @param \WP_REST_Request $r Request.
	 *
	 * @return void
	 */
	public function serve_rest( \WP_REST_Request $r ): void {
		Page_Route::start();
		$download = $r->get_param( 'download' );
		$more     = $r->get_param( 'more' );
		$this->serve( (string) $r['token'], is_string( $download ) ? sanitize_key( $download ) : '', is_string( $more ) ? sanitize_text_field( $more ) : '' );
	}

	/**
	 * Register the REST twin (on `rest_api_init`).
	 *
	 * @return void
	 */
	public function register_route(): void {
		Page_Route::register( 'record/(?P<token>[A-Za-z0-9_-]{43})', 'GET', array( $this, 'serve_rest' ) );
	}

	/**
	 * Answer one request for a token, then end it.
	 *
	 * @param string $token    Token from the path.
	 * @param string $download Either `ndjson`, `json`, or '' for the page.
	 * @param string $more     Cursor of the page to show ('' for the newest).
	 *
	 * @return void
	 */
	public function serve( string $token, string $download, string $more ): void {
		$link = 1 === preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $token ) ? $this->links->resolve( $token, 'view' ) : null;
		if ( null === $link ) {
			$this->send_headers( 410, self::PRIVATE_HEADERS + self::HTML_HEADERS );
			self::render( array( 'gone' => true ) );
			$this->finish();
			return;
		}

		$actor = self::actor( $link['actor_hash'] );
		if ( 'ndjson' === $download || 'json' === $download ) {
			$this->download( $actor, $download );
			$this->finish();
			return;
		}

		$page = null === $actor ? array(
			'rows' => array(),
			'more' => null,
		) : $this->page_of( $actor, $more );

		$url  = self::url( $token );
		$lang = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
		$this->send_headers( 200, self::PRIVATE_HEADERS + self::HTML_HEADERS );
		self::render(
			array(
				'gone'          => false,
				'statements'    => array_map( static fn( Stored_Statement $row ) => self::view_of( $row, $lang ), $page['rows'] ),
				'expires_at'    => $link['expires_at'],
				'expires_label' => Timestamp::parse( $link['expires_at'], '2.0.0' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i' ) . ' UTC',
				'ndjson_url'    => add_query_arg( 'download', 'ndjson', $url ),
				'json_url'      => add_query_arg( 'download', 'json', $url ),
				'newest_url'    => '' === $more ? '' : $url,
				'next_url'      => null === $page['more'] ? '' : add_query_arg( 'more', rawurlencode( $page['more'] ), $url ),
			)
		);
		$this->finish();
	}

	/**
	 * The Agent a link's actor hash stands for, from the agents the LRS has seen; null when it
	 * has none (no statements, or the actor was erased).
	 *
	 * @param string $actor_hash Raw SHA-1 actor hash.
	 *
	 * @return array|null Agent object.
	 */
	private static function actor( string $actor_hash ): ?array {
		global $wpdb;
		$ifi = $wpdb->get_var( $wpdb->prepare( 'SELECT ifi FROM %i WHERE ifi_hash = UNHEX(%s)', Tables::name( 'agents' ), bin2hex( $actor_hash ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin-owned table, never cached.
		if ( ! is_string( $ifi ) ) {
			return null;
		}
		try {
			$identity = Agent_Identity::from_array( (array) Json::decode_strict( $ifi, 65536, 8 ) );
		} catch ( Invalid_Input $e ) {
			return null;
		}
		return hash_equals( $actor_hash, $identity->hash() ) ? $identity->to_array() : null;
	}

	/**
	 * One page of the actor's statements: those whose actor is this Agent, newest first.
	 *
	 * The read runs as a principal bound to the actor, so the read filter keeps every other
	 * actor's statements out, including ones that name this actor as their object.
	 *
	 * @param array  $actor Agent object.
	 * @param string $more  Cursor ('' for the newest page). A bad or foreign cursor gives the newest page.
	 *
	 * @return array{rows:Stored_Statement[],more:?string}
	 */
	private function page_of( array $actor, string $more ): array {
		$p = self::principal( Agent_Identity::from_array( $actor ) );
		if ( '' !== $more ) {
			try {
				$cursor = More_Cursor::decode( $more );
				$result = $this->query->run( Query_Params::from_cursor( $cursor ), $p, $cursor );
				return array(
					'rows' => $result['rows'],
					'more' => $result['more'],
				);
			} catch ( Xapi_Error $e ) {
				unset( $e );
			}
		}
		$q      = Query_Params::from_request( new Xapi_Request( 'GET', 'statements', array( 'limit' => (string) self::PER_PAGE ) ) );
		$result = $this->query->run( $q, $p );
		return array(
			'rows' => $result['rows'],
			'more' => $result['more'],
		);
	}

	/**
	 * A read-only principal for one actor: `statements/read`, bound to the actor. Never stored.
	 *
	 * @param Agent_Identity $actor Actor.
	 *
	 * @return Principal
	 */
	private static function principal( Agent_Identity $actor ): Principal {
		return Principal::for_client(
			new Client(
				'view-link:' . bin2hex( $actor->hash() ),
				'View link',
				'',
				array( Scopes::STATEMENTS_READ ),
				Bindings::from_array( array( 'actor_ifi' => $actor->ifi_key() ) ),
				array(),
				false,
				'default',
				true,
				false,
				null,
				Clock::now(),
				null
			)
		);
	}

	/**
	 * What the template shows for one statement. Values are raw; the template escapes them.
	 *
	 * @param Stored_Statement $row  Row.
	 * @param string           $lang Accept-Language header.
	 *
	 * @return array{id:string,stored:string,verb:string,object:string,json:string}
	 */
	private static function view_of( Stored_Statement $row, string $lang ): array {
		$st     = Formatter::format( $row->statement(), 'canonical', $lang );
		$object = is_array( $st['object'] ?? null ) ? $st['object'] : array();
		$kind   = is_string( $object['objectType'] ?? null ) ? $object['objectType'] : 'Activity';
		if ( 'Activity' === $kind ) {
			$label = self::first_string( $object['definition']['name'] ?? null ) ?? ( is_string( $object['id'] ?? null ) ? $object['id'] : '' );
		} elseif ( 'StatementRef' === $kind && is_string( $object['id'] ?? null ) ) {
			$label = 'StatementRef ' . $object['id'];
		} else {
			$label = $kind;
		}
		$pretty = wp_json_encode( json_decode( $row->body ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return array(
			'id'     => $row->id,
			'stored' => $row->stored,
			'verb'   => self::first_string( $st['verb']['display'] ?? null ) ?? ( is_string( $st['verb']['id'] ?? null ) ? $st['verb']['id'] : '' ),
			'object' => $label,
			'json'   => is_string( $pretty ) ? $pretty : $row->body,
		);
	}

	/**
	 * The first string of a language map.
	 *
	 * @param mixed $map Language map.
	 *
	 * @return string|null
	 */
	private static function first_string( mixed $map ): ?string {
		if ( is_array( $map ) ) {
			foreach ( $map as $value ) {
				if ( is_string( $value ) && '' !== $value ) {
					return $value;
				}
			}
		}
		return null;
	}

	/**
	 * Stream the whole record: NDJSON exactly as the exporter writes it, or the same records as
	 * one JSON array. An actor the LRS has no agent row for gets an empty file.
	 *
	 * @param array|null $actor  Agent object, or null.
	 * @param string     $format Either `ndjson` or `json`.
	 *
	 * @return void
	 */
	private function download( ?array $actor, string $format ): void {
		$json = 'json' === $format;
		$this->send_headers(
			200,
			self::PRIVATE_HEADERS + array(
				'Content-Type'        => $json ? 'application/json; charset=utf-8' : 'application/x-ndjson; charset=utf-8',
				'Content-Disposition' => 'attachment; filename="learning-record-' . gmdate( 'Ymd\THis\Z' ) . ( $json ? '.json' : '.ndjson' ) . '"',
			)
		);
		if ( $json ) {
			echo '[';
		}
		if ( null !== $actor ) {
			$first = true;
			try {
				( new Ndjson_Exporter() )->stream(
					array( 'agent' => $actor ),
					static function ( string $line ) use ( $json, &$first ): void {
						if ( $json ) {
							echo ( $first ? '' : ',' ) . rtrim( $line, "\n" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML; each record is JSON built from stored bodies.
							$first = false;
							return;
						}
						echo $line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- NDJSON download, not HTML; each line is JSON built from stored bodies.
					}
				);
			} catch ( \RuntimeException $e ) {
				return; // The read failed part-way: the file ends early (and, as JSON, does not parse).
			}
		}
		if ( $json ) {
			echo ']';
		}
	}

	/**
	 * Render the template.
	 *
	 * @param array $lledger_view View values (raw; the template escapes them).
	 *
	 * @return void
	 */
	private static function render( array $lledger_view ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- read by the template.
		require __DIR__ . '/templates/record-page.php';
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
