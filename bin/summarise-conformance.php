<?php
/**
 * Turns raw conformance results into a Markdown summary.
 *
 * Understands two raw shapes: the ADL LRS suite run record (a nested "log" tree whose
 * suites carry "XAPI-nnnnn" ids) and a Jest JSON report (CATAPULT LTS, cmi5 ids such as
 * "9.3.0.0-1"). CLI: php bin/summarise-conformance.php conformance/raw/*.json > conformance/SUMMARY.md
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
	$reqs = array();
	if ( isset( $raw['log'] ) && is_array( $raw['log'] ) ) {
		lledger_conformance_walk_adl( $raw['log'], '', $reqs );
	} elseif ( isset( $raw['testResults'] ) ) {
		lledger_conformance_collect_jest( $raw, $reqs );
	}

	$failing = array_keys( array_filter( $reqs, static fn( $r ) => $r['failed'] ) );
	sort( $failing, SORT_NATURAL );
	$passed = count( $reqs ) - count( $failing );

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
 * Make a string safe for a single Markdown table cell.
 *
 * @param string $text Cell text.
 *
 * @return string
 */
function lledger_conformance_cell( string $text ): string {
	return trim( str_replace( array( '|', "\r", "\n" ), array( '\|', ' ', ' ' ), $text ) );
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
