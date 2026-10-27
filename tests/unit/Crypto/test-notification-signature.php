<?php
/**
 * Notification_Signature tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- base64 is the wire format under test.

use LearningLedger\Crypto\Ed25519;
use LearningLedger\Crypto\Notification_Signature;

class Notification_Signature_Test extends \PHPUnit\Framework\TestCase {
	public function test_notification_signature_round_trip() {
		$kp   = Ed25519::keypair();
		$body = '{"events":[]}';
		$sig  = Notification_Signature::sign( $body, $kp['secret'], 1761552000 );

		$raw = base64_decode( $sig, true );
		$this->assertSame( 64, strlen( $raw ) );
		$this->assertTrue( Ed25519::verify( $raw, '1761552000.' . $body, $kp['public'] ) );

		$this->assertTrue( Notification_Signature::verify( $sig, '1761552000', $body, $kp['public'] ) );
		$this->assertFalse( Notification_Signature::verify( $sig, '1761552001', $body, $kp['public'] ) );
		$this->assertFalse( Notification_Signature::verify( $sig, '1761552000', $body . ' ', $kp['public'] ) );
	}

	public function test_verify_rejects_other_key_and_malformed_signature() {
		$kp    = Ed25519::keypair();
		$other = Ed25519::keypair();
		$sig   = Notification_Signature::sign( 'b', $kp['secret'], 1 );
		$this->assertFalse( Notification_Signature::verify( $sig, '1', 'b', $other['public'] ) );
		$this->assertFalse( Notification_Signature::verify( '!!', '1', 'b', $kp['public'] ) );
		$this->assertFalse( Notification_Signature::verify( base64_encode( 'short' ), '1', 'b', $kp['public'] ) );
	}
}
