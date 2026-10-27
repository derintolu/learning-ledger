<?php
/**
 * Tests for the conformance summariser.
 *
 * @package LearningLedger
 */

class Summarise_Conformance_Test extends \PHPUnit\Framework\TestCase {
	public function test_lists_failures_with_known_reason_or_unexplained() {
		require_once dirname( __DIR__, 3 ) . '/bin/summarise-conformance.php';
		$raw = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/conformance/sample-raw.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$md  = lledger_summarise_conformance( $raw, array( 'XAPI-00100' => 'multipart attachments cut in v1 (§6.9)' ), 'xAPI 2.0' );
		$this->assertStringContainsString( '| XAPI-00100 | fail | multipart attachments cut in v1 (§6.9) |', $md );
		$this->assertStringContainsString( '| XAPI-00200 | fail | UNEXPLAINED |', $md );
		$this->assertStringNotContainsString( 'XAPI-00300', $md ); // passing ids are counted, not listed.
	}
}
