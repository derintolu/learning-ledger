<?php
/**
 * Response from the LRS.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Pairing;

/**
 * Status, headers (lowercase names) and raw body. Status 0 means the request
 * never got a response (transport failure).
 */
final class Lrs_Response {

	/**
	 * HTTP status, or 0 on transport failure.
	 *
	 * @var integer
	 */
	public readonly int $status;

	/**
	 * Headers by lowercase name.
	 *
	 * @var array<string,string|string[]>
	 */
	public readonly array $headers;

	/**
	 * Raw body.
	 *
	 * @var string
	 */
	public readonly string $body;

	/**
	 * Constructor.
	 *
	 * @param integer $status  HTTP status.
	 * @param array   $headers Headers.
	 * @param string  $body    Raw body.
	 */
	public function __construct( int $status, array $headers, string $body ) {
		$this->status  = $status;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
		$this->body    = $body;
	}

	/**
	 * Decoded JSON body, or null when it is empty or not JSON.
	 *
	 * @return mixed
	 */
	public function json(): mixed {
		if ( '' === $this->body ) {
			return null;
		}
		$decoded = json_decode( $this->body, true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
	}
}
