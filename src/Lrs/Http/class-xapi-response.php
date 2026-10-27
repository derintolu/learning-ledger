<?php
/**
 * Response value object for the xAPI endpoint.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Http;

use LearningLedger\Util\Json;

/**
 * Status, raw body, content type and headers, served byte for byte by the router.
 */
final class Xapi_Response {

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Raw body.
	 *
	 * @var string
	 */
	private string $body;

	/**
	 * Content type.
	 *
	 * @var string
	 */
	private string $content_type;

	/**
	 * Extra headers, name => value.
	 *
	 * @var array<string,string>
	 */
	private array $headers;

	/**
	 * Constructor.
	 *
	 * @param integer              $status       HTTP status.
	 * @param string               $body         Raw body.
	 * @param string               $content_type Content type.
	 * @param array<string,string> $headers      Extra headers.
	 */
	public function __construct( int $status, string $body = '', string $content_type = 'application/json', array $headers = array() ) {
		$this->status       = $status;
		$this->body         = $body;
		$this->content_type = $content_type;
		$this->headers      = $headers;
	}

	/**
	 * JSON response, encoded without escaping slashes or Unicode.
	 *
	 * @param integer              $status  HTTP status.
	 * @param mixed                $data    Data to encode.
	 * @param array<string,string> $headers Extra headers.
	 *
	 * @return self
	 */
	public static function json( int $status, mixed $data, array $headers = array() ): self {
		return new self( $status, Json::encode_exact( $data ), 'application/json', $headers );
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
	 * Raw body.
	 *
	 * @return string
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Content type.
	 *
	 * @return string
	 */
	public function content_type(): string {
		return $this->content_type;
	}

	/**
	 * Extra headers.
	 *
	 * @return array<string,string>
	 */
	public function headers(): array {
		return $this->headers;
	}
}
