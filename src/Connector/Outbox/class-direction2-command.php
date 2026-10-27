<?php
/**
 * WP-CLI command for the direction-2 setting.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Outbox;

/**
 * Manage per-course connector settings.
 */
final class Direction2_Command {

	/**
	 * Turns sending a course's on-site learning to the LRS on or off.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : LMS course post id.
	 *
	 * <state>
	 * : on or off.
	 * ---
	 * options:
	 *   - on
	 *   - off
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp learning-ledger course direction2 42 off
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function direction2( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$id    = (int) ( $args[0] ?? 0 );
		$state = (string) ( $args[1] ?? '' );
		if ( $id <= 0 || null === get_post( $id ) ) {
			\WP_CLI::error( 'No post with that id.' );
		}
		if ( 'on' !== $state && 'off' !== $state ) {
			\WP_CLI::error( 'State must be "on" or "off".' );
		}
		if ( 'on' === $state && Direction2::is_forced_off( $id ) ) {
			\WP_CLI::error( 'Direction 2 is always off for a course in a cmi5 player program.' );
		}
		Direction2::set( $id, $state );
		\WP_CLI::success( sprintf( 'Direction 2 is %s for course %d.', Direction2::is_on( $id ) ? 'on' : 'off', $id ) );
	}
}
