<?php
/**
 * `wp learning-ledger client ...`.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Clients;

use LearningLedger\Lrs\Auth\Client;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Util\Invalid_Input;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages go to WP-CLI only, never into HTML.

/**
 * Manages LRS clients by the SHA-256 of their secret. No flag takes a plaintext secret and nothing prints a hash.
 */
final class Client_Command {

	const ACTOR = 'cli';

	const FLAGS = array(
		'id',
		'name',
		'secret-sha256',
		'scopes',
		'bind-actor-home-page',
		'bind-actor-ifi',
		'bind-registration',
		'activity-prefix',
		'write-activity-prefix',
		'cors-origin',
		'trusted',
		'rate-class',
		'guard',
		'reject-non-account',
		'expires',
	);

	/**
	 * Create a client from the SHA-256 of its secret.
	 *
	 * ## OPTIONS
	 *
	 * --id=<id>
	 * : Client id (the Basic username).
	 *
	 * --secret-sha256=<hex>
	 * : SHA-256 of the secret, 64 hex characters.
	 *
	 * --scopes=<csv>
	 * : Comma-separated scopes.
	 *
	 * [--name=<name>]
	 * : Display name (default: the id).
	 *
	 * [--bind-actor-home-page=<iri>]
	 * : Limit the client to actors with this account home page.
	 *
	 * [--bind-actor-ifi=<ifi>]
	 * : Limit the client to this actor.
	 *
	 * [--bind-registration=<uuid>]
	 * : Limit the client to this registration.
	 *
	 * [--activity-prefix=<csv>]
	 * : Activity IRI prefixes for every write.
	 *
	 * [--write-activity-prefix=<csv>]
	 * : Activity IRI prefixes for statement writes only.
	 *
	 * [--cors-origin=<csv>]
	 * : Allowed browser origins (scheme://host[:port] or null).
	 *
	 * [--trusted]
	 * : Trust this client for satisfaction.
	 *
	 * [--rate-class=<class>]
	 * : Rate-limit class.
	 *
	 * [--no-guard]
	 * : Do not apply the cmi5 guard to this client's child tokens.
	 *
	 * [--reject-non-account]
	 * : Reject statements whose actor is not an account.
	 *
	 * [--expires=<time>]
	 * : RFC 3339 expiry.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function create( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		try {
			$id = self::create_client( $assoc_args );
		} catch ( Invalid_Input $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( 'Created client ' . $id . '.' );
	}

	/**
	 * Change a client. Takes the same options as create; --secret-sha256 rotates the secret.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Client id.
	 *
	 * [--name=<name>] [--secret-sha256=<hex>] [--scopes=<csv>] [--bind-actor-home-page=<iri>] [--bind-actor-ifi=<ifi>] [--bind-registration=<uuid>] [--activity-prefix=<csv>] [--write-activity-prefix=<csv>] [--cors-origin=<csv>] [--trusted] [--rate-class=<class>] [--no-guard] [--reject-non-account] [--expires=<time>]
	 * : As for create. An empty value clears a binding, the origins or the expiry.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function update( array $args, array $assoc_args ): void {
		$id = (string) ( $args[0] ?? '' );
		try {
			self::update_client( $id, $assoc_args );
		} catch ( Invalid_Input $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( 'Updated client ' . $id . '.' );
	}

	/**
	 * Revoke a client; its child tokens stop working with it.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Client id.
	 *
	 * @param array $args Positional arguments.
	 *
	 * @return void
	 */
	public function revoke( array $args ): void {
		$id = (string) ( $args[0] ?? '' );
		try {
			Clients_Module::revoke( $id, self::ACTOR );
		} catch ( Invalid_Input $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( 'Revoked client ' . $id . '.' );
	}

	/**
	 * List clients. Never prints a secret hash.
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
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), self::rows(), array( 'id', 'name', 'scopes', 'status', 'expires_at', 'created_at' ) );
	}

	/**
	 * Create a client from CLI flags.
	 *
	 * @param array $assoc Named arguments.
	 *
	 * @return string Client id.
	 * @throws Invalid_Input On invalid values or a duplicate id.
	 */
	public static function create_client( array $assoc ): string {
		$spec = Client_Input::from_array( self::to_input( $assoc ) );
		Clients_Module::create( $spec, self::ACTOR );
		return $spec->id;
	}

	/**
	 * Update a client from CLI flags.
	 *
	 * @param string $id    Client id.
	 * @param array  $assoc Named arguments.
	 *
	 * @return Client
	 * @throws Invalid_Input On invalid values, an unknown client or nothing to change.
	 */
	public static function update_client( string $id, array $assoc ): Client {
		$input = self::to_input( $assoc );
		if ( isset( $input['id'] ) ) {
			throw new Invalid_Input( 'The id cannot be changed.', 'invalid', 'id' );
		}
		return Clients_Module::update( $id, $input, self::ACTOR );
	}

	/**
	 * Rows for `client list`: no hash, no secret.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function rows(): array {
		$rows = array();
		foreach ( ( new Client_Repository() )->all() as $client ) {
			$view   = Clients_Module::view( $client );
			$status = 'active';
			if ( null !== $view['revoked_at'] ) {
				$status = 'revoked';
			} elseif ( ! $view['active'] ) {
				$status = 'expired';
			}
			$rows[] = array(
				'id'         => $client->id,
				'name'       => $client->name,
				'scopes'     => implode( ',', $client->scopes ),
				'status'     => $status,
				'expires_at' => (string) $view['expires_at'],
				'created_at' => (string) $view['created_at'],
			);
		}
		return $rows;
	}

	/**
	 * Map CLI flags to Client_Input keys.
	 *
	 * @param array $assoc Named arguments.
	 *
	 * @return array
	 * @throws Invalid_Input On an unknown flag.
	 */
	private static function to_input( array $assoc ): array {
		$unknown = array_diff( array_keys( $assoc ), self::FLAGS );
		if ( array() !== $unknown ) {
			throw new Invalid_Input( 'Unknown option --' . reset( $unknown ) . '.', 'unknown_property', (string) reset( $unknown ) );
		}
		$map  = array(
			'id'                 => 'id',
			'name'               => 'name',
			'secret-sha256'      => 'secret_sha256',
			'scopes'             => 'scopes',
			'cors-origin'        => 'cors_origins',
			'trusted'            => 'trusted',
			'rate-class'         => 'rate_class',
			'guard'              => 'guard_enabled',
			'reject-non-account' => 'reject_non_account',
			'expires'            => 'expires_at',
		);
		$bind = array(
			'bind-actor-home-page'  => 'actor_home_page',
			'bind-actor-ifi'        => 'actor_ifi',
			'bind-registration'     => 'registration',
			'activity-prefix'       => 'activity_prefixes',
			'write-activity-prefix' => 'write_activity_prefixes',
		);
		$in   = array();
		foreach ( $map as $flag => $key ) {
			if ( array_key_exists( $flag, $assoc ) ) {
				$in[ $key ] = $assoc[ $flag ];
			}
		}
		foreach ( $bind as $flag => $key ) {
			if ( array_key_exists( $flag, $assoc ) ) {
				$in['bindings'][ $key ] = $assoc[ $flag ];
			}
		}
		return $in;
	}
}
