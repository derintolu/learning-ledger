// Differential verification of PHP-signed badges with Digital Bazaar's libraries.
//
// bin/dev-sign-corpus.php signs tests/fixtures/badges/corpus.json in a throwaway
// WordPress and writes credentials/, status-lists/, canonical/ and did.json to a
// directory (LLEDGER_CORPUS, default .cache/badges-corpus). Here every credential and
// status list must verify with @digitalbazaar/vc and the eddsa-rdfc-2022 cryptosuite,
// and jsonld's RDFC-1.0 output must equal the plugin Canonicalizer's byte for byte.
// Contexts come only from resources/jsonld/; nothing is fetched. CI only.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gunzipSync, gzipSync } from 'node:zlib';
import * as vc from '@digitalbazaar/vc';
import { DataIntegrityProof } from '@digitalbazaar/data-integrity';
import { cryptosuite as eddsaRdfc2022CryptoSuite } from '@digitalbazaar/eddsa-rdfc-2022-cryptosuite';
import * as Ed25519Multikey from '@digitalbazaar/ed25519-multikey';
import jsonld from 'jsonld';
import { createDocumentLoader } from './document-loader.mjs';

// Belt and braces: any network access is recorded and fails.
const fetched = [];
globalThis.fetch = async ( url ) => {
	fetched.push( String( url ) );
	throw new Error( `Network access attempted: ${ url }` );
};

const ROOT = fileURLToPath( new URL( '../../', import.meta.url ) );
const DIR = resolve( ROOT, process.env.LLEDGER_CORPUS || '.cache/badges-corpus' );
const CORPUS = JSON.parse( readFileSync( join( ROOT, 'tests/fixtures/badges/corpus.json' ), 'utf8' ) );

const readJsonDir = ( sub ) => {
	const dir = join( DIR, sub );
	if ( ! existsSync( dir ) ) {
		return new Map();
	}
	return new Map(
		readdirSync( dir ).filter( ( f ) => f.endsWith( '.json' ) ).sort()
			.map( ( f ) => [ basename( f, '.json' ), JSON.parse( readFileSync( join( dir, f ), 'utf8' ) ) ] )
	);
};
const readCanonical = ( sub, name, part ) => readFileSync( join( DIR, 'canonical', sub, `${ name }.${ part }.nq` ), 'utf8' );

const credentials = readJsonDir( 'credentials' );

if ( 0 === credentials.size ) {
	test( 'the corpus has credentials', () => {
		assert.fail( `no credentials in ${ join( DIR, 'credentials' ) }; run bin/dev-sign-corpus.php first` );
	} );
} else {
	const didDocument = JSON.parse( readFileSync( join( DIR, 'did.json' ), 'utf8' ) );
	const statusLists = readJsonDir( 'status-lists' );
	const listsByUrl = Object.fromEntries( [ ...statusLists.values() ].map( ( l ) => [ l.id, l ] ) );
	const loader = createDocumentLoader( { didDocument, documents: listsByUrl } );
	const revoked = new Set( CORPUS.credentials.filter( ( c ) => c.revoke ).map( ( c ) => c.name ) );

	const suite = () => new DataIntegrityProof( { cryptosuite: eddsaRdfc2022CryptoSuite } );
	const verifyList = ( list, documentLoader = loader ) => vc.verifyCredential( { credential: list, suite: suite(), documentLoader } );

	// Bitstring Status List: the list credential must verify, and the entry's bit (index 0 = most significant bit of byte 0) must be 0.
	const checkStatus = async ( { credential, documentLoader } ) => {
		const entry = credential.credentialStatus;
		if ( 'BitstringStatusListEntry' !== entry?.type || 'revocation' !== entry.statusPurpose ) {
			return { verified: false, error: new Error( 'Unexpected credentialStatus.' ) };
		}
		const { document: list } = await documentLoader( entry.statusListCredential );
		const listResult = await verifyList( list, documentLoader );
		if ( ! listResult.verified ) {
			return { verified: false, error: listResult.error ?? new Error( 'Status list does not verify.' ) };
		}
		const issuer = typeof credential.issuer === 'string' ? credential.issuer : credential.issuer.id;
		if ( list.issuer !== issuer || entry.statusPurpose !== list.credentialSubject.statusPurpose ) {
			return { verified: false, error: new Error( 'Status list issuer or purpose differs.' ) };
		}
		const bits = gunzipSync( Buffer.from( list.credentialSubject.encodedList.replace( /^u/, '' ), 'base64url' ) );
		const index = Number( entry.statusListIndex );
		const set = 1 === ( ( bits[ index >> 3 ] >> ( 7 - ( index % 8 ) ) ) & 1 );
		return set ? { verified: false, revoked: true, error: new Error( 'Credential is revoked.' ) } : { verified: true, revoked: false };
	};
	const verify = ( credential, documentLoader = loader ) => vc.verifyCredential( { credential, suite: suite(), documentLoader, checkStatus } );

	const canonize = ( doc ) => jsonld.canonize( doc, { algorithm: 'RDFC-1.0', format: 'application/n-quads', safe: true, documentLoader: loader } );
	const unsecured = ( secured ) => {
		const doc = structuredClone( secured );
		delete doc.proof;
		return doc;
	};
	const proofConfig = ( secured ) => {
		const { proofValue, ...options } = secured.proof;
		return { ...options, '@context': secured[ '@context' ] };
	};
	const assertVerified = ( result ) => assert.equal( result.verified, true, result.error?.stack ?? JSON.stringify( result.error ?? result.results ) );
	// Fails, and for the expected reason (jsonld-signatures wraps proof errors in VerificationError.errors).
	const assertFails = ( result, reason ) => {
		assert.equal( result.verified, false );
		const errors = [ result.error, result.statusResult?.error ].filter( Boolean );
		const messages = errors.flatMap( ( e ) => [ e, ...( e.errors ?? [] ) ] ).map( ( e ) => e.message );
		assert.ok( messages.some( ( m ) => reason.test( m ) ), `expected ${ reason }, got: ${ messages.join( ' | ' ) }` );
	};

	test( 'every corpus entry was signed', () => {
		assert.deepEqual( [ ...credentials.keys() ].sort(), CORPUS.credentials.map( ( c ) => c.name ).sort() );
		assert.ok( statusLists.size > 0, 'no status lists' );
		assert.ok( didDocument.verificationMethod.length > 0, 'did.json has no verification method' );
	} );

	for ( const [ name, credential ] of credentials ) {
		test( `credential ${ name } ${ revoked.has( name ) ? 'has a valid proof and is revoked' : 'verifies' }`, async () => {
			assert.equal( credential.proof.type, 'DataIntegrityProof' );
			assert.equal( credential.proof.cryptosuite, 'eddsa-rdfc-2022' );
			const result = await verify( credential );
			if ( revoked.has( name ) ) {
				assert.equal( result.results?.[ 0 ]?.verified, true, result.error?.stack );
				assert.equal( result.statusResult?.revoked, true );
				assert.equal( result.verified, false );
			} else {
				assertVerified( result );
				assert.equal( result.statusResult?.revoked, false );
			}
		} );

		test( `credential ${ name } canonicalises as the plugin does`, async () => {
			assert.equal( await canonize( unsecured( credential ) ), readCanonical( 'credentials', name, 'document' ) );
			assert.equal( await canonize( proofConfig( credential ) ), readCanonical( 'credentials', name, 'proof' ) );
		} );
	}

	for ( const [ id, list ] of statusLists ) {
		test( `status list ${ id } verifies and canonicalises as the plugin does`, async () => {
			assertVerified( await verifyList( list ) );
			assert.equal( list.credentialSubject.type, 'BitstringStatusList' );
			assert.equal( await canonize( unsecured( list ) ), readCanonical( 'status-lists', id, 'document' ) );
			assert.equal( await canonize( proofConfig( list ) ), readCanonical( 'status-lists', id, 'proof' ) );
		} );
	}

	// Negative cases: each must fail closed.
	const [ sampleName, sample ] = [ ...credentials ].find( ( [ n ] ) => ! revoked.has( n ) );

	test( `a changed claim does not verify (${ sampleName })`, async () => {
		const tampered = structuredClone( sample );
		tampered.credentialSubject.achievement.name += ' (forged)';
		assertFails( await verify( tampered ), /Invalid signature/ );
	} );

	test( 'a changed proofValue does not verify', async () => {
		const tampered = structuredClone( sample );
		const last = tampered.proof.proofValue.slice( -1 );
		tampered.proof.proofValue = tampered.proof.proofValue.slice( 0, -1 ) + ( '2' === last ? '3' : '2' );
		assertFails( await verify( tampered ), /Invalid signature/ );
	} );

	test( 'a changed proof created time does not verify', async () => {
		const tampered = structuredClone( sample );
		tampered.proof.created = '2000-01-01T00:00:00Z';
		assertFails( await verify( tampered ), /Invalid signature/ );
	} );

	test( 'a context that is not bundled is refused, never fetched', async () => {
		await assert.rejects( loader( 'https://example.org/unbundled/v1' ), /not bundled/ );
		const tampered = structuredClone( sample );
		tampered[ '@context' ] = [ ...tampered[ '@context' ], 'https://example.org/unbundled/v1' ];
		assertFails( await verify( tampered ), /https:\/\/example\.org\/unbundled\/v1/ );
		assert.deepEqual( fetched, [] );
	} );

	test( 'a different public key under the same verification method does not verify', async () => {
		const other = await Ed25519Multikey.generate();
		const forgedDid = structuredClone( didDocument );
		for ( const method of forgedDid.verificationMethod ) {
			method.publicKeyMultibase = other.publicKeyMultibase;
		}
		const forgedLoader = createDocumentLoader( { didDocument: forgedDid, documents: listsByUrl } );
		assertFails( await verify( sample, forgedLoader ), /Invalid signature/ );
	} );

	test( 'a key the DID document does not list as an assertion method does not verify', async () => {
		const forgedDid = { ...structuredClone( didDocument ), assertionMethod: [] };
		const forgedLoader = createDocumentLoader( { didDocument: forgedDid, documents: listsByUrl } );
		assertFails( await verify( sample, forgedLoader ), /not authorized by controller for proof purpose "assertionMethod"/ );
	} );

	test( 'a status list with a changed bitstring does not verify, and neither does a credential checked against it', async () => {
		const url = sample.credentialStatus.statusListCredential;
		const forgedList = structuredClone( listsByUrl[ url ] );
		const size = gunzipSync( Buffer.from( forgedList.credentialSubject.encodedList.slice( 1 ), 'base64url' ) ).length;
		forgedList.credentialSubject.encodedList = 'u' + gzipSync( Buffer.alloc( size, 0xff ) ).toString( 'base64url' );
		assertFails( await verifyList( forgedList ), /Invalid signature/ );
		const forgedLoader = createDocumentLoader( { didDocument, documents: { ...listsByUrl, [ url ]: forgedList } } );
		assertFails( await verify( sample, forgedLoader ), /Invalid signature/ );
	} );
}
