<?php
/**
 * JSON-LD document loader serving only the bundled, pinned contexts.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Canon;

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Vendor\Accredify\JsonLd\Contracts\DocumentLoader;
use LearningLedger\Vendor\Accredify\JsonLd\Documents\RemoteDocument;
use LearningLedger\Vendor\Accredify\JsonLd\Exceptions\DocumentLoaderException;

/**
 * Resolves the three contexts a badge credential may use from resources/jsonld/.
 * Never touches the network: any other URL is refused.
 */
class Context_Loader implements DocumentLoader {

	/**
	 * Bundled context URL => file under resources/jsonld/ (exact URL match only).
	 */
	private const BUNDLED = array(
		'https://www.w3.org/ns/credentials/v2' => 'credentials-v2.jsonld',
		'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json' => 'ob-v3p0-context-3.0.3.json',
		'https://purl.imsglobal.org/spec/ob/v3p0/extensions.json' => 'ob-v3p0-extensions.json',
	);

	/**
	 * Parsed documents, keyed by URL.
	 *
	 * @var array<string, array>
	 */
	private static array $cache = array();

	/**
	 * Load a bundled context document.
	 *
	 * @param string $url Context URL.
	 *
	 * @return array The parsed document.
	 *
	 * @throws Invalid_Input Code context_not_bundled for any URL not bundled.
	 */
	public function load( string $url ): array {
		if ( ! isset( self::BUNDLED[ $url ] ) ) {
			throw new Invalid_Input( 'JSON-LD context is not bundled: ' . $url, 'context_not_bundled', $url ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
		if ( ! isset( self::$cache[ $url ] ) ) {
			$json = file_get_contents( LEARNING_LEDGER_DIR . 'resources/jsonld/' . self::BUNDLED[ $url ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file.
			if ( false === $json ) {
				throw new Invalid_Input( 'Bundled JSON-LD context is unreadable: ' . $url, 'context_not_bundled', $url ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
			}
			self::$cache[ $url ] = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		}
		return self::$cache[ $url ];
	}

	/**
	 * Document loader callback for php-json-ld.
	 *
	 * @param string $url Context URL.
	 *
	 * @return RemoteDocument
	 *
	 * @throws DocumentLoaderException Wrapping the Invalid_Input from load().
	 */
	public function loadDocument( string $url ): RemoteDocument { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Library interface.
		try {
			return new RemoteDocument( $this->load( $url ), $url );
		} catch ( Invalid_Input $e ) {
			throw new DocumentLoaderException( $e->getMessage(), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}
	}
}
