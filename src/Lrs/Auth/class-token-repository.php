<?php
/**
 * Child token storage.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Auth;

use LearningLedger\Crypto\Tokens;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Agent_Identity;
use LearningLedger\Xapi\Primitives;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table; tokens are looked up by primary key.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed; HTTP layers JSON-encode them.

/**
 * Mints, finds and ends child tokens in `lledger_tokens`. Only the secret's SHA-256 is stored.
 */
final class Token_Repository {

	const MAX_TTL      = 28800;
	const LAUNCH_MODES = array( 'Normal', 'Browse', 'Review' );

	/**
	 * Mint a child token for one registration, actor and AU session.
	 *
	 * @param Client     $parent_client Minting client (active, holding `tokens/mint`).
	 * @param Token_Spec $s             Spec.
	 *
	 * @return array{key:string,secret:string,expires_at:string} The secret is returned once and never stored.
	 * @throws Invalid_Input On invalid input, or when the parent may not mint this token.
	 * @throws \RuntimeException When the row cannot be stored.
	 */
	public function mint( Client $parent_client, Token_Spec $s ): array {
		$now = Clock::now();
		if ( ! $parent_client->is_active( $now ) || ! in_array( Scopes::TOKENS_MINT, $parent_client->scopes, true ) ) {
			throw new Invalid_Input( 'This client may not mint tokens.', 'forbidden', 'client' );
		}
		if ( ! Uuid::is_valid( $s->registration ) ) {
			throw new Invalid_Input( 'registration must be a UUID.', 'invalid_uuid', 'registration' );
		}
		if ( ! Uuid::is_valid( $s->session_id ) ) {
			throw new Invalid_Input( 'session_id must be a UUID.', 'invalid_uuid', 'session_id' );
		}
		if ( ! in_array( $s->launch_mode, self::LAUNCH_MODES, true ) ) {
			throw new Invalid_Input( 'Unknown launch mode.', 'invalid', 'launch_mode' );
		}
		if ( $s->ttl < 1 ) {
			throw new Invalid_Input( 'ttl must be positive.', 'invalid', 'ttl' );
		}
		Primitives::assert_iri( $s->activity_id, 'activity_id' );
		foreach ( $s->cors_origins as $origin ) {
			if ( ! is_string( $origin ) || '' === $origin ) {
				throw new Invalid_Input( 'Expected a list of origins.', 'type', 'cors_origins' );
			}
		}
		$actor        = Agent_Identity::from_array( $s->actor );
		$registration = strtolower( $s->registration );
		if ( ! $parent_client->bindings->allows_actor( $actor ) || ! $parent_client->bindings->allows_document( '', null, $registration ) ) {
			throw new Invalid_Input( 'The actor or registration is outside the client bindings.', 'forbidden', 'actor' );
		}

		$key     = 'tok_' . bin2hex( random_bytes( 12 ) );
		$secret  = Tokens::random( 32 );
		$expires = $now->modify( '+' . min( $s->ttl, self::MAX_TTL ) . ' seconds' );

		global $wpdb;
		$ok = $wpdb->insert(
			Tables::name( 'tokens' ),
			array(
				'token_key'     => $key,
				'secret_sha256' => Tokens::hash( $secret ),
				'parent_client' => $parent_client->id,
				'registration'  => Uuid::to_bin( $registration ),
				'actor_hash'    => $actor->hash(),
				'actor'         => wp_json_encode( $s->actor ),
				'activity_id'   => $s->activity_id,
				'session_id'    => strtolower( $s->session_id ),
				'launch_mode'   => $s->launch_mode,
				'cors_origins'  => wp_json_encode( array_values( array_unique( $s->cors_origins ) ) ),
				'guard'         => $s->guard ? 1 : 0,
				'expires_at'    => Timestamp::to_db( $expires ),
				'created_at'    => Timestamp::to_db( $now ),
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not store the token.' );
		}
		Origin_Index::flush();

		return array(
			'key'        => $key,
			'secret'     => $secret,
			'expires_at' => Timestamp::to_xapi( $expires ),
		);
	}

	/**
	 * Find a token (revoked and expired ones included).
	 *
	 * @param string $key Token key.
	 *
	 * @return Child_Token|null
	 */
	public function find( string $key ): ?Child_Token {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_key = %s', Tables::name( 'tokens' ), $key ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Revoke one token.
	 *
	 * @param string $key Token key.
	 *
	 * @return void
	 */
	public function revoke( string $key ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_at = %s WHERE token_key = %s AND revoked_at IS NULL', Tables::name( 'tokens' ), Timestamp::to_db( Clock::now() ), $key ) );
		Origin_Index::flush();
	}

	/**
	 * Revoke every token of a session.
	 *
	 * @param string $session_id Session id.
	 *
	 * @return void
	 */
	public function revoke_session( string $session_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_at = %s WHERE session_id = %s AND revoked_at IS NULL', Tables::name( 'tokens' ), Timestamp::to_db( Clock::now() ), strtolower( $session_id ) ) );
		Origin_Index::flush();
	}

	/**
	 * Record when the token's session was terminated (first time only).
	 *
	 * @param string             $key Token key.
	 * @param \DateTimeImmutable $at  Termination time.
	 *
	 * @return void
	 */
	public function mark_terminated( string $key, \DateTimeImmutable $at ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET terminated_at = %s WHERE token_key = %s AND terminated_at IS NULL', Tables::name( 'tokens' ), Timestamp::to_db( $at ), $key ) );
	}

	/**
	 * CORS origins of every unrevoked, unexpired token.
	 *
	 * @return string[]
	 */
	public function live_origins(): array {
		global $wpdb;
		$lists = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT cors_origins FROM %i WHERE revoked_at IS NULL AND expires_at > %s AND cors_origins <> JSON_ARRAY()',
				Tables::name( 'tokens' ),
				Timestamp::to_db( Clock::now() )
			)
		);
		$out   = array();
		foreach ( $lists as $json ) {
			foreach ( (array) json_decode( $json, true ) as $origin ) {
				if ( is_string( $origin ) ) {
					$out[ $origin ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * Expired tokens that were neither terminated nor revoked, oldest first.
	 *
	 * @param integer $limit Maximum rows.
	 *
	 * @return Child_Token[]
	 */
	public function expired_unterminated( int $limit ): array {
		if ( $limit < 1 ) {
			return array();
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE expires_at <= %s AND terminated_at IS NULL AND revoked_at IS NULL ORDER BY expires_at, token_key LIMIT %d',
				Tables::name( 'tokens' ),
				Timestamp::to_db( Clock::now() ),
				$limit
			),
			ARRAY_A
		);
		return array_map( array( self::class, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Row to Child_Token.
	 *
	 * @param array $row Database row.
	 *
	 * @return Child_Token
	 */
	private static function hydrate( array $row ): Child_Token {
		return new Child_Token(
			$row['token_key'],
			$row['secret_sha256'],
			$row['parent_client'],
			Uuid::from_bin( $row['registration'] ),
			(array) json_decode( $row['actor'], true ),
			$row['activity_id'],
			$row['session_id'],
			$row['launch_mode'],
			(array) json_decode( $row['cors_origins'], true ),
			(bool) $row['guard'],
			Timestamp::from_db( $row['expires_at'] ),
			null === $row['terminated_at'] ? null : Timestamp::from_db( $row['terminated_at'] ),
			null === $row['revoked_at'] ? null : Timestamp::from_db( $row['revoked_at'] ),
			Timestamp::from_db( $row['created_at'] )
		);
	}
}
