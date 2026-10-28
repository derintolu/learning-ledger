<?php
/**
 * Badges screen.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Admin;

use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Badges\Revocation\Revoker;
use LearningLedger\Db\Tables;
use LearningLedger\Kernel\Capabilities;
use LearningLedger\Util\Invalid_Input;

/**
 * List badges (50 per page), the held cases (recomputation disagreed with the trigger), and revoke.
 */
final class Badges_Screen {

	public const SLUG     = 'learning-ledger-badges';
	public const CAP      = Capabilities::MANAGE_BADGES;
	public const ROLE     = 'lrs';
	public const ACTION   = 'lledger_revoke_badge';
	public const PER_PAGE = 50;

	/**
	 * Menu and page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Badges', 'learning-ledger' );
	}

	/**
	 * Hook the revoke handler.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'revoke' ) );
	}

	/**
	 * Handle admin-post.php: revoke one badge.
	 *
	 * @return void
	 */
	public function revoke(): void {
		$id = Admin_Module::field( 'badge' );
		Admin_Module::verify( self::CAP, self::ACTION . '_' . $id );
		$reason = Admin_Module::field( 'reason' );
		$result = array( 'lledger_done' => 'revoked' );
		try {
			( new Revoker() )->revoke( $id, '' === $reason ? Revoker::REASON_ADMIN : $reason );
		} catch ( Invalid_Input $e ) {
			$result = array( 'lledger_error' => 'not_found' === $e->error_code() ? 'not_found' : 'invalid' );
		} catch ( \Throwable $e ) {
			$result = array( 'lledger_error' => 'failed' );
		}
		Admin_Module::back( self::SLUG, $result );
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		Admin_Module::require_cap( self::CAP );
		$paged  = max( 1, absint( Admin_Module::query_arg( 'paged' ) ) );
		$badges = new Badge_Repository();

		echo '<div class="wrap"><h1>' . esc_html( $this->title() ) . '</h1>';
		Admin_Module::notices(
			array( 'revoked' => __( 'Badge revoked.', 'learning-ledger' ) ),
			array(
				'not_found' => __( 'No badge has this id.', 'learning-ledger' ),
				'invalid'   => __( 'The revocation was refused.', 'learning-ledger' ),
				'failed'    => __( 'The badge could not be revoked. Nothing was changed.', 'learning-ledger' ),
			)
		);

		$held = array_filter( array_map( array( $badges, 'get' ), self::ids( Badge_Repository::HELD, self::PER_PAGE, 0 ) ) );
		echo '<h2 id="lledger-held">' . esc_html(
			sprintf(
				/* translators: %d: number of held badges. */
				__( 'Held cases (%d)', 'learning-ledger' ),
				$badges->count_with_status( Badge_Repository::HELD )
			)
		) . '</h2>';
		echo '<p>' . esc_html__( 'The LRS recomputed these and did not agree with the statement that triggered them, so nothing was issued.', 'learning-ledger' ) . '</p>';
		echo self::table( $held, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.

		$ids  = self::ids( null, self::PER_PAGE + 1, ( $paged - 1 ) * self::PER_PAGE );
		$more = count( $ids ) > self::PER_PAGE;
		$all  = array_filter( array_map( array( $badges, 'get' ), array_slice( $ids, 0, self::PER_PAGE ) ) );
		echo '<h2 id="lledger-all">' . esc_html__( 'All badges', 'learning-ledger' ) . '</h2>';
		echo self::table( $all, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo '<p>';
		if ( $paged > 1 ) {
			echo '<a class="button" href="' . esc_url( Admin_Module::url( self::SLUG, array( 'paged' => $paged - 1 ) ) ) . '">' . esc_html__( 'Newer', 'learning-ledger' ) . '</a> ';
		}
		if ( $more ) {
			echo '<a class="button" href="' . esc_url( Admin_Module::url( self::SLUG, array( 'paged' => $paged + 1 ) ) ) . '">' . esc_html__( 'Older', 'learning-ledger' ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Table of badges, with a revoke form on each badge not yet revoked when asked.
	 *
	 * @param array[] $rows        Badges from Badge_Repository::get().
	 * @param boolean $with_revoke Whether to add revoke forms.
	 *
	 * @return string Escaped HTML.
	 */
	public static function table( array $rows, bool $with_revoke ): string {
		$columns = array( __( 'Badge', 'learning-ledger' ), __( 'Achievement', 'learning-ledger' ), __( 'Status', 'learning-ledger' ), __( 'Registration', 'learning-ledger' ), __( 'Created', 'learning-ledger' ), __( 'Issued', 'learning-ledger' ), __( 'Revoked', 'learning-ledger' ) );
		if ( $with_revoke ) {
			$columns[] = __( 'Revoke', 'learning-ledger' );
		}
		$html = Admin_Module::table_open( $columns );
		if ( array() === $rows ) {
			$html .= Admin_Module::empty_row( count( $columns ), __( 'No badges.', 'learning-ledger' ) );
		}
		foreach ( $rows as $b ) {
			$cells = array(
				'<code>' . esc_html( (string) $b['id'] ) . '</code>',
				esc_html( (string) ( $b['achievement']['name'] ?? ( $b['achievement']['id'] ?? '' ) ) ),
				esc_html( (string) $b['status'] ),
				esc_html( (string) $b['registration'] ),
				esc_html( (string) $b['created_at'] ),
				esc_html( (string) ( $b['issued_at'] ?? '' ) ),
				esc_html( (string) ( $b['revoked_at'] ?? '' ) ),
			);
			if ( $with_revoke ) {
				$cells[] = Badge_Repository::REVOKED === $b['status'] ? '' : self::revoke_form( (string) $b['id'] );
			}
			$html .= Admin_Module::row( $cells );
		}
		return $html . '</tbody></table>';
	}

	/**
	 * Revoke form for one badge.
	 *
	 * @param string $id Badge id.
	 *
	 * @return string Escaped HTML.
	 */
	private static function revoke_form( string $id ): string {
		return Admin_Module::form_open( self::ACTION, self::ACTION . '_' . $id, array( 'badge' => $id ) )
			. '<label class="screen-reader-text" for="lledger-reason-' . esc_attr( $id ) . '">' . esc_html__( 'Reason', 'learning-ledger' ) . '</label>'
			. '<input type="text" id="lledger-reason-' . esc_attr( $id ) . '" name="reason" style="max-width:100%" placeholder="' . esc_attr( Revoker::REASON_ADMIN ) . '" maxlength="' . (int) Revoker::MAX_REASON . '"> '
			. '<button type="submit" class="button button-link-delete">' . esc_html__( 'Revoke', 'learning-ledger' ) . '</button></form>';
	}

	/**
	 * Badge ids, newest first (held cases oldest first).
	 *
	 * @param string|null $status Only this status, or null for all.
	 * @param integer     $limit  Rows.
	 * @param integer     $offset Offset.
	 *
	 * @return string[]
	 */
	private static function ids( ?string $status, int $limit, int $offset ): array {
		global $wpdb;
		$table = Tables::name( 'badges' );
		if ( null === $status ) {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', $table, $limit, $offset ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, admin view of current rows.
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s ORDER BY created_at, id LIMIT %d OFFSET %d', $table, $status, $limit, $offset ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, admin view of current rows.
		}
		return array_map( 'strval', is_array( $ids ) ? $ids : array() );
	}
}
