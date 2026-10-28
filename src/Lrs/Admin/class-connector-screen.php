<?php
/**
 * Connector state screen.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Admin;

use LearningLedger\Connector\Ledger\Apply_Ledger;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\Integration_State;
use LearningLedger\Kernel\Capabilities;

/**
 * Connector state: pairing, LMS integrations (with "confirm major version" for those paused
 * by the version gate) and results applied from statements that were later voided.
 */
final class Connector_Screen {

	public const SLUG   = 'learning-ledger-connector';
	public const CAP    = Capabilities::MANAGE_CONNECTOR;
	public const ROLE   = 'connector';
	public const ACTION = 'lledger_confirm_major';

	/**
	 * Menu and page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Connector', 'learning-ledger' );
	}

	/**
	 * Hook the confirm handler.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'confirm_major' ) );
	}

	/**
	 * Handle admin-post.php: accept the installed major version of a paused integration and resume it.
	 * The version is read from the loaded integration, never from the form.
	 *
	 * @return void
	 */
	public function confirm_major(): void {
		$id = sanitize_key( Admin_Module::field( 'integration' ) );
		Admin_Module::verify( self::CAP, self::ACTION . '_' . $id );
		$adapter = '' === $id ? null : Integration_Loader::adapter( $id );
		$version = null === $adapter ? '' : $adapter->lms_version();
		if ( '' === $version ) {
			Admin_Module::back( self::SLUG, array( 'lledger_error' => 'unknown' ) );
		}
		Integration_State::confirm_major( $id, $version );
		$entry = self::entries()[ $id ] ?? array();
		if ( Integration_State::PAUSED === ( $entry['status'] ?? '' ) && Integration_State::MAJOR === ( $entry['reason'] ?? '' ) ) {
			Integration_State::resume( $id );
		}
		Admin_Module::back( self::SLUG, array( 'lledger_done' => 'confirmed' ) );
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
			array( 'confirmed' => __( 'Major version confirmed; the integration runs again.', 'learning-ledger' ) ),
			array( 'unknown' => __( 'That integration is not loaded, so its version cannot be confirmed.', 'learning-ledger' ) )
		);
		$this->pairing();
		$this->integrations();
		$this->flagged();
		echo '</div>';
	}

	/**
	 * Pairing state, as the unprivileged status payload reports it (never the secret or its hash).
	 *
	 * @return void
	 */
	private function pairing(): void {
		$status = apply_filters( 'learning_ledger/status', array(), false ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		$fields = is_array( $status ) && is_array( $status['connector'] ?? null ) ? $status['connector'] : array();

		echo '<h2>' . esc_html__( 'Pairing', 'learning-ledger' ) . '</h2>';
		echo Admin_Module::table_open( array( __( 'Field', 'learning-ledger' ), __( 'Value', 'learning-ledger' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		if ( array() === $fields ) {
			echo Admin_Module::empty_row( 2, __( 'Not paired.', 'learning-ledger' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		foreach ( $fields as $key => $value ) {
			if ( is_bool( $value ) ) {
				$text = $value ? __( 'yes', 'learning-ledger' ) : __( 'no', 'learning-ledger' );
			} elseif ( is_scalar( $value ) ) {
				$text = (string) $value;
			} elseif ( null === $value ) {
				$text = '';
			} else {
				$text = (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
			}
			echo Admin_Module::row( array( '<code>' . esc_html( (string) $key ) . '</code>', esc_html( $text ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells escaped here.
		}
		echo '</tbody></table>';
	}

	/**
	 * LMS integrations with a recorded state.
	 *
	 * @return void
	 */
	private function integrations(): void {
		echo '<h2>' . esc_html__( 'LMS integrations', 'learning-ledger' ) . '</h2>';
		$columns = array( __( 'Integration', 'learning-ledger' ), __( 'Installed version', 'learning-ledger' ), __( 'Status', 'learning-ledger' ), __( 'Reason', 'learning-ledger' ), __( 'Notice', 'learning-ledger' ), __( 'Action', 'learning-ledger' ) );
		echo Admin_Module::table_open( $columns ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		$entries = self::entries();
		if ( array() === $entries ) {
			echo Admin_Module::empty_row( count( $columns ), __( 'No integration has run yet.', 'learning-ledger' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		foreach ( $entries as $id => $entry ) {
			$id      = (string) $id;
			$adapter = Integration_Loader::adapter( $id );
			$status  = Integration_State::status( $id );
			$action  = '';
			if ( Integration_State::PAUSED === $status && Integration_State::MAJOR === ( $entry['reason'] ?? '' ) ) {
				$action = Admin_Module::form_open( self::ACTION, self::ACTION . '_' . $id, array( 'integration' => $id ) )
					. '<button type="submit" class="button">' . esc_html__( 'Confirm major version', 'learning-ledger' ) . '</button></form>';
			}
			$cells = array(
				esc_html( $id ),
				esc_html( null === $adapter ? '' : $adapter->lms_version() ),
				esc_html( $status ),
				esc_html( (string) ( $entry['reason'] ?? '' ) ),
				esc_html( (string) ( $entry['notice'] ?? '' ) ),
				$action,
			);
			echo Admin_Module::row( $cells ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells escaped above.
		}
		echo '</tbody></table>';
	}

	/**
	 * Results applied to the LMS from statements that were later voided.
	 *
	 * @return void
	 */
	private function flagged(): void {
		echo '<h2>' . esc_html__( 'Applied results whose statement was voided', 'learning-ledger' ) . '</h2>';
		echo '<p>' . esc_html__( 'The LMS keeps these results; review them by hand.', 'learning-ledger' ) . '</p>';
		$columns = array( __( 'User', 'learning-ledger' ), __( 'Object', 'learning-ledger' ), __( 'Status', 'learning-ledger' ), __( 'Score', 'learning-ledger' ), __( 'Success', 'learning-ledger' ), __( 'Statement', 'learning-ledger' ) );
		echo Admin_Module::table_open( $columns ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		$rows = ( new Apply_Ledger() )->flagged();
		if ( array() === $rows ) {
			echo Admin_Module::empty_row( count( $columns ), __( 'None.', 'learning-ledger' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		foreach ( $rows as $r ) {
			$user  = get_userdata( $r['user_id'] );
			$cells = array(
				esc_html( ( $user ? $user->user_login : '' ) . ' (#' . $r['user_id'] . ')' ),
				esc_html( $r['object_kind'] . ' #' . $r['object_id'] ),
				esc_html( $r['status'] ),
				esc_html( null === $r['score_scaled'] ? '' : (string) $r['score_scaled'] ),
				esc_html( null === $r['success'] ? '' : ( $r['success'] ? 'true' : 'false' ) ),
				'<code>' . esc_html( $r['statement_id'] ) . '</code>',
			);
			echo Admin_Module::row( $cells ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells escaped above.
		}
		echo '</tbody></table>';
	}

	/**
	 * Recorded integration states (Integration_State's option), id => entry.
	 *
	 * @return array<string,array>
	 */
	private static function entries(): array {
		$all = get_option( Integration_State::OPTION, array() );
		return is_array( $all ) ? array_filter( $all, 'is_array' ) : array();
	}
}
