<?php
/**
 * Stored child token.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

use LearningLedger\Crypto\Tokens;

/**
 * A short-lived credential minted by a client for one AU session.
 */
final class Child_Token {

	/**
	 * Constructor.
	 *
	 * @param string                  $key           Token key (`tok_` + 24 hex), the Basic username.
	 * @param string                  $secret_sha256 Raw SHA-256 of the secret.
	 * @param string                  $parent_client Parent client id.
	 * @param string                  $registration  Registration UUID (lowercase).
	 * @param array                   $actor         Actor.
	 * @param string                  $activity_id   AU activity id.
	 * @param string                  $session_id    Session id.
	 * @param string                  $launch_mode   Launch mode.
	 * @param string[]                $cors_origins  CORS origins.
	 * @param boolean                 $guard         Apply the cmi5 guard.
	 * @param \DateTimeImmutable      $expires_at    Expiry.
	 * @param \DateTimeImmutable|null $terminated_at When the session was terminated.
	 * @param \DateTimeImmutable|null $revoked_at    Revocation time.
	 * @param \DateTimeImmutable      $created_at    Creation time.
	 */
	public function __construct(
		public readonly string $key,
		private readonly string $secret_sha256,
		public readonly string $parent_client,
		public readonly string $registration,
		public readonly array $actor,
		public readonly string $activity_id,
		public readonly string $session_id,
		public readonly string $launch_mode,
		public readonly array $cors_origins,
		public readonly bool $guard,
		public readonly \DateTimeImmutable $expires_at,
		public readonly ?\DateTimeImmutable $terminated_at,
		public readonly ?\DateTimeImmutable $revoked_at,
		public readonly \DateTimeImmutable $created_at
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
	 * Not revoked and not expired. Termination alone does not end the token (the cmi5 guard handles it).
	 *
	 * @param \DateTimeImmutable $now Now.
	 *
	 * @return boolean
	 */
	public function is_active( \DateTimeImmutable $now ): bool {
		return null === $this->revoked_at && $this->expires_at > $now;
	}
}
