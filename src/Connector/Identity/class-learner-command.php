<?php
/**
 * WP-CLI commands for learners.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Identity;

/**
 * Manage Learning Ledger learner records.
 */
final class Learner_Command {

	/**
	 * Fetches the LRS statements of every identity a user has linked, for links older than the
	 * 30-day unmatched window.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : WordPress user id.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function backfill( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$user = (int) ( $args[0] ?? 0 );
		if ( $user < 1 || false === get_userdata( $user ) ) {
			\WP_CLI::error( 'No user with that id.' );
		}
		$links = ( new Identity_Map() )->links_for_user( $user );
		if ( array() === $links ) {
			\WP_CLI::error( 'That user has no linked identity.' );
		}
		$backfill = new Backfill();
		$added    = 0;
		foreach ( $links as $link ) {
			if ( ! is_array( $link['actor'] ) ) {
				continue;
			}
			try {
				$added += $backfill->run( $user, $link['actor'] );
			} catch ( \RuntimeException $e ) {
				\WP_CLI::error( $e->getMessage() );
			}
		}
		\WP_CLI::success( sprintf( 'Added %d statements to the inbox.', $added ) );
	}
}
