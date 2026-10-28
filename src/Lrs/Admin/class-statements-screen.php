<?php
/**
 * Statements screen.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Admin;

use LearningLedger\Kernel\Capabilities;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Statements\More_Cursor;
use LearningLedger\Lrs\Statements\Query_Params;
use LearningLedger\Lrs\Statements\Statement_Query;
use LearningLedger\Lrs\Statements\Stored_Statement;

/**
 * Browse stored statements, 50 per page, filtered by agent, verb, activity and registration.
 * Statement JSON is only ever printed escaped, inside `<pre>`.
 */
final class Statements_Screen {

	public const SLUG     = 'learning-ledger-statements';
	public const CAP      = Capabilities::VIEW_RECORDS;
	public const ROLE     = 'lrs';
	public const PER_PAGE = 50;

	/**
	 * Filters, as statements GET parameters.
	 *
	 * @var string[]
	 */
	public const FILTERS = array( 'agent', 'verb', 'activity', 'registration' );

	/**
	 * Menu and page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Statements', 'learning-ledger' );
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
		$filters = array();
		foreach ( self::FILTERS as $name ) {
			$value = Admin_Module::query_arg( $name );
			if ( '' !== $value ) {
				$filters[ $name ] = $value;
			}
		}

		echo '<div class="wrap"><h1>' . esc_html( $this->title() ) . '</h1>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><table class="form-table" role="presentation">';
		$labels = array(
			'agent'        => __( 'Agent (JSON)', 'learning-ledger' ),
			'verb'         => __( 'Verb IRI', 'learning-ledger' ),
			'activity'     => __( 'Activity IRI', 'learning-ledger' ),
			'registration' => __( 'Registration', 'learning-ledger' ),
		);
		foreach ( $labels as $name => $label ) {
			printf(
				'<tr><th scope="row"><label for="lledger-%1$s">%2$s</label></th><td><input type="text" class="large-text code" id="lledger-%1$s" name="%1$s" value="%3$s"></td></tr>',
				esc_attr( $name ),
				esc_html( $label ),
				esc_attr( $filters[ $name ] ?? '' )
			);
		}
		echo '</table>';
		submit_button( __( 'Filter', 'learning-ledger' ), 'secondary', '', false );
		echo '</form>';

		self::results( $filters, self::SLUG, $filters );
		echo '</div>';
	}

	/**
	 * Print one page of statements matching the filters, with a link to the next page.
	 *
	 * @param array<string,string> $filters Statements GET parameters.
	 * @param string               $slug    Screen the next-page link points at.
	 * @param array<string,string> $keep    Query arguments the next-page link keeps.
	 *
	 * @return void
	 */
	public static function results( array $filters, string $slug, array $keep ): void {
		try {
			$page = self::page( $filters, Admin_Module::query_arg( 'more' ) );
		} catch ( Xapi_Error $e ) {
			Admin_Module::notice( $e->getMessage(), 'error' );
			return;
		}

		echo Admin_Module::table_open( array( __( 'Stored', 'learning-ledger' ), __( 'Actor', 'learning-ledger' ), __( 'Verb', 'learning-ledger' ), __( 'Object', 'learning-ledger' ), __( 'Registration', 'learning-ledger' ), __( 'Statement', 'learning-ledger' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		if ( array() === $page['rows'] ) {
			echo Admin_Module::empty_row( 6, __( 'No statements found.', 'learning-ledger' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		foreach ( $page['rows'] as $row ) {
			echo self::row( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		echo '</tbody></table>';

		if ( null !== $page['more'] ) {
			$next = Admin_Module::url( $slug, array_merge( $keep, array( 'more' => $page['more'] ) ) );
			echo '<p><a class="button" href="' . esc_url( $next ) . '">' . esc_html__( 'Older statements', 'learning-ledger' ) . '</a></p>';
		}
	}

	/**
	 * One page of statements, read as the LRS itself (every statement, no binding).
	 *
	 * @param array<string,string> $filters Statements GET parameters.
	 * @param string               $more    The `more` token of the previous page, or ''.
	 *
	 * @return array{rows:Stored_Statement[],more:?string}
	 * @throws Xapi_Error 400 for an invalid filter or token.
	 */
	public static function page( array $filters, string $more ): array {
		$cursor = null;
		if ( '' !== $more ) {
			$cursor = More_Cursor::decode( $more );
			$query  = Query_Params::from_cursor( $cursor );
		} else {
			$params          = array_intersect_key( $filters, array_flip( self::FILTERS ) );
			$params['limit'] = (string) self::PER_PAGE;
			$query           = Query_Params::from_request( new Xapi_Request( 'GET', 'statements', $params, array(), '', '2.0.0' ) );
		}
		$result = ( new Statement_Query() )->run( $query, Principal::system(), $cursor );
		return array(
			'rows' => $result['rows'],
			'more' => $result['more'],
		);
	}

	/**
	 * Table row of a statement.
	 *
	 * @param Stored_Statement $row Statement.
	 *
	 * @return string Escaped HTML.
	 */
	private static function row( Stored_Statement $row ): string {
		$st = json_decode( $row->body, true );
		$st = is_array( $st ) ? $st : array();
		return Admin_Module::row(
			array(
				esc_html( $row->stored ),
				esc_html( self::actor_label( is_array( $st['actor'] ?? null ) ? $st['actor'] : array() ) ),
				'<code>' . esc_html( (string) ( $st['verb']['id'] ?? '' ) ) . '</code>',
				'<code>' . esc_html( (string) ( $st['object']['id'] ?? ( $st['object']['objectType'] ?? '' ) ) ) . '</code>',
				esc_html( $row->registration ?? '' ),
				'<details><summary>' . esc_html( $row->id ) . '</summary>' . self::json( $row->body ) . '</details>',
			)
		);
	}

	/**
	 * Statement JSON, pretty-printed and escaped, inside `<pre>`.
	 *
	 * @param string $body Stored statement JSON.
	 *
	 * @return string Escaped HTML.
	 */
	public static function json( string $body ): string {
		$decoded = json_decode( $body );
		$pretty  = null === $decoded ? $body : (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return '<pre style="white-space:pre-wrap;max-height:30em;overflow:auto">' . esc_html( $pretty ) . '</pre>';
	}

	/**
	 * Short label of an actor: name, else its identifier.
	 *
	 * @param array $actor Actor.
	 *
	 * @return string Plain text.
	 */
	private static function actor_label( array $actor ): string {
		if ( isset( $actor['name'] ) && is_string( $actor['name'] ) ) {
			return $actor['name'];
		}
		if ( isset( $actor['account']['name'] ) && is_string( $actor['account']['name'] ) ) {
			return $actor['account']['name'] . ' (' . (string) ( $actor['account']['homePage'] ?? '' ) . ')';
		}
		foreach ( array( 'mbox', 'openid', 'mbox_sha1sum' ) as $key ) {
			if ( isset( $actor[ $key ] ) && is_string( $actor[ $key ] ) ) {
				return $actor[ $key ];
			}
		}
		return (string) ( $actor['objectType'] ?? '' );
	}
}
