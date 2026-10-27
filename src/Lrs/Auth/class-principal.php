<?php
/**
 * Authenticated caller.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

use LearningLedger\Xapi\Agent_Identity;

/**
 * A client, a child token of a client, or the LRS itself.
 */
final class Principal {

	/**
	 * The most a child token can do, further capped by what its parent holds. Profiles are read-only (can_write_profile()).
	 *
	 * @var string[]
	 */
	const CHILD_SCOPES = array( Scopes::STATEMENTS_WRITE, Scopes::STATEMENTS_READ_MINE, Scopes::STATE, Scopes::PROFILE );

	/**
	 * Authority name of the LRS's own statements.
	 */
	const SYSTEM_NAME = 'learning-ledger';

	/**
	 * Constructor.
	 *
	 * @param Client|null      $client Client (null only for the system principal).
	 * @param Child_Token|null $token  Child token, when authenticated with one.
	 */
	private function __construct( private readonly ?Client $client, private readonly ?Child_Token $token = null ) {}

	/**
	 * Principal for a client.
	 *
	 * @param Client $c Client.
	 *
	 * @return self
	 */
	public static function for_client( Client $c ): self {
		return new self( $c );
	}

	/**
	 * Principal for a child token of a client.
	 *
	 * @param Client      $parent_client Parent client.
	 * @param Child_Token $t             Token.
	 *
	 * @return self
	 */
	public static function for_token( Client $parent_client, Child_Token $t ): self {
		return new self( $parent_client, $t );
	}

	/**
	 * The LRS itself (all xAPI scopes, never trusted for satisfaction), for its own `earned` statements.
	 *
	 * @return self
	 */
	public static function system(): self {
		return new self( null );
	}

	/**
	 * Client id (the parent's for a child token).
	 *
	 * @return string
	 */
	public function client_id(): string {
		return $this->client ? $this->client->id : self::SYSTEM_NAME;
	}

	/**
	 * Whether this is a child token.
	 *
	 * @return boolean
	 */
	public function is_child(): bool {
		return null !== $this->token;
	}

	/**
	 * The child token, if any.
	 *
	 * @return Child_Token|null
	 */
	public function token(): ?Child_Token {
		return $this->token;
	}

	/**
	 * Whether the caller holds a scope.
	 *
	 * @param string $scope Scope.
	 *
	 * @return boolean
	 */
	public function can( string $scope ): bool {
		if ( null === $this->client ) {
			return in_array( $scope, Scopes::XAPI, true );
		}
		if ( null !== $this->token ) {
			return in_array( $scope, self::CHILD_SCOPES, true ) && $this->client->holds( $scope );
		}
		return $this->client->holds( $scope );
	}

	/**
	 * Whether the caller may write agent and activity profiles.
	 *
	 * @return boolean
	 */
	public function can_write_profile(): bool {
		return null === $this->token && $this->can( Scopes::PROFILE );
	}

	/**
	 * Bindings; a child token is narrowed to its actor and registration.
	 *
	 * @return Bindings
	 */
	public function bindings(): Bindings {
		if ( null === $this->client ) {
			return Bindings::from_array( array() );
		}
		if ( null !== $this->token ) {
			return $this->client->bindings->narrowed(
				Agent_Identity::from_array( $this->token->actor )->ifi_key(),
				$this->token->registration
			);
		}
		return $this->client->bindings;
	}

	/**
	 * Authority Agent for stored statements.
	 *
	 * @return array
	 */
	public function authority(): array {
		if ( null === $this->client ) {
			$name = self::SYSTEM_NAME;
		} elseif ( null !== $this->token ) {
			$name = $this->client->id . '/' . $this->token->key;
		} else {
			$name = $this->client->id;
		}
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => untrailingslashit( home_url() ),
				'name'     => $name,
			),
		);
	}

	/**
	 * Key stored as a statement's `authority_client`.
	 *
	 * @return string Client id, `tok:<token key>`, or `sys:learning-ledger`.
	 */
	public function authority_key(): string {
		if ( null === $this->client ) {
			return 'sys:' . self::SYSTEM_NAME;
		}
		return null !== $this->token ? 'tok:' . $this->token->key : $this->client->id;
	}

	/**
	 * Whether the client's statements count towards satisfaction (never for a child or the system).
	 *
	 * @return boolean
	 */
	public function trusted_for_satisfaction(): bool {
		return null !== $this->client && null === $this->token && $this->client->trusted;
	}

	/**
	 * Allowed CORS origins: the client's plus the token's.
	 *
	 * @return string[]
	 */
	public function cors_origins(): array {
		if ( null === $this->client ) {
			return array();
		}
		return array_values( array_unique( array_merge( $this->client->cors_origins, $this->token ? $this->token->cors_origins : array() ) ) );
	}

	/**
	 * Whether the cmi5 guard applies: a child token with its own and its parent's guard on.
	 *
	 * @return boolean
	 */
	public function guard_enabled(): bool {
		return null !== $this->token && $this->token->guard && $this->client->guard_enabled;
	}

	/**
	 * Whether statements whose actor is not an account are rejected.
	 *
	 * @return boolean
	 */
	public function rejects_non_account(): bool {
		return null !== $this->client && $this->client->reject_non_account;
	}
}
