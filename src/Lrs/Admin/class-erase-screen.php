<?php
/**
 * Erase-actor screen.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Admin;

use LearningLedger\Kernel\Capabilities;
use LearningLedger\Lrs\Privacy\Actor_Eraser;
use LearningLedger\Util\Invalid_Input;

/**
 * GDPR erasure of one actor: the form, a confirmation step, then the erasure.
 * The confirmation form's nonce is bound to the actor's hash.
 */
final class Erase_Screen {

	public const SLUG    = 'learning-ledger-erase';
	public const CAP     = Capabilities::MANAGE;
	public const ROLE    = 'lrs';
	public const ACTION  = 'lledger_erase_actor';
	public const CONFIRM = 'erase';

	/**
	 * Counts reported after an erasure.
	 *
	 * @var string[]
	 */
	private const COUNTS = array( 'statements', 'refs', 'documents', 'agents', 'tokens', 'links' );

	/**
	 * Menu and page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Erase actor', 'learning-ledger' );
	}

	/**
	 * Hook the erase handler.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'erase' ) );
	}

	/**
	 * Nonce action for erasing one actor.
	 *
	 * @param string $hash Raw actor hash.
	 *
	 * @return string
	 */
	public static function nonce_action( string $hash ): string {
		return self::ACTION . '_' . bin2hex( $hash );
	}

	/**
	 * Handle admin-post.php: erase the confirmed actor.
	 *
	 * @return void
	 */
	public function erase(): void {
		try {
			$actor = Admin_Module::agent( Admin_Module::json_field( 'actor' ) );
			$hash  = $actor->hash();
		} catch ( Invalid_Input $e ) {
			$actor = null;
			$hash  = '';
		}
		Admin_Module::verify( self::CAP, self::nonce_action( $hash ) );
		if ( null === $actor ) {
			Admin_Module::back( self::SLUG, array( 'lledger_error' => 'invalid' ) );
		}
		if ( self::CONFIRM !== Admin_Module::field( 'confirm' ) ) {
			Admin_Module::back( self::SLUG, array( 'lledger_error' => 'confirm' ) );
		}

		$result = array( 'lledger_done' => 'erased' );
		try {
			$counts = ( new Actor_Eraser() )->erase( $actor, 'user:' . get_current_user_id() );
			foreach ( self::COUNTS as $key ) {
				$result[ $key ] = (int) ( $counts[ $key ] ?? 0 );
			}
		} catch ( \Throwable $e ) {
			$result = array( 'lledger_error' => 'failed' );
		}
		Admin_Module::back( self::SLUG, $result );
	}

	/**
	 * Render the screen: the form, or the confirmation step when an actor is given.
	 *
	 * @return void
	 */
	public function render(): void {
		Admin_Module::require_cap( self::CAP );
		$json = Admin_Module::query_arg( 'actor' );

		echo '<div class="wrap"><h1>' . esc_html( $this->title() ) . '</h1>';
		$counts = array();
		foreach ( self::COUNTS as $key ) {
			$counts[] = $key . ': ' . absint( Admin_Module::query_arg( $key ) );
		}
		Admin_Module::notices(
			array(
				/* translators: %s: rows removed per kind. */
				'erased' => sprintf( __( 'Actor erased (%s).', 'learning-ledger' ), implode( ', ', $counts ) ),
			),
			array(
				'invalid' => __( 'The actor must be an identified Agent as JSON.', 'learning-ledger' ),
				'confirm' => __( 'The erasure was not confirmed. Nothing was erased.', 'learning-ledger' ),
				'failed'  => __( 'The erasure failed and was rolled back. Nothing was erased.', 'learning-ledger' ),
			)
		);

		if ( '' !== $json ) {
			try {
				$actor = Admin_Module::agent( $json );
				$this->confirmation( $json, $actor->hash() );
				echo '</div>';
				return;
			} catch ( Invalid_Input $e ) {
				Admin_Module::notice( $e->getMessage(), 'error' );
			}
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<p><label for="lledger-actor">' . esc_html__( 'Actor (Agent JSON)', 'learning-ledger' ) . '</label><br>';
		echo '<textarea class="large-text code" rows="3" id="lledger-actor" name="actor" placeholder="' . esc_attr( '{"mbox":"mailto:learner@example.org"}' ) . '">' . esc_textarea( $json ) . '</textarea></p>';
		submit_button( __( 'Review erasure', 'learning-ledger' ), 'secondary', '', false );
		echo '</form></div>';
	}

	/**
	 * Confirmation step.
	 *
	 * @param string $json Actor JSON as given.
	 * @param string $hash Raw actor hash.
	 *
	 * @return void
	 */
	private function confirmation( string $json, string $hash ): void {
		echo '<h2>' . esc_html__( 'Confirm erasure', 'learning-ledger' ) . '</h2>';
		echo '<p>' . esc_html__( 'This permanently deletes every statement, document, token and record link of this actor, and revokes their badges. It cannot be undone.', 'learning-ledger' ) . '</p>';
		echo Statements_Screen::json( $json ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		$hidden = array(
			'actor'   => $json,
			'confirm' => self::CONFIRM,
		);
		echo Admin_Module::form_open( self::ACTION, self::nonce_action( $hash ), $hidden ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		submit_button( __( 'Erase this actor', 'learning-ledger' ), 'delete', 'submit', false );
		echo ' <a class="button" href="' . esc_url( Admin_Module::url( self::SLUG ) ) . '">' . esc_html__( 'Cancel', 'learning-ledger' ) . '</a></form>';
	}
}
