<?php
/**
 * WP-CLI: badge issuer keys and identity.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Issuer;

use LearningLedger\Crypto\Multibase;

/**
 * Manage the badge issuer. Only key ids and public keys are ever printed.
 */
final class Badges_Command {

	/**
	 * Create the issuer key if there is none.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learning-ledger badges keygen
	 *
	 * @return void
	 */
	public function keygen(): void {
		$key = $this->run( array( Key_Store::class, 'generate' ) );
		\WP_CLI::success( 'Active badge key ' . $this->describe( $key ) );
	}

	/**
	 * Add a new active key. The previous key stays in did.json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learning-ledger badges rotate
	 *
	 * @return void
	 */
	public function rotate(): void {
		$key = $this->run( array( Key_Store::class, 'rotate' ) );
		\WP_CLI::success( 'New active badge key ' . $this->describe( $key ) );
	}

	/**
	 * Remove a compromised key from did.json.
	 *
	 * ## OPTIONS
	 *
	 * <kid>
	 * : Key id.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learning-ledger badges remove-key 0123456789abcdef
	 *
	 * @param array $args Positional arguments.
	 *
	 * @return void
	 *
	 * @subcommand remove-key
	 */
	public function remove_key( array $args ): void {
		$kid = (string) ( $args[0] ?? '' );
		$this->run( static fn() => Key_Store::remove( $kid ) );
		\WP_CLI::success( 'Removed badge key ' . $kid );
	}

	/**
	 * Set the issuer's display name.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Issuer name.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learning-ledger badges issuer-name "Learn WordPress, reimagined"
	 *
	 * @param array $args Positional arguments.
	 *
	 * @return void
	 *
	 * @subcommand issuer-name
	 */
	public function issuer_name( array $args ): void {
		$name = sanitize_text_field( (string) ( $args[0] ?? '' ) );
		if ( '' === $name ) {
			\WP_CLI::error( 'The issuer name must not be empty.' );
		}
		update_option( Issuer_Profile::NAME_OPTION, $name );
		\WP_CLI::success( 'Issuer name set.' );
	}

	/**
	 * Choose `host` (did:web:<host>) or `path` (did:web:<host>:issuer).
	 *
	 * ## OPTIONS
	 *
	 * <mode>
	 * : DID mode.
	 * ---
	 * options:
	 *   - host
	 *   - path
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp learning-ledger badges did-mode path
	 *
	 * @param array $args Positional arguments.
	 *
	 * @return void
	 *
	 * @subcommand did-mode
	 */
	public function did_mode( array $args ): void {
		$mode = (string) ( $args[0] ?? '' );
		if ( 'host' !== $mode && 'path' !== $mode ) {
			\WP_CLI::error( 'DID mode must be host or path.' );
		}
		update_option( Did_Document::MODE_OPTION, $mode );
		\WP_CLI::success( 'Issuer DID is ' . Did_Document::did() );
	}

	/**
	 * Run a key operation, turning failures into a CLI error.
	 *
	 * @param callable $op Operation.
	 *
	 * @return mixed
	 */
	private function run( callable $op ) {
		try {
			return $op();
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Key id and public multikey.
	 *
	 * @param Issuer_Key $key Key.
	 *
	 * @return string
	 */
	private function describe( Issuer_Key $key ): string {
		return $key->kid . ' ' . Multibase::ed25519_multikey( $key->public );
	}
}
