<?php
/**
 * An issuer signing key.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Issuer;

/**
 * Public half of an Ed25519 issuer key; the secret half is loaded only when asked for.
 */
final readonly class Issuer_Key {

	/**
	 * Key id.
	 *
	 * @var string
	 */
	public string $kid;

	/**
	 * Raw 32-byte Ed25519 public key.
	 *
	 * @var string
	 */
	public string $public;

	/**
	 * Creation time (UTC, `Y-m-d H:i:s.u`), or null when unknown (constant tier).
	 *
	 * @var string|null
	 */
	public ?string $created_at;

	/**
	 * Retirement time (UTC), or null while the key is active.
	 *
	 * @var string|null
	 */
	public ?string $retired_at;

	/**
	 * Loads the secret key. It captures only the key id, never key material.
	 *
	 * @var \Closure
	 */
	private \Closure $loader;

	/**
	 * Constructor.
	 *
	 * @param string      $kid        Key id.
	 * @param string      $public_raw Raw public key.
	 * @param string|null $created_at Creation time.
	 * @param string|null $retired_at Retirement time.
	 * @param \Closure    $loader     Returns the 64-byte Ed25519 secret key.
	 */
	public function __construct( string $kid, string $public_raw, ?string $created_at, ?string $retired_at, \Closure $loader ) {
		$this->kid        = $kid;
		$this->public     = $public_raw;
		$this->created_at = $created_at;
		$this->retired_at = $retired_at;
		$this->loader     = $loader;
	}

	/**
	 * The 64-byte Ed25519 secret key, read from its storage tier on each call.
	 *
	 * @return string
	 */
	public function secret(): string {
		return ( $this->loader )();
	}

	/**
	 * Debug output without the loader.
	 *
	 * @return array
	 */
	public function __debugInfo(): array {
		return array(
			'kid'        => $this->kid,
			'public'     => bin2hex( $this->public ),
			'created_at' => $this->created_at,
			'retired_at' => $this->retired_at,
		);
	}
}
