<?php
/**
 * Imported courses and their AUs.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Courses;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Plugin tables; names come from Tables::name(), values are prepared.

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;

/**
 * Stores the courses table and one course_aus row per AU.
 */
final class Course_Repository {

	/**
	 * The awardOn values the standard defines; anything else is a custom rule.
	 *
	 * @var string[]
	 */
	const STANDARD_RULES = array( 'au-moveon', 'block-satisfied', 'course-satisfied', 'any-path' );

	/**
	 * Columns read into a Course_Record.
	 */
	private const COLUMNS = 'c.course_iri, c.version, c.etag, c.tree, c.badges, c.badge_issuer, c.custom_rules, c.owner_client';

	/**
	 * Store a course, unless the stored one has the same ETag.
	 *
	 * Keeps the tree, `course.json#/badges` and `course.json#/badgeIssuer`; nothing else from
	 * course.json and no lesson JSON. Fires `learning_ledger/course_imported` when it stores.
	 *
	 * @param Course_Structure $s            Parsed, valid course structure.
	 * @param array|null       $course_json  Decoded cxm/course.json, null for plain cmi5.
	 * @param string           $etag         ETag of the imported bytes.
	 * @param string           $owner_client Importing client id.
	 *
	 * @return array{course:string,version:int,etag:string,changed:bool,created:bool}
	 *
	 * @throws Invalid_Input Codes `not_owner` (another client owns the course), `au_conflict` (an AU belongs to another course).
	 * @throws \RuntimeException When a database write fails.
	 */
	public function import( Course_Structure $s, ?array $course_json, string $etag, string $owner_client ): array {
		global $wpdb;
		$iri     = $s->course_id();
		$hash    = self::hash( $iri );
		$courses = Tables::name( 'courses' );
		$aus     = Tables::name( 'course_aus' );

		$badges = is_array( $course_json['badges'] ?? null ) ? array_values( $course_json['badges'] ) : array();
		$issuer = is_array( $course_json['badgeIssuer'] ?? null ) ? $course_json['badgeIssuer'] : null;
		$custom = false;
		foreach ( $badges as $badge ) {
			if ( ! is_array( $badge ) || ! in_array( $badge['awardOn'] ?? null, self::STANDARD_RULES, true ) ) {
				$custom = true;
			}
		}
		$au_hashes = array_values( array_unique( array_map( static fn( Au $au ): string => self::hash( $au->id() ), $s->aus() ) ) );

		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT id, version, etag, owner_client FROM {$courses} WHERE course_iri_hash = UNHEX(%s) FOR UPDATE", $hash ),
				ARRAY_A
			);
			if ( null !== $row && $row['owner_client'] !== $owner_client ) {
				throw new Invalid_Input( 'Another client owns this course.', 'not_owner', $iri );
			}
			if ( null !== $row && $row['etag'] === $etag ) {
				$wpdb->query( 'COMMIT' );
				return self::result( $iri, (int) $row['version'], $etag, false, false );
			}

			if ( array() !== $au_hashes ) {
				$in       = implode( ', ', array_fill( 0, count( $au_hashes ), 'UNHEX(%s)' ) );
				$conflict = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1 FROM {$aus} WHERE au_hash IN ({$in}) AND course_iri_hash <> UNHEX(%s) LIMIT 1",
						array_merge( $au_hashes, array( $hash ) )
					)
				);
				if ( null !== $conflict ) {
					throw new Invalid_Input( 'An AU in this course already belongs to another course.', 'au_conflict', $iri );
				}
			}

			$version = null === $row ? 1 : (int) $row['version'] + 1;
			$fields  = array(
				'course_iri'   => $iri,
				'version'      => $version,
				'etag'         => $etag,
				'xml'          => $s->xml(),
				'tree'         => Json::encode_exact( $s->to_array() ),
				'badges'       => Json::encode_exact( $badges ),
				'badge_issuer' => Json::encode_exact( $issuer ),
				'custom_rules' => $custom ? 1 : 0,
				'owner_client' => $owner_client,
				'updated'      => Timestamp::to_db( Clock::now() ),
			);
			$formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
			if ( null === $row ) {
				$written = $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$courses} (course_iri_hash, course_iri, version, etag, xml, tree, badges, badge_issuer, custom_rules, owner_client, updated) VALUES (UNHEX(%s), " . implode( ', ', $formats ) . ')',
						array_merge( array( $hash ), array_values( $fields ) )
					)
				);
			} else {
				$written = $wpdb->update( $courses, $fields, array( 'id' => (int) $row['id'] ), $formats, array( '%d' ) );
			}
			if ( false === $written ) {
				throw new \RuntimeException( 'Could not store the course.' );
			}

			if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$aus} WHERE course_iri_hash = UNHEX(%s)", $hash ) ) ) {
				throw new \RuntimeException( 'Could not replace the course AUs.' );
			}
			if ( array() !== $au_hashes ) {
				$values = implode( ', ', array_fill( 0, count( $au_hashes ), '(UNHEX(%s), UNHEX(%s))' ) );
				$args   = array();
				foreach ( $au_hashes as $au_hash ) {
					$args[] = $au_hash;
					$args[] = $hash;
				}
				if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO {$aus} (au_hash, course_iri_hash) VALUES {$values}", $args ) ) ) {
					throw new \RuntimeException( 'Could not store the course AUs.' );
				}
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Could not commit the course import.' );
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		/**
		 * A course was imported or its content changed.
		 *
		 * @param string $course_iri Course IRI.
		 * @param int    $version    New version.
		 */
		do_action( 'learning_ledger/course_imported', $iri, $version ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		return self::result( $iri, $version, $etag, true, null === $row );
	}

	/**
	 * A course by IRI.
	 *
	 * @param string $course_iri Course IRI.
	 *
	 * @return Course_Record|null
	 */
	public function get( string $course_iri ): ?Course_Record {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . Tables::name( 'courses' ) . ' c WHERE c.course_iri_hash = UNHEX(%s)', self::hash( $course_iri ) ),
			ARRAY_A
		);
		if ( null === $row || $row['course_iri'] !== $course_iri ) {
			return null;
		}
		return self::record( $row );
	}

	/**
	 * The course an AU belongs to.
	 *
	 * @param string $au_id AU publisher id (au@id).
	 *
	 * @return Course_Record|null
	 */
	public function for_au( string $au_id ): ?Course_Record {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ' . self::COLUMNS . ' FROM ' . Tables::name( 'course_aus' ) . ' a JOIN ' . Tables::name( 'courses' ) . ' c ON c.course_iri_hash = a.course_iri_hash WHERE a.au_hash = UNHEX(%s)',
				self::hash( $au_id )
			),
			ARRAY_A
		);
		return null === $row ? null : self::record( $row );
	}

	/**
	 * The course a statement is about: its object `{au}/au`, else any grouping activity that is an AU.
	 *
	 * @param array $statement Statement.
	 *
	 * @return Course_Record|null
	 */
	public function for_statement( array $statement ): ?Course_Record {
		$candidates = array();
		$object     = is_array( $statement['object'] ?? null ) ? $statement['object'] : array();
		$object_id  = $object['id'] ?? null;
		if ( 'Activity' === ( $object['objectType'] ?? 'Activity' ) && is_string( $object_id ) && str_ends_with( $object_id, '/au' ) ) {
			$candidates[] = substr( $object_id, 0, -3 );
		}
		$grouping = $statement['context']['contextActivities']['grouping'] ?? array();
		if ( is_array( $grouping ) && isset( $grouping['id'] ) ) {
			$grouping = array( $grouping );
		}
		foreach ( is_array( $grouping ) ? $grouping : array() as $activity ) {
			if ( is_array( $activity ) && is_string( $activity['id'] ?? null ) ) {
				$candidates[] = $activity['id'];
			}
		}
		foreach ( array_unique( $candidates ) as $au_id ) {
			$record = $this->for_au( $au_id );
			if ( null !== $record ) {
				return $record;
			}
		}
		return null;
	}

	/**
	 * Hex SHA-1 of an IRI, as the hash columns hold it.
	 *
	 * @param string $iri IRI.
	 *
	 * @return string
	 */
	private static function hash( string $iri ): string {
		return bin2hex( sha1( $iri, true ) );
	}

	/**
	 * Builds a record from a row.
	 *
	 * @param array $row Row with the COLUMNS fields.
	 *
	 * @return Course_Record
	 */
	private static function record( array $row ): Course_Record {
		$tree   = json_decode( (string) $row['tree'], true );
		$badges = json_decode( (string) $row['badges'], true );
		$issuer = json_decode( (string) $row['badge_issuer'], true );
		return new Course_Record(
			(string) $row['course_iri'],
			(int) $row['version'],
			(string) $row['etag'],
			is_array( $tree ) ? $tree : array(),
			is_array( $badges ) ? $badges : array(),
			is_array( $issuer ) ? $issuer : null,
			1 === (int) $row['custom_rules'],
			(string) $row['owner_client']
		);
	}

	/**
	 * Import outcome.
	 *
	 * @param string  $iri     Course IRI.
	 * @param integer $version Version.
	 * @param string  $etag    ETag.
	 * @param boolean $changed Whether anything was stored.
	 * @param boolean $created Whether the course is new.
	 *
	 * @return array{course:string,version:int,etag:string,changed:bool,created:bool}
	 */
	private static function result( string $iri, int $version, string $etag, bool $changed, bool $created ): array {
		return array(
			'course'  => $iri,
			'version' => $version,
			'etag'    => $etag,
			'changed' => $changed,
			'created' => $created,
		);
	}
}
