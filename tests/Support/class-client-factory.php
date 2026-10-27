<?php
/**
 * Creates LRS clients for tests.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Tests;

use LearningLedger\Crypto\Tokens;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Client_Spec;

/**
 * Test helper: a stored client and its plain secret.
 */
final class Client_Factory {

	/**
	 * Create a client.
	 *
	 * @param string[] $scopes    Scopes.
	 * @param array    $overrides Any Client_Spec argument by name (id, name, bindings, cors_origins, trusted, rate_class, guard_enabled, reject_non_account, expires_at).
	 *
	 * @return array{id:string,secret:string}
	 */
	public static function create( array $scopes, array $overrides = array() ): array {
		$id     = $overrides['id'] ?? 'test-' . bin2hex( random_bytes( 6 ) );
		$secret = Tokens::random( 32 );
		( new Client_Repository() )->create(
			new Client_Spec(
				$id,
				$overrides['name'] ?? 'Test client ' . $id,
				Tokens::hash( $secret ),
				$scopes,
				$overrides['bindings'] ?? array(),
				$overrides['cors_origins'] ?? array(),
				$overrides['trusted'] ?? false,
				$overrides['rate_class'] ?? 'default',
				$overrides['guard_enabled'] ?? true,
				$overrides['reject_non_account'] ?? false,
				$overrides['expires_at'] ?? null
			)
		);
		return array(
			'id'     => $id,
			'secret' => $secret,
		);
	}
}
