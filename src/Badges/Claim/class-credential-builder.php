<?php
/**
 * Open Badges 3.0 credential builder.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Claim;

/**
 * Builds the unsigned `OpenBadgeCredential` for a badge at claim time.
 *
 * The recipient is only the salted email hash; the xAPI actor is never put into the
 * credential, hashed or not (OB 3.0 §6). Only OB 3.0 and VC 2.0 terms are used, so the
 * credential canonicalizes in safe mode against the bundled contexts.
 */
final class Credential_Builder {

	/**
	 * The credential's `@context`: VC Data Model 2.0, then Open Badges 3.0.3.
	 */
	const CONTEXT = array( 'https://www.w3.org/ns/credentials/v2', 'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json' );

	/**
	 * Alignment fields copied from a declaration (the cxm course schema allows only these).
	 */
	const ALIGNMENT_FIELDS = array( 'targetName', 'targetUrl', 'targetDescription', 'targetFramework', 'targetCode', 'targetType' );

	/**
	 * Build the credential.
	 *
	 * @param array              $badge              Badge row (Badge_Repository::get()); uses `id` and `trigger_statement_id`.
	 * @param array              $achievement        Achievement fields from the course declaration.
	 * @param string             $did                Issuer DID.
	 * @param string             $issuer_name        Issuer display name.
	 * @param string             $identity_hash      The `sha256$<hex>` of the email and salt.
	 * @param string             $salt               Salt of the identity hash.
	 * @param array              $status_entry       BitstringStatusListEntry.
	 * @param string             $evidence_narrative Evidence narrative.
	 * @param \DateTimeImmutable $valid_from         Start of validity (written in UTC, whole seconds).
	 *
	 * @return array
	 */
	public static function build( array $badge, array $achievement, string $did, string $issuer_name, string $identity_hash, string $salt, array $status_entry, string $evidence_narrative, \DateTimeImmutable $valid_from ): array {
		$name = (string) ( $achievement['name'] ?? '' );
		return array(
			'@context'          => self::CONTEXT,
			'id'                => 'urn:uuid:' . strtolower( (string) $badge['id'] ),
			'type'              => array( 'VerifiableCredential', 'OpenBadgeCredential' ),
			'issuer'            => array(
				'id'   => $did,
				'type' => array( 'Profile' ),
				'name' => $issuer_name,
			),
			'validFrom'         => $valid_from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'name'              => $name,
			'credentialSubject' => array(
				'type'        => array( 'AchievementSubject' ),
				'identifier'  => array(
					array(
						'type'         => 'IdentityObject',
						'identityHash' => $identity_hash,
						'identityType' => 'emailAddress',
						'hashed'       => true,
						'salt'         => $salt,
					),
				),
				'achievement' => self::achievement( $achievement ),
			),
			'evidence'          => array(
				array(
					'id'        => 'urn:uuid:' . strtolower( (string) $badge['trigger_statement_id'] ),
					'type'      => array( 'Evidence' ),
					'narrative' => $evidence_narrative,
					'genre'     => 'xAPI statement',
				),
			),
			'credentialStatus'  => $status_entry,
		);
	}

	/**
	 * The OB 3.0 Achievement from the declaration's fields.
	 *
	 * A declared image URL becomes an `Image` object and each alignment gets its `Alignment`
	 * type, as the OB 3.0 schema requires (the alignment terms are only defined for that type).
	 *
	 * @param array $a Achievement fields.
	 *
	 * @return array
	 */
	private static function achievement( array $a ): array {
		$out = array(
			'id'          => (string) ( $a['id'] ?? '' ),
			'type'        => array( 'Achievement' ),
			'name'        => (string) ( $a['name'] ?? '' ),
			'description' => (string) ( $a['description'] ?? '' ),
			'criteria'    => array( 'narrative' => (string) ( $a['criteria']['narrative'] ?? '' ) ),
		);
		if ( is_string( $a['image'] ?? null ) && '' !== $a['image'] ) {
			$out['image'] = array(
				'id'   => $a['image'],
				'type' => 'Image',
			);
		}
		if ( is_string( $a['achievementType'] ?? null ) && '' !== $a['achievementType'] ) {
			$out['achievementType'] = $a['achievementType'];
		}
		$alignments = array();
		foreach ( is_array( $a['alignment'] ?? null ) ? $a['alignment'] : array() as $alignment ) {
			if ( is_array( $alignment ) ) {
				$alignments[] = array( 'type' => array( 'Alignment' ) ) + array_intersect_key( $alignment, array_flip( self::ALIGNMENT_FIELDS ) );
			}
		}
		if ( array() !== $alignments ) {
			$out['alignment'] = $alignments;
		}
		return $out;
	}
}
