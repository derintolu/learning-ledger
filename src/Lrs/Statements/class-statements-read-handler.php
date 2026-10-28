<?php
/**
 * GET on the Statements resource.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Statements;

use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Http\Resource_Handler;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Http\Xapi_Response;
use LearningLedger\Lrs\Http\Xapi_Router;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;
use LearningLedger\Xapi\Formatter;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Xapi_Error messages are served as JSON, never echoed into HTML.

/**
 * `GET statements`: one statement by `statementId` / `voidedStatementId`, or a StatementResult.
 */
final class Statements_Read_Handler implements Resource_Handler {

	/**
	 * Queries.
	 *
	 * @var Statement_Query
	 */
	private Statement_Query $query;

	/**
	 * Constructor.
	 *
	 * @param Statement_Query|null $query Queries.
	 */
	public function __construct( ?Statement_Query $query = null ) {
		$this->query = $query ?? new Statement_Query();
	}

	/**
	 * Handle GET.
	 *
	 * @param Xapi_Request $r Request.
	 *
	 * @return Xapi_Response
	 * @throws Xapi_Error 400 invalid parameters or `more`, 401, 403 without a read scope, 404 no such statement;
	 *                    each carries X-Experience-API-Consistent-Through (XAPI-00153).
	 */
	public function handle( Xapi_Request $r ): Xapi_Response {
		try {
			return $this->respond( $r );
		} catch ( Xapi_Error $e ) {
			throw new Xapi_Error( $e->status(), $e->getMessage(), array_merge( $e->headers(), self::consistent_header() ) );
		} catch ( Invalid_Input $e ) {
			throw new Xapi_Error( 400, $e->getMessage(), self::consistent_header() );
		}
	}

	/**
	 * The consistent-through header that every response to a statements GET carries.
	 *
	 * @return array<string,string>
	 */
	private static function consistent_header(): array {
		return array( 'X-Experience-API-Consistent-Through' => Statement_Query::consistent_through() );
	}

	/**
	 * Answer a GET.
	 *
	 * @param Xapi_Request $r Request.
	 *
	 * @return Xapi_Response
	 * @throws Xapi_Error As for handle().
	 * @throws Invalid_Input On an invalid parameter.
	 */
	private function respond( Xapi_Request $r ): Xapi_Response {
		$p = $r->principal();
		if ( null === $p ) {
			throw new Xapi_Error( 401, 'Authentication is required.' );
		}
		if ( ! $p->can( Scopes::STATEMENTS_READ ) && ! $p->can( Scopes::STATEMENTS_READ_MINE ) ) {
			throw new Xapi_Error( 403, 'This client may not read statements.' );
		}
		$is_v2 = '1.0.3' !== $r->version();
		$lang  = (string) $r->header( 'Accept-Language' );

		$cursor = null;
		$more   = $r->param( 'more' );
		if ( null !== $more ) {
			if ( ! is_string( $more ) || 1 !== count( $r->params() ) ) {
				throw new Xapi_Error( 400, 'The more parameter cannot be combined with other parameters.' );
			}
			$cursor = More_Cursor::decode( $more );
			$q      = Query_Params::from_cursor( $cursor );
		} else {
			$q = Query_Params::from_request( $r );
		}

		if ( null !== $q->statement_id || null !== $q->voided_statement_id ) {
			$voided = null !== $q->voided_statement_id;
			$row    = $this->query->find( (string) ( $voided ? $q->voided_statement_id : $q->statement_id ), $voided, $p );
			if ( null === $row ) {
				throw new Xapi_Error( 404, 'No such statement.' );
			}
			$headers = array( 'X-Experience-API-Consistent-Through' => Statement_Query::consistent_through() );
			if ( $is_v2 ) {
				$headers['Last-Modified'] = self::http_date( $row->stored );
			}
			return new Xapi_Response( 200, self::render( $row, $q->format, $lang ), 'application/json', $headers );
		}

		$result  = $this->query->run( $q, $p, $cursor );
		$parts   = array_map( static fn( Stored_Statement $row ) => self::render( $row, $q->format, $lang ), $result['rows'] );
		$body    = '{"statements":[' . implode( ',', $parts ) . '],"more":' . Json::encode_exact( null === $result['more'] ? '' : self::more_url( $result['more'] ) ) . '}';
		$headers = array( 'X-Experience-API-Consistent-Through' => $result['consistent_through'] );
		if ( $is_v2 && array() !== $result['rows'] ) {
			$headers['Last-Modified'] = self::http_date( max( array_map( static fn( Stored_Statement $row ) => $row->stored, $result['rows'] ) ) );
		}
		return new Xapi_Response( 200, $body, 'application/json', $headers );
	}

	/**
	 * A statement in the requested format: the stored bytes for `exact`, else re-encoded
	 * with the objects of the stored JSON kept as objects.
	 *
	 * @param Stored_Statement $row    Row.
	 * @param string           $format Format.
	 * @param string           $lang   Accept-Language header.
	 *
	 * @return string
	 */
	private static function render( Stored_Statement $row, string $format, string $lang ): string {
		if ( 'exact' === $format ) {
			return $row->body;
		}
		$shape = json_decode( $row->body, false, 512, JSON_THROW_ON_ERROR );
		return Json::encode_exact( self::restore_objects( Formatter::format( $row->statement(), $format, $lang ), $shape ) );
	}

	/**
	 * Turn back into objects the arrays that were JSON objects in the stored body (an empty
	 * `{}`, or an object whose keys look like list indexes).
	 *
	 * @param mixed $out   Formatted value (objects as associative arrays).
	 * @param mixed $shape The stored value, objects as \stdClass.
	 *
	 * @return mixed
	 */
	private static function restore_objects( mixed $out, mixed $shape ): mixed {
		if ( ! is_array( $out ) ) {
			return $out;
		}
		$was_object = $shape instanceof \stdClass;
		$in         = $was_object ? get_object_vars( $shape ) : ( is_array( $shape ) ? $shape : array() );
		foreach ( $out as $key => $value ) {
			if ( array_key_exists( $key, $in ) ) {
				$out[ $key ] = self::restore_objects( $value, $in[ $key ] );
			}
		}
		return $was_object && Json::is_list( $out ) ? (object) $out : $out;
	}

	/**
	 * The `more` IRL: path and query without scheme, host or port, on the base the request
	 * arrived at (the `/xapi/` alias, or the REST route).
	 *
	 * @param string $token Cursor token.
	 *
	 * @return string
	 */
	private static function more_url( string $token ): string {
		if ( self::arrived_via_alias() ) {
			$url = home_url( '/xapi/statements' ) . '?more=' . $token;
		} else {
			$url = add_query_arg( 'more', $token, rest_url( Xapi_Router::REST_NAMESPACE . '/statements' ) );
		}
		$parts = wp_parse_url( $url );
		return ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * Whether the request path, below the home path, starts with `/xapi` (as in Xapi_Router::is_xapi_request).
	 *
	 * @return boolean
	 */
	private static function arrived_via_alias(): bool {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against a path pattern.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = '/' . trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '/' !== $home ) {
			if ( ! str_starts_with( $path, $home . '/' ) && $path !== $home ) {
				return false;
			}
			$path = substr( $path, strlen( $home ) );
		}
		return 1 === preg_match( '#^/xapi(?:/|$)#', '/' . ltrim( $path, '/' ) );
	}

	/**
	 * HTTP date of an xAPI timestamp.
	 *
	 * @param string $stored Stored time, xAPI format.
	 *
	 * @return string
	 */
	private static function http_date( string $stored ): string {
		return Timestamp::parse( $stored, '2.0.0' )->format( 'D, d M Y H:i:s' ) . ' GMT';
	}
}
