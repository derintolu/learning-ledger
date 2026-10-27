<?php
/**
 * Client management module.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Clients;

use LearningLedger\Audit\Audit_Log;
use LearningLedger\Kernel\Module;
use LearningLedger\Lrs\Auth\Client;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Client_Spec;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed raw.

/**
 * Registers the client WP-CLI commands and admin REST routes, and holds the audited operations both use.
 */
final class Clients_Module implements Module {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( new Clients_Controller(), 'routes' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			$command = new Client_Command();
			\WP_CLI::add_command( 'learning-ledger client create', array( $command, 'create' ) );
			\WP_CLI::add_command( 'learning-ledger client update', array( $command, 'update' ) );
			\WP_CLI::add_command( 'learning-ledger client revoke', array( $command, 'revoke' ) );
			\WP_CLI::add_command( 'learning-ledger client list', array( $command, 'list_' ) );
		}
	}

	/**
	 * Create a client and audit it.
	 *
	 * @param Client_Spec $spec  Spec.
	 * @param string      $actor Audit actor.
	 *
	 * @return void
	 * @throws Invalid_Input On invalid fields or a duplicate id.
	 */
	public static function create( Client_Spec $spec, string $actor ): void {
		( new Client_Repository() )->create( $spec );
		Audit_Log::record(
			'client.create',
			array(
				'client_id' => $spec->id,
				'scopes'    => $spec->scopes,
			),
			$actor
		);
	}

	/**
	 * Change a client and audit the field names changed.
	 *
	 * @param string $id    Client id.
	 * @param array  $input Input as for Client_Input::changes().
	 * @param string $actor Audit actor.
	 *
	 * @return Client The client after the change.
	 * @throws Invalid_Input Code `not_found`, `empty` or an invalid value.
	 */
	public static function update( string $id, array $input, string $actor ): Client {
		$repo    = new Client_Repository();
		$current = $repo->find( $id );
		if ( null === $current ) {
			throw new Invalid_Input( 'No such client.', 'not_found', 'id' );
		}
		$changes = Client_Input::changes( $input, $current );
		if ( array() === $changes ) {
			throw new Invalid_Input( 'Nothing to update.', 'empty', '' );
		}
		$repo->update( $id, $changes );
		$fields = array_map( static fn( $k ) => 'secret_sha256_raw' === $k ? 'secret' : $k, array_keys( $changes ) );
		Audit_Log::record(
			'client.update',
			array(
				'client_id' => $id,
				'fields'    => $fields,
			),
			$actor
		);
		return $repo->find( $id );
	}

	/**
	 * Revoke a client and audit it. Revoking a revoked client does nothing.
	 *
	 * @param string $id    Client id.
	 * @param string $actor Audit actor.
	 *
	 * @return Client The client after the change.
	 * @throws Invalid_Input Code `not_found`.
	 */
	public static function revoke( string $id, string $actor ): Client {
		$repo   = new Client_Repository();
		$client = $repo->find( $id );
		if ( null === $client ) {
			throw new Invalid_Input( 'No such client.', 'not_found', 'id' );
		}
		if ( null === $client->revoked_at ) {
			$repo->revoke( $id );
			Audit_Log::record( 'client.revoke', array( 'client_id' => $id ), $actor );
		}
		return $repo->find( $id );
	}

	/**
	 * A client as returned to administrators: never any secret or hash.
	 *
	 * @param Client $c Client.
	 *
	 * @return array<string,mixed>
	 */
	public static function view( Client $c ): array {
		return array(
			'id'                 => $c->id,
			'name'               => $c->name,
			'scopes'             => $c->scopes,
			'bindings'           => (object) $c->bindings->to_array(),
			'cors_origins'       => $c->cors_origins,
			'trusted'            => $c->trusted,
			'rate_class'         => $c->rate_class,
			'guard_enabled'      => $c->guard_enabled,
			'reject_non_account' => $c->reject_non_account,
			'expires_at'         => null === $c->expires_at ? null : Timestamp::to_xapi( $c->expires_at ),
			'created_at'         => Timestamp::to_xapi( $c->created_at ),
			'revoked_at'         => null === $c->revoked_at ? null : Timestamp::to_xapi( $c->revoked_at ),
			'active'             => $c->is_active( Clock::now() ),
		);
	}
}
