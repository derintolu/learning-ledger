<?php
/**
 * JSON-LD to RDFC-1.0 canonical N-Quads.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Canon;

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Vendor\Accredify\JsonLd\Exceptions\DataLossException;
use LearningLedger\Vendor\Accredify\JsonLd\Exceptions\JsonLdException;
use LearningLedger\Vendor\Accredify\JsonLd\JsonLdOptions;
use LearningLedger\Vendor\Accredify\JsonLd\JsonLdProcessor;
use LearningLedger\Vendor\Accredify\RdfCanonicalize\RDFC10;

/**
 * Produces the canonical bytes an eddsa-rdfc-2022 proof is computed over.
 */
class Canonicalizer {

	/**
	 * Canonicalise a JSON-LD document.
	 *
	 * Expansion runs in safe mode against the bundled contexts only, so any
	 * term that would be silently dropped fails closed.
	 *
	 * @param array $document JSON-LD document (decoded as associative arrays).
	 *
	 * @return string Canonical N-Quads.
	 *
	 * @throws Invalid_Input Code undefined_term when data would be dropped,
	 *                       context_not_bundled for an unknown context URL,
	 *                       invalid for any other JSON-LD error.
	 */
	public function canonicalize( array $document ): string {
		try {
			$nquads = ( new JsonLdProcessor( new Context_Loader() ) )
				->toRdf( $document, new JsonLdOptions( safe: true ) )
				->toNQuads();
		} catch ( DataLossException $e ) {
			throw new Invalid_Input( $e->getMessage(), 'undefined_term', (string) ( $e->details['term'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		} catch ( JsonLdException $e ) {
			for ( $previous = $e->getPrevious(); null !== $previous; $previous = $previous->getPrevious() ) {
				if ( $previous instanceof Invalid_Input ) {
					throw $previous;
				}
			}
			throw new Invalid_Input( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}

		return $this->canonicalize_nquads( $nquads );
	}

	/**
	 * Canonicalise an RDF dataset given as N-Quads (RDFC-1.0, SHA-256).
	 *
	 * @param string $nquads N-Quads.
	 *
	 * @return string Canonical N-Quads.
	 */
	public function canonicalize_nquads( string $nquads ): string {
		return implode( '', ( new RDFC10() )->canonicalize( $nquads ) );
	}
}
