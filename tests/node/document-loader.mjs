// JSON-LD document loader for the badge verification tests. It never touches the
// network: it serves the contexts bundled in resources/jsonld/ (the same files and
// URLs as LearningLedger\Badges\Canon\Context_Loader), the issuer's did.json and its
// verification methods, and any extra documents the caller passes by URL.
import { readFileSync } from 'node:fs';

const RESOURCES = new URL( '../../resources/jsonld/', import.meta.url );

export const BUNDLED = {
	'https://www.w3.org/ns/credentials/v2': 'credentials-v2.jsonld',
	'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json': 'ob-v3p0-context-3.0.3.json',
	'https://purl.imsglobal.org/spec/ob/v3p0/extensions.json': 'ob-v3p0-extensions.json',
};

const contexts = new Map(
	Object.entries( BUNDLED ).map( ( [ url, file ] ) => [ url, JSON.parse( readFileSync( new URL( file, RESOURCES ), 'utf8' ) ) ] )
);

/**
 * @param {object}                 [options]
 * @param {object|null}            [options.didDocument] The issuer's DID document (did.json).
 * @param {Record<string, object>} [options.documents]   Further documents by exact URL (status list credentials).
 * @return {(url: string) => Promise<{contextUrl: null, documentUrl: string, document: object}>}
 */
export function createDocumentLoader( { didDocument = null, documents = {} } = {} ) {
	const serve = ( url, document ) => ( { contextUrl: null, documentUrl: url, document: structuredClone( document ) } );
	return async function documentLoader( url ) {
		if ( contexts.has( url ) ) {
			return serve( url, contexts.get( url ) );
		}
		if ( didDocument && typeof url === 'string' ) {
			if ( url === didDocument.id ) {
				return serve( url, didDocument );
			}
			if ( url.startsWith( `${ didDocument.id }#` ) ) {
				const method = ( didDocument.verificationMethod ?? [] ).find( ( m ) => m.id === url );
				if ( method ) {
					return serve( url, method );
				}
			}
		}
		if ( typeof url === 'string' && Object.hasOwn( documents, url ) ) {
			return serve( url, documents[ url ] );
		}
		throw new Error( `Document is not bundled: ${ url }` );
	};
}
