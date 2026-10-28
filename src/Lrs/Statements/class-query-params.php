<?php
/**
 * Parameters of a statements GET.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Statements;

use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;
use LearningLedger\Xapi\Actor_Validator;
use LearningLedger\Xapi\Agent_Identity;
use LearningLedger\Xapi\Primitives;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Xapi_Error messages are served as JSON, never echoed into HTML.

/**
 * Validated statement query parameters.
 */
final class Query_Params {

	/**
	 * Default `limit`.
	 */
	public const DEFAULT_LIMIT = 100;

	/**
	 * Largest `limit`.
	 */
	public const MAX_LIMIT = 500;

	/**
	 * Parameters allowed with `statementId` or `voidedStatementId`.
	 *
	 * @var string[]
	 */
	private const SINGLE_PARAMS = array( 'statementId', 'voidedStatementId', 'format', 'attachments' );

	/**
	 * Every parameter a statements GET accepts (besides `more`).
	 *
	 * @var string[]
	 */
	private const PARAMS = array( 'statementId', 'voidedStatementId', 'agent', 'verb', 'activity', 'registration', 'related_activities', 'related_agents', 'since', 'until', 'limit', 'format', 'attachments', 'ascending' );

	/**
	 * Formats.
	 *
	 * @var string[]
	 */
	private const FORMATS = array( 'exact', 'ids', 'canonical' );

	/**
	 * Use from_request() or from_cursor().
	 *
	 * @param string|null $statement_id        The `statementId` (lowercase).
	 * @param string|null $voided_statement_id The `voidedStatementId` (lowercase).
	 * @param string|null $agent_hash          Raw SHA-1 of the `agent` (Ref_Indexer::agent_hash).
	 * @param string|null $verb                Verb IRI.
	 * @param string|null $activity            Activity IRI.
	 * @param string|null $registration        Registration (lowercase UUID).
	 * @param boolean     $related_activities  Match activities anywhere in the statement.
	 * @param boolean     $related_agents      Match agents anywhere in the statement.
	 * @param string|null $since               Exclusive lower bound on `stored`, DB format (UTC, microseconds).
	 * @param string|null $until               Inclusive upper bound on `stored`, DB format.
	 * @param integer     $limit               Page size, 1 to 500.
	 * @param string      $format              One of `exact`, `ids` or `canonical`.
	 * @param boolean     $attachments         Whether attachments were asked for.
	 * @param boolean     $ascending           Oldest first.
	 */
	private function __construct(
		public readonly ?string $statement_id,
		public readonly ?string $voided_statement_id,
		public readonly ?string $agent_hash,
		public readonly ?string $verb,
		public readonly ?string $activity,
		public readonly ?string $registration,
		public readonly bool $related_activities,
		public readonly bool $related_agents,
		public readonly ?string $since,
		public readonly ?string $until,
		public readonly int $limit,
		public readonly string $format,
		public readonly bool $attachments,
		public readonly bool $ascending
	) {}

	/**
	 * Validate the query parameters of a request.
	 *
	 * @param Xapi_Request $r Request.
	 *
	 * @return self
	 * @throws Xapi_Error 400 for an unknown or invalid parameter, or a single-statement id with filters.
	 */
	public static function from_request( Xapi_Request $r ): self {
		$p       = $r->params();
		$version = '' === $r->version() ? '2.0.0' : $r->version();
		foreach ( array_keys( $p ) as $name ) {
			if ( ! in_array( $name, self::PARAMS, true ) ) {
				throw new Xapi_Error( 400, 'Unknown parameter for statements: ' . $name . '.' );
			}
			if ( ! is_string( $p[ $name ] ) ) {
				throw new Xapi_Error( 400, 'The ' . $name . ' parameter must be given once.' );
			}
		}

		$statement_id = self::uuid( $p, 'statementId' );
		$voided_id    = self::uuid( $p, 'voidedStatementId' );
		if ( null !== $statement_id || null !== $voided_id ) {
			if ( null !== $statement_id && null !== $voided_id ) {
				throw new Xapi_Error( 400, 'statementId and voidedStatementId cannot be combined.' );
			}
			$extra = array_diff( array_keys( $p ), self::SINGLE_PARAMS );
			if ( array() !== $extra ) {
				throw new Xapi_Error( 400, 'With statementId or voidedStatementId only format and attachments are allowed.' );
			}
		}

		$agent_hash = null;
		if ( isset( $p['agent'] ) ) {
			try {
				$agent = Json::decode_strict( $p['agent'], 65536, 8 );
				Actor_Validator::validate( $agent, $version, 'agent' );
				Agent_Identity::from_array( $agent );
			} catch ( Invalid_Input $e ) {
				throw new Xapi_Error( 400, 'The agent parameter must be an Agent or identified Group as JSON: ' . $e->getMessage() );
			}
			$agent_hash = Ref_Indexer::agent_hash( $agent );
		}

		$limit = self::DEFAULT_LIMIT;
		if ( isset( $p['limit'] ) ) {
			if ( 1 !== preg_match( '/\A\d{1,9}\z/', $p['limit'] ) ) {
				throw new Xapi_Error( 400, 'limit must be a non-negative integer.' );
			}
			$limit = (int) $p['limit'];
			$limit = 0 === $limit ? self::DEFAULT_LIMIT : min( $limit, self::MAX_LIMIT );
		}

		$format = $p['format'] ?? 'exact';
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			throw new Xapi_Error( 400, 'format must be exact, ids or canonical.' );
		}

		return new self(
			$statement_id,
			$voided_id,
			$agent_hash,
			self::iri( $p, 'verb' ),
			self::iri( $p, 'activity' ),
			self::uuid( $p, 'registration' ),
			self::boolean( $p, 'related_activities' ),
			self::boolean( $p, 'related_agents' ),
			self::time( $p, 'since', $version ),
			self::time( $p, 'until', $version ),
			$limit,
			$format,
			self::boolean( $p, 'attachments' ),
			self::boolean( $p, 'ascending' )
		);
	}

	/**
	 * The list query a `more` cursor carries (signed, so already validated).
	 *
	 * @param More_Cursor $c Cursor.
	 *
	 * @return self
	 * @throws Xapi_Error 400 when the cursor holds no query.
	 */
	public static function from_cursor( More_Cursor $c ): self {
		$q = $c->params()['query'] ?? null;
		if ( ! is_array( $q ) ) {
			throw new Xapi_Error( 400, 'The more token is invalid.' );
		}
		$hex   = static fn( string $k ) => isset( $q[ $k ] ) && is_string( $q[ $k ] ) ? (string) hex2bin( $q[ $k ] ) : null;
		$str   = static fn( string $k ) => isset( $q[ $k ] ) && is_string( $q[ $k ] ) ? $q[ $k ] : null;
		$limit = (int) ( $q['limit'] ?? self::DEFAULT_LIMIT );
		return new self(
			null,
			null,
			$hex( 'agent_hash' ),
			$str( 'verb' ),
			$str( 'activity' ),
			$str( 'registration' ),
			true === ( $q['related_activities'] ?? false ),
			true === ( $q['related_agents'] ?? false ),
			$str( 'since' ),
			$str( 'until' ),
			max( 1, min( $limit, self::MAX_LIMIT ) ),
			in_array( $q['format'] ?? null, self::FORMATS, true ) ? $q['format'] : 'exact',
			true === ( $q['attachments'] ?? false ),
			true === ( $q['ascending'] ?? false )
		);
	}

	/**
	 * The list query as stored in a `more` cursor (the agent as its hash, in hex).
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'agent_hash'         => null === $this->agent_hash ? null : bin2hex( $this->agent_hash ),
			'verb'               => $this->verb,
			'activity'           => $this->activity,
			'registration'       => $this->registration,
			'related_activities' => $this->related_activities,
			'related_agents'     => $this->related_agents,
			'since'              => $this->since,
			'until'              => $this->until,
			'limit'              => $this->limit,
			'format'             => $this->format,
			'attachments'        => $this->attachments,
			'ascending'          => $this->ascending,
		);
	}

	/**
	 * A UUID parameter, lowercased.
	 *
	 * @param array  $p    Parameters.
	 * @param string $name Name.
	 *
	 * @return string|null
	 * @throws Xapi_Error 400 when not a UUID.
	 */
	private static function uuid( array $p, string $name ): ?string {
		if ( ! isset( $p[ $name ] ) ) {
			return null;
		}
		if ( ! Uuid::is_valid( $p[ $name ] ) ) {
			throw new Xapi_Error( 400, $name . ' must be a UUID.' );
		}
		return strtolower( $p[ $name ] );
	}

	/**
	 * An IRI parameter.
	 *
	 * @param array  $p    Parameters.
	 * @param string $name Name.
	 *
	 * @return string|null
	 * @throws Xapi_Error 400 when not an IRI.
	 */
	private static function iri( array $p, string $name ): ?string {
		if ( ! isset( $p[ $name ] ) ) {
			return null;
		}
		try {
			Primitives::assert_iri( $p[ $name ], $name );
		} catch ( Invalid_Input $e ) {
			throw new Xapi_Error( 400, $name . ' must be an IRI.' );
		}
		return $p[ $name ];
	}

	/**
	 * A boolean parameter (`true` or `false`).
	 *
	 * @param array  $p    Parameters.
	 * @param string $name Name.
	 *
	 * @return boolean
	 * @throws Xapi_Error 400 for any other value.
	 */
	private static function boolean( array $p, string $name ): bool {
		if ( ! isset( $p[ $name ] ) ) {
			return false;
		}
		if ( 'true' !== $p[ $name ] && 'false' !== $p[ $name ] ) {
			throw new Xapi_Error( 400, $name . ' must be true or false.' );
		}
		return 'true' === $p[ $name ];
	}

	/**
	 * A timestamp parameter as a DB value in UTC with microseconds.
	 *
	 * @param array  $p       Parameters.
	 * @param string $name    Name.
	 * @param string $version Negotiated xAPI version.
	 *
	 * @return string|null
	 * @throws Xapi_Error 400 when not a timestamp.
	 */
	private static function time( array $p, string $name, string $version ): ?string {
		if ( ! isset( $p[ $name ] ) ) {
			return null;
		}
		try {
			return Timestamp::to_db( Timestamp::parse( $p[ $name ], $version ) );
		} catch ( Invalid_Input $e ) {
			throw new Xapi_Error( 400, $name . ' must be a timestamp.' );
		}
	}
}
