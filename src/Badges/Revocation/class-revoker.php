<?php
/**
 * Badge revocation.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Revocation;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table, never cached: badge status must be read fresh.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name is interpolated.

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Badges\Status\Status_List_Repository;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

/**
 * Revokes badges: on an admin request, when the trigger statement is voided, and when the
 * learner is erased (spec §8.3, §13).
 *
 * An issued badge has its status list bit flipped first (the list is re-signed), so copies
 * already shared stop verifying; only then does its row turn `revoked`. A pending or held
 * badge just turns `revoked`, which stops it being claimed.
 */
final class Revoker {

	const REASON_ADMIN  = 'admin_request';
	const REASON_VOIDED = 'trigger_voided';
	const REASON_ERASED = 'actor_erased';

	/**
	 * Longest reason kept, in bytes.
	 */
	const MAX_REASON = 200;

	/**
	 * Times a revocation is retried when the badge changed status under it (a claim racing it).
	 */
	private const ATTEMPTS = 3;

	/**
	 * Constructor.
	 *
	 * @param Badge_Repository       $badges Badges.
	 * @param Status_List_Repository $status Status lists.
	 */
	public function __construct(
		private readonly Badge_Repository $badges = new Badge_Repository(),
		private readonly Status_List_Repository $status = new Status_List_Repository()
	) {}

	/**
	 * Revoke a badge. Revoking a revoked badge does nothing.
	 *
	 * @param string $badge_id Badge id.
	 * @param string $reason   Why (plain text, at most MAX_REASON bytes are kept).
	 *
	 * @return void
	 *
	 * @throws Invalid_Input Code `not_found` for an unknown badge, `invalid` for an empty reason.
	 * @throws \RuntimeException When the status list cannot be re-signed or the row cannot be written.
	 */
	public function revoke( string $badge_id, string $reason ): void {
		$reason = self::clean_reason( $reason );
		for ( $attempt = 0; $attempt < self::ATTEMPTS; $attempt++ ) {
			$badge = $this->badges->get( $badge_id );
			if ( null === $badge ) {
				throw new Invalid_Input( 'No badge has this id.', 'not_found', 'id' );
			}
			if ( Badge_Repository::REVOKED === $badge['status'] ) {
				return;
			}
			if ( Badge_Repository::ISSUED === $badge['status'] ) {
				if ( null === $badge['status_list_id'] || null === $badge['status_index'] ) {
					throw new \RuntimeException( 'The issued badge has no status list entry.' );
				}
				$this->status->set_revoked( $badge['status_list_id'], $badge['status_index'] );
				$changed = self::mark_revoked( $badge['id'], Badge_Repository::ISSUED, Badge_Repository::ISSUED );
			} else {
				$changed = self::mark_revoked( $badge['id'], Badge_Repository::PENDING, Badge_Repository::HELD );
			}
			if ( $changed ) {
				$this->revoked( $badge, $reason );
				return;
			}
		}
		throw new \RuntimeException( 'Could not revoke the badge.' );
	}

	/**
	 * `learning_ledger/statement_voided`: revoke the badges the voided statement earned.
	 *
	 * The void is already committed, so a failure is logged rather than thrown.
	 *
	 * @param string $target_id Id of the voided statement.
	 *
	 * @return void
	 */
	public function on_statement_voided( string $target_id ): void {
		if ( ! Uuid::is_valid( $target_id ) ) {
			return;
		}
		global $wpdb;
		$table = Tables::name( 'badges' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE trigger_statement_id = UNHEX(%s) AND status <> %s ORDER BY id", str_replace( '-', '', strtolower( $target_id ) ), Badge_Repository::REVOKED ) );
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			try {
				$this->revoke( (string) $id, self::REASON_VOIDED );
			} catch ( \Throwable $e ) {
				self::log( (string) $id, self::REASON_VOIDED, $e );
			}
		}
	}

	/**
	 * `learning_ledger/actor_erased`: revoke the learner's badges, then delete their rows
	 * (recipient hash, salt and credential with them).
	 *
	 * A badge that could not be revoked keeps its row, so that it can still be revoked: its
	 * shared copies would otherwise verify for ever. Erasing the actor again retries it.
	 * Without $revoke_badges, issued (claimed) badges stay valid and keep their rows; badges
	 * never claimed are still revoked and deleted.
	 *
	 * @param string  $actor_hash    Raw SHA-1 actor hash.
	 * @param array   $counts        Erasure counts (unused).
	 * @param boolean $revoke_badges Whether issued badges are revoked too.
	 *
	 * @return void
	 */
	public function on_actor_erased( string $actor_hash, array $counts = array(), bool $revoke_badges = true ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- the action passes the counts before the flag.
		foreach ( $this->badges->for_actor( $actor_hash ) as $badge ) {
			if ( Badge_Repository::REVOKED === $badge['status'] ) {
				continue;
			}
			if ( ! $revoke_badges && Badge_Repository::ISSUED === $badge['status'] ) {
				continue;
			}
			try {
				$this->revoke( $badge['id'], self::REASON_ERASED );
			} catch ( \Throwable $e ) {
				self::log( $badge['id'], self::REASON_ERASED, $e );
			}
		}
		global $wpdb;
		$table = Tables::name( 'badges' );
		if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE actor_hash = UNHEX(%s) AND status = %s", bin2hex( $actor_hash ), Badge_Repository::REVOKED ) ) ) {
			error_log( 'Learning Ledger: could not delete an erased actor\'s badges.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operators need to know erasure is incomplete.
		}
	}

	/**
	 * Set a badge revoked if it still has one of the given statuses.
	 *
	 * @param string $id    Badge id.
	 * @param string $from  A status it may have.
	 * @param string $or_in Another status it may have (repeat $from when there is one).
	 *
	 * @return boolean Whether the row changed.
	 *
	 * @throws \RuntimeException On a database error.
	 */
	private static function mark_revoked( string $id, string $from, string $or_in ): bool {
		global $wpdb;
		$table   = Tables::name( 'badges' );
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, revoked_at = %s WHERE id = %s AND status IN (%s, %s)",
				Badge_Repository::REVOKED,
				Timestamp::to_db( Clock::now() ),
				strtolower( $id ),
				$from,
				$or_in
			)
		);
		if ( false === $changed ) {
			throw new \RuntimeException( 'Could not revoke the badge.' );
		}
		return $changed > 0;
	}

	/**
	 * Audit and announce a revocation.
	 *
	 * @param array  $badge  Badge row as it was before.
	 * @param string $reason Reason.
	 *
	 * @return void
	 */
	private function revoked( array $badge, string $reason ): void {
		$credential_id = 'urn:uuid:' . $badge['id'];
		$data          = array(
			'badge'      => $badge['id'],
			'credential' => $credential_id,
			'reason'     => $reason,
			'from'       => $badge['status'],
		);
		if ( Badge_Repository::ISSUED === $badge['status'] ) {
			$data['status_list']  = $badge['status_list_id'];
			$data['status_index'] = $badge['status_index'];
		}
		Audit_Log::record( 'badge_revoked', $data, self::audit_actor() );

		/**
		 * Fires once when a badge is revoked.
		 *
		 * @param string $credential_id Credential id (`urn:uuid:<badge id>`).
		 * @param string $reason        Why.
		 */
		do_action( 'learning_ledger/badge_revoked', $credential_id, $reason ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
	}

	/**
	 * Plain-text reason, capped.
	 *
	 * @param string $reason Reason.
	 *
	 * @return string
	 *
	 * @throws Invalid_Input When nothing is left.
	 */
	private static function clean_reason( string $reason ): string {
		$reason = trim( mb_strcut( sanitize_text_field( $reason ), 0, self::MAX_REASON, 'UTF-8' ) );
		if ( '' === $reason ) {
			throw new Invalid_Input( 'A revocation needs a reason.', 'invalid', 'reason' );
		}
		return $reason;
	}

	/**
	 * Audit actor for the current request.
	 *
	 * @return string|null
	 */
	private static function audit_actor(): ?string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		$user = get_current_user_id();
		return $user ? 'user:' . $user : null;
	}

	/**
	 * Log a revocation a listener could not complete.
	 *
	 * @param string     $badge_id Badge id.
	 * @param string     $reason   Reason.
	 * @param \Throwable $e        Failure.
	 *
	 * @return void
	 */
	private static function log( string $badge_id, string $reason, \Throwable $e ): void {
		error_log( sprintf( 'Learning Ledger: badge %s was not revoked (%s): %s', $badge_id, $reason, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operators must revoke it by hand.
	}
}
