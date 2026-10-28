<?php
/**
 * Subscriptions screen.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Admin;

use LearningLedger\Kernel\Capabilities;
use LearningLedger\Lrs\Notify\Notify_Module;
use LearningLedger\Lrs\Notify\Subscription_Repository;
use LearningLedger\Util\Invalid_Input;

/**
 * List webhook subscriptions with their delivery status, and delete them.
 */
final class Subscriptions_Screen {

	public const SLUG   = 'learning-ledger-subscriptions';
	public const CAP    = Capabilities::MANAGE;
	public const ROLE   = 'lrs';
	public const ACTION = 'lledger_delete_subscription';

	/**
	 * Menu and page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Subscriptions', 'learning-ledger' );
	}

	/**
	 * Hook the delete handler.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'delete' ) );
	}

	/**
	 * Handle admin-post.php: delete one subscription.
	 *
	 * @return void
	 */
	public function delete(): void {
		$id = absint( Admin_Module::field( 'subscription' ) );
		Admin_Module::verify( self::CAP, self::ACTION . '_' . $id );
		$result = array( 'lledger_done' => 'deleted' );
		try {
			Notify_Module::delete( $id, 'user:' . get_current_user_id() );
		} catch ( Invalid_Input $e ) {
			$result = array( 'lledger_error' => 'not_found' );
		} catch ( \RuntimeException $e ) {
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
		echo '<div class="wrap"><h1>' . esc_html( $this->title() ) . '</h1>';
		Admin_Module::notices(
			array( 'deleted' => __( 'Subscription deleted, with its pending deliveries.', 'learning-ledger' ) ),
			array(
				'not_found' => __( 'No such subscription.', 'learning-ledger' ),
				'failed'    => __( 'The subscription could not be deleted. Nothing was changed.', 'learning-ledger' ),
			)
		);

		$columns = array( __( 'ID', 'learning-ledger' ), __( 'Target URL', 'learning-ledger' ), __( 'Filters', 'learning-ledger' ), __( 'Status', 'learning-ledger' ), __( 'Failing since', 'learning-ledger' ), __( 'Delivered up to', 'learning-ledger' ), __( 'Created', 'learning-ledger' ), __( 'Delete', 'learning-ledger' ) );
		echo Admin_Module::table_open( $columns ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		$subs = ( new Subscription_Repository() )->all();
		if ( array() === $subs ) {
			echo Admin_Module::empty_row( count( $columns ), __( 'No subscriptions.', 'learning-ledger' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		foreach ( $subs as $s ) {
			$cells = array(
				esc_html( (string) $s['id'] ),
				'<code>' . esc_html( $s['url'] ) . '</code>',
				'<code>' . esc_html( (string) wp_json_encode( (object) $s['filters'], JSON_UNESCAPED_SLASHES ) ) . '</code>',
				esc_html( $s['status'] ),
				esc_html( (string) ( $s['failing_since'] ?? '' ) ),
				esc_html( (string) $s['cursor'] ),
				esc_html( $s['created_at'] ),
				Admin_Module::form_open( self::ACTION, self::ACTION . '_' . $s['id'], array( 'subscription' => $s['id'] ) )
					. '<button type="submit" class="button button-link-delete">' . esc_html__( 'Delete', 'learning-ledger' ) . '</button></form>',
			);
			echo Admin_Module::row( $cells ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells escaped above.
		}
		echo '</tbody></table></div>';
	}
}
