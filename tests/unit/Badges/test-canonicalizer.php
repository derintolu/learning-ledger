<?php
/**
 * Canonicalizer and Context_Loader tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Canon\Canonicalizer;
use LearningLedger\Badges\Canon\Context_Loader;
use LearningLedger\Util\Invalid_Input;

class Canonicalizer_Test extends \PHPUnit\Framework\TestCase {

	private const VC_V2  = 'https://www.w3.org/ns/credentials/v2';
	private const OB_V3  = 'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json';
	private const OB_EXT = 'https://purl.imsglobal.org/spec/ob/v3p0/extensions.json';

	public function test_bundled_contexts_load_and_match_pinned_hashes() {
		$files  = array(
			self::VC_V2  => '59955ced6697d61e03f2b2556febe5308ab16842846f5b586d7f1f7adec92734',
			self::OB_V3  => '3d34f4d4ef1bce691106e63798beb5e7b862ba841423f5ee1e53ab7ddf3bca84',
			self::OB_EXT => '87c06c81bc2317663bf288d740d94fddd2818c4342b186270ff6371dfaa63f93',
		);
		$names  = array(
			self::VC_V2  => 'credentials-v2.jsonld',
			self::OB_V3  => 'ob-v3p0-context-3.0.3.json',
			self::OB_EXT => 'ob-v3p0-extensions.json',
		);
		$loader = new Context_Loader();
		foreach ( $files as $url => $sha256 ) {
			$this->assertSame( $sha256, hash_file( 'sha256', LEARNING_LEDGER_DIR . 'resources/jsonld/' . $names[ $url ] ) );
			$this->assertArrayHasKey( '@context', $loader->load( $url ) );
		}
	}

	public function test_unbundled_context_throws() {
		foreach ( array( 'https://example.com/context.json', 'http://www.w3.org/ns/credentials/v2', self::VC_V2 . '#' ) as $url ) {
			try {
				( new Context_Loader() )->load( $url );
				$this->fail( 'Expected Invalid_Input for ' . $url );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'context_not_bundled', $e->error_code() );
			}
		}

		$this->expectException( Invalid_Input::class );
		try {
			( new Canonicalizer() )->canonicalize(
				array(
					'@context' => array( self::VC_V2, 'https://example.com/context.json' ),
					'type'     => 'VerifiableCredential',
				)
			);
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'context_not_bundled', $e->error_code() );
			throw $e;
		}
	}

	public function test_undefined_term_throws() {
		$document = json_decode( '{"@context":["https://www.w3.org/ns/credentials/v2"],"type":"VerifiableCredential","fooBar":1}', true );

		$this->expectException( Invalid_Input::class );
		try {
			( new Canonicalizer() )->canonicalize( $document );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'undefined_term', $e->error_code() );
			throw $e;
		}
	}

	public function test_simple_credential_is_deterministic() {
		$credential = self::credential();
		$shuffled   = self::reverse_keys( $credential );
		$this->assertNotSame( array_keys( $credential ), array_keys( $shuffled ) );

		$canonicalizer = new Canonicalizer();
		$first         = $canonicalizer->canonicalize( $credential );
		$second        = $canonicalizer->canonicalize( $shuffled );

		$this->assertSame( $first, $second );
		$this->assertSame( $first, ( new Canonicalizer() )->canonicalize( $credential ) );
		$this->assertStringContainsString( '<urn:uuid:2e2b1a8e-55a4-4c9f-9a5b-7d3f0c1e6a11> <https://www.w3.org/2018/credentials#validFrom> "2026-10-27T00:00:00Z"^^<http://www.w3.org/2001/XMLSchema#dateTime> .', $first );
		$this->assertStringContainsString( '_:c14n0 ', $first );
		$this->assertStringEndsWith( " .\n", $first );
	}

	private static function credential(): array {
		return array(
			'@context'          => array( self::VC_V2, self::OB_V3 ),
			'id'                => 'urn:uuid:2e2b1a8e-55a4-4c9f-9a5b-7d3f0c1e6a11',
			'type'              => array( 'VerifiableCredential', 'OpenBadgeCredential' ),
			'issuer'            => array(
				'id'   => 'https://example.org/issuers/1',
				'type' => array( 'Profile' ),
				'name' => 'Example Academy',
			),
			'validFrom'         => '2026-10-27T00:00:00Z',
			'name'              => 'Intro to Ledgers',
			'credentialSubject' => array(
				'type'        => array( 'AchievementSubject' ),
				'achievement' => array(
					'id'          => 'https://example.org/achievements/1',
					'type'        => array( 'Achievement' ),
					'name'        => 'Intro to Ledgers',
					'description' => 'Completed the introductory course.',
					'criteria'    => array( 'narrative' => 'Pass every lesson.' ),
				),
			),
		);
	}

	private static function reverse_keys( array $value ): array {
		if ( array_is_list( $value ) ) {
			return array_map( fn( $v ) => is_array( $v ) ? self::reverse_keys( $v ) : $v, $value );
		}
		$out = array();
		foreach ( array_reverse( $value, true ) as $k => $v ) {
			$out[ $k ] = is_array( $v ) ? self::reverse_keys( $v ) : $v;
		}
		return $out;
	}
}
