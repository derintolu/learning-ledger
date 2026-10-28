<?php
/**
 * The wp-admin screens.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Admin;

use LearningLedger\Kernel\Capabilities;
use LearningLedger\Kernel\Module;
use LearningLedger\Kernel\Roles;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Xapi\Agent_Identity;

/**
 * The "Learning Ledger" menu: one submenu per screen whose role is enabled, and the
 * admin-post.php handlers of those screens.
 *
 * Task 12 (connector role) owns the top-level menu; this module adds it only when the
 * connector role is off. Every screen class has the constants SLUG, CAP and ROLE and the
 * methods title(), hooks() and render().
 */
final class Admin_Module implements Module {

	/**
	 * Top-level menu slug (shared with Program_Module).
	 */
	public const MENU = 'learning-ledger';

	/**
	 * Screens, in menu order.
	 *
	 * @var string[]
	 */
	public const SCREENS = array(
		Statements_Screen::class,
		Learner_Screen::class,
		Clients_Screen::class,
		Subscriptions_Screen::class,
		Badges_Screen::class,
		Erase_Screen::class,
		Connector_Screen::class,
	);

	/**
	 * Screens whose role is enabled.
	 *
	 * @var object[]
	 */
	private array $screens = array();

	/**
	 * Hook the menu and the action handlers of the screens whose role is enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::SCREENS as $class ) {
			if ( Roles::is_enabled( $class::ROLE ) ) {
				$screen          = new $class();
				$this->screens[] = $screen;
				$screen->hooks();
			}
		}
		if ( array() !== $this->screens ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		}
	}

	/**
	 * Add the submenus (and the top-level menu when the connector role is off).
	 *
	 * @return void
	 */
	public function menu(): void {
		if ( ! Roles::is_enabled( 'connector' ) ) {
			add_menu_page(
				__( 'Learning Ledger', 'learning-ledger' ),
				__( 'Learning Ledger', 'learning-ledger' ),
				Capabilities::VIEW_RECORDS,
				self::MENU,
				array( $this, 'render_landing' ),
				'dashicons-welcome-learn-more',
				58
			);
		}
		foreach ( $this->screens as $screen ) {
			add_submenu_page( self::MENU, $screen->title(), $screen->title(), $screen::CAP, $screen::SLUG, array( $screen, 'render' ) );
		}
	}

	/**
	 * Landing page of the top-level menu: links to the screens the user may open.
	 *
	 * @return void
	 */
	public function render_landing(): void {
		self::require_cap( Capabilities::VIEW_RECORDS );
		echo '<div class="wrap"><h1>' . esc_html__( 'Learning Ledger', 'learning-ledger' ) . '</h1><ul>';
		foreach ( $this->screens as $screen ) {
			if ( current_user_can( $screen::CAP ) ) {
				echo '<li><a href="' . esc_url( self::url( $screen::SLUG ) ) . '">' . esc_html( $screen->title() ) . '</a></li>';
			}
		}
		echo '</ul></div>';
	}

	/**
	 * Die unless the current user holds a capability.
	 *
	 * @param string $cap Capability.
	 *
	 * @return void
	 */
	public static function require_cap( string $cap ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'learning-ledger' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Check an admin-post.php request: capability first, then the nonce (both die on failure).
	 *
	 * @param string $cap          Capability.
	 * @param string $nonce_action Nonce action the form was signed with.
	 *
	 * @return void
	 */
	public static function verify( string $cap, string $nonce_action ): void {
		self::require_cap( $cap );
		check_admin_referer( $nonce_action );
	}

	/**
	 * URL of a screen.
	 *
	 * @param string $slug Screen slug.
	 * @param array  $args Extra query arguments (raw values; they are URL-encoded here).
	 *
	 * @return string
	 */
	public static function url( string $slug, array $args = array() ): string {
		$args = array_map( static fn( $v ): string => rawurlencode( (string) $v ), array_merge( array( 'page' => $slug ), $args ) );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Redirect back to a screen after an action and stop.
	 *
	 * @param string $slug Screen slug.
	 * @param array  $args Query arguments (`lledger_done` or `lledger_error` with a fixed key).
	 *
	 * @return never
	 */
	public static function back( string $slug, array $args ): never {
		wp_safe_redirect( self::url( $slug, $args ) );
		exit;
	}

	/**
	 * A POSTed field as plain text ('' when absent). Handlers read the item id with it before
	 * verify(), since the nonce action names the item.
	 *
	 * @param string $name Field name.
	 *
	 * @return string
	 */
	public static function field( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the handler runs verify() before acting on it.
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
	}

	/**
	 * A POSTed JSON field, unslashed but otherwise unaltered ('' when absent); the caller
	 * validates it strictly.
	 *
	 * @param string $name Field name.
	 *
	 * @return string
	 */
	public static function json_field( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the handler runs verify() before acting on it; JSON must not be altered, it is validated strictly.
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? trim( wp_unslash( $_POST[ $name ] ) ) : '';
	}

	/**
	 * A GET parameter as a trimmed string ('' when absent). Only for read-only views: the
	 * value is validated by the caller and escaped on output.
	 *
	 * @param string $name Parameter name.
	 *
	 * @return string
	 */
	public static function query_arg( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only filter; JSON and IRIs must not be altered, they are validated by the caller and escaped on output.
		return isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? trim( wp_unslash( $_GET[ $name ] ) ) : '';
	}

	/**
	 * An actor given as Agent JSON.
	 *
	 * @param string $json Agent JSON.
	 *
	 * @return Agent_Identity
	 * @throws Invalid_Input When it is not JSON or not an identified Agent.
	 */
	public static function agent( string $json ): Agent_Identity {
		$agent = Json::decode_strict( $json, 65536, 8 );
		if ( ! is_array( $agent ) ) {
			throw new Invalid_Input( 'The actor must be an Agent object.', 'invalid', 'actor' );
		}
		return Agent_Identity::from_array( $agent );
	}

	/**
	 * Print the notice for `lledger_done` / `lledger_error` from a fixed map of keys to messages.
	 *
	 * @param array<string,string> $done   Success key => message.
	 * @param array<string,string> $errors Error key => message.
	 *
	 * @return void
	 */
	public static function notices( array $done, array $errors ): void {
		$key = sanitize_key( self::query_arg( 'lledger_done' ) );
		if ( isset( $done[ $key ] ) ) {
			self::notice( $done[ $key ], 'success' );
		}
		$key = sanitize_key( self::query_arg( 'lledger_error' ) );
		if ( isset( $errors[ $key ] ) ) {
			self::notice( $errors[ $key ], 'error' );
		}
	}

	/**
	 * Print one notice.
	 *
	 * @param string $message Plain text.
	 * @param string $type    Notice type: success, error or warning.
	 *
	 * @return void
	 */
	public static function notice( string $message, string $type ): void {
		printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/**
	 * Hidden fields and opening tag of an admin-post.php form.
	 *
	 * @param string $action       The admin-post action.
	 * @param string $nonce_action Nonce action.
	 * @param array  $hidden       Extra hidden fields.
	 *
	 * @return string
	 */
	public static function form_open( string $action, string $nonce_action, array $hidden = array() ): string {
		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. wp_nonce_field( $nonce_action, '_wpnonce', true, false );
		foreach ( $hidden as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		return $html;
	}

	/**
	 * Open a `widefat striped` list table with a header row.
	 *
	 * @param string[] $columns Column labels.
	 *
	 * @return string
	 */
	public static function table_open( array $columns ): string {
		$html = '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( $columns as $label ) {
			$html .= '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		return $html . '</tr></thead><tbody>';
	}

	/**
	 * A table row from cells that are already escaped HTML.
	 *
	 * @param string[] $cells Escaped cell HTML.
	 *
	 * @return string
	 */
	public static function row( array $cells ): string {
		return '<tr><td>' . implode( '</td><td>', $cells ) . '</td></tr>';
	}

	/**
	 * Empty-table row.
	 *
	 * @param integer $span    Columns.
	 * @param string  $message Plain text.
	 *
	 * @return string
	 */
	public static function empty_row( int $span, string $message ): string {
		return '<tr class="no-items"><td colspan="' . (int) $span . '">' . esc_html( $message ) . '</td></tr>';
	}
}
