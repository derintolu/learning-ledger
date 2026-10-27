<?php
/**
 * Streams a stored AU file, with single-range support.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Origin;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streams a stored file in chunks; WP_Filesystem cannot seek.

/**
 * Turns a stored file, an optional `Range` header and response headers into a
 * plan (status, headers, byte span), then sends it.
 */
final class File_Responder {

	/**
	 * Bytes read and echoed at a time.
	 */
	private const CHUNK = 65536;

	/**
	 * Send a stored file: status line, headers and the requested bytes (no body for HEAD).
	 *
	 * @param array       $file    From Au_Store::lookup (`file`, `size`).
	 * @param string|null $range   Raw `Range` header, if any.
	 * @param array       $headers Headers to send, name => value.
	 *
	 * @return void
	 */
	public function send( array $file, ?string $range, array $headers ): void {
		$this->emit( $this->plan( $file, $range, $headers ) );
	}

	/**
	 * What send() would do, without sending anything.
	 *
	 * Only a single `bytes=` range is honoured. A multi-range, non-bytes or
	 * malformed header is ignored (full 200), as RFC 9110 allows; a
	 * well-formed range past the end of the file is 416.
	 *
	 * @param array       $file    From Au_Store::lookup (`file`, `size`).
	 * @param string|null $range   Raw `Range` header, if any.
	 * @param array       $headers Headers to send, name => value.
	 *
	 * @return array{status:int,headers:array<string,string>,file:string|null,offset:int,length:int}
	 */
	public function plan( array $file, ?string $range, array $headers ): array {
		$path = (string) ( $file['file'] ?? '' );
		$size = is_file( $path ) ? (int) filesize( $path ) : (int) ( $file['size'] ?? 0 );

		$headers['Accept-Ranges'] = 'bytes';
		$span                     = self::span( $range, $size );
		if ( false === $span ) {
			$headers['Content-Range']  = 'bytes */' . $size;
			$headers['Content-Length'] = '0';
			return self::result( 416, $headers, null, 0, 0 );
		}
		if ( null === $span ) {
			$headers['Content-Length'] = (string) $size;
			return self::result( 200, $headers, $path, 0, $size );
		}
		list( $start, $end )       = $span;
		$headers['Content-Range']  = 'bytes ' . $start . '-' . $end . '/' . $size;
		$headers['Content-Length'] = (string) ( $end - $start + 1 );
		return self::result( 206, $headers, $path, $start, $end - $start + 1 );
	}

	/**
	 * Send a plan from plan() or a 404 plan. Any `Set-Cookie` already queued is removed,
	 * and the Content-Type is sent as given (no default charset appended).
	 *
	 * @param array{status:int,headers:array<string,string>,file:string|null,offset:int,length:int} $plan Plan.
	 *
	 * @return void
	 */
	public function emit( array $plan ): void {
		if ( ! headers_sent() ) {
			header_remove( 'Set-Cookie' );
			// PHP would append its default charset to text/* types, overriding an AU's own <meta charset>.
			ini_set( 'default_charset', '' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			http_response_code( $plan['status'] );
			foreach ( $plan['headers'] as $name => $value ) {
				header( $name . ': ' . str_replace( array( "\r", "\n" ), '', (string) $value ) );
			}
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'HEAD' === $method || null === $plan['file'] || $plan['length'] <= 0 ) {
			return;
		}
		$handle = fopen( $plan['file'], 'rb' );
		if ( false === $handle ) {
			return;
		}
		if ( $plan['offset'] > 0 ) {
			fseek( $handle, $plan['offset'] );
		}
		$left = $plan['length'];
		while ( $left > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, min( self::CHUNK, $left ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$left -= strlen( $chunk );
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw file bytes, served with nosniff and the manifest Content-Type.
		}
		fclose( $handle );
	}

	/**
	 * A 404 plan with no body.
	 *
	 * @return array{status:int,headers:array<string,string>,file:null,offset:int,length:int}
	 */
	public static function not_found(): array {
		return self::result(
			404,
			array(
				'X-Content-Type-Options' => 'nosniff',
				'Cache-Control'          => 'no-store',
				'Content-Type'           => 'text/plain; charset=utf-8',
				'Content-Length'         => '0',
			),
			null,
			0,
			0
		);
	}

	/**
	 * The byte span a `Range` header asks for.
	 *
	 * @param string|null $range Raw header.
	 * @param integer     $size  File size.
	 *
	 * @return array{0:int,1:int}|null|false Inclusive span; null to ignore the header; false when unsatisfiable.
	 */
	private static function span( ?string $range, int $size ) {
		if ( null === $range || 1 !== preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $range ), $m ) || ( '' === $m[1] && '' === $m[2] ) ) {
			return null;
		}
		if ( '' === $m[1] ) {
			$suffix = (int) $m[2];
			if ( 0 === $suffix || 0 === $size ) {
				return false;
			}
			return array( max( 0, $size - $suffix ), $size - 1 );
		}
		$start = (int) $m[1];
		if ( '' !== $m[2] && (int) $m[2] < $start ) {
			return null;
		}
		if ( $start >= $size ) {
			return false;
		}
		$end = '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );
		return array( $start, $end );
	}

	/**
	 * Plan array.
	 *
	 * @param integer               $status  HTTP status.
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $file    File to read, or null for no body.
	 * @param integer               $offset  First byte.
	 * @param integer               $length  Byte count.
	 *
	 * @return array{status:int,headers:array<string,string>,file:string|null,offset:int,length:int}
	 */
	private static function result( int $status, array $headers, ?string $file, int $offset, int $length ): array {
		return array(
			'status'  => $status,
			'headers' => $headers,
			'file'    => $file,
			'offset'  => $offset,
			'length'  => $length,
		);
	}
}
