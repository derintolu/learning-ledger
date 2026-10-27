<?php
/**
 * Document loader for tests: the bundled contexts plus test-only fixtures.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Tests;

use LearningLedger\Badges\Canon\Context_Loader;

/**
 * Serves https://www.w3.org/ns/credentials/examples/v2 from tests/fixtures
 * (needed by the W3C vc-di-eddsa vectors) and defers everything else to
 * Context_Loader, so production never resolves that context.
 */
class Fixture_Context_Loader extends Context_Loader {

	/**
	 * Fixture context URL => file under tests/fixtures/.
	 */
	private const FIXTURES = array(
		'https://www.w3.org/ns/credentials/examples/v2' => 'w3c/vc-di-eddsa/credentials-examples-v2.jsonld',
	);

	/**
	 * Load a fixture or bundled context document.
	 *
	 * @param string $url Context URL.
	 *
	 * @return array
	 */
	public function load( string $url ): array {
		if ( isset( self::FIXTURES[ $url ] ) ) {
			$json = file_get_contents( LEARNING_LEDGER_DIR . 'tests/fixtures/' . self::FIXTURES[ $url ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
			return json_decode( (string) $json, true, 512, JSON_THROW_ON_ERROR );
		}
		return parent::load( $url );
	}
}
