<?php
/**
 * Learner screen.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Admin;

use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Kernel\Capabilities;
use LearningLedger\Util\Invalid_Input;

/**
 * One actor's record: their badges and their statements, 50 per page.
 */
final class Learner_Screen {

	public const SLUG = 'learning-ledger-learner';
	public const CAP  = Capabilities::VIEW_RECORDS;
	public const ROLE = 'lrs';

	/**
	 * Menu and page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Learner', 'learning-ledger' );
	}

	/**
	 * No actions on this screen.
	 *
	 * @return void
	 */
	public function hooks(): void {}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		Admin_Module::require_cap( self::CAP );
		$json = Admin_Module::query_arg( 'agent' );

		echo '<div class="wrap"><h1>' . esc_html( $this->title() ) . '</h1>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<p><label for="lledger-agent">' . esc_html__( 'Actor (Agent JSON)', 'learning-ledger' ) . '</label><br>';
		echo '<textarea class="large-text code" rows="3" id="lledger-agent" name="agent" placeholder="' . esc_attr( '{"account":{"homePage":"https://example.org","name":"42"}}' ) . '">' . esc_textarea( $json ) . '</textarea></p>';
		submit_button( __( 'Show record', 'learning-ledger' ), 'secondary', '', false );
		echo '</form>';

		if ( '' === $json ) {
			echo '</div>';
			return;
		}
		try {
			$actor = Admin_Module::agent( $json );
		} catch ( Invalid_Input $e ) {
			Admin_Module::notice( $e->getMessage(), 'error' );
			echo '</div>';
			return;
		}

		if ( current_user_can( Erase_Screen::CAP ) ) {
			echo '<p><a class="button button-link-delete" href="' . esc_url( Admin_Module::url( Erase_Screen::SLUG, array( 'actor' => $json ) ) ) . '">' . esc_html__( 'Erase this actor', 'learning-ledger' ) . '</a></p>';
		}

		echo '<h2>' . esc_html__( 'Badges', 'learning-ledger' ) . '</h2>';
		echo Badges_Screen::table( ( new Badge_Repository() )->for_actor( $actor->hash() ), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.

		echo '<h2>' . esc_html__( 'Statements', 'learning-ledger' ) . '</h2>';
		Statements_Screen::results( array( 'agent' => $json ), self::SLUG, array( 'agent' => $json ) );
		echo '</div>';
	}
}
