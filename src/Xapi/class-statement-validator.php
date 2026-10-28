<?php
/**
 * Strict xAPI statement validation.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Xapi;

use LearningLedger\Util\Duration;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never echoed; HTTP layers JSON-encode them.

/**
 * Validates a decoded statement against xAPI 1.0.3 or 2.0.0.
 *
 * Objects arrive as associative arrays (Json::decode_strict), so an empty
 * `{}` and an empty `[]` are indistinguishable here; an empty array is
 * accepted wherever either an object or a list is allowed.
 */
final class Statement_Validator {

	/**
	 * Verb IRI reserved for voiding.
	 */
	public const VOIDED_VERB = 'http://adlnet.gov/expapi/verbs/voided';

	/**
	 * Top-level statement properties.
	 *
	 * @var string[]
	 */
	private const STATEMENT_KEYS = array( 'id', 'actor', 'verb', 'object', 'result', 'context', 'timestamp', 'stored', 'authority', 'version', 'attachments' );

	/**
	 * SubStatement properties.
	 *
	 * @var string[]
	 */
	private const SUB_STATEMENT_KEYS = array( 'objectType', 'actor', 'verb', 'object', 'result', 'context', 'timestamp', 'attachments' );

	/**
	 * Context properties common to both versions.
	 *
	 * @var string[]
	 */
	private const CONTEXT_KEYS = array( 'registration', 'instructor', 'team', 'contextActivities', 'revision', 'platform', 'language', 'statement', 'extensions' );

	/**
	 * Context properties added by 2.0.0.
	 *
	 * @var string[]
	 */
	private const CONTEXT_KEYS_2_0 = array( 'contextAgents', 'contextGroups' );

	/**
	 * Activity definition properties.
	 *
	 * @var string[]
	 */
	private const DEFINITION_KEYS = array( 'name', 'description', 'type', 'moreInfo', 'extensions', 'interactionType', 'correctResponsesPattern', 'choices', 'scale', 'source', 'target', 'steps' );

	/**
	 * Interaction types and the component lists each supports.
	 *
	 * @var array<string, string[]>
	 */
	private const INTERACTION_LISTS = array(
		'true-false'   => array(),
		'choice'       => array( 'choices' ),
		'fill-in'      => array(),
		'long-fill-in' => array(),
		'matching'     => array( 'source', 'target' ),
		'performance'  => array( 'steps' ),
		'sequencing'   => array( 'choices' ),
		'likert'       => array( 'scale' ),
		'numeric'      => array(),
		'other'        => array(),
	);

	/**
	 * All interaction component list names.
	 *
	 * @var string[]
	 */
	private const COMPONENT_LISTS = array( 'choices', 'scale', 'source', 'target', 'steps' );

	/**
	 * Attachment properties.
	 *
	 * @var string[]
	 */
	private const ATTACHMENT_KEYS = array( 'usageType', 'display', 'description', 'contentType', 'length', 'sha2', 'fileUrl' );

	/**
	 * Validate a statement.
	 *
	 * @param array  $st      Decoded statement.
	 * @param string $version Negotiated xAPI version, `1.0.3` or `2.0.0`.
	 *
	 * @throws Invalid_Input On the first violation, with the dotted path of the offending value.
	 *
	 * @return void
	 */
	public static function validate( array $st, string $version ): void {
		if ( ! in_array( $version, Version::SUPPORTED, true ) ) {
			throw new Invalid_Input( 'Unsupported xAPI version.', 'version', '' );
		}
		self::statement( $st, $version, '', false );
	}

	/**
	 * Validate a statement or SubStatement.
	 *
	 * @param mixed   $st      Statement.
	 * @param string  $version The xAPI version.
	 * @param string  $path    Path of the statement ('' at top level).
	 * @param boolean $is_sub  Whether this is a SubStatement.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function statement( mixed $st, string $version, string $path, bool $is_sub ): void {
		self::assert_object( $st, $path );
		self::check_keys( $st, $is_sub ? self::SUB_STATEMENT_KEYS : self::STATEMENT_KEYS, $path );
		foreach ( array( 'actor', 'verb', 'object' ) as $key ) {
			if ( ! array_key_exists( $key, $st ) ) {
				throw new Invalid_Input( 'Missing required property.', 'required', self::join( $path, $key ) );
			}
		}

		if ( array_key_exists( 'id', $st ) ) {
			Primitives::assert_uuid( $st['id'], self::join( $path, 'id' ) );
		}
		Actor_Validator::validate( $st['actor'], $version, self::join( $path, 'actor' ) );
		self::verb( $st['verb'], self::join( $path, 'verb' ) );
		$object_type = self::object( $st['object'], $version, self::join( $path, 'object' ), $is_sub );

		if ( self::VOIDED_VERB === $st['verb']['id'] && 'StatementRef' !== $object_type ) {
			throw new Invalid_Input( 'A voiding statement must have a StatementRef object.', 'voided', self::join( $path, 'object.objectType' ) );
		}
		if ( array_key_exists( 'result', $st ) ) {
			self::result( $st['result'], self::join( $path, 'result' ) );
		}
		if ( array_key_exists( 'context', $st ) ) {
			self::context( $st['context'], $version, self::join( $path, 'context' ), $object_type );
		}
		foreach ( array( 'timestamp', 'stored' ) as $key ) {
			if ( array_key_exists( $key, $st ) ) {
				self::timestamp( $st[ $key ], $version, self::join( $path, $key ) );
			}
		}
		if ( array_key_exists( 'authority', $st ) ) {
			Actor_Validator::validate( $st['authority'], $version, self::join( $path, 'authority' ) );
			self::authority_group( $st['authority'], self::join( $path, 'authority' ) );
		}
		if ( array_key_exists( 'version', $st ) ) {
			self::statement_version( $st['version'], $version, self::join( $path, 'version' ) );
		}
		if ( array_key_exists( 'attachments', $st ) ) {
			self::attachments( $st['attachments'], self::join( $path, 'attachments' ) );
		}
	}

	/**
	 * Validate a Verb.
	 *
	 * @param mixed  $verb Verb.
	 * @param string $path Path.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function verb( mixed $verb, string $path ): void {
		self::assert_object( $verb, $path );
		self::check_keys( $verb, array( 'id', 'display' ), $path );
		self::require_key( $verb, 'id', $path );
		Primitives::assert_iri( $verb['id'], $path . '.id' );
		if ( array_key_exists( 'display', $verb ) ) {
			Primitives::assert_lang_map( $verb['display'], $path . '.display' );
		}
	}

	/**
	 * Validate a statement's object.
	 *
	 * @param mixed   $obj     Object.
	 * @param string  $version The xAPI version.
	 * @param string  $path    Path.
	 * @param boolean $in_sub  Whether the object belongs to a SubStatement.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return string The effective objectType.
	 */
	private static function object( mixed $obj, string $version, string $path, bool $in_sub ): string {
		self::assert_object( $obj, $path );
		$type = $obj['objectType'] ?? 'Activity';
		switch ( $type ) {
			case 'Activity':
				self::activity( $obj, $path );
				break;
			case 'Agent':
			case 'Group':
				Actor_Validator::validate( $obj, $version, $path );
				break;
			case 'StatementRef':
				self::statement_ref( $obj, $path );
				break;
			case 'SubStatement':
				if ( $in_sub ) {
					throw new Invalid_Input( 'A SubStatement cannot contain a SubStatement.', 'nested_substatement', $path . '.objectType' );
				}
				self::statement( $obj, $version, $path, true );
				break;
			default:
				throw new Invalid_Input( 'objectType must be Activity, Agent, Group, StatementRef or SubStatement.', 'object_type', $path . '.objectType' );
		}
		return $type;
	}

	/**
	 * Validate an Activity.
	 *
	 * @param mixed  $activity Activity.
	 * @param string $path     Path.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function activity( mixed $activity, string $path ): void {
		self::assert_object( $activity, $path );
		if ( array_key_exists( 'objectType', $activity ) && 'Activity' !== $activity['objectType'] ) {
			throw new Invalid_Input( 'objectType must be Activity.', 'object_type', $path . '.objectType' );
		}
		self::check_keys( $activity, array( 'objectType', 'id', 'definition' ), $path );
		self::require_key( $activity, 'id', $path );
		Primitives::assert_iri( $activity['id'], $path . '.id' );
		if ( array_key_exists( 'definition', $activity ) ) {
			self::definition( $activity['definition'], $path . '.definition' );
		}
	}

	/**
	 * Validate an Activity Definition, including interaction properties.
	 *
	 * @param mixed  $def  Definition.
	 * @param string $path Path.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function definition( mixed $def, string $path ): void {
		self::assert_object( $def, $path );
		self::check_keys( $def, self::DEFINITION_KEYS, $path );
		foreach ( array( 'name', 'description' ) as $key ) {
			if ( array_key_exists( $key, $def ) ) {
				Primitives::assert_lang_map( $def[ $key ], $path . '.' . $key );
			}
		}
		foreach ( array( 'type', 'moreInfo' ) as $key ) {
			if ( array_key_exists( $key, $def ) ) {
				Primitives::assert_iri( $def[ $key ], $path . '.' . $key );
			}
		}
		if ( array_key_exists( 'extensions', $def ) ) {
			Primitives::assert_extensions( $def['extensions'], $path . '.extensions' );
		}

		$interaction_keys = array_merge( array( 'correctResponsesPattern' ), self::COMPONENT_LISTS );
		$is_interaction   = array_key_exists( 'interactionType', $def ) || array() !== array_intersect( $interaction_keys, array_keys( $def ) );
		if ( ! $is_interaction ) {
			return;
		}
		$type = $def['interactionType'] ?? null;
		if ( ! is_string( $type ) || ! array_key_exists( $type, self::INTERACTION_LISTS ) ) {
			throw new Invalid_Input( 'Interaction activities need a valid interactionType.', 'interaction_type', $path . '.interactionType' );
		}
		if ( array_key_exists( 'correctResponsesPattern', $def ) ) {
			$pattern = $def['correctResponsesPattern'];
			self::assert_list( $pattern, $path . '.correctResponsesPattern' );
			foreach ( $pattern as $i => $response ) {
				if ( ! is_string( $response ) ) {
					throw new Invalid_Input( 'Response patterns must be strings.', 'type', $path . '.correctResponsesPattern[' . $i . ']' );
				}
			}
		}
		foreach ( self::COMPONENT_LISTS as $list ) {
			if ( ! array_key_exists( $list, $def ) ) {
				continue;
			}
			if ( ! in_array( $list, self::INTERACTION_LISTS[ $type ], true ) ) {
				throw new Invalid_Input( 'This component list is not supported by the interactionType.', 'interaction_component', $path . '.' . $list );
			}
			self::components( $def[ $list ], $path . '.' . $list );
		}
	}

	/**
	 * Validate an interaction component list.
	 *
	 * @param mixed  $components Components.
	 * @param string $path       Path.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function components( mixed $components, string $path ): void {
		self::assert_list( $components, $path );
		$seen = array();
		foreach ( $components as $i => $component ) {
			$item = $path . '[' . $i . ']';
			self::assert_object( $component, $item );
			self::check_keys( $component, array( 'id', 'description' ), $item );
			self::require_key( $component, 'id', $item );
			if ( ! is_string( $component['id'] ) || '' === $component['id'] ) {
				throw new Invalid_Input( 'Component id must be a non-empty string.', 'type', $item . '.id' );
			}
			if ( isset( $seen[ $component['id'] ] ) ) {
				throw new Invalid_Input( 'Component ids must be distinct within a list.', 'duplicate', $item . '.id' );
			}
			$seen[ $component['id'] ] = true;
			if ( array_key_exists( 'description', $component ) ) {
				Primitives::assert_lang_map( $component['description'], $item . '.description' );
			}
		}
	}

	/**
	 * Validate a Statement Reference.
	 *
	 * @param mixed  $ref  Reference.
	 * @param string $path Path.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function statement_ref( mixed $ref, string $path ): void {
		self::assert_object( $ref, $path );
		if ( 'StatementRef' !== ( $ref['objectType'] ?? null ) ) {
			throw new Invalid_Input( 'objectType must be StatementRef.', 'object_type', $path . '.objectType' );
		}
		self::check_keys( $ref, array( 'objectType', 'id' ), $path );
		self::require_key( $ref, 'id', $path );
		Primitives::assert_uuid( $ref['id'], $path . '.id' );
	}

	/**
	 * Validate a Result.
	 *
	 * @param mixed  $result Result.
	 * @param string $path   Path.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function result( mixed $result, string $path ): void {
		self::assert_object( $result, $path );
		self::check_keys( $result, array( 'score', 'success', 'completion', 'response', 'duration', 'extensions' ), $path );
		if ( array_key_exists( 'score', $result ) ) {
			self::score( $result['score'], $path . '.score' );
		}
		foreach ( array( 'success', 'completion' ) as $key ) {
			if ( array_key_exists( $key, $result ) ) {
				Primitives::assert_bool( $result[ $key ], $path . '.' . $key );
			}
		}
		if ( array_key_exists( 'response', $result ) && ! is_string( $result['response'] ) ) {
			throw new Invalid_Input( 'response must be a string.', 'type', $path . '.response' );
		}
		if ( array_key_exists( 'duration', $result ) ) {
			$valid = is_string( $result['duration'] );
			if ( $valid ) {
				try {
					Duration::parse( $result['duration'] );
				} catch ( Invalid_Input $e ) {
					$valid = false;
				}
			}
			if ( ! $valid ) {
				throw new Invalid_Input( 'duration must be an ISO 8601 duration.', 'duration', $path . '.duration' );
			}
		}
		if ( array_key_exists( 'extensions', $result ) ) {
			Primitives::assert_extensions( $result['extensions'], $path . '.extensions' );
		}
	}

	/**
	 * Validate a Score.
	 *
	 * @param mixed  $score Score.
	 * @param string $path  Path.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function score( mixed $score, string $path ): void {
		self::assert_object( $score, $path );
		self::check_keys( $score, array( 'scaled', 'raw', 'min', 'max' ), $path );
		foreach ( array( 'scaled', 'raw', 'min', 'max' ) as $key ) {
			if ( array_key_exists( $key, $score ) ) {
				Primitives::assert_number( $score[ $key ], $path . '.' . $key );
			}
		}
		if ( isset( $score['scaled'] ) && ( $score['scaled'] < -1 || $score['scaled'] > 1 ) ) {
			throw new Invalid_Input( 'scaled must be between -1 and 1.', 'range', $path . '.scaled' );
		}
		if ( isset( $score['min'], $score['max'] ) && $score['min'] >= $score['max'] ) {
			throw new Invalid_Input( 'min must be less than max.', 'range', $path . '.min' );
		}
		if ( isset( $score['raw'] ) ) {
			if ( ( isset( $score['min'] ) && $score['raw'] < $score['min'] ) || ( isset( $score['max'] ) && $score['raw'] > $score['max'] ) ) {
				throw new Invalid_Input( 'raw must be between min and max.', 'range', $path . '.raw' );
			}
		}
	}

	/**
	 * Validate a Context.
	 *
	 * @param mixed  $context     Context.
	 * @param string $version     The xAPI version.
	 * @param string $path        Path.
	 * @param string $object_type The statement's effective objectType.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function context( mixed $context, string $version, string $path, string $object_type ): void {
		self::assert_object( $context, $path );
		$allowed = '2.0.0' === $version ? array_merge( self::CONTEXT_KEYS, self::CONTEXT_KEYS_2_0 ) : self::CONTEXT_KEYS;
		self::check_keys( $context, $allowed, $path );

		if ( array_key_exists( 'registration', $context ) ) {
			Primitives::assert_uuid( $context['registration'], $path . '.registration' );
		}
		if ( array_key_exists( 'instructor', $context ) ) {
			Actor_Validator::validate( $context['instructor'], $version, $path . '.instructor' );
		}
		if ( array_key_exists( 'team', $context ) ) {
			self::group( $context['team'], $version, $path . '.team' );
		}
		if ( array_key_exists( 'contextActivities', $context ) ) {
			self::context_activities( $context['contextActivities'], $path . '.contextActivities' );
		}
		foreach ( array( 'revision', 'platform' ) as $key ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}
			if ( 'Activity' !== $object_type ) {
				throw new Invalid_Input( $key . ' is only allowed when the object is an Activity.', $key, $path . '.' . $key );
			}
			if ( ! is_string( $context[ $key ] ) ) {
				throw new Invalid_Input( $key . ' must be a string.', 'type', $path . '.' . $key );
			}
		}
		if ( array_key_exists( 'language', $context ) ) {
			self::language( $context['language'], $path . '.language' );
		}
		if ( array_key_exists( 'statement', $context ) ) {
			self::statement_ref( $context['statement'], $path . '.statement' );
		}
		if ( array_key_exists( 'extensions', $context ) ) {
			Primitives::assert_extensions( $context['extensions'], $path . '.extensions' );
		}
		if ( array_key_exists( 'contextAgents', $context ) ) {
			self::context_members( $context['contextAgents'], $version, $path . '.contextAgents', 'contextAgent', 'agent' );
		}
		if ( array_key_exists( 'contextGroups', $context ) ) {
			self::context_members( $context['contextGroups'], $version, $path . '.contextGroups', 'contextGroup', 'group' );
		}
	}

	/**
	 * Validate contextActivities: each value one Activity or a list of them.
	 *
	 * @param mixed  $activities The contextActivities object.
	 * @param string $path       Path.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function context_activities( mixed $activities, string $path ): void {
		self::assert_object( $activities, $path );
		self::check_keys( $activities, array( 'parent', 'grouping', 'category', 'other' ), $path );
		foreach ( $activities as $key => $value ) {
			$item = $path . '.' . $key;
			if ( ! is_array( $value ) ) {
				throw new Invalid_Input( 'Expected an Activity or an array of Activities.', 'type', $item );
			}
			if ( array_is_list( $value ) ) {
				foreach ( $value as $i => $activity ) {
					self::activity( $activity, $item . '[' . $i . ']' );
				}
				continue;
			}
			self::activity( $value, $item );
		}
	}

	/**
	 * Validate contextAgents or contextGroups (2.0.0).
	 *
	 * @param mixed  $members     The array.
	 * @param string $version     The xAPI version.
	 * @param string $path        Path.
	 * @param string $object_type Required objectType of each entry.
	 * @param string $actor_key   Either `agent` or `group`.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function context_members( mixed $members, string $version, string $path, string $object_type, string $actor_key ): void {
		self::assert_list( $members, $path );
		foreach ( $members as $i => $entry ) {
			$item = $path . '[' . $i . ']';
			self::assert_object( $entry, $item );
			self::check_keys( $entry, array( 'objectType', $actor_key, 'relevantTypes' ), $item );
			if ( ( $entry['objectType'] ?? null ) !== $object_type ) {
				throw new Invalid_Input( 'objectType must be ' . $object_type . '.', 'object_type', $item . '.objectType' );
			}
			self::require_key( $entry, $actor_key, $item );
			if ( 'agent' === $actor_key ) {
				self::agent( $entry['agent'], $version, $item . '.agent' );
			} else {
				self::group( $entry['group'], $version, $item . '.group' );
			}
			if ( array_key_exists( 'relevantTypes', $entry ) ) {
				$types = $entry['relevantTypes'];
				self::assert_list( $types, $item . '.relevantTypes' );
				if ( array() === $types ) {
					throw new Invalid_Input( 'relevantTypes must list at least one IRI.', 'type', $item . '.relevantTypes' );
				}
				foreach ( $types as $j => $iri ) {
					Primitives::assert_iri( $iri, $item . '.relevantTypes[' . $j . ']' );
				}
			}
		}
	}

	/**
	 * Validate an actor that must be an Agent.
	 *
	 * @param mixed  $agent   Agent.
	 * @param string $version The xAPI version.
	 * @param string $path    Path.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function agent( mixed $agent, string $version, string $path ): void {
		self::assert_object( $agent, $path );
		if ( 'Agent' !== ( $agent['objectType'] ?? 'Agent' ) ) {
			throw new Invalid_Input( 'Expected an Agent.', 'object_type', $path . '.objectType' );
		}
		Actor_Validator::validate( $agent, $version, $path );
	}

	/**
	 * Validate an actor that must be a Group.
	 *
	 * @param mixed  $group   Group.
	 * @param string $version The xAPI version.
	 * @param string $path    Path.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function group( mixed $group, string $version, string $path ): void {
		self::assert_object( $group, $path );
		if ( 'Group' !== ( $group['objectType'] ?? null ) ) {
			throw new Invalid_Input( 'Expected a Group.', 'object_type', $path . '.objectType' );
		}
		Actor_Validator::validate( $group, $version, $path );
	}

	/**
	 * Validate a BCP 47 language tag.
	 *
	 * @param mixed  $tag  Tag.
	 * @param string $path Path.
	 *
	 * @throws Invalid_Input Code `language`.
	 *
	 * @return void
	 */
	private static function language( mixed $tag, string $path ): void {
		$valid = is_string( $tag ) && '' !== $tag;
		if ( $valid ) {
			try {
				Primitives::assert_lang_map( array( $tag => '' ), $path );
			} catch ( Invalid_Input $e ) {
				$valid = false;
			}
		}
		if ( ! $valid ) {
			throw new Invalid_Input( 'language must be an RFC 5646 language tag.', 'language', $path );
		}
	}

	/**
	 * Validate a timestamp per version.
	 *
	 * @param mixed  $value   Timestamp.
	 * @param string $version The xAPI version.
	 * @param string $path    Path.
	 *
	 * @throws Invalid_Input Code `timestamp`.
	 *
	 * @return void
	 */
	private static function timestamp( mixed $value, string $version, string $path ): void {
		// A negative zero offset means "offset unknown", which no xAPI timestamp may use (XAPI-00123).
		$valid = is_string( $value ) && 1 !== preg_match( '/-00(?::?00)?\z/', $value );
		if ( $valid ) {
			try {
				Timestamp::parse( $value, $version );
			} catch ( Invalid_Input $e ) {
				$valid = false;
			}
		}
		if ( ! $valid ) {
			throw new Invalid_Input( 'Invalid timestamp for this xAPI version.', 'timestamp', $path );
		}
	}

	/**
	 * An authority that is a Group is an anonymous Group of exactly two Agents
	 * (Data 2.4.9, XAPI-00024, XAPI-00098, XAPI-00100). Call after Actor_Validator.
	 *
	 * @param array  $authority Authority, already a valid Agent or Group.
	 * @param string $path      Path.
	 *
	 * @throws Invalid_Input Code `authority`.
	 *
	 * @return void
	 */
	private static function authority_group( array $authority, string $path ): void {
		if ( 'Group' !== ( $authority['objectType'] ?? 'Agent' ) ) {
			return;
		}
		if ( array() !== array_intersect( Agent_Identity::IFI_KEYS, array_keys( $authority ) ) ) {
			throw new Invalid_Input( 'An authority Group must be anonymous.', 'authority', $path );
		}
		if ( 2 !== count( $authority['member'] ) ) {
			throw new Invalid_Input( 'An authority Group must have exactly two members.', 'authority', $path . '.member' );
		}
	}

	/**
	 * Validate the statement's `version` property.
	 *
	 * Under 1.0.3 it is `1.0` or `1.0.x`; under 2.0.0, also `2.0` or `2.0.x` (XAPI-00101).
	 *
	 * @param mixed  $value   Version.
	 * @param string $version The xAPI version.
	 * @param string $path    Path.
	 *
	 * @throws Invalid_Input Code `version`.
	 *
	 * @return void
	 */
	private static function statement_version( mixed $value, string $version, string $path ): void {
		$majors = '2.0.0' === $version ? '[12]' : '1';
		$semver = '/^' . $majors . '\.0(?:\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?)?$/D';
		if ( ! is_string( $value ) || 1 !== preg_match( $semver, $value ) ) {
			throw new Invalid_Input( 'Unsupported statement version.', 'version', $path );
		}
	}

	/**
	 * Validate attachments. Only `fileUrl` attachments are accepted, since
	 * multipart requests are rejected before validation.
	 *
	 * @param mixed  $attachments Attachments.
	 * @param string $path        Path.
	 *
	 * @throws Invalid_Input On any violation.
	 *
	 * @return void
	 */
	private static function attachments( mixed $attachments, string $path ): void {
		self::assert_list( $attachments, $path );
		foreach ( $attachments as $i => $att ) {
			$item = $path . '[' . $i . ']';
			self::assert_object( $att, $item );
			self::check_keys( $att, self::ATTACHMENT_KEYS, $item );
			foreach ( array( 'usageType', 'display', 'contentType', 'length', 'sha2', 'fileUrl' ) as $key ) {
				self::require_key( $att, $key, $item );
			}
			Primitives::assert_iri( $att['usageType'], $item . '.usageType' );
			Primitives::assert_lang_map( $att['display'], $item . '.display' );
			if ( array_key_exists( 'description', $att ) ) {
				Primitives::assert_lang_map( $att['description'], $item . '.description' );
			}
			if ( ! is_string( $att['contentType'] ) || 1 !== preg_match( '#^[A-Za-z0-9!\#$&^_.+-]+/[A-Za-z0-9!\#$&^_.+-]+(?:\s*;.*)?$#D', $att['contentType'] ) ) {
				throw new Invalid_Input( 'contentType must be an Internet Media Type.', 'type', $item . '.contentType' );
			}
			if ( ! is_int( $att['length'] ) || $att['length'] < 0 ) {
				throw new Invalid_Input( 'length must be a non-negative integer.', 'type', $item . '.length' );
			}
			if ( ! is_string( $att['sha2'] ) || '' === $att['sha2'] ) {
				throw new Invalid_Input( 'sha2 must be a string.', 'type', $item . '.sha2' );
			}
			Primitives::assert_iri( $att['fileUrl'], $item . '.fileUrl' );
		}
	}

	/**
	 * Assert a decoded JSON object.
	 *
	 * @param mixed  $v    Value.
	 * @param string $path Path.
	 *
	 * @throws Invalid_Input Code `type`.
	 *
	 * @return void
	 */
	private static function assert_object( mixed $v, string $path ): void {
		if ( ! Primitives::is_object( $v ) ) {
			throw new Invalid_Input( 'Expected an object.', 'type', $path );
		}
	}

	/**
	 * Assert a decoded JSON array.
	 *
	 * @param mixed  $v    Value.
	 * @param string $path Path.
	 *
	 * @throws Invalid_Input Code `type`.
	 *
	 * @return void
	 */
	private static function assert_list( mixed $v, string $path ): void {
		if ( ! is_array( $v ) || ! array_is_list( $v ) ) {
			throw new Invalid_Input( 'Expected an array.', 'type', $path );
		}
	}

	/**
	 * Reject properties outside the allowed list.
	 *
	 * @param array    $obj     Object.
	 * @param string[] $allowed Allowed keys.
	 * @param string   $path    Path of the object.
	 *
	 * @throws Invalid_Input Code `unknown_property`.
	 *
	 * @return void
	 */
	private static function check_keys( array $obj, array $allowed, string $path ): void {
		foreach ( array_keys( $obj ) as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				throw new Invalid_Input( 'Unknown property.', 'unknown_property', self::join( $path, (string) $key ) );
			}
		}
	}

	/**
	 * Require a property.
	 *
	 * @param array  $obj  Object.
	 * @param string $key  Key.
	 * @param string $path Path of the object.
	 *
	 * @throws Invalid_Input Code `required`.
	 *
	 * @return void
	 */
	private static function require_key( array $obj, string $key, string $path ): void {
		if ( ! array_key_exists( $key, $obj ) ) {
			throw new Invalid_Input( 'Missing required property.', 'required', self::join( $path, $key ) );
		}
	}

	/**
	 * Join a path and a key.
	 *
	 * @param string $path Path ('' at the top level).
	 * @param string $key  Key.
	 *
	 * @return string
	 */
	private static function join( string $path, string $key ): string {
		return '' === $path ? $key : $path . '.' . $key;
	}
}
