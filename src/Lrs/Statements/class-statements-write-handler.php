<?php
/**
 * PUT and POST on the Statements resource.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Statements;

use LearningLedger\Lrs\Http\Resource_Handler;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Http\Xapi_Response;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Xapi_Error messages are served as JSON, never echoed into HTML.

/**
 * `PUT statements?statementId=` (204) and `POST statements` (200, the ids).
 */
final class Statements_Write_Handler implements Resource_Handler {

	/**
	 * Largest statements request body, in bytes.
	 */
	public const MAX_BODY_BYTES = 1048576;

	/**
	 * Most statements in one POST.
	 */
	public const MAX_BATCH = 500;

	/**
	 * Deepest JSON nesting accepted.
	 */
	private const MAX_DEPTH = 64;

	/**
	 * Statement store.
	 *
	 * @var Statement_Store
	 */
	private Statement_Store $store;

	/**
	 * Constructor.
	 *
	 * @param Statement_Store|null $store Statement store.
	 */
	public function __construct( ?Statement_Store $store = null ) {
		$this->store = $store ?? new Statement_Store();
	}

	/**
	 * Handle PUT or POST.
	 *
	 * @param Xapi_Request $r Request.
	 *
	 * @return Xapi_Response
	 * @throws Xapi_Error 400, 403, 405, 409 or 413.
	 */
	public function handle( Xapi_Request $r ): Xapi_Response {
		$principal = $r->principal();
		if ( null === $principal ) {
			throw new Xapi_Error( 401, 'Authentication is required.' );
		}
		$content_type = strtolower( trim( (string) $r->header( 'Content-Type' ) ) );
		if ( str_starts_with( $content_type, 'multipart/mixed' ) ) {
			throw new Xapi_Error( 400, 'multipart attachments are not supported by this LRS' );
		}
		if ( strlen( $r->body() ) > self::MAX_BODY_BYTES ) {
			throw new Xapi_Error( 413, 'A statements request body must not be larger than 1 MB.' );
		}
		$decoded = self::decode( $r->body() );

		if ( 'PUT' === $r->method() ) {
			$id = $r->param( 'statementId' );
			if ( ! is_string( $id ) || '' === $id ) {
				throw new Xapi_Error( 400, 'PUT requires the statementId parameter.' );
			}
			if ( ! $decoded instanceof \stdClass ) {
				throw new Xapi_Error( 400, 'PUT takes a single statement object.' );
			}
			$this->store->put( $id, get_object_vars( $decoded ), $principal, $r->version() );
			return new Xapi_Response( 204, '', '' );
		}

		if ( 'POST' !== $r->method() ) {
			throw new Xapi_Error( 405, 'Method not allowed on this resource.', array( 'Allow' => 'GET, PUT, POST' ) );
		}
		$list = is_array( $decoded ) ? $decoded : array( $decoded );
		if ( count( $list ) > self::MAX_BATCH ) {
			throw new Xapi_Error( 413, 'A request may hold at most 500 statements.' );
		}
		$statements = array();
		foreach ( $list as $st ) {
			if ( ! $st instanceof \stdClass ) {
				throw new Xapi_Error( 400, 'Each statement must be a JSON object.' );
			}
			$statements[] = get_object_vars( $st );
		}
		return Xapi_Response::json( 200, $this->store->store( $statements, $principal, $r->version() ) );
	}

	/**
	 * Decode the body, keeping JSON objects as \stdClass so `{}` and `[]` stay apart.
	 *
	 * @param string $body Raw body.
	 *
	 * @return mixed
	 * @throws Xapi_Error 400 when the body is not JSON.
	 */
	private static function decode( string $body ): mixed {
		try {
			Json::decode_strict( $body, self::MAX_BODY_BYTES, self::MAX_DEPTH );
			return json_decode( $body, false, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR );
		} catch ( Invalid_Input | \JsonException $e ) {
			throw new Xapi_Error( 400, 'The request body is not valid JSON: ' . $e->getMessage() );
		}
	}
}
