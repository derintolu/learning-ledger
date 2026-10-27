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

	public function test_redaction_removes_secret_basic_credential_and_authorization_header() {
		$file   = tempnam( sys_get_temp_dir(), 'lledger-redact' );
		$secret = 'abc123secret';
		$cred   = base64_encode( 'conformance:' . $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		file_put_contents( $file, "plain {$secret}\ncred {$cred} here\nAuthorization: Basic QUJDOkRFRg==\n\"Authorization\":\"Bearer tok123\"\nkeep me\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$script = dirname( __DIR__, 3 ) . '/bin/redact.sh';
		$cmd    = 'bash -c ' . escapeshellarg( '. "$1"; lledger_redact "$2" conformance "$3"' ) . ' _ ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $file ) . ' ' . escapeshellarg( $secret );
		exec( $cmd, $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$text = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertSame( 0, $code );
		$this->assertStringNotContainsString( $secret, $text );
		$this->assertStringNotContainsString( $cred, $text );
		$this->assertStringNotContainsString( 'QUJDOkRFRg', $text );
		$this->assertStringNotContainsString( 'tok123', $text );
		$this->assertStringContainsString( 'keep me', $text );
		$this->assertStringContainsString( '[REDACTED]', $text );
	}
}
