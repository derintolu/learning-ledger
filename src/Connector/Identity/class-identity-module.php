<?php
/**
 * Identity module: learner role and status binding.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Identity;

use LearningLedger\Kernel\Module;

/**
 * Registers the anonymous-learner role and publishes the actor home page in status.
 */
final class Identity_Module implements Module {

	const ROLE = 'lledger_learner';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'ensure_role' ) );
		add_filter( 'learning_ledger/status', array( $this, 'status' ), 10, 2 ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * Add the read-only learner role if missing.
	 *
	 * @return void
	 */
	public function ensure_role(): void {
		if ( null === get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Learning Ledger learner', array( 'read' => true ) );
		}
	}

	/**
	 * Add actor_home_page to the connector status block.
	 *
	 * @param array   $payload    Status payload.
	 * @param boolean $privileged Whether the caller may see private detail.
	 *
	 * @return array
	 */
	public function status( array $payload, bool $privileged = false ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$payload['connector'] = array_merge(
			is_array( $payload['connector'] ?? null ) ? $payload['connector'] : array(),
			array( 'actor_home_page' => ( new Identity_Map() )->home_page() )
		);
		return $payload;
	}
}
