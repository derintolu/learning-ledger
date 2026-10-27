<?php
/**
 * Public status route.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Kernel;

use LearningLedger\Crypto\Ed25519;
use LearningLedger\Db\Installer;

/**
 * GET /learning-ledger/v1/status: versions, enabled roles and the sodium backend.
 */
final class Status_Route implements Module {

	public const NAMESPACE = 'learning-ledger/v1';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Route callback. Privileged callers hold `lledger_manage` (cookie with nonce, or Application Password).
	 *
	 * @return \WP_REST_Response
	 */
	public function handle(): \WP_REST_Response {
		return new \WP_REST_Response( self::payload( current_user_can( Capabilities::MANAGE ) ), 200 );
	}

	/**
	 * Status payload, passed through `learning_ledger/status`.
	 *
	 * @param boolean $privileged Whether the caller may see privileged additions.
	 *
	 * @return array<string,mixed>
	 */
	public static function payload( bool $privileged ): array {
		$payload = array(
			'plugin_version' => LEARNING_LEDGER_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'roles'          => Roles::enabled(),
			'sodium'         => Ed25519::backend(),
			'schema_version' => (int) get_option( Installer::OPTION, 0 ),
		);
		return (array) apply_filters( 'learning_ledger/status', $payload, $privileged ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
	}
}
