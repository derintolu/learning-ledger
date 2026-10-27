<?php
/**
 * Agent inverse functional identifier.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Xapi;

use LearningLedger\Util\Invalid_Input;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never echoed; HTTP layers JSON-encode them.

/**
 * The single IFI of an Agent (or identified Group), compared exactly as sent.
 */
final class Agent_Identity {

	/**
	 * IFI property names.
	 *
	 * @var string[]
	 */
	public const IFI_KEYS = array( 'mbox', 'mbox_sha1sum', 'openid', 'account' );

	/**
	 * IFI kind (one of IFI_KEYS).
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * IFI value: a string, or an account array with homePage and name.
	 *
	 * @var string|array
	 */
	private string|array $value;

	/**
	 * Constructor.
	 *
	 * @param string       $kind  IFI kind.
	 * @param string|array $value IFI value.
	 */
	private function __construct( string $kind, string|array $value ) {
		$this->kind  = $kind;
		$this->value = $value;
	}

	/**
	 * Build from an Agent or Group object. Error paths are relative to the object.
	 *
	 * @param array $agent Decoded agent.
	 *
	 * @return self
	 *
	 * @throws Invalid_Input Code `ifi` unless exactly one IFI is present, or the IFI's own code.
	 */
	public static function from_array( array $agent ): self {
		$present = array_values( array_filter( self::IFI_KEYS, static fn( $k ) => array_key_exists( $k, $agent ) ) );
		if ( 1 !== count( $present ) ) {
			throw new Invalid_Input( 'An agent must have exactly one of mbox, mbox_sha1sum, openid or account.', 'ifi', '' );
		}
		$kind = $present[0];
		$v    = $agent[ $kind ];

		switch ( $kind ) {
			case 'mbox':
				Primitives::assert_mbox( $v, 'mbox' );
				return new self( $kind, $v );
			case 'mbox_sha1sum':
				Primitives::assert_sha1_hex( $v, 'mbox_sha1sum' );
				return new self( $kind, strtolower( $v ) );
			case 'openid':
				Primitives::assert_iri( $v, 'openid' );
				return new self( $kind, $v );
			default:
				if ( ! Primitives::is_object( $v ) ) {
					throw new Invalid_Input( 'account must be an object.', 'type', 'account' );
				}
				foreach ( array_keys( $v ) as $key ) {
					if ( 'homePage' !== $key && 'name' !== $key ) {
						throw new Invalid_Input( 'Unknown property.', 'unknown_property', 'account.' . $key );
					}
				}
				Primitives::assert_iri( $v['homePage'] ?? null, 'account.homePage' );
				if ( ! isset( $v['name'] ) || ! is_string( $v['name'] ) ) {
					throw new Invalid_Input( 'account.name must be a string.', 'type', 'account.name' );
				}
				return new self(
					$kind,
					array(
						'homePage' => $v['homePage'],
						'name'     => $v['name'],
					)
				);
		}
	}

	/**
	 * Hash of an account homePage alone (raw SHA-1 of `home:` . homePage).
	 *
	 * @param string $home_page Account homePage.
	 *
	 * @return string 20 raw bytes.
	 */
	public static function home_hash( string $home_page ): string {
		return sha1( 'home:' . $home_page, true );
	}

	/**
	 * IFI kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Canonical key: `<kind>:<value>`, account as `account:<homePage>|<name>`.
	 *
	 * @return string
	 */
	public function ifi_key(): string {
		if ( 'account' === $this->kind ) {
			return 'account:' . $this->value['homePage'] . '|' . $this->value['name'];
		}
		return $this->kind . ':' . $this->value;
	}

	/**
	 * Raw SHA-1 of ifi_key().
	 *
	 * @return string 20 raw bytes.
	 */
	public function hash(): string {
		return sha1( $this->ifi_key(), true );
	}

	/**
	 * Account homePage, or null for other kinds.
	 *
	 * @return string|null
	 */
	public function home_page(): ?string {
		return 'account' === $this->kind ? $this->value['homePage'] : null;
	}

	/**
	 * The IFI as an Agent object.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'objectType' => 'Agent',
			$this->kind  => $this->value,
		);
	}
}
