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

	private static function rejects( array $st, string $version, string $path, string $message ): void {
		try {
			Statement_Validator::validate( $st, $version );
		} catch ( Invalid_Input $e ) {
			self::assertSame( $path, $e->path(), $message );
			return;
		}
		self::fail( 'Expected Invalid_Input at "' . $path . '": ' . $message );
	}

	/**
	 * XAPI-00024 / XAPI-00098 / XAPI-00100 (Data 2.4.9): an authority that is a Group is an
	 * anonymous Group of exactly two Agents.
	 */
	public function test_xapi_00098_authority_group_is_anonymous_with_two_members() {
		$st    = self::load( self::FIXTURES . 'valid/minimal.both.json' );
		$oauth = array(
			'account' => array(
				'homePage' => 'http://example.com/xAPI/OAuth/Token',
				'name'     => 'oauth_consumer_x75db',
			),
		);
		$bob   = array( 'mbox' => 'mailto:bob@example.com' );
		$two   = array(
			'objectType' => 'Group',
			'member'     => array( $oauth, $bob ),
		);
		foreach ( array( '1.0.3', '2.0.0' ) as $version ) {
			Statement_Validator::validate( array_merge( $st, array( 'authority' => $two ) ), $version );
			Statement_Validator::validate( array_merge( $st, array( 'authority' => $bob ) ), $version );

			foreach ( array(
				'mbox'         => 'mailto:group@example.com',
				'mbox_sha1sum' => 'cd9b00a5611f94eaa7b1661edab976068e364975',
				'openid'       => 'http://openid.example.org/12345',
				'account'      => array(
					'homePage' => 'http://www.example.com',
					'name'     => 'xAPI account name',
				),
			) as $ifi => $value ) {
				self::rejects( array_merge( $st, array( 'authority' => array_merge( $two, array( $ifi => $value ) ) ) ), $version, 'authority', 'identified group (' . $ifi . ') ' . $version );
			}
			$one   = array_merge( $two, array( 'member' => array( $bob ) ) );
			$three = array_merge( $two, array( 'member' => array( $oauth, $bob, array( 'mbox' => 'mailto:james@example.com' ) ) ) );
			self::rejects( array_merge( $st, array( 'authority' => $one ) ), $version, 'authority.member', 'one member ' . $version );
			self::rejects( array_merge( $st, array( 'authority' => $three ) ), $version, 'authority.member', 'three members ' . $version );
		}
	}

	/**
	 * XAPI-00101 (Data 2.4.10): a statement `version` of "1.0" or "1.0.x" is accepted; others are 400.
	 */
	public function test_xapi_00101_statement_version_1_0_without_patch_is_accepted() {
		$st = self::load( self::FIXTURES . 'valid/minimal.both.json' );
		foreach ( array( '1.0.3', '2.0.0' ) as $version ) {
			foreach ( array( '1.0', '1.0.0', '1.0.9' ) as $value ) {
				Statement_Validator::validate( array_merge( $st, array( 'version' => $value ) ), $version );
			}
			foreach ( array( 'should fail', '0.9.9', '1.1.0', '1', '1.0.', '1.0-rc.1' ) as $value ) {
				self::rejects( array_merge( $st, array( 'version' => $value ) ), $version, 'version', $value . ' ' . $version );
			}
		}
		Statement_Validator::validate( array_merge( $st, array( 'version' => '2.0' ) ), '2.0.0' );
		self::rejects( array_merge( $st, array( 'version' => '2.0' ) ), '1.0.3', 'version', '2.0 under 1.0.3' );
	}

	/**
	 * XAPI-00123 (Data 4.5): a negative zero offset ("-00", "-0000", "-00:00") is not a valid
	 * timestamp, in a statement or a SubStatement, under either version.
	 */
	public function test_xapi_00123_negative_zero_offset_timestamp_is_rejected() {
		$st  = self::load( self::FIXTURES . 'valid/minimal.both.json' );
		$sub = array_merge(
			$st,
			array(
				'object' => array_merge(
					array( 'objectType' => 'SubStatement' ),
					$st
				),
			)
		);
		foreach ( array( '1.0.3', '2.0.0' ) as $version ) {
			foreach ( array( '-00', '-0000', '-00:00' ) as $offset ) {
				$ts = '2008-09-15T15:53:00.601' . $offset;
				self::rejects( array_merge( $st, array( 'timestamp' => $ts ) ), $version, 'timestamp', $offset . ' ' . $version );
				$bad                        = $sub;
				$bad['object']['timestamp'] = $ts;
				self::rejects( $bad, $version, 'object.timestamp', 'substatement ' . $offset . ' ' . $version );
			}
			foreach ( array( '+00:00', 'Z', '-05:00' ) as $offset ) {
				Statement_Validator::validate( array_merge( $st, array( 'timestamp' => '2008-09-15T15:53:00.601' . $offset ) ), $version );
			}
		}
		foreach ( array( '+00', '+0000', '-0500' ) as $offset ) {
			Statement_Validator::validate( array_merge( $st, array( 'timestamp' => '2008-09-15T15:53:00.601' . $offset ) ), '1.0.3' );
		}
	}

	/**
	 * XAPI-00124 (Data 4.6): a duration is ISO 8601:2004 4.4.3.2, so weeks stand alone ("P4W1D" is 400).
	 */
	public function test_xapi_00124_week_duration_cannot_combine_with_other_units() {
		$st = self::load( self::FIXTURES . 'valid/minimal.both.json' );
		foreach ( array( '1.0.3', '2.0.0' ) as $version ) {
			foreach ( array( 'PT4H35M59.14S', 'PT16559.14S', 'P3Y1M29DT4H35M59.14S', 'P3Y', 'P4W' ) as $d ) {
				Statement_Validator::validate( array_merge( $st, array( 'result' => array( 'duration' => $d ) ) ), $version );
			}
			foreach ( array( 'P4W1D', 'P1Y4W', 'P4WT1H' ) as $d ) {
				self::rejects( array_merge( $st, array( 'result' => array( 'duration' => $d ) ) ), $version, 'result.duration', $d . ' ' . $version );
			}
		}
	}
}
