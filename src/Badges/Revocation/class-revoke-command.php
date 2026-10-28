<?php
/**
 * WP-CLI: badge revocation and re-issue.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Revocation;

/**
 * `wp learning-ledger badges revoke` and `badges reissue`, plus the hooks that keep status
 * lists signed by the active key around `badges rotate` and `badges remove-key`.
 */
final class Revoke_Command {

	/**
	 * Constructor.
	 *
	 * @param Revoker  $revoker  Revoker.
	 * @param Reissuer $reissuer Reissuer.
	 */
	public function __construct(
		private readonly Revoker $revoker = new Revoker(),
		private readonly Reissuer $reissuer = new Reissuer()
	) {}

	/**
	 * Revoke a badge. An issued badge's status list bit is flipped, so shared copies stop verifying.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Badge id (UUID).
	 *
	 * [--reason=<reason>]
	 * : Why the badge is revoked.
	 * ---
	 * default: admin_request
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp learning-ledger badges revoke 6f1c3c1e-9a5e-4c1b-8d4e-2b7f0c9a1d11 --reason="Issued in error"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function revoke( array $args, array $assoc_args ): void {
		$id     = (string) ( $args[0] ?? '' );
		$reason = (string) ( $assoc_args['reason'] ?? Revoker::REASON_ADMIN );
		try {
			$this->revoker->revoke( $id, $reason );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( 'Revoked badge ' . $id );
	}

	/**
	 * Re-issue every badge signed by a removed key, with the active key.
	 *
	 * Each badge keeps its id and gets a newer validFrom. Status lists are re-signed too.
	 *
	 * ## OPTIONS
	 *
	 * --kid=<kid>
	 * : Id of the removed key.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learning-ledger badges reissue --kid=0123456789abcdef
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function reissue( array $args, array $assoc_args ): void {
		try {
			$count = $this->reissuer->reissue_for_key( (string) ( $assoc_args['kid'] ?? '' ) );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( sprintf( 'Re-issued %d badges.', $count ) );
	}

	/**
	 * Before `badges remove-key <kid>`: move the status lists onto the active key, or stop the
	 * removal when the key is the only one that could sign them.
	 *
	 * @return void
	 */
	public function before_remove_key(): void {
		$argv = (array) \WP_CLI::get_runner()->arguments;
		$at   = array_search( 'remove-key', $argv, true );
		$kid  = false === $at ? '' : (string) ( $argv[ $at + 1 ] ?? '' );
		try {
			$this->reissuer->prepare_key_removal( $kid );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * After `badges rotate`: re-sign the status lists with the new key.
	 *
	 * @return void
	 */
	public function after_rotate(): void {
		try {
			$count = $this->reissuer->resign_status_lists();
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			\WP_CLI::warning( 'Status lists were not re-signed with the new key: ' . $e->getMessage() . ' `badges remove-key` re-signs them before it removes the previous key.' );
			return;
		}
		\WP_CLI::log( sprintf( 'Re-signed %d status lists with the new key.', $count ) );
	}
}
