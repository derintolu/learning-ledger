<?php
/**
 * VC-JWT signing of an Open Badges 3.0 credential.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Jwt;

use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Vendor\Firebase\JWT\JWT;

/**
 * Signs a credential as a compact JWS with RS256 (OB 3.0 §8.2), the degraded alternative to the
 * eddsa-rdfc-2022 Data Integrity proof. The JOSE header is `typ`, `alg` and `kid`, where `kid`
 * is `<did>#<rsa kid>`, a JsonWebKey verification method in did.json.
 */
final class Vc_Jwt_Signer {

	/**
	 * JOSE algorithm.
	 */
	const ALG = 'RS256';

	/**
	 * Sign a credential with the active RSA badge key.
	 *
	 * The payload carries `iss` (issuer.id), `jti` (id), `nbf` (validFrom), `sub`
	 * (credentialSubject.id, when the credential has one), `exp` (validUntil, when set) and the
	 * credential itself as the `vc` claim.
	 *
	 * @param array $credential Unsigned credential.
	 *
	 * @return string Compact JWS.
	 *
	 * @throws Invalid_Input When the credential has no id, issuer id or valid validFrom.
	 * @throws \RuntimeException When the RSA key is unavailable.
	 */
	public static function sign( array $credential ): string {
		$issuer = $credential['issuer'] ?? null;
		$issuer = is_array( $issuer ) ? ( $issuer['id'] ?? null ) : $issuer;
		$id     = $credential['id'] ?? null;
		if ( ! is_string( $issuer ) || '' === $issuer || ! is_string( $id ) || '' === $id ) {
			throw new Invalid_Input( 'The credential needs an id and an issuer id.', 'invalid', 'credential' );
		}

		$payload = array(
			'iss' => $issuer,
			'jti' => $id,
			'nbf' => self::numeric_date( $credential['validFrom'] ?? null, 'validFrom' ),
		);
		$subject = $credential['credentialSubject']['id'] ?? null;
		if ( is_string( $subject ) && '' !== $subject ) {
			$payload['sub'] = $subject;
		}
		if ( array_key_exists( 'validUntil', $credential ) ) {
			$payload['exp'] = self::numeric_date( $credential['validUntil'], 'validUntil' );
		}
		$payload['vc'] = $credential;

		$key     = Rsa_Key_Store::active();
		$private = openssl_pkey_get_private( $key['private_pem'] );
		$kid     = Did_Document::did() . '#' . $key['kid'];
		unset( $key );
		if ( false === $private ) {
			throw new \RuntimeException( 'The RSA badge key cannot be read.' );
		}
		// A key object, not the PEM string, so the PEM never appears among a stack trace's arguments.
		return JWT::encode( $payload, $private, self::ALG, $kid );
	}

	/**
	 * Seconds since the epoch of an XML Schema dateTime.
	 *
	 * @param mixed  $value Date-time string.
	 * @param string $field Credential property, for the error.
	 *
	 * @return integer
	 *
	 * @throws Invalid_Input When the value is not a date-time with a time zone.
	 */
	private static function numeric_date( mixed $value, string $field ): int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/', $value ) ) {
			throw new Invalid_Input( 'The credential ' . esc_html( $field ) . ' must be a date-time with a time zone.', 'invalid', esc_html( $field ) );
		}
		try {
			return ( new \DateTimeImmutable( $value ) )->getTimestamp();
		} catch ( \Exception $e ) {
			throw new Invalid_Input( 'The credential ' . esc_html( $field ) . ' must be a date-time with a time zone.', 'invalid', esc_html( $field ) );
		}
	}
}
