<?php
/**
 * Connector pairing module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Pairing;

use LearningLedger\Kernel\Module;

/**
 * Registers the pairing WP-CLI commands and the connector's status fields.
 */
final class Pairing_Module implements Module {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'learning_ledger/status', array( $this, 'status' ), 10, 2 );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			$command = new Pairing_Command();
			\WP_CLI::add_command( 'learning-ledger connector connect', array( $command, 'connect' ) );
			\WP_CLI::add_command( 'learning-ledger connector set', array( $command, 'set' ) );
		}
	}

	/**
	 * Add pairing state under `connector`. The secret hash only goes to privileged callers.
	 *
	 * @param array   $payload    Status payload.
	 * @param boolean $privileged Whether the caller may see the secret hash.
	 *
	 * @return array
	 */
	public function status( array $payload, bool $privileged = false ): array {
		$settings = Connector_Settings::get();
		$source   = Connector_Secret::source();
		try {
			$key_id     = Connector_Keys::key_id();
			$public_key = Connector_Keys::public_b64();
		} catch ( \RuntimeException $e ) {
			$key_id     = null;
			$public_key = null;
		}

		$fields = array(
			'paired'             => '' !== $settings['lrs_url'] && '' !== $settings['client_id'] && null !== $source,
			'lrs_url'            => $settings['lrs_url'],
			'client_id'          => $settings['client_id'],
			'secret_source'      => $source,
			'signing_key_id'     => $key_id,
			'signing_public_key' => $public_key,
			'notify_kid'         => $settings['notify_kid'],
		);
		if ( true === $privileged ) {
			$fields['secret_sha256'] = Connector_Secret::sha256_hex();
		}

		$existing             = isset( $payload['connector'] ) && is_array( $payload['connector'] ) ? $payload['connector'] : array();
		$payload['connector'] = array_merge( $existing, $fields );
		return $payload;
	}
}
