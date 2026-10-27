<?php
/**
 * eddsa-rdfc-2022 tests against the W3C vc-di-eddsa vectors.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Canon\Canonicalizer;
use LearningLedger\Badges\Signing\Eddsa_Rdfc_2022;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Multibase;
use LearningLedger\Tests\Fixture_Context_Loader;
use LearningLedger\Util\Invalid_Input;

class Eddsa_Rdfc_2022_Test extends \PHPUnit\Framework\TestCase {

	private const DIR     = LEARNING_LEDGER_DIR . 'tests/fixtures/w3c/vc-di-eddsa/';
	private const CANON   = LEARNING_LEDGER_DIR . 'tests/fixtures/w3c/rdf-canon/rdfc10/';
	private const VM      = 'did:key:z6MkrJVnaZkeFzdQyMZu1cgjg7k1pZZ6pvBQ7XJPt4swbTQ2#z6MkrJVnaZkeFzdQyMZu1cgjg7k1pZZ6pvBQ7XJPt4swbTQ2';
	private const CREATED = '2023-02-24T23:36:38Z';

	private static function text( string $name ): string {
		return file_get_contents( self::DIR . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
	}

	private static function json( string $name ): array {
		return json_decode( self::text( $name ), true, 512, JSON_THROW_ON_ERROR );
	}

	private static function keypair(): array {
		$secret = Multibase::base58btc_decode( self::json( 'key-pair.json' )['privateKeyMultibase'] );
		self::assertSame( "\x80\x26", substr( $secret, 0, 2 ) ); // ed25519-priv multicodec.
		$kp = Ed25519::from_seed( substr( $secret, 2 ) );
		self::assertSame( self::json( 'key-pair.json' )['publicKeyMultibase'], Multibase::ed25519_multikey( $kp['public'] ) );
		return $kp;
	}

	private static function created(): \DateTimeImmutable {
		return new \DateTimeImmutable( self::CREATED );
	}

	private static function resolver(): callable {
		$public = self::keypair()['public'];
		return static fn( string $vm ): ?string => self::VM === $vm ? $public : null;
	}

	private static function verify( array $secured ): bool {
		return Eddsa_Rdfc_2022::verify( $secured, self::resolver(), new Fixture_Context_Loader() );
	}

	public function test_w3c_vector_canonical_forms() {
		$unsigned = self::json( 'unsigned-credential.json' );
		$config   = Eddsa_Rdfc_2022::proof_config( $unsigned, self::VM, self::created() );
		$this->assertSame( self::json( 'proof-options.json' ), $config );

		$canonicalizer = new Canonicalizer( new Fixture_Context_Loader() );
		$this->assertSame( self::text( 'canonical-document.nq' ), $canonicalizer->canonicalize( $unsigned ) );
		$this->assertSame( self::text( 'canonical-proof.nq' ), $canonicalizer->canonicalize( $config ) );
	}

	public function test_proof_config_normalises_created_to_utc_seconds() {
		$config = Eddsa_Rdfc_2022::proof_config(
			self::json( 'unsigned-credential.json' ),
			self::VM,
			new \DateTimeImmutable( '2023-02-25T01:36:38.750+02:00' ),
			'authentication'
		);
		$this->assertSame( self::CREATED, $config['created'] );
		$this->assertSame( 'authentication', $config['proofPurpose'] );
	}

	public function test_w3c_vector_hashes() {
		$unsigned = self::json( 'unsigned-credential.json' );
		$hash     = Eddsa_Rdfc_2022::hash_data( $unsigned, self::json( 'proof-options.json' ), new Fixture_Context_Loader() );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertSame( self::text( 'proof-hash.txt' ), bin2hex( substr( $hash, 0, 32 ) ) );
		$this->assertSame( self::text( 'document-hash.txt' ), bin2hex( substr( $hash, 32 ) ) );
		$this->assertSame( self::text( 'combined-hash.txt' ), bin2hex( $hash ) );
	}

	public function test_w3c_vector_proof_value() {
		$signed = Eddsa_Rdfc_2022::sign(
			self::json( 'unsigned-credential.json' ),
			self::VM,
			self::keypair()['secret'],
			self::created(),
			'assertionMethod',
			new Fixture_Context_Loader()
		);

		$this->assertSame( self::text( 'proof-value.txt' ), $signed['proof']['proofValue'] );
		$this->assertSame( self::json( 'signed-credential.json' ), $signed );
	}

	public function test_sign_refuses_a_document_that_already_has_a_proof() {
		$this->expectException( Invalid_Input::class );
		Eddsa_Rdfc_2022::sign( self::json( 'signed-credential.json' ), self::VM, self::keypair()['secret'], self::created(), 'assertionMethod', new Fixture_Context_Loader() );
	}

	public function test_verify_accepts_vector_and_rejects_tamper() {
		$signed = self::json( 'signed-credential.json' );
		$this->assertTrue( self::verify( $signed ) );

		$tampered                                  = $signed;
		$tampered['credentialSubject']['alumniOf'] = 'The School of Counterfeits';
		$this->assertFalse( self::verify( $tampered ) );
	}

	public static function malformed(): array {
		$p = static function ( string $k, $v ): callable {
			return static function ( array $d ) use ( $k, $v ): array {
				$d['proof'][ $k ] = $v;
				return $d;
			};
		};
		$u = static function ( string $k ): callable {
			return static function ( array $d ) use ( $k ): array {
				unset( $d['proof'][ $k ] );
				return $d;
			};
		};
		return array(
			'no proof'                   => array( static fn( array $d ): array => array_diff_key( $d, array( 'proof' => 0 ) ) ),
			'proof is a list'            => array( static fn( array $d ): array => array( 'proof' => array( $d['proof'] ) ) + $d ),
			'other proof type'           => array( $p( 'type', 'Ed25519Signature2020' ) ),
			'other cryptosuite'          => array( $p( 'cryptosuite', 'eddsa-jcs-2022' ) ),
			'no proofValue'              => array( $u( 'proofValue' ) ),
			'proofValue not base58btc'   => array( $p( 'proofValue', 'u' . substr( self::text( 'proof-value.txt' ), 1 ) ) ),
			'proofValue bad character'   => array( $p( 'proofValue', 'z0OIl' ) ),
			'proofValue truncated'       => array( $p( 'proofValue', substr( self::text( 'proof-value.txt' ), 0, -2 ) ) ),
			'unknown key'                => array( $p( 'verificationMethod', 'did:example:other#key-1' ) ),
			'no verificationMethod'      => array( $u( 'verificationMethod' ) ),
			'no proofPurpose'            => array( $u( 'proofPurpose' ) ),
			'other proofPurpose'         => array( $p( 'proofPurpose', 'authentication' ) ),
			'created altered'            => array( $p( 'created', '2023-02-24T23:36:39Z' ) ),
			'created not a dateTime'     => array( $p( 'created', 'yesterday' ) ),
			'proof context not a prefix' => array( $p( '@context', array( 'https://www.w3.org/ns/credentials/examples/v2' ) ) ),
			'unbundled document context' => array(
				static function ( array $d ): array {
					$d['@context'][] = 'https://example.com/context.json';
					return $d;
				},
			),
		);
	}

	/**
	 * @dataProvider malformed
	 */
	public function test_verify_rejects_malformed_proofs( callable $mutate ) {
		$this->assertFalse( self::verify( $mutate( self::json( 'signed-credential.json' ) ) ) );
	}

	public function test_verify_accepts_proof_context_that_prefixes_the_document_context() {
		$signed                      = self::json( 'signed-credential.json' );
		$signed['proof']['@context'] = $signed['@context'];
		$this->assertTrue( self::verify( $signed ) );
	}

	public function test_verify_rejects_a_resolver_returning_a_malformed_key() {
		$signed = self::json( 'signed-credential.json' );
		$this->assertFalse( Eddsa_Rdfc_2022::verify( $signed, static fn(): ?string => 'short', new Fixture_Context_Loader() ) );
	}

	public function test_untrusted_limit_stops_the_w3c_poison_clique_graph() {
		$poison = file_get_contents( self::CANON . 'test074-in.nq' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		try {
			( new Canonicalizer( null, Canonicalizer::UNTRUSTED_MAX_DEEP_ITERATIONS ) )->canonicalize_nquads( $poison );
			$this->fail( 'Expected the deep-iteration limit to trip on test074c.' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'canon_limit', $e->error_code() );
		}
	}

	public function test_untrusted_limit_still_canonicalises_the_w3c_computable_poison_graphs() {
		$canonicalizer = new Canonicalizer( null, Canonicalizer::UNTRUSTED_MAX_DEEP_ITERATIONS );
		foreach ( array( '044', '045', '046' ) as $n ) {
			$this->assertSame(
				file_get_contents( self::CANON . "test{$n}-rdfc10.nq" ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
				$canonicalizer->canonicalize_nquads( file_get_contents( self::CANON . "test{$n}-in.nq" ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
			);
		}
	}

	public function test_verify_applies_the_limit_to_a_poison_credential() {
		$secured = self::poison_credential();
		$config  = $secured['proof'];
		unset( $config['proofValue'] );
		$config['@context'] = $secured['@context'];
		$document           = $secured;
		unset( $document['proof'] );

		try {
			Eddsa_Rdfc_2022::hash_data( $document, $config, null, Canonicalizer::UNTRUSTED_MAX_DEEP_ITERATIONS );
			$this->fail( 'Expected the deep-iteration limit to trip on the poison credential.' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'canon_limit', $e->error_code() );
		}

		$start = microtime( true );
		$this->assertFalse( Eddsa_Rdfc_2022::verify( $secured, self::resolver() ) );
		$this->assertLessThan( 5.0, microtime( true ) - $start );
	}

	/**
	 * A credential whose subject is a 10-node clique of blank nodes (the shape of test074c).
	 */
	private static function poison_credential(): array {
		$nodes = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$links = array();
			for ( $j = 0; $j < 10; $j++ ) {
				if ( $i !== $j ) {
					$links[] = array( '@id' => "_:e{$j}" );
				}
			}
			$nodes[] = array(
				'@id'                    => "_:e{$i}",
				'https://example.org/#p' => $links,
			);
		}
		return array(
			'@context'          => array( 'https://www.w3.org/ns/credentials/v2' ),
			'type'              => array( 'VerifiableCredential' ),
			'issuer'            => 'https://vc.example/issuers/5678',
			'validFrom'         => '2023-01-01T00:00:00Z',
			'credentialSubject' => array( 'https://example.org/#clique' => $nodes ),
			'proof'             => array(
				'type'               => 'DataIntegrityProof',
				'cryptosuite'        => 'eddsa-rdfc-2022',
				'created'            => self::CREATED,
				'verificationMethod' => self::VM,
				'proofPurpose'       => 'assertionMethod',
				'proofValue'         => self::text( 'proof-value.txt' ),
			),
		);
	}
}
