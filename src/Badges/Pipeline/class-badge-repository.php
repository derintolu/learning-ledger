<?php
/**
 * Badge rows.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Pipeline;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table, never cached: badge status must be read fresh.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name is interpolated.

use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

/**
 * Reads and writes `lledger_badges`: one row per achievement per registration.
 *
 * A badge is created pending (or held when the recomputation disagreed) and signed later,
 * at claim time. The unique key (achievement_hash, registration) is what keeps two
 * evaluations racing each other from creating the same badge twice.
 */
final class Badge_Repository {

	const PENDING = 'pending';
	const HELD    = 'held';
	const ISSUED  = 'issued';
	const REVOKED = 'revoked';

	/**
	 * Every badge status.
	 *
	 * @var string[]
	 */
	const STATUSES = array( self::PENDING, self::HELD, self::ISSUED, self::REVOKED );

	/**
	 * Columns read into a badge array.
	 */
	private const COLUMNS = 'id, achievement, registration, actor_hash, course_iri_hash, trigger_statement_id, status, narrative, waived_count, recipient_hash, salt, credential, status_list_id, status_index, valid_from, issued_at, revoked_at, shared, created_at';

	/**
	 * Create a badge awaiting its claim.
	 *
	 * @param array   $achievement  Achievement fields (needs a string `id`).
	 * @param string  $registration Registration UUID.
	 * @param string  $actor_hash   Raw SHA-1 actor hash of the learner.
	 * @param string  $course_iri   Course IRI.
	 * @param string  $trigger_id   Id of the statement that earned the badge.
	 * @param string  $narrative    Evidence narrative.
	 * @param integer $waived_count Lessons that counted through a waiver.
	 * @param string  $status       PENDING, or HELD when the recomputation disagreed with the trigger.
	 *
	 * @return string|null The new badge id, or null when this achievement already has a badge in the registration.
	 *
	 * @throws Invalid_Input On a missing achievement id, an invalid UUID or status.
	 * @throws \RuntimeException On any other database error.
	 */
	public function create_pending( array $achievement, string $registration, string $actor_hash, string $course_iri, string $trigger_id, string $narrative, int $waived_count, string $status = self::PENDING ): ?string {
		$achievement_id = $achievement['id'] ?? null;
		if ( ! is_string( $achievement_id ) || '' === $achievement_id ) {
			throw new Invalid_Input( 'The achievement needs an id.', 'invalid', 'achievement.id' );
		}
		if ( ! Uuid::is_valid( $registration ) || ! Uuid::is_valid( $trigger_id ) ) {
			throw new Invalid_Input( 'Registration and trigger must be UUIDs.', 'invalid', 'registration' );
		}
		if ( self::PENDING !== $status && self::HELD !== $status ) {
			throw new Invalid_Input( 'A new badge is pending or held.', 'invalid', 'status' );
		}

		global $wpdb;
		$table = Tables::name( 'badges' );
		$id    = Uuid::v4();
		$sql   = $wpdb->prepare(
			"INSERT INTO {$table} (id, achievement_hash, achievement, registration, actor_hash, course_iri_hash, trigger_statement_id, status, narrative, waived_count, created_at) VALUES (%s, UNHEX(%s), %s, UNHEX(%s), UNHEX(%s), UNHEX(%s), UNHEX(%s), %s, %s, %d, %s)",
			$id,
			sha1( $achievement_id ),
			Json::encode_exact( $achievement ),
			self::hex_uuid( $registration ),
			bin2hex( $actor_hash ),
			sha1( $course_iri ),
			self::hex_uuid( $trigger_id ),
			$status,
			$narrative,
			$waived_count,
			Timestamp::to_db( Clock::now() )
		);

		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$wpdb->suppress_errors( $suppress );
		if ( false === $ok ) {
			if ( str_contains( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
				return null;
			}
			throw new \RuntimeException( 'Could not store the badge.' );
		}
		return $id;
	}

	/**
	 * A badge by id.
	 *
	 * @param string $id Badge id.
	 *
	 * @return array|null Keys as in COLUMNS; hashes raw, UUIDs lowercase text, times xAPI format,
	 *                    `achievement` and `credential` decoded.
	 */
	public function get( string $id ): ?array {
		if ( ! Uuid::is_valid( $id ) ) {
			return null;
		}
		global $wpdb;
		$table = Tables::name( 'badges' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %s", strtolower( $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Badges of a registration, oldest first.
	 *
	 * @param string $registration Registration UUID.
	 *
	 * @return array[]
	 */
	public function for_registration( string $registration ): array {
		if ( ! Uuid::is_valid( $registration ) ) {
			return array();
		}
		global $wpdb;
		$table = Tables::name( 'badges' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE registration = UNHEX(%s) ORDER BY created_at, id", self::hex_uuid( $registration ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Badges of a learner, oldest first.
	 *
	 * @param string $actor_hash Raw SHA-1 actor hash.
	 *
	 * @return array[]
	 */
	public function for_actor( string $actor_hash ): array {
		global $wpdb;
		$table = Tables::name( 'badges' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE actor_hash = UNHEX(%s) ORDER BY created_at, id", bin2hex( $actor_hash ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Number of badges with a status.
	 *
	 * @param string $status One of STATUSES.
	 *
	 * @return integer
	 */
	public function count_with_status( string $status ): int {
		global $wpdb;
		$table = Tables::name( 'badges' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
	}

	/**
	 * Change a badge's status.
	 *
	 * @param string $id     Badge id.
	 * @param string $status One of STATUSES.
	 *
	 * @return boolean Whether a row changed.
	 *
	 * @throws Invalid_Input On an unknown status.
	 * @throws \RuntimeException On a database error.
	 */
	public function set_status( string $id, string $status ): bool {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new Invalid_Input( 'Unknown badge status.', 'invalid', 'status' );
		}
		global $wpdb;
		$table   = Tables::name( 'badges' );
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id = %s", $status, strtolower( $id ) ) );
		if ( false === $changed ) {
			throw new \RuntimeException( 'Could not change the badge status.' );
		}
		return $changed > 0;
	}

	/**
	 * Record the signed credential of a pending badge and mark it issued.
	 *
	 * Only a pending badge changes, so two claims racing each other issue it once.
	 *
	 * @param string  $id             Badge id.
	 * @param array   $credential     Signed credential.
	 * @param integer $list_id        Status list id.
	 * @param integer $index          Index in the status list.
	 * @param string  $recipient_hash Salted recipient identity hash.
	 * @param string  $salt           Salt of the recipient hash.
	 *
	 * @return boolean Whether this call issued the badge (false when it was not pending).
	 *
	 * @throws \RuntimeException On a database error.
	 */
	public function store_issued( string $id, array $credential, int $list_id, int $index, string $recipient_hash, string $salt ): bool {
		global $wpdb;
		$table = Tables::name( 'badges' );
		$now   = Clock::now();
		$from  = $now;
		if ( is_string( $credential['validFrom'] ?? null ) ) {
			try {
				$from = new \DateTimeImmutable( $credential['validFrom'] );
			} catch ( \Exception $e ) {
				$from = $now;
			}
		}
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET credential = %s, status_list_id = %d, status_index = %d, recipient_hash = %s, salt = %s, status = %s, valid_from = %s, issued_at = %s WHERE id = %s AND status = %s",
				Json::encode_exact( $credential ),
				$list_id,
				$index,
				$recipient_hash,
				$salt,
				self::ISSUED,
				Timestamp::to_db( $from ),
				Timestamp::to_db( $now ),
				strtolower( $id ),
				self::PENDING
			)
		);
		if ( false === $changed ) {
			throw new \RuntimeException( 'Could not store the issued badge.' );
		}
		return 1 === $changed;
	}

	/**
	 * Delete every badge of a learner (erasure).
	 *
	 * @param string $actor_hash Raw SHA-1 actor hash.
	 *
	 * @return integer Badges deleted.
	 */
	public function delete_for_actor( string $actor_hash ): int {
		global $wpdb;
		$table = Tables::name( 'badges' );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE actor_hash = UNHEX(%s)", bin2hex( $actor_hash ) ) );
	}

	/**
	 * UUID as 32 hex digits.
	 *
	 * @param string $id UUID.
	 *
	 * @return string
	 */
	private static function hex_uuid( string $id ): string {
		return str_replace( '-', '', strtolower( $id ) );
	}

	/**
	 * Optional database time in xAPI format.
	 *
	 * @param string|null $value Database value.
	 *
	 * @return string|null
	 */
	private static function time( ?string $value ): ?string {
		return null === $value ? null : Timestamp::to_xapi( Timestamp::from_db( $value ) );
	}

	/**
	 * Row to array.
	 *
	 * @param array $row Row.
	 *
	 * @return array
	 */
	private static function hydrate( array $row ): array {
		$achievement = json_decode( (string) $row['achievement'], true );
		$credential  = null === $row['credential'] ? null : json_decode( (string) $row['credential'], true );
		return array(
			'id'                   => (string) $row['id'],
			'achievement'          => is_array( $achievement ) ? $achievement : array(),
			'registration'         => Uuid::from_bin( $row['registration'] ),
			'actor_hash'           => (string) $row['actor_hash'],
			'course_iri_hash'      => (string) $row['course_iri_hash'],
			'trigger_statement_id' => Uuid::from_bin( $row['trigger_statement_id'] ),
			'status'               => (string) $row['status'],
			'narrative'            => (string) $row['narrative'],
			'waived_count'         => (int) $row['waived_count'],
			'recipient_hash'       => $row['recipient_hash'],
			'salt'                 => $row['salt'],
			'credential'           => is_array( $credential ) ? $credential : null,
			'status_list_id'       => null === $row['status_list_id'] ? null : (int) $row['status_list_id'],
			'status_index'         => null === $row['status_index'] ? null : (int) $row['status_index'],
			'valid_from'           => self::time( $row['valid_from'] ),
			'issued_at'            => self::time( $row['issued_at'] ),
			'revoked_at'           => self::time( $row['revoked_at'] ),
			'shared'               => 1 === (int) $row['shared'],
			'created_at'           => self::time( $row['created_at'] ),
		);
	}
}
