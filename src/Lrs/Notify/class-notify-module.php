<?php
/**
 * LRS notifications module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Notify;

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Kernel\Capabilities;
use LearningLedger\Kernel\Module;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed raw.

/**
 * Subscriptions (REST and WP-CLI), outbox rows on store, delivery at shutdown with the minute
 * cron as catch-up, the notification key in `/about`, and the failing-subscription notice.
 */
final class Notify_Module implements Module {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'learning_ledger/statements_stored', array( new Delivery_Enqueuer(), 'on_stored' ), 10, 1 ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		add_action( 'learning_ledger/cron_minute', array( $this, 'cron' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		add_filter( 'learning_ledger/about_extension', array( $this, 'about' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		add_action( 'rest_api_init', array( new Subscriptions_Controller(), 'routes' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			$command = new Subscription_Command();
			\WP_CLI::add_command( 'learning-ledger subscription create', array( $command, 'create' ) );
			\WP_CLI::add_command( 'learning-ledger subscription list', array( $command, 'list_' ) );
			\WP_CLI::add_command( 'learning-ledger subscription delete', array( $command, 'delete' ) );
		}
	}

	/**
	 * Minute cron: deliveries the shutdown run did not finish.
	 *
	 * @return void
	 */
	public function cron(): void {
		( new Webhook_Sender() )->run();
	}

	/**
	 * Add `notifications` to the About extension.
	 *
	 * @param array $ext Extension.
	 *
	 * @return array
	 */
	public function about( array $ext ): array {
		try {
			$key = Notify_Keys::active();
		} catch ( \RuntimeException $e ) {
			return $ext;
		}
		$ext['notifications'] = array(
			'kid'               => $key['kid'],
			'public_key'        => base64_encode( $key['public'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- public key transport encoding.
			'subscriptions_url' => rest_url( 'learning-ledger/v1/subscriptions' ),
		);
		return $ext;
	}

	/**
	 * Admin notice for failing subscriptions, to users who manage the LRS.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		foreach ( ( new Subscription_Repository() )->all() as $sub ) {
			if ( Subscription_Repository::FAILING !== $sub['status'] ) {
				continue;
			}
			$text = sprintf(
				/* translators: 1: subscription id, 2: target URL. */
				__( 'Learning Ledger: webhook subscription %1$d (%2$s) has failed for more than 72 hours. Deliveries are retried every 6 hours; the connector catches up by polling meanwhile.', 'learning-ledger' ),
				$sub['id'],
				$sub['url']
			);
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $text ) );
		}
	}

	/**
	 * Create a subscription and audit it.
	 *
	 * @param string $url     Target URL.
	 * @param array  $filters Filters.
	 * @param string $actor   Audit actor.
	 *
	 * @return integer New id.
	 *
	 * @throws Invalid_Input On invalid input.
	 */
	public static function create( string $url, array $filters, string $actor ): int {
		$id = ( new Subscription_Repository() )->create( $url, $filters );
		Audit_Log::record( 'subscription.create', array( 'subscription_id' => $id ), $actor );
		return $id;
	}

	/**
	 * Delete a subscription and audit it.
	 *
	 * @param integer $id    Id.
	 * @param string  $actor Audit actor.
	 *
	 * @return void
	 *
	 * @throws Invalid_Input Code `not_found`.
	 */
	public static function delete( int $id, string $actor ): void {
		if ( ! ( new Subscription_Repository() )->delete( $id ) ) {
			throw new Invalid_Input( 'No such subscription.', 'not_found', 'id' );
		}
		Audit_Log::record( 'subscription.delete', array( 'subscription_id' => $id ), $actor );
	}

	/**
	 * A subscription as returned to administrators.
	 *
	 * @param array $sub Subscription.
	 *
	 * @return array<string,mixed>
	 */
	public static function view( array $sub ): array {
		return array(
			'id'            => $sub['id'],
			'url'           => $sub['url'],
			'filters'       => (object) $sub['filters'],
			'status'        => $sub['status'],
			'failing_since' => null === $sub['failing_since'] ? null : Timestamp::to_xapi( Timestamp::from_db( $sub['failing_since'] ) ),
			'cursor'        => $sub['cursor'],
			'created_at'    => Timestamp::to_xapi( Timestamp::from_db( $sub['created_at'] ) ),
		);
	}
}
