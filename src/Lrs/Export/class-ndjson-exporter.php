<?php
/**
 * NDJSON statement export.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Export;

use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Statements\Ref_Indexer;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;
use LearningLedger\Util\Uuid;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables, never cached.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name and fixed clauses are interpolated.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed raw.

/**
 * Streams stored statements as NDJSON, oldest first, one chunk of rows in memory at a time.
 *
 * Each line is `{"statement":<the stored JSON bytes>,"stored":"<time>","content_sha256":"<hex>"}`.
 */
final class Ndjson_Exporter {

	/**
	 * Rows read per query.
	 */
	public const CHUNK = 500;

	/**
	 * Check and normalise the filters.
	 *
	 * @param array $filters Keys (all optional): `since`, `until` (RFC 3339, compared with the stored time, both inclusive),
	 *                       `registration` (UUID), `agent` (an Agent as a JSON string or array; matches the actor).
	 *
	 * @return array{since:?string,until:?string,registration:?string,agent_hash:?string} Times in database format,
	 *               registration as lowercase UUID, agent as hex SHA-1.
	 * @throws Invalid_Input On a malformed filter.
	 */
	public static function normalize_filters( array $filters ): array {
		$out = array(
			'since'        => null,
			'until'        => null,
			'registration' => null,
			'agent_hash'   => null,
		);
		foreach ( array( 'since', 'until' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== $filters[ $key ] ) {
				if ( ! is_string( $filters[ $key ] ) ) {
					throw new Invalid_Input( 'The ' . $key . ' filter must be an RFC 3339 timestamp.', 'invalid_filter', $key );
				}
				try {
					$out[ $key ] = Timestamp::to_db( Timestamp::parse( $filters[ $key ], '2.0.0' ) );
				} catch ( Invalid_Input $e ) {
					throw new Invalid_Input( 'The ' . $key . ' filter must be an RFC 3339 timestamp.', 'invalid_filter', $key );
				}
			}
		}
		if ( isset( $filters['registration'] ) && '' !== $filters['registration'] ) {
			if ( ! is_string( $filters['registration'] ) || ! Uuid::is_valid( $filters['registration'] ) ) {
				throw new Invalid_Input( 'The registration filter must be a UUID.', 'invalid_filter', 'registration' );
			}
			$out['registration'] = strtolower( $filters['registration'] );
		}
		if ( isset( $filters['agent'] ) && '' !== $filters['agent'] && array() !== $filters['agent'] ) {
			$agent = is_string( $filters['agent'] ) ? json_decode( $filters['agent'], true, 32 ) : $filters['agent'];
			$hash  = is_array( $agent ) ? Ref_Indexer::agent_hash( $agent ) : null;
			if ( null === $hash ) {
				throw new Invalid_Input( 'The agent filter must be an Agent object with an identifier.', 'invalid_filter', 'agent' );
			}
			$out['agent_hash'] = bin2hex( $hash );
		}
		return $out;
	}

	/**
	 * Write every matching statement, oldest first.
	 *
	 * @param array    $filters As for normalize_filters().
	 * @param callable $write   Called with each line, which ends in a newline.
	 *
	 * @return integer Lines written.
	 * @throws Invalid_Input On a malformed filter, before anything is written.
	 */
	public function stream( array $filters, callable $write ): int {
		global $wpdb;
		$f     = self::normalize_filters( $filters );
		$table = Tables::name( 'statements' );

		$where = '';
		$args  = array();
		if ( null !== $f['since'] ) {
			$where .= ' AND `stored` >= %s';
			$args[] = $f['since'];
		}
		if ( null !== $f['until'] ) {
			$where .= ' AND `stored` <= %s';
			$args[] = $f['until'];
		}
		if ( null !== $f['registration'] ) {
			$where .= ' AND registration = UNHEX(%s)';
			$args[] = str_replace( '-', '', $f['registration'] );
		}
		if ( null !== $f['agent_hash'] ) {
			$where .= ' AND actor_hash = UNHEX(%s)';
			$args[] = $f['agent_hash'];
		}
		$sql = "SELECT seq, `stored`, LOWER(HEX(content_sha256)) AS sha, body FROM {$table} WHERE seq > %d{$where} ORDER BY seq LIMIT " . self::CHUNK;

		$count = 0;
		$after = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $after ), $args ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only, built above.
			$wpdb->flush();
			if ( ! is_array( $rows ) ) {
				throw new \RuntimeException( 'The statements could not be read.' );
			}
			$size = count( $rows );
			foreach ( $rows as $i => $row ) {
				$after = (int) $row['seq'];
				$write( '{"statement":' . $row['body'] . ',"stored":"' . Timestamp::to_xapi( Timestamp::from_db( $row['stored'] ) ) . '","content_sha256":"' . $row['sha'] . "\"}\n" );
				unset( $rows[ $i ] );
				++$count;
			}
		} while ( self::CHUNK === $size );
		return $count;
	}

	/**
	 * The filters as recorded in the audit log: no personal data, the agent as its hash.
	 *
	 * @param array $filters As for normalize_filters().
	 *
	 * @return array<string,string>
	 * @throws Invalid_Input On a malformed filter.
	 */
	public static function describe( array $filters ): array {
		$f   = self::normalize_filters( $filters );
		$out = array();
		foreach ( array( 'since', 'until' ) as $key ) {
			if ( null !== $f[ $key ] ) {
				$out[ $key ] = Timestamp::to_xapi( Timestamp::from_db( $f[ $key ] ) );
			}
		}
		if ( null !== $f['registration'] ) {
			$out['registration'] = $f['registration'];
		}
		if ( null !== $f['agent_hash'] ) {
			$out['agent_hash'] = $f['agent_hash'];
		}
		return $out;
	}
}
