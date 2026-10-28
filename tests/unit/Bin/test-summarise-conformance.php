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

	public function test_unlisted_failures_are_the_failing_ids_known_gaps_do_not_name() {
		require_once dirname( __DIR__, 3 ) . '/bin/summarise-conformance.php';
		$raw = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/conformance/sample-raw.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( array( 'XAPI-00200' ), lledger_conformance_unlisted( $raw, array( 'XAPI-00100' => 'cut' ) ) );
		$this->assertSame(
			array(),
			lledger_conformance_unlisted(
				$raw,
				array(
					'XAPI-00100' => 'cut',
					'XAPI-00200' => 'cut',
				)
			)
		);

		$jest = array(
			'testResults' => array(
				array(
					'assertionResults' => array(
						array(
							'fullName'        => 'Package import',
							'status'          => 'failed',
							'failureMessages' => array( 'Exceeded timeout of 5000 ms' ),
						),
						array(
							'fullName'        => 'AU execution',
							'status'          => 'failed',
							'failureMessages' => array( '9.3.0.0-2 (d) rejected' ),
						),
						array(
							'fullName' => 'passes',
							'status'   => 'passed',
						),
					),
				),
			),
		);
		$this->assertSame( array( '9.3.0.0-2' ), lledger_conformance_unlisted( $jest, array( 'Package import' => 'slow' ) ) );
	}

	/**
	 * Run the gate on a raw file: exit code and output.
	 *
	 * @param array $raw  Raw result.
	 * @param array $gaps Known gaps.
	 *
	 * @return array{0:int,1:string}
	 */
	private function gate( array $raw, array $gaps ): array {
		$dir = sys_get_temp_dir() . '/lledger-gate-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $dir . '/raw.json', json_encode( $raw ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.WP.AlternativeFunctions.json_encode_json_encode
		file_put_contents( $dir . '/gaps.json', json_encode( $gaps ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$cmd = 'php ' . escapeshellarg( dirname( __DIR__, 3 ) . '/bin/summarise-conformance.php' ) . ' --check ' . escapeshellarg( $dir . '/raw.json' ) . ' --known-gaps ' . escapeshellarg( $dir . '/gaps.json' ) . ' 2>&1';
		exec( $cmd, $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		array_map( 'unlink', glob( $dir . '/*' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array( $code, implode( "\n", $out ) );
	}

	public function test_check_fails_only_on_unlisted_failures_and_prints_them() {
		$raw            = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/conformance/sample-raw.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		[ $code, $out ] = $this->gate( $raw, array( 'XAPI-00100' => 'cut' ) );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'XAPI-00200', $out );
		$this->assertStringNotContainsString( 'XAPI-00100', $out );

		[ $code ] = $this->gate(
			$raw,
			array(
				'XAPI-00100' => 'cut',
				'XAPI-00200' => 'cut',
			)
		);
		$this->assertSame( 0, $code );
	}

	/**
	 * A suite run that crashed or stopped early fails the gate even though every recorded failure
	 * is listed: a Jest file that failed without a failing test, a Jest runtime error, an ADL
	 * run whose state is not `finished`, and an ADL run with a cancelled test.
	 */
	public function test_crashed_or_aborted_runs_fail_the_check_even_with_listed_failures() {
		require_once dirname( __DIR__, 3 ) . '/bin/summarise-conformance.php';
		$listed = array(
			'fullName'        => 'Package import',
			'status'          => 'failed',
			'failureMessages' => array( 'Exceeded timeout of 5000 ms' ),
		);
		$jest   = array(
			'numRuntimeErrorTestSuites' => 0,
			'testResults'               => array(
				array(
					'status'           => 'failed',
					'assertionResults' => array( $listed ),
				),
			),
		);
		$gaps   = array( 'Package import' => 'slow' );
		$this->assertNull( lledger_conformance_crash( $jest ) );
		[ $code ] = $this->gate( $jest, $gaps );
		$this->assertSame( 0, $code, 'only listed failures' );

		$crashed                  = $jest;
		$crashed['testResults'][] = array(
			'status'           => 'failed',
			'message'          => 'Test suite failed to run',
			'assertionResults' => array(),
		);
		$this->assertNotNull( lledger_conformance_crash( $crashed ) );
		[ $code, $out ] = $this->gate( $crashed, $gaps );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'failed without a failing test', $out );

		$runtime                              = $jest;
		$runtime['numRuntimeErrorTestSuites'] = 1;
		$this->assertNotNull( lledger_conformance_crash( $runtime ) );
		[ $code ] = $this->gate( $runtime, $gaps );
		$this->assertSame( 1, $code );

		$adl  = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/conformance/sample-raw.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$both = array(
			'XAPI-00100' => 'cut',
			'XAPI-00200' => 'cut',
		);
		$this->assertNull( lledger_conformance_crash( $adl ) );
		[ $code ] = $this->gate( $adl, $both );
		$this->assertSame( 0, $code, 'a finished ADL run with listed failures' );

		$error          = $adl;
		$error['state'] = 'error';
		[ $code, $out ] = $this->gate( $error, $both );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'error', $out );

		$cancelled = $adl;
		$cancelled['log']['tests'][0]['tests'][0]['status'] = 'cancelled';
		$this->assertNotNull( lledger_conformance_crash( $cancelled ) );
		[ $code ] = $this->gate( $cancelled, $both );
		$this->assertSame( 1, $code );
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

	/**
	 * Run lledger_redact over a file body and return the exit code and the rewritten text.
	 *
	 * @param string $body   File contents.
	 * @param string $secret Secret.
	 *
	 * @return array{0:int,1:string}
	 */
	private function redact( string $body, string $secret ): array {
		$file = tempnam( sys_get_temp_dir(), 'lledger-redact' );
		file_put_contents( $file, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$script = dirname( __DIR__, 3 ) . '/bin/redact.sh';
		$cmd    = 'bash -c ' . escapeshellarg( '. "$1"; lledger_redact "$2" conformance "$3"' ) . ' _ ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $file ) . ' ' . escapeshellarg( $secret );
		exec( $cmd, $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$text = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array( $code, $text );
	}

	public function test_redaction_removes_url_encoded_credentials_in_a_form_body() {
		$secret = 'p@?w/>d+ 1';
		$cred   = base64_encode( 'conformance:' . $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->assertStringContainsString( '/', $cred );
		$this->assertStringContainsString( '+', $cred );
		$this->assertStringContainsString( '=', $cred );
		$lower_hex           = preg_replace_callback( '/%[0-9A-F]{2}/', static fn( $m ) => strtolower( $m[0] ), rawurlencode( $cred ) );
		$body                = implode(
			"\n",
			array(
				'method=GET&Authorization=Basic%20' . rawurlencode( $cred ) . '&content-type=application%2Fjson',
				'Authorization=Basic+' . urlencode( $cred ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'Authorization=Basic%20' . $lower_hex,
				'Authorization%3DBasic%20' . rawurlencode( $cred ),
				'authorization=Bearer%20tok%2B123%3D&x=1',
				'authorization=Bearer+tok456',
				'client_secret=' . rawurlencode( $secret ) . '&b=1',
				'client_secret=' . urlencode( $secret ) . '&b=2', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'cred=' . rawurlencode( $cred ),
				'cred=' . str_replace( '/', '\\/', $cred ),
				'keep me',
			)
		);
		list( $code, $text ) = $this->redact( $body, $secret );
		$this->assertSame( 0, $code );
		foreach ( array( $secret, $cred, rawurlencode( $secret ), urlencode( $secret ), rawurlencode( $cred ), urlencode( $cred ), 'tok%2B123', 'tok456' ) as $leak ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			$this->assertStringNotContainsString( $leak, $text );
		}
		$this->assertStringNotContainsString( $lower_hex, $text );
		$this->assertStringContainsString( 'method=GET&Authorization=Basic%20[REDACTED]&content-type=application%2Fjson', $text );
		$this->assertStringContainsString( 'authorization=Bearer%20[REDACTED]&x=1', $text );
		$this->assertStringContainsString( 'client_secret=[REDACTED]&b=1', $text );
		$this->assertStringContainsString( 'keep me', $text );
	}

	public function test_redaction_makes_repo_root_paths_relative() {
		$root                = dirname( __DIR__, 3 );
		$esc                 = str_replace( '/', '\\/', $root );
		$body                = implode(
			"\n",
			array(
				'expected 200 got 400\nat (' . $root . '/.cache/lrs-conformance/test/v1_0_3/a.js:12:5)',
				'json escaped ' . $esc . '\\/.cache\\/lrs-conformance\\/b.js',
				'elsewhere /opt/other/.cache/c.js stays',
				'keep me',
			)
		);
		list( $code, $text ) = $this->redact( $body, 'unused-secret' );
		$this->assertSame( 0, $code );
		$this->assertStringNotContainsString( $root, $text );
		$this->assertStringNotContainsString( $esc, $text );
		$this->assertStringContainsString( 'at (.cache/lrs-conformance/test/v1_0_3/a.js:12:5)', $text );
		$this->assertStringContainsString( 'json escaped .cache\\/lrs-conformance\\/b.js', $text );
		$this->assertStringContainsString( '/opt/other/.cache/c.js stays', $text );
		$this->assertStringContainsString( 'keep me', $text );
	}
}
