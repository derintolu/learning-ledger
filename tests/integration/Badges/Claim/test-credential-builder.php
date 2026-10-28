<?php
/**
 * Credential_Builder tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Canon\Canonicalizer;
use LearningLedger\Badges\Claim\Credential_Builder;
use LearningLedger\Badges\Signing\Eddsa_Rdfc_2022;
use LearningLedger\Crypto\Ed25519;

class Credential_Builder_Test extends WP_UnitTestCase {

	const BADGE_ID   = '6f1c1b2e-3d4a-4b5c-8d6e-7f8091a2b3c4';
	const TRIGGER_ID = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
	const DID        = 'did:web:lrs.example';
	const HASH       = 'sha256$9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
	const SALT       = '00112233445566778899aabbccddeeff';

	private static function badge(): array {
		return array(
			'id'                   => self::BADGE_ID,
			'trigger_statement_id' => self::TRIGGER_ID,
			'registration'         => 'f4a6b9c2-1d3e-4f5a-9b8c-7d6e5f4a3b2c',
			'actor_hash'           => sha1( 'account::https://site.example|learner-1', true ),
		);
	}

	private static function achievement(): array {
		return array(
			'id'              => 'https://example.org/badges/one',
			'name'            => 'Badge one',
			'description'     => 'Earned one.',
			'criteria'        => array( 'narrative' => 'Do one.' ),
			'image'           => 'https://example.org/img/one.png',
			'achievementType' => 'Badge',
			'alignment'       => array(
				array(
					'targetName' => 'Skill one',
					'targetUrl'  => 'https://example.org/skills/one',
				),
			),
		);
	}

	private static function entry(): array {
		return array(
			'id'                   => 'https://lrs.example/wp-json/learning-ledger/v1/status-lists/1#42',
			'type'                 => 'BitstringStatusListEntry',
			'statusPurpose'        => 'revocation',
			'statusListIndex'      => '42',
			'statusListCredential' => 'https://lrs.example/wp-json/learning-ledger/v1/status-lists/1',
		);
	}

	private static function build( ?array $achievement = null ): array {
		return Credential_Builder::build(
			self::badge(),
			$achievement ?? self::achievement(),
			self::DID,
			'Learn WordPress, reimagined',
			self::HASH,
			self::SALT,
			self::entry(),
			'1 of 1 lessons completed',
			new \DateTimeImmutable( '2026-10-27T10:11:12.345678+02:00' )
		);
	}

	public function test_builds_the_open_badge_credential() {
		$this->assertSame(
			array(
				'@context'          => array( 'https://www.w3.org/ns/credentials/v2', 'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json' ),
				'id'                => 'urn:uuid:' . self::BADGE_ID,
				'type'              => array( 'VerifiableCredential', 'OpenBadgeCredential' ),
				'issuer'            => array(
					'id'   => self::DID,
					'type' => array( 'Profile' ),
					'name' => 'Learn WordPress, reimagined',
				),
				'validFrom'         => '2026-10-27T08:11:12Z',
				'name'              => 'Badge one',
				'credentialSubject' => array(
					'type'        => array( 'AchievementSubject' ),
					'identifier'  => array(
						array(
							'type'         => 'IdentityObject',
							'identityHash' => self::HASH,
							'identityType' => 'emailAddress',
							'hashed'       => true,
							'salt'         => self::SALT,
						),
					),
					'achievement' => array(
						'id'              => 'https://example.org/badges/one',
						'type'            => array( 'Achievement' ),
						'name'            => 'Badge one',
						'description'     => 'Earned one.',
						'criteria'        => array( 'narrative' => 'Do one.' ),
						'image'           => array(
							'id'   => 'https://example.org/img/one.png',
							'type' => 'Image',
						),
						'achievementType' => 'Badge',
						'alignment'       => array(
							array(
								'type'       => array( 'Alignment' ),
								'targetName' => 'Skill one',
								'targetUrl'  => 'https://example.org/skills/one',
							),
						),
					),
				),
				'evidence'          => array(
					array(
						'id'        => 'urn:uuid:' . self::TRIGGER_ID,
						'type'      => array( 'Evidence' ),
						'narrative' => '1 of 1 lessons completed',
						'genre'     => 'xAPI statement',
					),
				),
				'credentialStatus'  => self::entry(),
			),
			self::build()
		);
	}

	public function test_optional_achievement_fields_are_left_out_when_absent() {
		$achievement = self::achievement();
		unset( $achievement['image'], $achievement['achievementType'], $achievement['alignment'] );

		$built = self::build( $achievement )['credentialSubject']['achievement'];

		$this->assertSame( array( 'id', 'type', 'name', 'description', 'criteria' ), array_keys( $built ) );
	}

	public function test_fields_outside_the_achievement_shape_are_not_copied() {
		$achievement             = self::achievement();
		$achievement['awardOn']  = 'course-satisfied';
		$achievement['for']      = 'block-one';
		$achievement['criteria'] = array(
			'narrative' => 'Do one.',
			'extra'     => 'dropped',
		);

		$built = self::build( $achievement )['credentialSubject']['achievement'];

		$this->assertArrayNotHasKey( 'awardOn', $built );
		$this->assertArrayNotHasKey( 'for', $built );
		$this->assertSame( array( 'narrative' => 'Do one.' ), $built['criteria'] );
	}

	public function test_carries_no_xapi_actor_hashed_or_not() {
		$json = (string) wp_json_encode( self::build() );

		$this->assertStringNotContainsString( 'learner-1', $json );
		$this->assertStringNotContainsString( bin2hex( self::badge()['actor_hash'] ), $json );
		$this->assertStringNotContainsString( self::badge()['registration'], $json );
		$this->assertStringNotContainsString( '"actor"', $json );
		$this->assertStringNotContainsString( 'mbox', $json );
	}

	public function test_canonicalizes_in_safe_mode_and_signs_and_verifies() {
		$credential = self::build();
		$this->assertNotSame( '', ( new Canonicalizer() )->canonicalize( $credential ) );

		$pair   = Ed25519::keypair();
		$vm     = self::DID . '#k1';
		$signed = Eddsa_Rdfc_2022::sign( $credential, $vm, $pair['secret'], new \DateTimeImmutable( '2026-10-27T08:11:12Z' ) );

		$this->assertTrue(
			Eddsa_Rdfc_2022::verify(
				$signed,
				static fn( string $method ): ?string => $vm === $method ? $pair['public'] : null
			)
		);
	}
}
