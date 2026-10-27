<?php
/**
 * Statement_Validator tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Xapi\Statement_Validator;

class Statement_Validator_Test extends \PHPUnit\Framework\TestCase {

	private const FIXTURES = __DIR__ . '/../../fixtures/xapi/';

	/**
	 * Versions a fixture applies to, from the second-to-last dotted segment of its name.
	 */
	private static function versions_for( string $file ): array {
		$parts = explode( '.', basename( $file, '.json' ) );
		$tag   = end( $parts );
		$map   = array(
			'both' => array( '1.0.3', '2.0.0' ),
			'v103' => array( '1.0.3' ),
			'v200' => array( '2.0.0' ),
		);
		if ( ! isset( $map[ $tag ] ) ) {
			throw new \LogicException( 'Fixture name must end in .both, .v103 or .v200: ' . $file ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only message.
		}
		return $map[ $tag ];
	}

	private static function load( string $file ): array {
		return json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
	}

	public static function valid_provider(): array {
		$cases = array();
		foreach ( glob( self::FIXTURES . 'valid/*.json' ) as $file ) {
			foreach ( self::versions_for( $file ) as $version ) {
				$cases[ basename( $file ) . ' @ ' . $version ] = array( $file, $version );
			}
		}
		return $cases;
	}

	public static function invalid_provider(): array {
		$cases = array();
		foreach ( glob( self::FIXTURES . 'invalid/*.json' ) as $file ) {
			$expect = substr( $file, 0, -strlen( '.json' ) ) . '.expect';
			foreach ( self::versions_for( $file ) as $version ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
				$cases[ basename( $file ) . ' @ ' . $version ] = array( $file, $version, trim( (string) file_get_contents( $expect ) ) );
			}
		}
		return $cases;
	}

	public function test_fixture_sets_are_not_empty() {
		$this->assertGreaterThanOrEqual( 10, count( self::valid_provider() ) );
		$this->assertGreaterThanOrEqual( 40, count( self::invalid_provider() ) );
		foreach ( glob( self::FIXTURES . 'invalid/*.json' ) as $file ) {
			$this->assertFileExists( substr( $file, 0, -strlen( '.json' ) ) . '.expect' );
		}
	}

	/**
	 * @dataProvider valid_provider
	 */
	public function test_valid_fixture_passes( string $file, string $version ) {
		Statement_Validator::validate( self::load( $file ), $version );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @dataProvider invalid_provider
	 */
	public function test_invalid_fixture_throws_at_expected_path( string $file, string $version, string $path ) {
		try {
			Statement_Validator::validate( self::load( $file ), $version );
			$this->fail( 'Expected Invalid_Input at "' . $path . '" for ' . basename( $file ) );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( $path, $e->path(), $e->getMessage() );
		}
	}

	public function test_context_agents_accepted_under_2_0_and_rejected_under_1_0_3() {
		$st = self::load( self::FIXTURES . 'invalid/context-agents.v103.json' );
		Statement_Validator::validate( $st, '2.0.0' );
		$this->expectException( Invalid_Input::class );
		Statement_Validator::validate( $st, '1.0.3' );
	}

	public function test_timestamp_without_offset_accepted_under_1_0_3_only() {
		$st = self::load( self::FIXTURES . 'invalid/timestamp-without-offset.v200.json' );
		Statement_Validator::validate( $st, '1.0.3' );
		$this->expectException( Invalid_Input::class );
		Statement_Validator::validate( $st, '2.0.0' );
	}

	public function test_version_2_0_accepted_under_2_0() {
		$st = self::load( self::FIXTURES . 'invalid/version-2.0.0-under-103.v103.json' );
		Statement_Validator::validate( $st, '2.0.0' );
		$this->addToAssertionCount( 1 );
	}

	public function test_unknown_top_level_key_reports_code() {
		try {
			Statement_Validator::validate( self::load( self::FIXTURES . 'invalid/unknown-top-level-key.both.json' ), '2.0.0' );
			$this->fail( 'Expected Invalid_Input' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'unknown_property', $e->error_code() );
		}
	}

	public function test_unsupported_version_argument_throws() {
		$this->expectException( Invalid_Input::class );
		Statement_Validator::validate( self::load( self::FIXTURES . 'valid/minimal.both.json' ), '1.0.2' );
	}
}
