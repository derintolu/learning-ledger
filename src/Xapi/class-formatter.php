<?php
/**
 * Statement output formats.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Xapi;

/**
 * Applies the `format` parameter of a statement query (exact, ids, canonical).
 */
final class Formatter {

	/**
	 * IFI properties of an Agent or Group.
	 *
	 * @var string[]
	 */
	private const IFI_KEYS = array( 'mbox', 'mbox_sha1sum', 'openid', 'account' );

	/**
	 * Keys of contextActivities.
	 *
	 * @var string[]
	 */
	private const CONTEXT_ACTIVITY_KEYS = array( 'parent', 'grouping', 'category', 'other' );

	/**
	 * Interaction component lists of an activity definition.
	 *
	 * @var string[]
	 */
	private const COMPONENT_KEYS = array( 'choices', 'scale', 'source', 'target', 'steps' );

	/**
	 * Format a decoded statement.
	 *
	 * `exact` returns it unchanged. `ids` reduces agents and groups to their IFIs (group
	 * members kept as IFIs), activities to `{objectType,id}` and verbs to `{id}`. `canonical`
	 * reduces each language map to its best `Accept-Language` match, else its first entry.
	 *
	 * @param array  $statement       Decoded statement.
	 * @param string $format          One of `exact`, `ids` or `canonical`.
	 * @param string $accept_language Accept-Language header value ('' when absent).
	 *
	 * @return array
	 */
	public static function format( array $statement, string $format, string $accept_language ): array {
		if ( 'ids' === $format ) {
			return self::ids_statement( $statement );
		}
		if ( 'canonical' === $format ) {
			return self::canonical_statement( $statement, self::languages( $accept_language ) );
		}
		return $statement;
	}

	/**
	 * `ids` form of a statement or SubStatement.
	 *
	 * @param array $st Statement.
	 *
	 * @return array
	 */
	private static function ids_statement( array $st ): array {
		foreach ( array( 'actor', 'authority' ) as $key ) {
			if ( isset( $st[ $key ] ) && is_array( $st[ $key ] ) ) {
				$st[ $key ] = self::ids_agent( $st[ $key ] );
			}
		}
		if ( isset( $st['verb'] ) && is_array( $st['verb'] ) && isset( $st['verb']['id'] ) ) {
			$st['verb'] = array( 'id' => $st['verb']['id'] );
		}
		if ( isset( $st['object'] ) && is_array( $st['object'] ) ) {
			$type = $st['object']['objectType'] ?? 'Activity';
			if ( 'Activity' === $type ) {
				$st['object'] = self::ids_activity( $st['object'] );
			} elseif ( 'Agent' === $type || 'Group' === $type ) {
				$st['object'] = self::ids_agent( $st['object'] );
			} elseif ( 'SubStatement' === $type ) {
				$st['object'] = self::ids_statement( $st['object'] );
			}
		}
		if ( ! isset( $st['context'] ) || ! is_array( $st['context'] ) ) {
			return $st;
		}
		$context = $st['context'];
		foreach ( array( 'instructor', 'team' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_array( $context[ $key ] ) ) {
				$context[ $key ] = self::ids_agent( $context[ $key ] );
			}
		}
		foreach ( array(
			'contextAgents' => 'agent',
			'contextGroups' => 'group',
		) as $list => $field ) {
			if ( ! isset( $context[ $list ] ) || ! is_array( $context[ $list ] ) ) {
				continue;
			}
			foreach ( $context[ $list ] as $i => $entry ) {
				if ( is_array( $entry ) && isset( $entry[ $field ] ) && is_array( $entry[ $field ] ) ) {
					$context[ $list ][ $i ][ $field ] = self::ids_agent( $entry[ $field ] );
				}
			}
		}
		$context = self::map_context_activities( $context, static fn( array $a ) => self::ids_activity( $a ) );

		$st['context'] = $context;
		return $st;
	}

	/**
	 * An Agent or Group reduced to its objectType and IFI; a group's members likewise.
	 *
	 * @param array $agent Agent or Group.
	 *
	 * @return array
	 */
	private static function ids_agent( array $agent ): array {
		$out = array();
		if ( isset( $agent['objectType'] ) ) {
			$out['objectType'] = $agent['objectType'];
		}
		foreach ( self::IFI_KEYS as $key ) {
			if ( array_key_exists( $key, $agent ) ) {
				$out[ $key ] = $agent[ $key ];
			}
		}
		if ( 'Group' === ( $agent['objectType'] ?? null ) && isset( $agent['member'] ) && is_array( $agent['member'] ) ) {
			$out['member'] = array_map( static fn( $m ) => is_array( $m ) ? self::ids_agent( $m ) : $m, $agent['member'] );
		}
		return $out;
	}

	/**
	 * An Activity reduced to `{objectType,id}`.
	 *
	 * @param array $activity Activity.
	 *
	 * @return array
	 */
	private static function ids_activity( array $activity ): array {
		return array(
			'objectType' => 'Activity',
			'id'         => $activity['id'] ?? null,
		);
	}

	/**
	 * `canonical` form of a statement or SubStatement.
	 *
	 * @param array    $st    Statement.
	 * @param string[] $langs Preferred language ranges, best first.
	 *
	 * @return array
	 */
	private static function canonical_statement( array $st, array $langs ): array {
		if ( isset( $st['verb']['display'] ) && is_array( $st['verb']['display'] ) ) {
			$st['verb']['display'] = self::best( $st['verb']['display'], $langs );
		}
		if ( isset( $st['object'] ) && is_array( $st['object'] ) ) {
			$type = $st['object']['objectType'] ?? 'Activity';
			if ( 'Activity' === $type ) {
				$st['object'] = self::canonical_activity( $st['object'], $langs );
			} elseif ( 'SubStatement' === $type ) {
				$st['object'] = self::canonical_statement( $st['object'], $langs );
			}
		}
		if ( isset( $st['context'] ) && is_array( $st['context'] ) ) {
			$st['context'] = self::map_context_activities( $st['context'], static fn( array $a ) => self::canonical_activity( $a, $langs ) );
		}
		if ( isset( $st['attachments'] ) && is_array( $st['attachments'] ) ) {
			foreach ( $st['attachments'] as $i => $attachment ) {
				foreach ( array( 'display', 'description' ) as $key ) {
					if ( isset( $attachment[ $key ] ) && is_array( $attachment[ $key ] ) ) {
						$st['attachments'][ $i ][ $key ] = self::best( $attachment[ $key ], $langs );
					}
				}
			}
		}
		return $st;
	}

	/**
	 * An Activity with each language map of its definition reduced.
	 *
	 * @param array    $activity Activity.
	 * @param string[] $langs    Preferred language ranges.
	 *
	 * @return array
	 */
	private static function canonical_activity( array $activity, array $langs ): array {
		if ( ! isset( $activity['definition'] ) || ! is_array( $activity['definition'] ) ) {
			return $activity;
		}
		$def = $activity['definition'];
		foreach ( array( 'name', 'description' ) as $key ) {
			if ( isset( $def[ $key ] ) && is_array( $def[ $key ] ) ) {
				$def[ $key ] = self::best( $def[ $key ], $langs );
			}
		}
		foreach ( self::COMPONENT_KEYS as $key ) {
			if ( ! isset( $def[ $key ] ) || ! is_array( $def[ $key ] ) ) {
				continue;
			}
			foreach ( $def[ $key ] as $i => $component ) {
				if ( isset( $component['description'] ) && is_array( $component['description'] ) ) {
					$def[ $key ][ $i ]['description'] = self::best( $component['description'], $langs );
				}
			}
		}
		$activity['definition'] = $def;
		return $activity;
	}

	/**
	 * Apply a function to every context activity (a list or a single object).
	 *
	 * @param array    $context Context.
	 * @param callable $map_fn  Activity => Activity.
	 *
	 * @return array
	 */
	private static function map_context_activities( array $context, callable $map_fn ): array {
		if ( ! isset( $context['contextActivities'] ) || ! is_array( $context['contextActivities'] ) ) {
			return $context;
		}
		foreach ( self::CONTEXT_ACTIVITY_KEYS as $key ) {
			$value = $context['contextActivities'][ $key ] ?? null;
			if ( ! is_array( $value ) ) {
				continue;
			}
			if ( isset( $value['id'] ) ) {
				$context['contextActivities'][ $key ] = $map_fn( $value );
				continue;
			}
			foreach ( $value as $i => $activity ) {
				if ( is_array( $activity ) ) {
					$context['contextActivities'][ $key ][ $i ] = $map_fn( $activity );
				}
			}
		}
		return $context;
	}

	/**
	 * The best entry of a language map, as a one-entry map.
	 *
	 * A range matches a tag equal to it, a tag it is a prefix of (`fr` matches `fr-FR`), or a
	 * tag that is a prefix of it (`en` matches `en-US`); `*` matches anything. No match: the first entry.
	 *
	 * @param array    $map   Language map.
	 * @param string[] $langs Preferred language ranges, best first.
	 *
	 * @return array
	 */
	private static function best( array $map, array $langs ): array {
		if ( array() === $map ) {
			return $map;
		}
		$tags = array_map( 'strval', array_keys( $map ) );
		foreach ( $langs as $range ) {
			if ( '*' === $range ) {
				return array( $tags[0] => $map[ $tags[0] ] );
			}
			foreach ( array( 'exact', 'longer', 'shorter' ) as $pass ) {
				foreach ( $tags as $tag ) {
					$t = strtolower( $tag );
					if ( ( 'exact' === $pass && $t === $range )
						|| ( 'longer' === $pass && str_starts_with( $t, $range . '-' ) )
						|| ( 'shorter' === $pass && str_starts_with( $range, $t . '-' ) ) ) {
						return array( $tag => $map[ $tag ] );
					}
				}
			}
		}
		return array( $tags[0] => $map[ $tags[0] ] );
	}

	/**
	 * Language ranges of an Accept-Language header, best first (q=0 dropped, ties in header order).
	 *
	 * @param string $header Header value.
	 *
	 * @return string[] Lower-case ranges.
	 */
	private static function languages( string $header ): array {
		$ranked = array();
		foreach ( explode( ',', $header ) as $i => $part ) {
			$pieces = array_map( 'trim', explode( ';', $part ) );
			$range  = strtolower( (string) array_shift( $pieces ) );
			if ( '' === $range ) {
				continue;
			}
			$q = 1.0;
			foreach ( $pieces as $param ) {
				if ( 1 === preg_match( '/\Aq\s*=\s*([0-9.]+)\z/i', $param, $m ) ) {
					$q = (float) $m[1];
				}
			}
			if ( $q > 0 ) {
				$ranked[] = array( $q, $i, $range );
			}
		}
		usort( $ranked, static fn( $a, $b ) => array( $b[0], $a[1] ) <=> array( $a[0], $b[1] ) );
		return array_column( $ranked, 2 );
	}
}
