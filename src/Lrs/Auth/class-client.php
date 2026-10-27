<?php
/**
 * Stored LRS client.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

use LearningLedger\Crypto\Tokens;

/**
 * A client record from `lledger_clients`.
 */
final class Client {

	/**
	 * Constructor.
	 *
	 * @param string                  $id                 Client id.
	 * @param string                  $name               Display name.
	 * @param string                  $secret_sha256      Raw SHA-256 of the secret.
	 * @param string[]                $scopes             Scopes.
	 * @param Bindings                $bindings           Bindings.
	 * @param string[]                $cors_origins       CORS origins.
	 * @param boolean                 $trusted            Trusted for satisfaction.
	 * @param string                  $rate_class         Rate-limit class.
	 * @param boolean                 $guard_enabled      Whether its child tokens get the cmi5 guard.
	 * @param boolean                 $reject_non_account Reject non-account actors.
	 * @param \DateTimeImmutable|null $expires_at         Expiry.
	 * @param \DateTimeImmutable      $created_at         Creation time.
	 * @param \DateTimeImmutable|null $revoked_at         Revocation time.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		private readonly string $secret_sha256,
		public readonly array $scopes,
		public readonly Bindings $bindings,
		public readonly array $cors_origins,
		public readonly bool $trusted,
		public readonly string $rate_class,
		public readonly bool $guard_enabled,
		public readonly bool $reject_non_account,
		public readonly ?\DateTimeImmutable $expires_at,
		public readonly \DateTimeImmutable $created_at,
		public readonly ?\DateTimeImmutable $revoked_at
	) {}

	/**
	 * Constant-time check of a presented secret.
	 *
	 * @param string $secret Presented secret.
	 *
	 * @return boolean
	 */
	public function secret_matches( string $secret ): bool {
		return hash_equals( $this->secret_sha256, Tokens::hash( $secret ) );
	}

	/**
	 * Not revoked and not expired.
	 *
	 * @param \DateTimeImmutable $now Now.
	 *
	 * @return boolean
	 */
	public function is_active( \DateTimeImmutable $now ): bool {
		return null === $this->revoked_at && ( null === $this->expires_at || $this->expires_at > $now );
	}

	/**
	 * Whether any held scope implies the wanted one.
	 *
	 * @param string $scope Wanted scope.
	 *
	 * @return boolean
	 */
	public function holds( string $scope ): bool {
		foreach ( $this->scopes as $held ) {
			if ( Scopes::implies( $held, $scope ) ) {
				return true;
			}
		}
		return false;
	}
}
