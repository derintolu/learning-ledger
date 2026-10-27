<?php
/**
 * Client bindings.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;
use LearningLedger\Xapi\Primitives;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed; HTTP layers JSON-encode them.

/**
 * Limits on which actors, registration and activities a client may touch.
 */
final class Bindings {

	/**
	 * Bound actor `ifi_key`, if any.
	 *
	 * @var string|null
	 */
	private ?string $actor_ifi = null;

	/**
	 * Bound account homePage, if any.
	 *
	 * @var string|null
	 */
	private ?string $actor_home_page = null;

	/**
	 * Bound registration (lowercase UUID), if any.
	 *
	 * @var string|null
	 */
	private ?string $registration = null;

	/**
	 * Activity IRI prefixes for every write.
	 *
	 * @var string[]
	 */
	private array $activity_prefixes = array();

	/**
	 * Activity IRI prefixes for statement writes only.
	 *
	 * @var string[]
	 */
	private array $write_activity_prefixes = array();

	/**
	 * Use from_array().
	 */
	private function __construct() {}

	/**
	 * Build from the stored JSON shape.
	 *
	 * @param array $in Keys `actor_ifi`, `actor_home_page`, `registration`, `activity_prefixes`, `write_activity_prefixes`.
	 *
	 * @return self
	 * @throws Invalid_Input Code `invalid_binding`.
	 */
	public static function from_array( array $in ): self {
		$b = new self();
		foreach ( $in as $key => $value ) {
			switch ( $key ) {
				case 'actor_ifi':
					if ( ! is_string( $value ) || '' === $value ) {
						throw self::invalid( $key );
					}
					$b->actor_ifi = $value;
					break;
				case 'actor_home_page':
					self::assert_iri( $value, $key );
					$b->actor_home_page = $value;
					break;
				case 'registration':
					if ( ! is_string( $value ) || ! Uuid::is_valid( $value ) ) {
						throw self::invalid( $key );
					}
					$b->registration = strtolower( $value );
					break;
				case 'activity_prefixes':
				case 'write_activity_prefixes':
					if ( ! is_array( $value ) ) {
						throw self::invalid( $key );
					}
					foreach ( $value as $prefix ) {
						self::assert_iri( $prefix, $key );
					}
					$b->$key = array_values( $value );
					break;
				default:
					throw self::invalid( (string) $key );
			}
		}
		return $b;
	}

	/**
	 * Copy narrowed to one actor and registration (a child token's bindings).
	 *
	 * @param string $actor_ifi    Actor ifi_key.
	 * @param string $registration Registration UUID.
	 *
	 * @return self
	 */
	public function narrowed( string $actor_ifi, string $registration ): self {
		$copy               = clone $this;
		$copy->actor_ifi    = $actor_ifi;
		$copy->registration = strtolower( $registration );
		return $copy;
	}

	/**
	 * The stored JSON shape (only keys that are set).
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array_filter(
			array(
				'actor_ifi'               => $this->actor_ifi,
				'actor_home_page'         => $this->actor_home_page,
				'registration'            => $this->registration,
				'activity_prefixes'       => $this->activity_prefixes,
				'write_activity_prefixes' => $this->write_activity_prefixes,
			),
			static fn( $v ) => null !== $v && array() !== $v
		);
	}

	/**
	 * Whether a statement write is within the bindings. A voiding statement is checked through its target elsewhere.
	 *
	 * @param array $st Decoded statement.
	 *
	 * @return boolean
	 */
	public function allows_statement( array $st ): bool {
		if ( null !== $this->actor_ifi || null !== $this->actor_home_page ) {
			if ( ! isset( $st['actor'] ) || ! is_array( $st['actor'] ) ) {
				return false;
			}
			try {
				$actor = Agent_Identity::from_array( $st['actor'] );
			} catch ( Invalid_Input $e ) {
				return false;
			}
			if ( ! $this->allows_actor( $actor ) ) {
				return false;
			}
		}

		if ( null !== $this->registration ) {
			$reg = $st['context']['registration'] ?? null;
			if ( ! is_string( $reg ) || strtolower( $reg ) !== $this->registration ) {
				return false;
			}
		}

		$object = $st['object'] ?? null;
		if ( is_array( $object ) && 'Activity' === ( $object['objectType'] ?? 'Activity' ) ) {
			$id = $object['id'] ?? null;
			if ( ! is_string( $id ) ) {
				return array() === $this->activity_prefixes && array() === $this->write_activity_prefixes;
			}
			return self::matches( $id, $this->activity_prefixes ) && self::matches( $id, $this->write_activity_prefixes );
		}
		return true;
	}

	/**
	 * Whether an actor is within the actor bindings.
	 *
	 * @param Agent_Identity $a Actor.
	 *
	 * @return boolean
	 */
	public function allows_actor( Agent_Identity $a ): bool {
		if ( null !== $this->actor_ifi && $a->ifi_key() !== $this->actor_ifi ) {
			return false;
		}
		if ( null !== $this->actor_home_page && $a->home_page() !== $this->actor_home_page ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a document is within the bindings. Absent parts (empty IRI, null agent or registration) are not checked.
	 *
	 * @param string              $activity_iri Activity IRI, or '' for an agent profile.
	 * @param Agent_Identity|null $a            Agent, if the document has one.
	 * @param string|null         $registration Registration, if the document has one.
	 *
	 * @return boolean
	 */
	public function allows_document( string $activity_iri, ?Agent_Identity $a, ?string $registration ): bool {
		if ( '' !== $activity_iri && ! self::matches( $activity_iri, $this->activity_prefixes ) ) {
			return false;
		}
		if ( null !== $a && ! $this->allows_actor( $a ) ) {
			return false;
		}
		if ( null !== $registration && null !== $this->registration && strtolower( $registration ) !== $this->registration ) {
			return false;
		}
		return true;
	}

	/**
	 * Read filter: raw SHA-1 `actor_hash` (of the bound ifi_key), raw `actor_home_hash`
	 * (Agent_Identity::home_hash), lowercase `registration` UUID. Activity prefixes do not filter reads.
	 *
	 * @return array{actor_hash?:string,actor_home_hash?:string,registration?:string}
	 */
	public function read_filter(): array {
		$f = array();
		if ( null !== $this->actor_ifi ) {
			$f['actor_hash'] = sha1( $this->actor_ifi, true );
		}
		if ( null !== $this->actor_home_page ) {
			$f['actor_home_hash'] = Agent_Identity::home_hash( $this->actor_home_page );
		}
		if ( null !== $this->registration ) {
			$f['registration'] = $this->registration;
		}
		return $f;
	}

	/**
	 * Whether an IRI starts with one of the prefixes (an empty list allows all).
	 *
	 * @param string   $iri      IRI.
	 * @param string[] $prefixes Prefixes.
	 *
	 * @return boolean
	 */
	private static function matches( string $iri, array $prefixes ): bool {
		if ( array() === $prefixes ) {
			return true;
		}
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $iri, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Assert an absolute IRI.
	 *
	 * @param mixed  $v   Value.
	 * @param string $key Binding key.
	 *
	 * @return void
	 * @throws Invalid_Input Code `invalid_binding`.
	 */
	private static function assert_iri( mixed $v, string $key ): void {
		try {
			Primitives::assert_iri( $v, $key );
		} catch ( Invalid_Input $e ) {
			throw self::invalid( $key );
		}
	}

	/**
	 * Error for a bad binding.
	 *
	 * @param string $key Binding key.
	 *
	 * @return Invalid_Input
	 */
	private static function invalid( string $key ): Invalid_Input {
		return new Invalid_Input( 'Invalid binding.', 'invalid_binding', 'bindings.' . $key );
	}
}
