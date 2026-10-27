<?php
/**
 * W3C rdf-canon RDFC-1.0 evaluation tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Canon\Canonicalizer;
use LearningLedger\Vendor\Accredify\RdfCanonicalize\RDFC10;

class Rdf_Canon_Suite_Test extends \PHPUnit\Framework\TestCase {

	private const DIR = LEARNING_LEDGER_DIR . 'tests/fixtures/w3c/rdf-canon/';

	public static function eval_tests(): array {
		$manifest = json_decode( file_get_contents( self::DIR . 'manifest.jsonld' ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$cases    = array();
		foreach ( $manifest['entries'] as $entry ) {
			if ( 'rdfc:RDFC10EvalTest' !== $entry['type'] ) {
				continue;
			}
			$cases[ $entry['id'] ] = array( $entry['action'], $entry['result'], $entry['hashAlgorithm'] ?? 'SHA256' );
		}
		return $cases;
	}

	public function test_manifest_has_all_eval_tests() {
		$this->assertCount( 64, self::eval_tests() );
	}

	/**
	 * @dataProvider eval_tests
	 */
	public function test_eval( string $action, string $result, string $hash_algorithm ) {
		$input    = file_get_contents( self::DIR . $action ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$expected = file_get_contents( self::DIR . $result ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.

		if ( 'SHA256' === $hash_algorithm ) {
			$actual = ( new Canonicalizer() )->canonicalize_nquads( $input );
		} else {
			// Canonicalizer is SHA-256 only (eddsa-rdfc-2022); exercise the library's other profile directly.
			$actual = implode( '', ( new RDFC10( array(), PHP_INT_MAX, strtolower( $hash_algorithm ) ) )->canonicalize( $input ) );
		}

		$this->assertSame( $expected, $actual );
	}
}
