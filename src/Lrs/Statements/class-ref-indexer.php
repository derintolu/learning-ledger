<?php
/**
 * Index rows for related_agents and related_activities.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Statements;

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Xapi\Agent_Identity;

/**
 * Lists the agents and activities a statement refers to, as `lledger_statement_refs` rows.
 */
final class Ref_Indexer {

	/**
	 * Keys of contextActivities.
	 *
	 * @var string[]
	 */
	private const CONTEXT_ACTIVITY_KEYS = array( 'parent', 'grouping', 'category', 'other' );

	/**
	 * References of a validated statement: raw SHA-1 hash and kind, without duplicates.
	 *
	 * @param array $statement Decoded statement.
	 *
	 * @return array<array{hash:string,kind:string}>
	 */
	public static function refs( array $statement ): array {
		$refs = array();
		self::collect( $statement, '', $refs );
		if ( isset( $statement['authority'] ) && is_array( $statement['authority'] ) ) {
			self::add_agent( $statement['authority'], 'authority', $refs );
		}
		$object = $statement['object'] ?? null;
		if ( is_array( $object ) && 'SubStatement' === ( $object['objectType'] ?? null ) ) {
			self::collect( $object, 'substatement-', $refs );
		}
		return array_values( $refs );
	}

	/**
	 * Raw SHA-1 of an Agent or Group: its IFI hash, or for an anonymous Group
	 * SHA-1 of `anon:` followed by the sorted member ifi_keys joined by newlines.
	 *
	 * @param array $agent Agent or Group.
	 *
	 * @return string|null 20 raw bytes, or null when there is nothing to identify it by.
	 */
	public static function agent_hash( array $agent ): ?string {
		try {
			return Agent_Identity::from_array( $agent )->hash();
		} catch ( Invalid_Input $e ) {
			unset( $e );
		}
		if ( 'Group' !== ( $agent['objectType'] ?? null ) || ! isset( $agent['member'] ) || ! is_array( $agent['member'] ) ) {
			return null;
		}
		$keys = array();
		foreach ( $agent['member'] as $member ) {
			try {
				$keys[] = Agent_Identity::from_array( (array) $member )->ifi_key();
			} catch ( Invalid_Input $e ) {
				unset( $e );
			}
		}
		sort( $keys, SORT_STRING );
		return sha1( 'anon:' . implode( "\n", $keys ), true );
	}

	/**
	 * Every agent and activity a statement or SubStatement mentions (except authority).
	 *
	 * @param array  $st     Statement or SubStatement.
	 * @param string $prefix Kind prefix ('' or `substatement-`).
	 * @param array  $refs   Refs so far, keyed for de-duplication.
	 *
	 * @return void
	 */
	private static function collect( array $st, string $prefix, array &$refs ): void {
		if ( isset( $st['actor'] ) && is_array( $st['actor'] ) ) {
			self::add_agent( $st['actor'], $prefix . 'actor', $refs );
		}
		$object = $st['object'] ?? null;
		if ( is_array( $object ) ) {
			$type = $object['objectType'] ?? 'Activity';
			if ( 'Activity' === $type && isset( $object['id'] ) && is_string( $object['id'] ) ) {
				self::add( sha1( $object['id'], true ), $prefix . 'object-activity', $refs );
			} elseif ( 'Agent' === $type || 'Group' === $type ) {
				self::add_agent( $object, $prefix . 'object-agent', $refs );
			}
		}

		$context = $st['context'] ?? null;
		if ( ! is_array( $context ) ) {
			return;
		}
		foreach ( array( 'instructor', 'team' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_array( $context[ $key ] ) ) {
				self::add_agent( $context[ $key ], $prefix . $key, $refs );
			}
		}
		foreach ( array(
			'contextAgents' => array( 'agent', 'context-agent' ),
			'contextGroups' => array( 'group', 'context-group' ),
		) as $list => [ $field, $kind ] ) {
			foreach ( is_array( $context[ $list ] ?? null ) ? $context[ $list ] : array() as $entry ) {
				if ( is_array( $entry ) && isset( $entry[ $field ] ) && is_array( $entry[ $field ] ) ) {
					self::add_agent( $entry[ $field ], $prefix . $kind, $refs );
				}
			}
		}
		$activities = $context['contextActivities'] ?? null;
		if ( is_array( $activities ) ) {
			foreach ( self::CONTEXT_ACTIVITY_KEYS as $key ) {
				foreach ( self::activity_list( $activities[ $key ] ?? null ) as $activity ) {
					self::add( sha1( $activity['id'], true ), $prefix . 'context-' . $key, $refs );
				}
			}
		}
	}

	/**
	 * A contextActivities value as a list of activities with a string id.
	 *
	 * @param mixed $value A single activity or a list.
	 *
	 * @return array[]
	 */
	public static function activity_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		if ( isset( $value['id'] ) ) {
			$value = array( $value );
		}
		return array_values( array_filter( $value, static fn( $a ) => is_array( $a ) && isset( $a['id'] ) && is_string( $a['id'] ) ) );
	}

	/**
	 * Add an agent ref.
	 *
	 * @param array  $agent Agent or Group.
	 * @param string $kind  Kind.
	 * @param array  $refs  Refs so far.
	 *
	 * @return void
	 */
	private static function add_agent( array $agent, string $kind, array &$refs ): void {
		$hash = self::agent_hash( $agent );
		if ( null !== $hash ) {
			self::add( $hash, $kind, $refs );
		}
	}

	/**
	 * Add a ref once.
	 *
	 * @param string $hash Raw hash.
	 * @param string $kind Kind.
	 * @param array  $refs Refs so far.
	 *
	 * @return void
	 */
	private static function add( string $hash, string $kind, array &$refs ): void {
		$refs[ $kind . ':' . $hash ] = array(
			'hash' => $hash,
			'kind' => $kind,
		);
	}
}
