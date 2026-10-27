<?php
/**
 * Data Integrity eddsa-rdfc-2022 proofs (W3C vc-di-eddsa).
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Signing;

use LearningLedger\Badges\Canon\Canonicalizer;
use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Multibase;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Vendor\Accredify\JsonLd\Contracts\DocumentLoader;

/**
 * Signs and verifies credentials with a DataIntegrityProof using the
 * eddsa-rdfc-2022 cryptosuite: RDFC-1.0 canonicalization, SHA-256, Ed25519.
 */
final class Eddsa_Rdfc_2022 {

	const PROOF_TYPE  = 'DataIntegrityProof';
	const CRYPTOSUITE = 'eddsa-rdfc-2022';

	/**
	 * Proof purpose a verified credential proof must carry.
	 */
	const VERIFY_PURPOSE = 'assertionMethod';

	/**
	 * Longest base58btc multibase a 64-byte signature encodes to (`z` + 88).
	 * Checked before decoding, since the decoder is quadratic in its input.
	 */
	const MAX_PROOF_VALUE_LENGTH = 89;

	/**
	 * Proof configuration (the proof options plus the document's `@context`).
	 *
	 * @param array              $doc                 Document being secured.
	 * @param string             $verification_method Verification method URL.
	 * @param \DateTimeImmutable $created             Creation time (written in UTC, whole seconds).
	 * @param string             $purpose             Proof purpose.
	 *
	 * @return array
	 */
	public static function proof_config( array $doc, string $verification_method, \DateTimeImmutable $created, string $purpose = 'assertionMethod' ): array {
		$config = array(
			'type'               => self::PROOF_TYPE,
			'cryptosuite'        => self::CRYPTOSUITE,
			'created'            => $created->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'verificationMethod' => $verification_method,
			'proofPurpose'       => $purpose,
		);
		if ( array_key_exists( '@context', $doc ) ) {
			$config['@context'] = $doc['@context'];
		}
		return $config;
	}

	/**
	 * Hash data: SHA-256(canon(proof config)) followed by SHA-256(canon(document)).
	 *
	 * @param array               $doc_without_proof   Document without `proof`.
	 * @param array               $proof_config        Proof configuration.
	 * @param DocumentLoader|null $loader              Context loader; defaults to the bundled contexts.
	 * @param integer             $max_deep_iterations RDFC-1.0 deep-iteration limit.
	 *
	 * @return string 64 bytes.
	 *
	 * @throws Invalid_Input When either document cannot be canonicalized.
	 */
	public static function hash_data( array $doc_without_proof, array $proof_config, ?DocumentLoader $loader = null, int $max_deep_iterations = PHP_INT_MAX ): string {
		$canonicalizer = new Canonicalizer( $loader, $max_deep_iterations );
		return hash( 'sha256', $canonicalizer->canonicalize( $proof_config ), true )
			. hash( 'sha256', $canonicalizer->canonicalize( $doc_without_proof ), true );
	}

	/**
	 * Secure a document with an eddsa-rdfc-2022 proof.
	 *
	 * @param array               $doc                 Document without a proof.
	 * @param string              $verification_method Verification method URL.
	 * @param string              $secret              Raw 64-byte Ed25519 secret key.
	 * @param \DateTimeImmutable  $created             Creation time.
	 * @param string              $purpose             Proof purpose.
	 * @param DocumentLoader|null $loader              Context loader; defaults to the bundled contexts.
	 *
	 * @return array The document with `proof` (carrying `proofValue`, base58btc multibase).
	 *
	 * @throws Invalid_Input When the document already has a proof, the verification method is
	 *                       empty, or the document cannot be canonicalized.
	 */
	public static function sign( array $doc, string $verification_method, string $secret, \DateTimeImmutable $created, string $purpose = 'assertionMethod', ?DocumentLoader $loader = null ): array {
		if ( array_key_exists( 'proof', $doc ) ) {
			throw new Invalid_Input( 'Document already has a proof.', 'invalid', 'proof' );
		}
		if ( '' === $verification_method ) {
			throw new Invalid_Input( 'Verification method is empty.', 'invalid', 'proof.verificationMethod' );
		}
		$config = self::proof_config( $doc, $verification_method, $created, $purpose );
		$proof  = $config;
		unset( $proof['@context'] );
		$proof['proofValue'] = Multibase::base58btc_encode( Ed25519::sign( self::hash_data( $doc, $config, $loader ), $secret ) );

		$doc['proof'] = $proof;
		return $doc;
	}

	/**
	 * Verify a document's eddsa-rdfc-2022 proof. Fails closed: anything
	 * malformed, unresolvable or not canonicalizable within the untrusted
	 * work limit yields false.
	 *
	 * @param array               $secured        Secured document.
	 * @param callable            $public_key_for Resolver: (string $verification_method): ?string raw 32-byte key.
	 * @param DocumentLoader|null $loader         Context loader; defaults to the bundled contexts.
	 *
	 * @return boolean
	 */
	public static function verify( array $secured, callable $public_key_for, ?DocumentLoader $loader = null ): bool {
		$proof = $secured['proof'] ?? null;
		if ( ! is_array( $proof ) || array_is_list( $proof ) ) {
			return false;
		}
		if ( self::PROOF_TYPE !== ( $proof['type'] ?? null )
			|| self::CRYPTOSUITE !== ( $proof['cryptosuite'] ?? null )
			|| self::VERIFY_PURPOSE !== ( $proof['proofPurpose'] ?? null )
			|| ! is_string( $proof['verificationMethod'] ?? null ) || '' === $proof['verificationMethod']
			|| ! is_string( $proof['proofValue'] ?? null ) || strlen( $proof['proofValue'] ) > self::MAX_PROOF_VALUE_LENGTH
			|| ( array_key_exists( 'created', $proof ) && ! self::is_datetime( $proof['created'] ) ) ) {
			return false;
		}

		$document = $secured;
		unset( $document['proof'] );
		$options = $proof;
		unset( $options['proofValue'] );

		if ( array_key_exists( '@context', $options ) ) {
			$proof_context = (array) $options['@context'];
			$doc_context   = (array) ( $document['@context'] ?? array() );
			if ( array_slice( $doc_context, 0, count( $proof_context ) ) !== $proof_context ) {
				return false;
			}
			$document['@context'] = $options['@context'];
		}
		if ( array_key_exists( '@context', $document ) ) {
			$options['@context'] = $document['@context'];
		}

		try {
			$signature = Multibase::base58btc_decode( $proof['proofValue'] );
		} catch ( Invalid_Input $e ) {
			return false;
		}
		if ( 64 !== strlen( $signature ) ) {
			return false;
		}

		try {
			$public_key = $public_key_for( $proof['verificationMethod'] );
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( ! is_string( $public_key ) || 32 !== strlen( $public_key ) ) {
			return false;
		}

		try {
			$hash = self::hash_data( $document, $options, $loader, Canonicalizer::UNTRUSTED_MAX_DEEP_ITERATIONS );
		} catch ( \Exception $e ) {
			return false;
		}

		return Ed25519::verify( $signature, $hash, $public_key );
	}

	/**
	 * Whether a value is an XML Schema dateTime with a time zone and a real
	 * calendar date (hours 00-23, no leap second, offsets up to 14:00).
	 *
	 * @param mixed $value Value.
	 *
	 * @return boolean
	 */
	private static function is_datetime( mixed $value ): bool {
		return is_string( $value )
			&& 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):[0-5]\d:[0-5]\d(\.\d+)?(Z|[+-]((0\d|1[0-3]):[0-5]\d|14:00))$/D', $value, $m )
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}
}
