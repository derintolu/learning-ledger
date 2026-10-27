<?php
/**
 * `wp learning-ledger connector trust-key` and `revoke-key`.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\CourseServer;

use LearningLedger\Util\Invalid_Input;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages go to WP-CLI only, never into HTML.

/**
 * Registers and revokes course-server public keys. Only public keys cross WP-CLI.
 */
final class Trust_Key_Command {

	/**
	 * Trust a course server's Ed25519 public key for the signed course-server routes.
	 *
	 * ## OPTIONS
	 *
	 * --id=<id>
	 * : Key id the course server sends in X-Learning-Ledger-Key.
	 *
	 * --public-key=<base64>
	 * : Base64 of the raw 32-byte Ed25519 public key.
	 *
	 * [--capabilities=<csv>]
	 * : Comma-separated capabilities. Default: every capability except create_learners.
	 *
	 * [--per-minute=<n>]
	 * : Learners the key may create per minute. Default: 30.
	 *
	 * [--per-day=<n>]
	 * : Learners the key may create per day. Default: 2000.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function trust_key( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		try {
			$id     = (string) ( $assoc_args['id'] ?? '' );
			$caps   = isset( $assoc_args['capabilities'] )
				? array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['capabilities'] ) ), 'strlen' ) )
				: Trusted_Keys::DEFAULT_CAPABILITIES;
			$limits = array();
			foreach ( array(
				'per-minute' => 'per_minute',
				'per-day'    => 'per_day',
			) as $flag => $name ) {
				if ( isset( $assoc_args[ $flag ] ) ) {
					$value = (string) $assoc_args[ $flag ];
					if ( 1 !== preg_match( '/^[1-9][0-9]{0,8}$/', $value ) ) {
						throw new Invalid_Input( '--' . $flag . ' must be a positive integer.', 'invalid', $flag );
					}
					$limits[ $name ] = (int) $value;
				}
			}
			( new Trusted_Keys() )->add( $id, (string) ( $assoc_args['public-key'] ?? '' ), $caps, $limits );
		} catch ( Invalid_Input $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		$key  = ( new Trusted_Keys() )->get( $id );
		$caps = implode( ',', $key['capabilities'] ?? array() );
		\WP_CLI::success(
			sprintf(
				'Trusted key %s with capabilities %s (%d per minute, %d per day).',
				$id,
				'' === $caps ? 'none' : $caps,
				$key['limits']['per_minute'] ?? 0,
				$key['limits']['per_day'] ?? 0
			)
		);
	}

	/**
	 * Revoke a trusted course-server key.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Key id.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function revoke_key( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		$id   = (string) ( $args[0] ?? '' );
		$keys = new Trusted_Keys();
		if ( null === $keys->get( $id ) ) {
			\WP_CLI::error( 'No active trusted key has that id.' );
			return;
		}
		$keys->revoke( $id );
		\WP_CLI::success( sprintf( 'Revoked key %s.', $id ) );
	}
}
