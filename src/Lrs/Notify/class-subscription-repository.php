<?php
/**
 * Webhook subscriptions.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Notify;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- subscriptions and outbox are plugin-owned.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed raw.

use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Statements\Stored_Statement;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

/**
 * `lledger_subscriptions`: a target URL and filters on actor homePage, activity-IRI prefix,
 * verbs and registrations. An absent filter matches everything.
 *
 * A subscription is returned as an array: `id`, `url`, `filters`, `status`,
 * `failing_since` (DATETIME string or null), `cursor` (last delivered seq), `created_at`.
 */
final class Subscription_Repository {

	const ACTIVE  = 'active';
	const FAILING = 'failing';

	const FILTERS = array( 'actor_home_page', 'activity_prefix', 'verbs', 'registrations' );

	/**
	 * Longest target URL accepted.
	 */
	private const MAX_URL = 2048;

	/**
	 * Subscriptions loaded once for matching().
	 *
	 * @var array[]|null
	 */
	private ?array $cache = null;

	/**
	 * Create a subscription.
	 *
	 * @param string $url     Target URL: https (http too when LLEDGER_TESTING), not on a private or link-local address.
	 * @param array  $filters Any of `actor_home_page`, `activity_prefix`, `verbs[]`, `registrations[]`.
	 *
	 * @return integer New id.
	 *
	 * @throws Invalid_Input On a bad URL (`unsafe_url` for a private target) or bad filters.
	 * @throws \RuntimeException When the insert fails.
	 */
	public function create( string $url, array $filters ): int {
		$url     = self::validate_url( $url );
		$filters = self::normalize_filters( $filters );
		global $wpdb;
		$ok = $wpdb->insert(
			Tables::name( 'subscriptions' ),
			array(
				'target_url' => $url,
				'filters'    => wp_json_encode( (object) $filters ),
				'status'     => self::ACTIVE,
				'cursor_seq' => 0,
				'created_at' => Timestamp::to_db( Clock::now() ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		if ( 1 !== $ok ) {
			throw new \RuntimeException( 'The subscription could not be stored.' );
		}
		$this->cache = null;
		return (int) $wpdb->insert_id;
	}

	/**
	 * One subscription.
	 *
	 * @param integer $id Id.
	 *
	 * @return array|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Tables::name( 'subscriptions' ), $id ) );
		return null === $row ? null : self::hydrate( $row );
	}

	/**
	 * Every subscription, oldest first.
	 *
	 * @return array[]
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', Tables::name( 'subscriptions' ) ) );
		return array_map( array( self::class, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Delete a subscription and its undelivered and delivered outbox rows.
	 *
	 * @param integer $id Id.
	 *
	 * @return boolean False when there was no such subscription.
	 *
	 * @throws \RuntimeException When the database write fails (nothing is deleted).
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Could not start the subscription delete.' );
		}
		$n    = $wpdb->delete( Tables::name( 'subscriptions' ), array( 'id' => $id ), array( '%d' ) );
		$rows = $wpdb->delete( Tables::name( 'outbox' ), array( 'subscription_id' => $id ), array( '%d' ) );
		if ( false === $n || false === $rows || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			throw new \RuntimeException( 'Could not delete the subscription.' );
		}
		$this->cache = null;
		return 1 === $n;
	}

	/**
	 * Ids of the subscriptions a stored statement matches, in id order.
	 *
	 * @param Stored_Statement $row Stored statement.
	 *
	 * @return int[]
	 */
	public function matching( Stored_Statement $row ): array {
		if ( null === $this->cache ) {
			$this->cache = $this->all();
		}
		if ( array() === $this->cache ) {
			return array();
		}
		$st  = $row->statement();
		$ids = array();
		foreach ( $this->cache as $sub ) {
			if ( self::matches( $sub['filters'], $st ) ) {
				$ids[] = $sub['id'];
			}
		}
		return $ids;
	}

	/**
	 * Mark a subscription failing.
	 *
	 * @param integer $id Id.
	 *
	 * @return void
	 */
	public function mark_failing( int $id ): void {
		global $wpdb;
		$wpdb->update( Tables::name( 'subscriptions' ), array( 'status' => self::FAILING ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Mark a subscription active and forget its failure streak.
	 *
	 * @param integer $id Id.
	 *
	 * @return void
	 */
	public function mark_active( int $id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET status = %s, failing_since = NULL WHERE id = %d', Tables::name( 'subscriptions' ), self::ACTIVE, $id )
		);
	}

	/**
	 * Record a failed delivery: the first failure of a streak starts `failing_since`.
	 *
	 * @param integer $id Id.
	 *
	 * @return \DateTimeImmutable When the current failure streak began.
	 */
	public function note_failure( int $id ): \DateTimeImmutable {
		global $wpdb;
		$now   = Timestamp::to_db( Clock::now() );
		$table = Tables::name( 'subscriptions' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET failing_since = %s WHERE id = %d AND failing_since IS NULL', $table, $now, $id ) );
		$since = $wpdb->get_var( $wpdb->prepare( 'SELECT failing_since FROM %i WHERE id = %d', $table, $id ) );
		return Timestamp::from_db( is_string( $since ) ? $since : $now );
	}

	/**
	 * Move the delivered cursor forward (never back).
	 *
	 * @param integer $id  Id.
	 * @param integer $seq Last delivered seq.
	 *
	 * @return void
	 */
	public function advance_cursor( int $id, int $seq ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET cursor_seq = GREATEST(cursor_seq, %d) WHERE id = %d', Tables::name( 'subscriptions' ), $seq, $id )
		);
	}

	/**
	 * Whether a statement passes a subscription's filters.
	 *
	 * The homePage comparison ignores a trailing slash. The activity prefix applies to an
	 * Activity object; other objects (a voiding StatementRef, an Agent) pass it, as in Bindings.
	 *
	 * @param array $filters Normalised filters.
	 * @param array $st      Decoded statement.
	 *
	 * @return boolean
	 */
	public static function matches( array $filters, array $st ): bool {
		if ( isset( $filters['actor_home_page'] ) ) {
			$home = $st['actor']['account']['homePage'] ?? null;
			if ( ! is_string( $home ) || untrailingslashit( $home ) !== untrailingslashit( $filters['actor_home_page'] ) ) {
				return false;
			}
		}
		if ( isset( $filters['activity_prefix'] ) ) {
			$object = is_array( $st['object'] ?? null ) ? $st['object'] : array();
			if ( 'Activity' === ( $object['objectType'] ?? 'Activity' ) ) {
				$id = $object['id'] ?? null;
				if ( ! is_string( $id ) || ! str_starts_with( $id, $filters['activity_prefix'] ) ) {
					return false;
				}
			}
		}
		if ( isset( $filters['verbs'] ) ) {
			$verb = $st['verb']['id'] ?? null;
			if ( ! is_string( $verb ) || ! in_array( $verb, $filters['verbs'], true ) ) {
				return false;
			}
		}
		if ( isset( $filters['registrations'] ) ) {
			$reg = $st['context']['registration'] ?? null;
			if ( ! is_string( $reg ) || ! in_array( strtolower( $reg ), $filters['registrations'], true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Validate filters. Empty values are dropped; registrations are lowercased.
	 *
	 * @param array $in Filters.
	 *
	 * @return array
	 *
	 * @throws Invalid_Input On an unknown key or a bad value.
	 */
	public static function normalize_filters( array $in ): array {
		$out = array();
		foreach ( $in as $key => $value ) {
			if ( ! in_array( $key, self::FILTERS, true ) ) {
				throw new Invalid_Input( 'Unknown subscription filter.', 'unknown_property', 'filters.' . $key );
			}
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}
			switch ( $key ) {
				case 'actor_home_page':
					if ( ! is_string( $value ) || ! self::is_iri( $value ) ) {
						throw new Invalid_Input( 'actor_home_page must be an absolute IRI.', 'invalid', 'filters.actor_home_page' );
					}
					$out[ $key ] = $value;
					break;
				case 'activity_prefix':
					if ( ! is_string( $value ) || ! self::is_iri( $value ) ) {
						throw new Invalid_Input( 'activity_prefix must be an absolute IRI.', 'invalid', 'filters.activity_prefix' );
					}
					$out[ $key ] = $value;
					break;
				case 'verbs':
					if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
						throw new Invalid_Input( 'verbs must be a list of IRIs.', 'invalid', 'filters.verbs' );
					}
					foreach ( $value as $verb ) {
						if ( ! is_string( $verb ) || ! self::is_iri( $verb ) ) {
							throw new Invalid_Input( 'verbs must be a list of IRIs.', 'invalid', 'filters.verbs' );
						}
					}
					$out[ $key ] = array_values( array_unique( $value ) );
					break;
				case 'registrations':
					if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
						throw new Invalid_Input( 'registrations must be a list of UUIDs.', 'invalid', 'filters.registrations' );
					}
					$regs = array();
					foreach ( $value as $reg ) {
						if ( ! is_string( $reg ) || ! Uuid::is_valid( $reg ) ) {
							throw new Invalid_Input( 'registrations must be a list of UUIDs.', 'invalid', 'filters.registrations' );
						}
						$regs[] = strtolower( $reg );
					}
					$out[ $key ] = array_values( array_unique( $regs ) );
					break;
			}
		}
		$ordered = array();
		foreach ( self::FILTERS as $key ) {
			if ( isset( $out[ $key ] ) ) {
				$ordered[ $key ] = $out[ $key ];
			}
		}
		return $ordered;
	}

	/**
	 * Validate a target URL.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 *
	 * @throws Invalid_Input On a bad URL.
	 */
	private static function validate_url( string $url ): string {
		$url     = trim( $url );
		$parts   = wp_parse_url( $url );
		$schemes = defined( 'LLEDGER_TESTING' ) && LLEDGER_TESTING ? array( 'https', 'http' ) : array( 'https' );
		if ( strlen( $url ) > self::MAX_URL || ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), $schemes, true )
			|| '' === (string) ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] )
			|| esc_url_raw( $url, $schemes ) !== $url ) {
			throw new Invalid_Input( 'The target must be an https URL without credentials.', 'invalid', 'url' );
		}
		if ( ! Webhook_Sender::target_allowed( $url ) ) {
			throw new Invalid_Input( 'The target is on a private, loopback or link-local address.', 'unsafe_url', 'url' );
		}
		// The checks wp_safe_remote_post() will apply at send time: the host resolves, the address and port are allowed.
		if ( false === wp_http_validate_url( $url ) ) {
			throw new Invalid_Input( 'The target host does not resolve, or its address or port is not allowed.', 'unsafe_url', 'url' );
		}
		return $url;
	}

	/**
	 * Whether a string is an absolute IRI (has a scheme).
	 *
	 * @param string $v Value.
	 *
	 * @return boolean
	 */
	private static function is_iri( string $v ): bool {
		return 1 === preg_match( '/\A[A-Za-z][A-Za-z0-9+.-]*:\S+\z/', $v );
	}

	/**
	 * Row to array.
	 *
	 * @param object $row Database row.
	 *
	 * @return array
	 */
	private static function hydrate( object $row ): array {
		$filters = json_decode( (string) $row->filters, true );
		return array(
			'id'            => (int) $row->id,
			'url'           => (string) $row->target_url,
			'filters'       => is_array( $filters ) ? $filters : array(),
			'status'        => (string) $row->status,
			'failing_since' => null === $row->failing_since ? null : (string) $row->failing_since,
			'cursor'        => (int) $row->cursor_seq,
			'created_at'    => (string) $row->created_at,
		);
	}
}
