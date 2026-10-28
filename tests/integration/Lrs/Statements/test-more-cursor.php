<?php
/**
 * More_Cursor tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Crypto\Hmac;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Statements\More_Cursor;
use LearningLedger\Util\Clock;

class More_Cursor_Test extends WP_UnitTestCase {

	public function tear_down() {
		Hmac::set_key_for_tests( null );
		Clock::freeze( null );
		parent::tear_down();
	}

	private static function status_of( string $token ): int {
		try {
			More_Cursor::decode( $token );
		} catch ( Xapi_Error $e ) {
			return $e->status();
		}
		return 0;
	}

	public function test_round_trip() {
		$params = array(
			'verb'  => 'http://adlnet.gov/expapi/verbs/completed',
			'limit' => 100,
		);
		$token  = More_Cursor::encode( $params, 4711, '2026-10-27T08:00:00.123456Z' );
		$this->assertMatchesRegularExpression( '/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $token );

		$c = More_Cursor::decode( $token );
		$this->assertSame( $params, $c->params() );
		$this->assertSame( 4711, $c->last_seq() );
		$this->assertSame( '2026-10-27T08:00:00.123456Z', $c->upper_bound() );
	}

	public function test_tampered_token_is_400() {
		$token          = More_Cursor::encode( array( 'limit' => 100 ), 10, '2026-10-27T08:00:00.000000Z' );
		[ $body, $mac ] = explode( '.', $token );

		$payload             = json_decode( base64_decode( strtr( $body, '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$payload['last_seq'] = 999999;
		$forged              = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->assertSame( 400, self::status_of( $forged . '.' . $mac ) );
		$this->assertSame( 400, self::status_of( $body . '.' . strrev( $mac ) ) );
		$this->assertSame( 400, self::status_of( $body ) );
		$this->assertSame( 400, self::status_of( '' ) );
		$this->assertSame( 400, self::status_of( 'not a token' ) );
		$this->assertSame( 0, self::status_of( $token ) );
	}

	public function test_token_signed_with_another_key_is_400() {
		Hmac::set_key_for_tests( 'another site' );
		$token = More_Cursor::encode( array(), 10, '2026-10-27T08:00:00.000000Z' );
		Hmac::set_key_for_tests( null );
		$this->assertSame( 400, self::status_of( $token ) );
	}

	public function test_signed_payload_of_wrong_shape_is_400() {
		$body = rtrim( strtr( base64_encode( '{"params":[],"last_seq":"x","upper_bound":1}' ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$mac  = rtrim( strtr( base64_encode( Hmac::sign( $body, 'more' ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertSame( 400, self::status_of( $body . '.' . $mac ) );
	}
	public function test_token_expires_after_24_hours() {
		Clock::freeze( new DateTimeImmutable( '2026-10-27T08:00:00Z' ) );
		$token = More_Cursor::encode( array(), 10, '2026-10-27T07:59:59.000000Z' );
		Clock::freeze( new DateTimeImmutable( '2026-10-28T07:59:59Z' ) );
		$this->assertSame( 0, self::status_of( $token ) );
		Clock::freeze( new DateTimeImmutable( '2026-10-28T08:00:01Z' ) );
		$this->assertSame( 400, self::status_of( $token ) );
	}
}
