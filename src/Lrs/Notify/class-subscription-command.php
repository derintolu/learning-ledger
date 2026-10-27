<?php
/**
 * `wp learning-ledger subscription ...`.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Notify;

use LearningLedger\Util\Invalid_Input;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages go to WP-CLI only, never into HTML.

/**
 * Manages webhook subscriptions.
 */
final class Subscription_Command {

	const ACTOR = 'cli';

	/**
	 * Create a subscription.
	 *
	 * ## OPTIONS
	 *
	 * --url=<url>
	 * : Target URL (the connector's inbox), https.
	 *
	 * [--actor-home-page=<iri>]
	 * : Only statements whose actor account has this homePage.
	 *
	 * [--activity-prefix=<iri>]
	 * : Only statements whose Activity object id starts with this.
	 *
	 * [--verb=<csv>]
	 * : Only these verb IRIs.
	 *
	 * [--registration=<csv>]
	 * : Only these registrations.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function create( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		// `--url` is also a WP-CLI global flag, which WP-CLI takes before the command sees it.
		if ( ! isset( $assoc_args['url'] ) ) {
			$assoc_args['url'] = (string) ( \WP_CLI::get_runner()->config['url'] ?? '' );
		}
		try {
			$id = self::create_subscription( $assoc_args );
		} catch ( Invalid_Input $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( 'Created subscription ' . $id . '.' );
	}

	/**
	 * List subscriptions.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), self::rows(), array( 'id', 'url', 'filters', 'status', 'failing_since', 'cursor', 'created_at' ) );
	}

	/**
	 * Delete a subscription and its queued deliveries.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Subscription id.
	 *
	 * @param array $args Positional arguments.
	 *
	 * @return void
	 */
	public function delete( array $args ): void {
		$id = (string) ( $args[0] ?? '' );
		try {
			self::delete_subscription( $id );
		} catch ( Invalid_Input $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( 'Deleted subscription ' . $id . '.' );
	}

	/**
	 * Create a subscription from CLI flags.
	 *
	 * @param array $assoc Named arguments.
	 *
	 * @return integer New id.
	 *
	 * @throws Invalid_Input On invalid values.
	 */
	public static function create_subscription( array $assoc ): int {
		$filters = array(
			'actor_home_page' => (string) ( $assoc['actor-home-page'] ?? '' ),
			'activity_prefix' => (string) ( $assoc['activity-prefix'] ?? '' ),
			'verbs'           => self::csv( $assoc['verb'] ?? '' ),
			'registrations'   => self::csv( $assoc['registration'] ?? '' ),
		);
		return Notify_Module::create( (string) ( $assoc['url'] ?? '' ), $filters, self::ACTOR );
	}

	/**
	 * Delete a subscription by id.
	 *
	 * @param string $id Id.
	 *
	 * @return void
	 *
	 * @throws Invalid_Input Code `not_found` (or `invalid` for a non-numeric id).
	 */
	public static function delete_subscription( string $id ): void {
		if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/', $id ) ) {
			throw new Invalid_Input( 'The subscription id must be a positive integer.', 'invalid', 'id' );
		}
		Notify_Module::delete( (int) $id, self::ACTOR );
	}

	/**
	 * Rows for `list`.
	 *
	 * @return array[]
	 */
	public static function rows(): array {
		$rows = array();
		foreach ( ( new Subscription_Repository() )->all() as $sub ) {
			$view            = Notify_Module::view( $sub );
			$view['filters'] = wp_json_encode( $view['filters'] );
			$rows[]          = $view;
		}
		return $rows;
	}

	/**
	 * Split a comma-separated value.
	 *
	 * @param mixed $v Value.
	 *
	 * @return string[]
	 */
	private static function csv( mixed $v ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $v ) ), static fn( $s ) => '' !== $s ) );
	}
}
