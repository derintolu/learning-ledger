<?php
/**
 * Client to create.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

/**
 * Client spec.
 */
final class Client_Spec {

	/**
	 * Constructor.
	 *
	 * @param string                  $id                 Client id (the Basic username).
	 * @param string                  $name               Display name.
	 * @param string                  $secret_sha256_raw  Raw SHA-256 of the secret (32 bytes).
	 * @param string[]                $scopes             Scopes.
	 * @param array                   $bindings           Bindings JSON shape.
	 * @param string[]                $cors_origins       CORS origins.
	 * @param boolean                 $trusted            Trusted for satisfaction.
	 * @param string                  $rate_class         Rate-limit class.
	 * @param boolean                 $guard_enabled      Whether this client's child tokens get the cmi5 guard.
	 * @param boolean                 $reject_non_account Reject statements whose actor is not an account.
	 * @param \DateTimeImmutable|null $expires_at         Expiry, or null for none.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $secret_sha256_raw,
		public readonly array $scopes,
		public readonly array $bindings = array(),
		public readonly array $cors_origins = array(),
		public readonly bool $trusted = false,
		public readonly string $rate_class = 'default',
		public readonly bool $guard_enabled = true,
		public readonly bool $reject_non_account = false,
		public readonly ?\DateTimeImmutable $expires_at = null
	) {}
}
