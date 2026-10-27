<?php
/**
 * Export and migration module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Export;

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Kernel\Capabilities;
use LearningLedger\Kernel\Module;
use LearningLedger\Util\Invalid_Input;

/**
 * Registers the export route, its streaming response and the `statements` WP-CLI commands.
 */
final class Export_Module implements Module {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( new Export_Controller(), 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 4 );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			$command = new Statements_Command();
			\WP_CLI::add_command( 'learning-ledger statements export', array( $command, 'export' ) );
			\WP_CLI::add_command( 'learning-ledger statements import', array( $command, 'import' ) );
		}
	}

	/**
	 * Stream the export in place of the JSON body (filter `rest_pre_serve_request`). The headers are
	 * already sent by then. Lines are written as they are read, never collected.
	 *
	 * @param boolean           $served  Whether the request has been served.
	 * @param \WP_HTTP_Response $result  The response.
	 * @param \WP_REST_Request  $request Request.
	 * @param \WP_REST_Server   $server  Server.
	 *
	 * @return boolean
	 */
	public function serve( bool $served, \WP_HTTP_Response $result, \WP_REST_Request $request, \WP_REST_Server $server ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter signature.
		if ( $served || Export_Controller::ROUTE !== $request->get_route() || ! $result instanceof \WP_REST_Response || 200 !== $result->get_status() || ! current_user_can( Capabilities::EXPORT ) ) {
			return $served;
		}
		if ( 'HEAD' === $request->get_method() ) {
			return true; // Headers only: nothing is read and nothing is exported.
		}
		$filters = Export_Controller::filters( $request );
		try {
			$described = Ndjson_Exporter::describe( $filters );
		} catch ( Invalid_Input $e ) {
			return false;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- a large export outlasts the default limit; may be disabled by the host.
		}
		$actor   = 'user:' . get_current_user_id();
		$written = 0;
		$done    = false;
		self::audit_export_start( 'rest', $described, $actor );
		try {
			( new Ndjson_Exporter() )->stream(
				$filters,
				static function ( string $line ) use ( &$written ): void {
					echo $line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- NDJSON, not HTML; every line is JSON built from stored bodies.
					++$written;
					if ( 0 === $written % Ndjson_Exporter::CHUNK ) {
						flush();
					}
				}
			);
			$done = true;
		} finally {
			self::audit_export_end( 'rest', $written, $done, $actor );
		}
		return true;
	}

	/**
	 * Record that an export began (counts and filter values only, never statements).
	 *
	 * @param string $channel Either `rest` or `cli`.
	 * @param array  $filters Ndjson_Exporter::describe().
	 * @param string $actor   Audit actor.
	 *
	 * @return void
	 */
	public static function audit_export_start( string $channel, array $filters, string $actor ): void {
		Audit_Log::record(
			'statements.export',
			array(
				'channel' => $channel,
				'filters' => $filters,
			),
			$actor
		);
	}

	/**
	 * Record how an export ended.
	 *
	 * @param string  $channel  Either `rest` or `cli`.
	 * @param integer $count    Lines written.
	 * @param boolean $complete Whether every matching statement was written.
	 * @param string  $actor    Audit actor.
	 *
	 * @return void
	 */
	public static function audit_export_end( string $channel, int $count, bool $complete, string $actor ): void {
		Audit_Log::record(
			'statements.export_done',
			array(
				'channel'  => $channel,
				'count'    => $count,
				'complete' => $complete,
			),
			$actor
		);
	}
}
