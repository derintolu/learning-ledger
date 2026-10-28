<?php
/**
 * VC-JWT fallback switch (degraded badge mode).
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Jwt;

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Badges\Issuer\Did_Document;
use LearningLedger\Kernel\Module;

/**
 * Option `lledger_badge_format` chooses `di` (default: eddsa-rdfc-2022 Data Integrity proof) or
 * `vc-jwt` (RS256 VC-JWT, which passes 1EdTech but not the Learner Credential Wallet).
 *
 * In `vc-jwt` mode the `learning_ledger/badge_sign` filter replaces the signed credential with a
 * VC-JWT, which Claim_Handler stores as it is. Every stored RSA key is published in did.json in
 * either mode, so badges already issued as VC-JWT keep verifying after a switch back to `di`.
 */
final class Jwt_Module implements Module {

	/**
	 * Option holding the badge format.
	 */
	const OPTION = 'lledger_badge_format';

	const FORMAT_DI     = 'di';
	const FORMAT_VC_JWT = 'vc-jwt';

	/**
	 * Site Health test name.
	 */
	const HEALTH_TEST = 'lledger_badge_format';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'learning_ledger/badge_sign', array( $this, 'badge_sign' ), 10, 2 ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		add_filter( 'learning_ledger/did_verification_methods', array( $this, 'did_verification_methods' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		add_filter( 'site_status_tests', array( $this, 'site_status_tests' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command(
				'learning-ledger badges format',
				array( $this, 'format_command' ),
				array(
					'shortdesc' => 'Choose the badge proof format: di (default) or vc-jwt (degraded: passes 1EdTech, not LCW).',
					'synopsis'  => array(
						array(
							'type'        => 'positional',
							'name'        => 'format',
							'description' => 'Badge format.',
							'optional'    => false,
							'options'     => array( self::FORMAT_DI, self::FORMAT_VC_JWT ),
						),
					),
				)
			);
		}
	}

	/**
	 * The badge format in force. Anything but exactly `vc-jwt` means `di`.
	 *
	 * @return string
	 */
	public static function format(): string {
		return self::FORMAT_VC_JWT === get_option( self::OPTION, self::FORMAT_DI ) ? self::FORMAT_VC_JWT : self::FORMAT_DI;
	}

	/**
	 * In `vc-jwt` mode, sign the unsigned credential as a VC-JWT instead.
	 *
	 * @param mixed $signed     The credential with its Data Integrity proof (or an earlier filter's result).
	 * @param mixed $credential The unsigned credential.
	 *
	 * @return mixed
	 *
	 * @throws \RuntimeException When the RSA key is unavailable (the claim then fails before anything is stored).
	 */
	public function badge_sign( mixed $signed, mixed $credential ): mixed {
		if ( self::FORMAT_VC_JWT !== self::format() || ! is_array( $credential ) ) {
			return $signed;
		}
		return Vc_Jwt_Signer::sign( $credential );
	}

	/**
	 * Add each readable RSA key to did.json as a JsonWebKey verification method. A stored key that
	 * cannot be read is skipped by Rsa_Key_Store::published(); Site Health reports it.
	 *
	 * @param mixed $methods Verification methods.
	 *
	 * @return array
	 */
	public function did_verification_methods( mixed $methods ): array {
		$methods = is_array( $methods ) ? $methods : array();
		$added   = array();
		try {
			$did = Did_Document::did();
			foreach ( Rsa_Key_Store::published() as $key ) {
				$added[] = array(
					'id'           => $did . '#' . $key['kid'],
					'type'         => 'JsonWebKey',
					'controller'   => $did,
					'publicKeyJwk' => $key['public_jwk'],
				);
			}
		} catch ( \RuntimeException $e ) {
			// A misconfigured constant or file RSA key must not take the Ed25519 keys out of did.json.
			return $methods;
		}
		return array_merge( $methods, $added );
	}

	/**
	 * Add the badge format test to Site Health.
	 *
	 * @param mixed $tests Tests by type.
	 *
	 * @return array
	 */
	public function site_status_tests( mixed $tests ): array {
		$tests                                = is_array( $tests ) ? $tests : array();
		$tests['direct'][ self::HEALTH_TEST ] = array(
			'label' => __( 'Learning Ledger: badge format', 'learning-ledger' ),
			'test'  => array( $this, 'test_badge_format' ),
		);
		return $tests;
	}

	/**
	 * Good in the default format; recommended (degraded) in VC-JWT mode. An RSA key that cannot be
	 * read is critical in VC-JWT mode (new badges may not sign) and recommended otherwise (badges
	 * already issued as VC-JWT may stop verifying).
	 *
	 * @return array
	 */
	public function test_badge_format(): array {
		$vc_jwt     = self::FORMAT_VC_JWT === self::format();
		$unreadable = Rsa_Key_Store::unreadable();
		if ( array() !== $unreadable ) {
			return array(
				'label'       => __( 'An RSA badge key cannot be read', 'learning-ledger' ),
				'status'      => $vc_jwt ? 'critical' : 'recommended',
				'badge'       => array(
					'label' => __( 'Learning Ledger', 'learning-ledger' ),
					'color' => $vc_jwt ? 'red' : 'orange',
				),
				'description' => '<p>' . esc_html(
					sprintf(
						/* translators: %s: comma-separated RSA key ids (or a key storage tier). */
						__( 'These RSA keys used for VC-JWT badges cannot be read: %s. A key whose public half does not parse is left out of did.json, so badges signed with it stop verifying; a key whose private half does not open cannot sign new badges.', 'learning-ledger' ),
						implode( ', ', $unreadable )
					)
				) . '</p>',
				'actions'     => '<p>' . esc_html__( 'Check LLEDGER_BADGE_RSA_KEY or LLEDGER_BADGE_RSA_KEY_FILE, or whether the WordPress salts or LLEDGER_ENCRYPTION_KEY changed since the key was stored.', 'learning-ledger' ) . '</p>',
				'test'        => self::HEALTH_TEST,
			);
		}
		$result = array(
			'label'       => __( 'Badges use Data Integrity proofs', 'learning-ledger' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Learning Ledger', 'learning-ledger' ),
				'color' => 'green',
			),
			'description' => '<p>' . esc_html__( 'Badges are signed with eddsa-rdfc-2022, which both the 1EdTech validator and the Learner Credential Wallet accept.', 'learning-ledger' ) . '</p>',
			'actions'     => '',
			'test'        => self::HEALTH_TEST,
		);
		if ( ! $vc_jwt ) {
			return $result;
		}
		$result['label']          = __( 'Badges are issued in degraded VC-JWT mode', 'learning-ledger' );
		$result['status']         = 'recommended';
		$result['badge']['color'] = 'orange';
		$result['description']    = '<p>' . esc_html__( 'New badges are signed as VC-JWT with RS256. They pass the 1EdTech validator but cannot be imported into the Learner Credential Wallet.', 'learning-ledger' ) . '</p>';
		$result['actions']        = '<p>' . esc_html__( 'Switch back with: wp learning-ledger badges format di', 'learning-ledger' ) . '</p>';
		return $result;
	}

	/**
	 * Set the badge proof format.
	 *
	 * ## OPTIONS
	 *
	 * <format>
	 * : `di` (default) or `vc-jwt`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learning-ledger badges format vc-jwt
	 *
	 * @param array $args Positional arguments.
	 *
	 * @return void
	 */
	public function format_command( array $args ): void {
		$format = (string) ( $args[0] ?? '' );
		if ( ! in_array( $format, array( self::FORMAT_DI, self::FORMAT_VC_JWT ), true ) ) {
			\WP_CLI::error( 'The badge format must be di or vc-jwt.' );
			return;
		}
		$message = 'Badge format set to di.';
		try {
			if ( self::FORMAT_VC_JWT === $format ) {
				$key     = Rsa_Key_Store::active();
				$message = 'Badge format set to vc-jwt (degraded: passes 1EdTech, not the Learner Credential Wallet). RSA key ' . $key['kid'] . '.';
			}
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		update_option( self::OPTION, $format, false );
		Audit_Log::record( 'badge_format_changed', array( 'format' => $format ), 'cli' );
		\WP_CLI::success( $message );
	}
}
