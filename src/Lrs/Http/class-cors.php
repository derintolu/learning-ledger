<?php
/**
 * CORS for the xAPI endpoint.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Http;

/**
 * Explicit origin allow-lists; origins match literally (including `null`), never by wildcard.
 */
final class Cors {

	/**
	 * Preflight answer: 204, with CORS headers only for an allowed origin.
	 *
	 * @param string   $origin  Origin header.
	 * @param string[] $allowed Allowed origins.
	 *
	 * @return Xapi_Response
	 */
	public static function preflight( string $origin, array $allowed ): Xapi_Response {
		if ( ! self::allows( $origin, $allowed ) ) {
			return new Xapi_Response( 204, '', '' );
		}
		return new Xapi_Response(
			204,
			'',
			'',
			array(
				'Access-Control-Allow-Origin'  => $origin,
				'Vary'                         => 'Origin',
				'Access-Control-Allow-Methods' => 'GET, PUT, POST, DELETE, OPTIONS',
				'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Experience-API-Version, If-Match, If-None-Match',
				'Access-Control-Max-Age'       => '600',
			)
		);
	}

	/**
	 * Add CORS headers to an actual response when the origin is allowed.
	 *
	 * @param Xapi_Response $res     Response.
	 * @param string|null   $origin  Origin header.
	 * @param string[]      $allowed Allowed origins.
	 *
	 * @return Xapi_Response
	 */
	public static function decorate( Xapi_Response $res, ?string $origin, array $allowed ): Xapi_Response {
		if ( null === $origin || ! self::allows( $origin, $allowed ) ) {
			return $res;
		}
		return new Xapi_Response(
			$res->status(),
			$res->body(),
			$res->content_type(),
			array_merge(
				$res->headers(),
				array(
					'Access-Control-Allow-Origin'   => $origin,
					'Vary'                          => 'Origin',
					'Access-Control-Expose-Headers' => 'ETag, Last-Modified, X-Experience-API-Version, X-Experience-API-Consistent-Through',
				)
			)
		);
	}

	/**
	 * Literal membership; `*` is never a wildcard.
	 *
	 * @param string   $origin  Origin.
	 * @param string[] $allowed Allowed origins.
	 *
	 * @return boolean
	 */
	private static function allows( string $origin, array $allowed ): bool {
		return '' !== $origin && '*' !== $origin && in_array( $origin, $allowed, true );
	}
}
