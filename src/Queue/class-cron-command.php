<?php
/**
 * WP-CLI: cron secret.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Queue;

/**
 * Manage the cron trigger route.
 */
final class Cron_Command {

	/**
	 * Store the SHA-256 of the secret the cron route accepts.
	 *
	 * The secret itself never passes through WP-CLI: hash it elsewhere and pass the hash.
	 *
	 * ## OPTIONS
	 *
	 * --sha256=<hex>
	 * : Lowercase or uppercase hex SHA-256 (64 characters) of the secret.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learning-ledger cron secret --sha256=$(printf %s "$SECRET" | sha256sum | cut -d' ' -f1)
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function secret( array $args, array $assoc_args ): void {
		unset( $args );
		$hex = strtolower( trim( (string) ( $assoc_args['sha256'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $hex ) ) {
			\WP_CLI::error( 'The --sha256 value must be 64 hex characters.' );
		}
		update_option( Cron_Route::SECRET_OPTION, $hex, false );
		\WP_CLI::success( 'Cron secret hash saved.' );
	}
}
