<?php
/**
 * HTTP Basic authentication for LRS clients and child tokens.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

use LearningLedger\Crypto\Tokens;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Util\Clock;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed; HTTP layers JSON-encode them.

/**
 * Turns a Basic credential into a Principal, enforcing the failed-auth and per-client rate limits.
 * Secrets are compared as SHA-256 with hash_equals and are never logged or echoed.
 */
final class Authenticator {

	const REALM           = 'Basic realm="Learning Ledger"';
	const MISSING_MESSAGE = 'Authorization header missing (some hosts strip it; see readme)';
	const INVALID_MESSAGE = 'Invalid credentials.';

	/**
	 * Client storage.
	 *
	 * @var Client_Repository
	 */
	private Client_Repository $clients;

	/**
	 * Token storage.
	 *
	 * @var Token_Repository
	 */
	private Token_Repository $tokens;

	/**
	 * Per-client buckets.
	 *
	 * @var Rate_Limiter
	 */
	private Rate_Limiter $limiter;

	/**
	 * Per-IP failure counter.
	 *
	 * @var Auth_Fail_Limiter
	 */
	private Auth_Fail_Limiter $failures;

	/**
	 * Constructor.
	 *
	 * @param Client_Repository|null $clients  Client storage.
	 * @param Token_Repository|null  $tokens   Token storage.
	 * @param Rate_Limiter|null      $limiter  Rate limiter.
	 * @param Auth_Fail_Limiter|null $failures Failure limiter.
	 */
	public function __construct( ?Client_Repository $clients = null, ?Token_Repository $tokens = null, ?Rate_Limiter $limiter = null, ?Auth_Fail_Limiter $failures = null ) {
		$this->clients  = $clients ?? new Client_Repository();
		$this->tokens   = $tokens ?? new Token_Repository();
		$this->limiter  = $limiter ?? new Rate_Limiter();
		$this->failures = $failures ?? new Auth_Fail_Limiter();
	}

	/**
	 * Authenticate a request.
	 *
	 * @param Xapi_Request $r      Request.
	 * @param array        $server Server variables, read when the request has no Authorization header.
	 *
	 * @return Principal
	 * @throws Xapi_Error 401 (with WWW-Authenticate) or 429 (with Retry-After).
	 */
	public function authenticate( Xapi_Request $r, array $server ): Principal {
		$ip      = $r->ip();
		$blocked = $this->failures->blocked_for( $ip );
		if ( $blocked > 0 ) {
			throw new Xapi_Error( 429, 'Too many failed authentication attempts.', array( 'Retry-After' => (string) $blocked ) );
		}

		$credential = self::credential( $r, $server );
		if ( null === $credential ) {
			throw new Xapi_Error( 401, self::MISSING_MESSAGE, array( 'WWW-Authenticate' => self::REALM ) );
		}

		$principal = $this->verify( $credential[0], $credential[1] );
		if ( null === $principal ) {
			$this->failures->hit( $ip );
			throw new Xapi_Error( 401, self::INVALID_MESSAGE, array( 'WWW-Authenticate' => self::REALM ) );
		}

		$client  = $this->clients->find( $principal->client_id() );
		$classes = Rate_Limiter::classes();
		$class   = array_key_exists( $client->rate_class, $classes ) ? $classes[ $client->rate_class ] : $classes['default'];
		if ( null !== $class && ! $this->limiter->consume( 'client:' . $principal->authority_key(), (float) $class[0], (int) $class[1] ) ) {
			throw new Xapi_Error( 429, 'Rate limit exceeded.', array( 'Retry-After' => '1' ) );
		}

		return $principal;
	}

	/**
	 * Username and password from the request, or null when no credential was sent.
	 * A header that is present but not valid Basic yields an empty pair, which fails verification.
	 *
	 * @param Xapi_Request $r      Request.
	 * @param array        $server Server variables.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private static function credential( Xapi_Request $r, array $server ): ?array {
		$header = $r->header( 'Authorization' );
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $k ) {
			if ( ( null === $header || '' === $header ) && isset( $server[ $k ] ) && is_string( $server[ $k ] ) ) {
				$header = wp_unslash( $server[ $k ] );
			}
		}

		if ( null === $header || '' === $header ) {
			if ( isset( $server['PHP_AUTH_USER'] ) && is_string( $server['PHP_AUTH_USER'] ) ) {
				$pw = isset( $server['PHP_AUTH_PW'] ) && is_string( $server['PHP_AUTH_PW'] ) ? $server['PHP_AUTH_PW'] : '';
				return array( wp_unslash( $server['PHP_AUTH_USER'] ), wp_unslash( $pw ) );
			}
			return null;
		}

		if ( 1 !== preg_match( '/^Basic\s+([A-Za-z0-9+\/]+=*)\s*$/i', $header, $m ) ) {
			return array( '', '' );
		}
		$decoded = base64_decode( $m[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- HTTP Basic credentials.
		if ( false === $decoded || ! str_contains( $decoded, ':' ) ) {
			return array( '', '' );
		}
		return explode( ':', $decoded, 2 );
	}

	/**
	 * Look up and verify a credential. Unknown usernames still pay for one hash comparison.
	 *
	 * @param string $user     Username (client id or token key).
	 * @param string $password Secret.
	 *
	 * @return Principal|null
	 */
	private function verify( string $user, string $password ): ?Principal {
		$now = Clock::now();
		if ( '' === $user || '' === $password ) {
			return null;
		}

		if ( str_starts_with( $user, 'tok_' ) ) {
			$token = $this->tokens->find( $user );
			if ( null === $token ) {
				hash_equals( Tokens::hash( '' ), Tokens::hash( $password ) );
				return null;
			}
			if ( ! $token->secret_matches( $password ) || ! $token->is_active( $now ) ) {
				return null;
			}
			$parent_client = $this->clients->find( $token->parent_client );
			if ( null === $parent_client || ! $parent_client->is_active( $now ) ) {
				return null;
			}
			return Principal::for_token( $parent_client, $token );
		}

		$client = $this->clients->find( $user );
		if ( null === $client ) {
			hash_equals( Tokens::hash( '' ), Tokens::hash( $password ) );
			return null;
		}
		if ( ! $client->secret_matches( $password ) || ! $client->is_active( $now ) ) {
			return null;
		}
		return Principal::for_client( $client );
	}
}
