<?php
/**
 * Version negotiation for xAPI.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Xapi;

use LearningLedger\Util\Invalid_Input;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never echoed; HTTP layers JSON-encode them.

/**
 * Maps an X-Experience-API-Version header to the version this LRS answers with.
 */
final class Version {

	/**
	 * Versions this LRS implements.
	 *
	 * @var string[]
	 */
	public const SUPPORTED = array( '1.0.3', '2.0.0' );

	/**
	 * Negotiate the version for a request.
	 *
	 * @param string|null $header X-Experience-API-Version header value.
	 *
	 * @return string `1.0.3` or `2.0.0`.
	 *
	 * @throws Invalid_Input Code `version` when missing or unsupported.
	 */
	public static function negotiate( ?string $header ): string {
		$value = null === $header ? '' : trim( $header );
		if ( 1 === preg_match( '/^1\.0(\.[0-3])?$/D', $value ) ) {
			return '1.0.3';
		}
		if ( 1 === preg_match( '/^2\.0(\.[0-9]+)?$/D', $value ) ) {
			return '2.0.0';
		}
		throw new Invalid_Input( 'Unsupported or missing X-Experience-API-Version header.', 'version', 'X-Experience-API-Version' );
	}
}
