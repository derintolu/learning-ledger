<?php
/**
 * Error raised while handling an xAPI request.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Http;

/**
 * An HTTP error that the router turns into an xAPI-shaped response.
 */
class Xapi_Error extends \RuntimeException {

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Extra headers, name => value.
	 *
	 * @var array<string,string>
	 */
	private array $headers;

	/**
	 * Constructor.
	 *
	 * @param integer              $status  HTTP status.
	 * @param string               $message Message for the client.
	 * @param array<string,string> $headers Extra headers.
	 */
	public function __construct( int $status, string $message, array $headers = array() ) {
		parent::__construct( $message, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- served as JSON by to_response().
		$this->status  = $status;
		$this->headers = $headers;
	}

	/**
	 * HTTP status.
	 *
	 * @return integer
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Extra headers.
	 *
	 * @return array<string,string>
	 */
	public function headers(): array {
		return $this->headers;
	}

	/**
	 * Response with body `{"error":true,"message":"..."}`.
	 *
	 * @return Xapi_Response
	 */
	public function to_response(): Xapi_Response {
		return Xapi_Response::json(
			$this->status,
			array(
				'error'   => true,
				'message' => $this->getMessage(),
			),
			$this->headers
		);
	}
}
