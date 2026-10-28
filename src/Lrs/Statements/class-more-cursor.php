<?php
/**
 * Signed paging cursor for statement queries.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Statements;

use LearningLedger\Crypto\Hmac;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Util\Clock;

/**
 * The `more` token: base64url JSON of the query parameters, the last seq returned, the
 * fixed upper bound (consistent-through of the first page) and an expiry, then `.` and its HMAC.
 */
final class More_Cursor {

	/**
	 * HMAC purpose label.
	 */
	private const PURPOSE = 'more';

	/**
	 * How long a token stays valid, in seconds.
	 */
	private const TTL = 86400;

	/**
	 * Longest token accepted.
	 */
	private const MAX_LENGTH = 8192;

	/**
	 * Constructor.
	 *
	 * @param array   $params      Query parameters.
	 * @param integer $last_seq    Seq of the last row returned.
	 * @param string  $upper_bound Upper bound on `stored`, xAPI format.
	 */
	private function __construct( private readonly array $params, private readonly int $last_seq, private readonly string $upper_bound ) {}

	/**
	 * Encode a cursor.
	 *
	 * @param array   $params      Query parameters.
	 * @param integer $last_seq    Seq of the last row returned.
	 * @param string  $upper_bound Upper bound on `stored`, xAPI format.
	 *
	 * @return string
	 */
	public static function encode( array $params, int $last_seq, string $upper_bound ): string {
		$payload = array(
			'params'      => $params,
			'last_seq'    => $last_seq,
			'upper_bound' => $upper_bound,
			'exp'         => Clock::now()->getTimestamp() + self::TTL,
		);
		$body    = self::b64( (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) );
		return $body . '.' . self::b64( Hmac::sign( $body, self::PURPOSE ) );
	}

	/**
	 * Decode and verify a cursor.
	 *
	 * @param string $token Token.
	 *
	 * @return self
	 * @throws Xapi_Error 400 when the token is malformed, expired, or its signature does not verify.
	 */
	public static function decode( string $token ): self {
		$invalid = new Xapi_Error( 400, 'The more token is invalid.' );
		if ( strlen( $token ) > self::MAX_LENGTH || 1 !== preg_match( '/\A([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\z/', $token, $m ) ) {
			throw $invalid;
		}
		$mac = self::unb64( $m[2] );
		if ( null === $mac || ! Hmac::verify( $mac, $m[1], self::PURPOSE ) ) {
			throw $invalid;
		}
		$json    = self::unb64( $m[1] );
		$payload = null === $json ? null : json_decode( $json, true );
		if ( ! is_array( $payload ) || ! is_array( $payload['params'] ?? null ) || ! is_int( $payload['last_seq'] ?? null ) || ! is_string( $payload['upper_bound'] ?? null )
			|| ! is_int( $payload['exp'] ?? null ) || $payload['exp'] < Clock::now()->getTimestamp() ) {
			throw $invalid;
		}
		return new self( $payload['params'], $payload['last_seq'], $payload['upper_bound'] );
	}

	/**
	 * Query parameters.
	 *
	 * @return array
	 */
	public function params(): array {
		return $this->params;
	}

	/**
	 * Seq of the last row returned.
	 *
	 * @return integer
	 */
	public function last_seq(): int {
		return $this->last_seq;
	}

	/**
	 * Upper bound on `stored` (the first page's consistent-through), xAPI format.
	 *
	 * @return string
	 */
	public function upper_bound(): string {
		return $this->upper_bound;
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $raw Bytes.
	 *
	 * @return string
	 */
	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding a signed token, not obfuscation.
	}

	/**
	 * Decode base64url.
	 *
	 * @param string $text Base64url text.
	 *
	 * @return string|null Null when invalid.
	 */
	private static function unb64( string $text ): ?string {
		$raw = base64_decode( strtr( $text, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a signed token, not obfuscation.
		return false === $raw ? null : $raw;
	}
}
