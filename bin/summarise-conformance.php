<?php
/**
 * Turns raw conformance results into a Markdown summary.
 *
 * Understands two raw shapes: the ADL LRS suite run record (a nested "log" tree whose
 * suites carry "XAPI-nnnnn" ids) and a Jest JSON report (CATAPULT LTS, cmi5 ids such as
 * "9.3.0.0-1"). CLI: php bin/summarise-conformance.php conformance/raw/*.json > conformance/SUMMARY.md
 *
 * Gate: php bin/summarise-conformance.php --check <raw.json> [--known-gaps <file>] [--suite-status <n>]
 * exits 1 and prints the ids when a failing test is not listed in conformance/known-gaps.json
 * (or when the suite exited non-zero without recording any failure), 0 otherwise.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DevelopmentFunctions, WordPress.WP.GlobalVariablesOverride

/**
 * Collect per-requirement pass/fail from an ADL LRS suite run record.
 *
 * @param array<string,mixed> $node      Suite or test node.
 * @param string              $inherited Requirement id inherited from an ancestor.
 * @param array<string,array> $reqs      Accumulator: id => [ 'failed' => bool, 'errors' => string[] ].
 *
 * @return void
 */
function lledger_conformance_walk_adl( array $node, string $inherited, array &$reqs ): void {
	$id = $inherited;
	foreach ( array( 'requirement', 'title' ) as $key ) {
		if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && preg_match( '/XAPI-\d+/', $node[ $key ], $m ) ) {
			$id = $m[0];
			break;
		}
	}
	$children = isset( $node['tests'] ) && is_array( $node['tests'] ) ? $node['tests'] : array();
	if ( $children ) {
		foreach ( $children as $child ) {
			if ( is_array( $child ) ) {
				lledger_conformance_walk_adl( $child, $id, $reqs );
			}
		}
		return;
	}
	if ( '' === $id ) {
		$id = (string) ( $node['title'] ?? 'unknown' );
	}
	$reqs[ $id ] = $reqs[ $id ] ?? array(
		'failed' => false,
		'errors' => array(),
	);
	if ( 'failed' === ( $node['status'] ?? '' ) ) {
		$reqs[ $id ]['failed'] = true;
	}
}

/**
 * Collect per-requirement pass/fail from a Jest JSON report.
 *
 * @param array<string,mixed> $raw  Jest report.
 * @param array<string,array> $reqs Accumulator.
 *
 * @return void
 */
function lledger_conformance_collect_jest( array $raw, array &$reqs ): void {
	foreach ( $raw['testResults'] ?? array() as $file ) {
		foreach ( $file['assertionResults'] ?? array() as $a ) {
			$messages = implode( ' ', (array) ( $a['failureMessages'] ?? array() ) );
			$id       = (string) ( $a['fullName'] ?? $a['title'] ?? 'unknown' );
			if ( preg_match( '/\b\d+(?:\.\d+){2,}-\d+\b/', $messages, $m ) ) {
				$id = $m[0];
			}
			$reqs[ $id ] = $reqs[ $id ] ?? array(
				'failed' => false,
				'errors' => array(),
			);
			if ( 'failed' === ( $a['status'] ?? '' ) ) {
				$reqs[ $id ]['failed'] = true;
			}
		}
	}
}

/**
 * Summarise one raw conformance result as Markdown.
 *
 * @param array<string,mixed>  $raw        Raw suite result (ADL run record or Jest JSON).
 * @param array<string,string> $known_gaps Requirement id => reason.
 * @param string               $title      Heading for the section.
 *
 * @return string Markdown: heading, counts and a table of failing requirements only.
 */
function lledger_summarise_conformance( array $raw, array $known_gaps, string $title ): string {
	$reqs    = lledger_conformance_requirements( $raw );
	$failing = lledger_conformance_failing( $reqs );
	$passed  = count( $reqs ) - count( $failing );

	$md  = "### {$title}\n\n";
	$md .= sprintf( "Requirements passed: %d. Requirements failing: %d.\n\n", $passed, count( $failing ) );
	$md .= "| Requirement | Result | Reason |\n| --- | --- | --- |\n";
	foreach ( $failing as $id ) {
		$reason = isset( $known_gaps[ $id ] ) && '' !== $known_gaps[ $id ] ? $known_gaps[ $id ] : 'UNEXPLAINED';
		$md    .= sprintf( "| %s | fail | %s |\n", lledger_conformance_cell( (string) $id ), lledger_conformance_cell( $reason ) );
	}
	return $md;
}

/**
 * Per-requirement results of a raw suite result (ADL run record or Jest JSON).
 *
 * @param array<string,mixed> $raw Raw suite result.
 *
 * @return array<string,array> id => [ 'failed' => bool, 'errors' => string[] ].
 */
function lledger_conformance_requirements( array $raw ): array {
	$reqs = array();
	if ( isset( $raw['log'] ) && is_array( $raw['log'] ) ) {
		lledger_conformance_walk_adl( $raw['log'], '', $reqs );
	} elseif ( isset( $raw['testResults'] ) ) {
		lledger_conformance_collect_jest( $raw, $reqs );
	}
	return $reqs;
}

/**
 * Failing ids, in natural order.
 *
 * @param array<string,array> $reqs Per-requirement results.
 *
 * @return string[]
 */
function lledger_conformance_failing( array $reqs ): array {
	$failing = array_map( 'strval', array_keys( array_filter( $reqs, static fn( $r ) => $r['failed'] ) ) );
	sort( $failing, SORT_NATURAL );
	return $failing;
}

/**
 * Failing ids that conformance/known-gaps.json does not list: what fails the gate.
 *
 * @param array<string,mixed>  $raw        Raw suite result.
 * @param array<string,string> $known_gaps Requirement id => reason.
 *
 * @return string[]
 */
function lledger_conformance_unlisted( array $raw, array $known_gaps ): array {
	return array_values( array_filter( lledger_conformance_failing( lledger_conformance_requirements( $raw ) ), static fn( $id ) => ! isset( $known_gaps[ $id ] ) || '' === $known_gaps[ $id ] ) );
}

/**
 * Make a string safe for a single Markdown table cell.
 *
 * @param string $text Cell text.
 *
 * @return string
 */
function lledger_conformance_cell( string $text ): string {
	return trim( str_replace( array( '|', "\r", "\n" ), array( '\|', ' ', ' ' ), $text ) );
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ && '--check' === ( $argv[1] ?? '' ) ) {
	$lledger_opts = array(
		'--check'        => '',
		'--known-gaps'   => dirname( __DIR__ ) . '/conformance/known-gaps.json',
		'--suite-status' => '0',
	);
	$lledger_argc = count( $argv );
	for ( $lledger_i = 1; $lledger_i + 1 < $lledger_argc; $lledger_i += 2 ) {
		if ( ! array_key_exists( $argv[ $lledger_i ], $lledger_opts ) ) {
			fwrite( STDERR, "Unknown argument: {$argv[ $lledger_i ]}\n" );
			exit( 2 );
		}
		$lledger_opts[ $argv[ $lledger_i ] ] = $argv[ $lledger_i + 1 ];
	}
	$lledger_raw  = is_readable( $lledger_opts['--check'] ) ? json_decode( (string) file_get_contents( $lledger_opts['--check'] ), true ) : null;
	$lledger_gaps = is_readable( $lledger_opts['--known-gaps'] ) ? json_decode( (string) file_get_contents( $lledger_opts['--known-gaps'] ), true ) : null;
	if ( ! is_array( $lledger_raw ) || ! is_array( $lledger_gaps ) ) {
		fwrite( STDERR, "Cannot read the raw result or the known gaps.\n" );
		exit( 1 );
	}
	$lledger_failing  = lledger_conformance_failing( lledger_conformance_requirements( $lledger_raw ) );
	$lledger_unlisted = lledger_conformance_unlisted( $lledger_raw, $lledger_gaps );
	if ( array() !== $lledger_unlisted ) {
		fwrite( STDERR, "Failing tests not listed in conformance/known-gaps.json:\n  " . implode( "\n  ", $lledger_unlisted ) . "\n" );
		exit( 1 );
	}
	if ( array() === $lledger_failing && '0' !== (string) $lledger_opts['--suite-status'] ) {
		fwrite( STDERR, "The suite exited {$lledger_opts['--suite-status']} without recording a failing test.\n" );
		exit( 1 );
	}
	echo 'Every failing test (' . count( $lledger_failing ) . ") is listed in conformance/known-gaps.json.\n";
	exit( 0 );
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	$lledger_files = array_slice( $argv, 1 );
	if ( ! $lledger_files ) {
		fwrite( STDERR, "Usage: php bin/summarise-conformance.php conformance/raw/*.json > conformance/SUMMARY.md\n" );
		exit( 1 );
	}
	$lledger_gaps_file = dirname( __DIR__ ) . '/conformance/known-gaps.json';
	$lledger_gaps      = is_readable( $lledger_gaps_file ) ? json_decode( (string) file_get_contents( $lledger_gaps_file ), true ) : array();
	$lledger_gaps      = is_array( $lledger_gaps ) ? $lledger_gaps : array();

	echo "# Conformance summary\n\n";
	foreach ( $lledger_files as $lledger_file ) {
		$lledger_raw = json_decode( (string) file_get_contents( $lledger_file ), true );
		if ( ! is_array( $lledger_raw ) ) {
			fwrite( STDERR, "Skipping unreadable file: {$lledger_file}\n" );
			continue;
		}
		echo lledger_summarise_conformance( $lledger_raw, $lledger_gaps, basename( $lledger_file, '.json' ) ) . "\n";
	}
}
