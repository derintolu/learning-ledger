<?php
/**
 * Signs tests/fixtures/badges/corpus.json with the plugin, for the differential
 * verification in tests/node/verify-badges.test.mjs.
 *
 * Runs only in a throwaway test site with LLEDGER_TESTING true: it generates a badge
 * key when none is active, allocates status list entries and revokes some of them.
 *
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/learning-ledger/bin/dev-sign-corpus.php wp-content/plugins/learning-ledger/.cache/badges-corpus
 *
 * Writes into <out dir>: credentials/<name>.json, status-lists/<list id>.json, did.json, and
 * canonical/{credentials,status-lists}/<name>.{document,proof}.nq, the Canonicalizer's RDFC-1.0
 * output for the document without its proof and for the proof configuration.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Reads the fixture and writes local test output.

use LearningLedger\Badges\Canon\Canonicalizer;
use LearningLedger\Badges\Claim\Credential_Builder;
use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Badges\Issuer\Key_Store;
use LearningLedger\Badges\Signing\Eddsa_Rdfc_2022;
use LearningLedger\Badges\Status\Status_List_Repository;
use LearningLedger\Db\Installer;
use LearningLedger\Util\Clock;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

/**
 * Sign the corpus into a directory.
 *
 * @param array $args Positional arguments from `wp eval-file`: the output directory.
 *
 * @return void
 */
function lledger_dev_sign_corpus( array $args ): void {
	if ( ! defined( 'LLEDGER_TESTING' ) || ! LLEDGER_TESTING ) {
		WP_CLI::error( 'dev-sign-corpus runs only where LLEDGER_TESTING is true.' );
	}
	$out = rtrim( (string) ( $args[0] ?? '' ), '/' );
	if ( '' === $out ) {
		WP_CLI::error( 'Usage: wp eval-file bin/dev-sign-corpus.php <out dir>' );
	}

	$corpus = json_decode( (string) file_get_contents( LEARNING_LEDGER_DIR . 'tests/fixtures/badges/corpus.json' ), true, 512, JSON_THROW_ON_ERROR );

	foreach ( array( 'credentials', 'status-lists', 'canonical/credentials', 'canonical/status-lists' ) as $sub ) {
		if ( ! wp_mkdir_p( $out . '/' . $sub ) ) {
			WP_CLI::error( 'Cannot create ' . $out . '/' . $sub );
		}
		foreach ( array_merge( (array) glob( $out . '/' . $sub . '/*.json' ), (array) glob( $out . '/' . $sub . '/*.nq' ) ) as $stale ) {
			wp_delete_file( $stale );
		}
	}

	Installer::maybe_upgrade();
	$key    = Key_Store::active() ?? Key_Store::generate();
	$vm     = Did_Document::verification_method_for( $key );
	$did    = Did_Document::did();
	$status = new Status_List_Repository();
	$lists  = array();
	$revoke = array();

	foreach ( $corpus['credentials'] as $entry ) {
		$name = (string) $entry['name'];
		if ( 1 !== preg_match( '/^[a-z0-9-]{1,100}$/D', $name ) ) {
			WP_CLI::error( 'Corpus entry name is not a file-safe slug: ' . $name );
		}
		$slot       = $status->allocate();
		$now        = Clock::now();
		$salt       = bin2hex( random_bytes( 16 ) );
		$credential = Credential_Builder::build(
			array(
				'id'                   => wp_generate_uuid4(),
				'trigger_statement_id' => wp_generate_uuid4(),
			),
			$entry['achievement'],
			$did,
			(string) $corpus['issuerName'],
			'sha256$' . hash( 'sha256', $name . '@example.org' . $salt ),
			$salt,
			$status->entry( $slot['list_id'], $slot['index'] ),
			(string) $entry['narrative'],
			$now
		);
		// Credential_Builder emits no result terms; the corpus adds them so they are canonicalised and signed too.
		if ( isset( $entry['resultDescription'] ) ) {
			$credential['credentialSubject']['achievement']['resultDescription'] = $entry['resultDescription'];
		}
		if ( isset( $entry['result'] ) ) {
			$credential['credentialSubject']['result'] = $entry['result'];
		}

		$signed = Eddsa_Rdfc_2022::sign( $credential, $vm, $key->secret(), $now );
		if ( ! Eddsa_Rdfc_2022::verify( $signed, static fn( string $method ): ?string => $method === $vm ? $key->public : null ) ) {
			WP_CLI::error( 'The plugin does not verify its own credential: ' . $name );
		}
		lledger_dev_sign_corpus_write( $out, 'credentials', $name, $signed );

		$lists[ $slot['list_id'] ] = true;
		if ( ! empty( $entry['revoke'] ) ) {
			$revoke[] = $slot;
		}
	}

	foreach ( $revoke as $slot ) {
		$status->set_revoked( $slot['list_id'], $slot['index'] );
	}
	foreach ( array_keys( $lists ) as $list_id ) {
		lledger_dev_sign_corpus_write( $out, 'status-lists', (string) $list_id, $status->credential( $list_id ) );
	}
	lledger_dev_sign_corpus_put( $out . '/did.json', lledger_dev_sign_corpus_json( Did_Document::document() ) );

	WP_CLI::success( sprintf( 'Signed %d credentials (%d revoked) and %d status lists into %s.', count( $corpus['credentials'] ), count( $revoke ), count( $lists ), $out ) );
}

/**
 * Write a secured document and the Canonicalizer's output for it.
 *
 * @param string $out     Output directory.
 * @param string $sub     Subdirectory: `credentials` or `status-lists`.
 * @param string $name    File name without extension.
 * @param array  $secured Secured document.
 *
 * @return void
 */
function lledger_dev_sign_corpus_write( string $out, string $sub, string $name, array $secured ): void {
	$canonicalizer = new Canonicalizer();
	$document      = $secured;
	unset( $document['proof'] );
	$config = $secured['proof'];
	unset( $config['proofValue'] );
	$config['@context'] = $secured['@context'];

	lledger_dev_sign_corpus_put( "$out/$sub/$name.json", lledger_dev_sign_corpus_json( $secured ) );
	lledger_dev_sign_corpus_put( "$out/canonical/$sub/$name.document.nq", $canonicalizer->canonicalize( $document ) );
	lledger_dev_sign_corpus_put( "$out/canonical/$sub/$name.proof.nq", $canonicalizer->canonicalize( $config ) );
}

/**
 * JSON for an output file.
 *
 * @param array $data Data.
 *
 * @return string
 */
function lledger_dev_sign_corpus_json( array $data ): string {
	return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
}

/**
 * Write a file or stop.
 *
 * @param string $path    Path.
 * @param string $content Content.
 *
 * @return void
 */
function lledger_dev_sign_corpus_put( string $path, string $content ): void {
	if ( false === file_put_contents( $path, $content ) ) {
		WP_CLI::error( 'Cannot write ' . $path );
	}
}

lledger_dev_sign_corpus( $args ?? array() );
