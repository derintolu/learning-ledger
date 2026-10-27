<?php
/**
 * JSON-LD to RDFC-1.0 canonical N-Quads.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Canon;

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Vendor\Accredify\JsonLd\Contracts\DocumentLoader;
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
	 * RDFC-1.0 deep-iteration limit for input from outside the site.
	 *
	 * Admits the W3C rdf-canon "computable given defined limits" poison graphs
	 * (test044c-test046c need 42) and stops the clique graph test074c.
	 */
	public const UNTRUSTED_MAX_DEEP_ITERATIONS = 100;

	/**
	 * JSON-LD document loader.
	 *
	 * @var DocumentLoader
	 */
	private DocumentLoader $loader;

	/**
	 * RDFC-1.0 deep-iteration limit per blank node.
	 *
	 * @var int
	 */
	private int $max_deep_iterations;

	/**
	 * Constructor.
	 *
	 * @param DocumentLoader|null $loader              Context loader; defaults to the bundled Context_Loader.
	 * @param integer             $max_deep_iterations RDFC-1.0 deep-iteration limit per blank node. Leave
	 *                                                 unbounded for documents this site built; pass
	 *                                                 UNTRUSTED_MAX_DEEP_ITERATIONS for anything received.
	 */
	public function __construct( ?DocumentLoader $loader = null, int $max_deep_iterations = PHP_INT_MAX ) {
		$this->loader              = $loader ?? new Context_Loader();
		$this->max_deep_iterations = $max_deep_iterations;
	}

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
	 * @throws Invalid_Input Code canon_limit when the deep-iteration limit is exceeded,
	 *                       undefined_term when data would be dropped,
	 *                       context_not_bundled for an unknown context URL,
	 *                       invalid for any other JSON-LD error.
	 */
	public function canonicalize( array $document ): string {
		try {
			$nquads = ( new JsonLdProcessor( $this->loader ) )
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
	 *
	 * @throws Invalid_Input Code canon_limit when the deep-iteration limit is exceeded.
	 * @throws \Exception    Any other canonicalisation error, unchanged.
	 */
	public function canonicalize_nquads( string $nquads ): string {
		try {
			return implode( '', ( new RDFC10( array(), $this->max_deep_iterations ) )->canonicalize( $nquads ) );
		} catch ( \Exception $e ) {
			if ( str_starts_with( $e->getMessage(), 'Maximum deep iterations' ) ) {
				throw new Invalid_Input( 'RDF canonicalization exceeded its work limit.', 'canon_limit' );
			}
			throw $e;
		}
	}
}
