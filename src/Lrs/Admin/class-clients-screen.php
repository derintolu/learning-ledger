<?php
/**
 * Clients screen.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Admin;

use LearningLedger\Kernel\Capabilities;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Clients\Clients_Module;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;

/**
 * List LRS clients and revoke them. No secret or secret hash is ever shown.
 */
final class Clients_Screen {

	public const SLUG   = 'learning-ledger-clients';
	public const CAP    = Capabilities::MANAGE;
	public const ROLE   = 'lrs';
	public const ACTION = 'lledger_revoke_client';

	/**
	 * Menu and page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Clients', 'learning-ledger' );
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
	 * Handle admin-post.php: revoke one client.
	 *
	 * @return void
	 */
	public function revoke(): void {
		$id = Admin_Module::field( 'client' );
		Admin_Module::verify( self::CAP, self::ACTION . '_' . $id );
		$result = array( 'lledger_done' => 'revoked' );
		try {
			Clients_Module::revoke( $id, 'user:' . get_current_user_id() );
		} catch ( Invalid_Input $e ) {
			$result = array( 'lledger_error' => 'not_found' );
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
		echo '<div class="wrap"><h1>' . esc_html( $this->title() ) . '</h1>';
		Admin_Module::notices(
			array( 'revoked' => __( 'Client revoked. Its child tokens stop working with it.', 'learning-ledger' ) ),
			array( 'not_found' => __( 'No such client.', 'learning-ledger' ) )
		);
		echo '<p>' . esc_html__( 'Create and change clients with WP-CLI (wp learning-ledger client) or the REST API; the secret is shown only once, when it is created.', 'learning-ledger' ) . '</p>';

		$columns = array( __( 'ID', 'learning-ledger' ), __( 'Name', 'learning-ledger' ), __( 'Scopes', 'learning-ledger' ), __( 'Trusted', 'learning-ledger' ), __( 'Created', 'learning-ledger' ), __( 'Expires', 'learning-ledger' ), __( 'Status', 'learning-ledger' ), __( 'Revoke', 'learning-ledger' ) );
		echo Admin_Module::table_open( $columns ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		$clients = ( new Client_Repository() )->all();
		if ( array() === $clients ) {
			echo Admin_Module::empty_row( count( $columns ), __( 'No clients.', 'learning-ledger' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		$now = Clock::now();
		foreach ( $clients as $c ) {
			if ( null !== $c->revoked_at ) {
				$status = __( 'revoked', 'learning-ledger' );
			} elseif ( $c->is_active( $now ) ) {
				$status = __( 'active', 'learning-ledger' );
			} else {
				$status = __( 'expired', 'learning-ledger' );
			}
			$cells = array(
				'<code>' . esc_html( $c->id ) . '</code>',
				esc_html( $c->name ),
				esc_html( implode( ', ', $c->scopes ) ),
				esc_html( $c->trusted ? __( 'yes', 'learning-ledger' ) : __( 'no', 'learning-ledger' ) ),
				esc_html( Timestamp::to_xapi( $c->created_at ) ),
				esc_html( null === $c->expires_at ? '' : Timestamp::to_xapi( $c->expires_at ) ),
				esc_html( $status ),
				null !== $c->revoked_at ? '' : Admin_Module::form_open( self::ACTION, self::ACTION . '_' . $c->id, array( 'client' => $c->id ) )
					. '<button type="submit" class="button button-link-delete">' . esc_html__( 'Revoke', 'learning-ledger' ) . '</button></form>',
			);
			echo Admin_Module::row( $cells ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells escaped above.
		}
		echo '</tbody></table></div>';
	}
}
