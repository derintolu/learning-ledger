<?php
/**
 * MIME types for stored AU files.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Player\Store;

/**
 * Allowlist from file extension to MIME type; anything else is a download.
 */
final class Mime_Map {

	/**
	 * Type served for any extension not in the allowlist.
	 */
	public const FALLBACK = 'application/octet-stream';

	/**
	 * Allowed extensions (lowercase) and their types.
	 */
	private const TYPES = array(
		'html'  => 'text/html',
		'htm'   => 'text/html',
		'js'    => 'text/javascript',
		'mjs'   => 'text/javascript',
		'css'   => 'text/css',
		'json'  => 'application/json',
		'xml'   => 'application/xml',
		'txt'   => 'text/plain',
		'svg'   => 'image/svg+xml',
		'png'   => 'image/png',
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'gif'   => 'image/gif',
		'webp'  => 'image/webp',
		'ico'   => 'image/x-icon',
		'woff'  => 'font/woff',
		'woff2' => 'font/woff2',
		'ttf'   => 'font/ttf',
		'otf'   => 'font/otf',
		'mp3'   => 'audio/mpeg',
		'mp4'   => 'video/mp4',
		'webm'  => 'video/webm',
		'ogg'   => 'audio/ogg',
		'wav'   => 'audio/wav',
		'vtt'   => 'text/vtt',
		'pdf'   => 'application/pdf',
		'wasm'  => 'application/wasm',
	);

	/**
	 * MIME type for a package path, from its last extension.
	 *
	 * @param string $path Package path.
	 *
	 * @return string
	 */
	public static function for_path( string $path ): string {
		$base = basename( $path );
		$dot  = strrpos( $base, '.' );
		if ( false === $dot || 0 === $dot ) {
			return self::FALLBACK;
		}
		return self::TYPES[ strtolower( substr( $base, $dot + 1 ) ) ] ?? self::FALLBACK;
	}
}
