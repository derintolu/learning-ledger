<?php
/**
 * Child token to mint.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

/**
 * Token spec.
 */
final class Token_Spec {

	/**
	 * Constructor.
	 *
	 * @param string   $registration Registration UUID.
	 * @param array    $actor        Actor (Agent object).
	 * @param string   $activity_id  AU activity id.
	 * @param string   $session_id   The cmi5 session id (UUID).
	 * @param string   $launch_mode  Launch mode: Normal, Browse or Review.
	 * @param integer  $ttl          Lifetime in seconds (capped at 28,800).
	 * @param string[] $cors_origins The AU's CORS origins.
	 * @param boolean  $guard        Apply the cmi5 guard.
	 */
	public function __construct(
		public readonly string $registration,
		public readonly array $actor,
		public readonly string $activity_id,
		public readonly string $session_id,
		public readonly string $launch_mode,
		public readonly int $ttl = 28800,
		public readonly array $cors_origins = array(),
		public readonly bool $guard = true
	) {}
}
