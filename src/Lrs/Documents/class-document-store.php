<?php
/**
 * Document storage.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Documents;

use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;
use LearningLedger\Xapi\Agent_Identity;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- custom table; SQL is built from fixed fragments and every value goes through $wpdb->prepare().
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed; HTTP layers JSON-encode them.

/**
 * Stores State, Agent Profile and Activity Profile documents as bytes in `lledger_documents`,
 * with SHA-1 ETags and optimistic concurrency.
 *
 * Content is sent as hex (UNHEX) so arbitrary bytes never meet wpdb's charset check.
 * Conditional writes are single `UPDATE … WHERE etag …` / `INSERT` statements whose affected
 * rows decide the outcome, so two writers holding the same ETag cannot both win.
 */
final class Document_Store {

	const DEFAULT_MAX_BYTES = 2097152;

	const MAX_BYTES_OPTION = 'lledger_document_max_bytes';

	/**
	 * How often a POST merge retries after losing a race to another writer.
	 */
	const MERGE_ATTEMPTS = 5;

	/**
	 * The stored document, if any.
	 *
	 * @param Doc_Key $k Key with an id.
	 *
	 * @return Document|null
	 */
	public function get( Doc_Key $k ): ?Document {
		global $wpdb;
		self::require_id( $k );
		list( $where, $args ) = self::where( $k, true );
		$row                  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT content, content_type, etag, updated FROM ' . Tables::name( 'documents' ) . " WHERE {$where}", $args ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return new Document( (string) $row['content'], (string) $row['content_type'], (string) $row['etag'], Timestamp::from_db( (string) $row['updated'] ) );
	}

	/**
	 * Store a document, replacing any existing one, subject to If-Match / If-None-Match.
	 *
	 * @param Doc_Key     $k             Key with an id.
	 * @param string      $content       Bytes.
	 * @param string      $content_type  Content-Type.
	 * @param string|null $if_match      If-Match header value.
	 * @param string|null $if_none_match If-None-Match header value.
	 *
	 * @return Document
	 * @throws Xapi_Error 400 bad key or type, 412 precondition failed, 413 too large, 500 storage failure.
	 */
	public function put( Doc_Key $k, string $content, string $content_type, ?string $if_match, ?string $if_none_match ): Document {
		self::require_id( $k );
		self::assert_size( $content );
		self::assert_content_type( $content_type );

		$doc   = new Document( $content, $content_type, sha1( $content ), Clock::now() );
		$match = self::tags( $if_match );
		$none  = self::tags( $if_none_match );

		if ( null === $match && null === $none ) {
			$this->upsert( $k, $doc );
			return $doc;
		}

		if ( null === $none || ! in_array( '*', $none, true ) ) {
			$in = null === $match || in_array( '*', $match, true ) ? null : $match;
			if ( 1 === $this->update( $k, $doc, $in, $none ) ) {
				return $doc;
			}
		}
		if ( null === $match && 1 === $this->insert( $k, $doc ) ) {
			return $doc;
		}

		// Nothing changed: either a precondition failed, or the row already holds exactly this write.
		$current = $this->get( $k );
		if ( null !== $current && $current->etag === $doc->etag && $current->content_type === $content_type && self::passes( $current, $match, $none ) ) {
			return $current;
		}
		throw new Xapi_Error( 412, 'Precondition failed: the document has changed or its existence does not match the request.' );
	}

	/**
	 * POST: merge a JSON object into the stored JSON object (top level), or store it when absent.
	 *
	 * @param Doc_Key     $k             Key with an id.
	 * @param string      $content       Bytes.
	 * @param string      $content_type  Content-Type.
	 * @param string|null $if_match      If-Match header value.
	 * @param string|null $if_none_match If-None-Match header value.
	 *
	 * @return Document
	 * @throws Xapi_Error 400 when either side is not a JSON object, 409 on persistent contention, 412, 413.
	 */
	public function post_merge( Doc_Key $k, string $content, string $content_type, ?string $if_match, ?string $if_none_match ): Document {
		self::require_id( $k );
		self::assert_size( $content );
		self::assert_content_type( $content_type );

		$match    = self::tags( $if_match );
		$none     = self::tags( $if_none_match );
		$is_json  = self::is_json_type( $content_type );
		$incoming = $is_json ? self::decode_object( $content ) : null;
		if ( $is_json && null === $incoming ) {
			throw new Xapi_Error( 400, 'A POSTed JSON document must be a JSON object.' );
		}

		for ( $attempt = 0; $attempt < self::MERGE_ATTEMPTS; $attempt++ ) {
			$current = $this->get( $k );
			if ( ! self::passes( $current, $match, $none ) ) {
				throw new Xapi_Error( 412, 'Precondition failed: the document has changed or its existence does not match the request.' );
			}

			if ( null === $current ) {
				$doc = new Document( $content, $content_type, sha1( $content ), Clock::now() );
				if ( 1 === $this->insert( $k, $doc ) ) {
					return $doc;
				}
				continue;
			}

			if ( ! $is_json || ! self::is_json_type( $current->content_type ) ) {
				throw new Xapi_Error( 400, 'POST merges only when both the stored and the new document are application/json objects.' );
			}
			$merged = self::decode_object( $current->content );
			if ( null === $merged ) {
				throw new Xapi_Error( 400, 'The stored document is not a JSON object, so it cannot be merged.' );
			}
			foreach ( get_object_vars( $incoming ) as $name => $value ) {
				$merged->{$name} = $value;
			}
			$bytes = Json::encode_exact( $merged );
			self::assert_size( $bytes );

			$doc = new Document( $bytes, $current->content_type, sha1( $bytes ), Clock::now() );
			if ( 1 === $this->update( $k, $doc, array( $current->etag ), null ) ) {
				return $doc;
			}
			$again = $this->get( $k );
			if ( null !== $again && $again->etag === $doc->etag && $current->etag === $doc->etag ) {
				return $again;
			}
		}
		throw new Xapi_Error( 409, 'The document is being changed by another request; try again.' );
	}

	/**
	 * Delete one document, subject to If-Match.
	 *
	 * @param Doc_Key     $k        Key with an id.
	 * @param string|null $if_match If-Match header value.
	 *
	 * @return void
	 * @throws Xapi_Error 412 when If-Match does not match.
	 */
	public function delete( Doc_Key $k, ?string $if_match ): void {
		global $wpdb;
		self::require_id( $k );
		$match                = self::tags( $if_match );
		list( $where, $args ) = self::where( $k, true );
		if ( null !== $match && ! in_array( '*', $match, true ) ) {
			$where .= ' AND etag IN (' . implode( ',', array_fill( 0, count( $match ), '%s' ) ) . ')';
			$args   = array_merge( $args, $match );
		}
		$affected = self::run( $wpdb->prepare( 'DELETE FROM ' . Tables::name( 'documents' ) . " WHERE {$where}", $args ) );
		if ( null !== $match && 0 === $affected ) {
			throw new Xapi_Error( 412, 'Precondition failed: the document has changed or does not exist.' );
		}
	}

	/**
	 * Delete every state document for an activity and agent (and registration, when given).
	 *
	 * @param Doc_Key $k_without_id State key; its id is ignored.
	 *
	 * @return integer Documents deleted.
	 * @throws Xapi_Error 400 for a profile key.
	 */
	public function delete_all( Doc_Key $k_without_id ): int {
		global $wpdb;
		if ( 'state' !== $k_without_id->kind ) {
			throw new Xapi_Error( 400, 'Only state documents can be deleted as a set.' );
		}
		list( $where, $args ) = self::where( $k_without_id, false );
		return self::run( $wpdb->prepare( 'DELETE FROM ' . Tables::name( 'documents' ) . " WHERE {$where}", $args ) );
	}

	/**
	 * Ids of the documents in a context, optionally only those changed after `$since`.
	 *
	 * @param Doc_Key                 $k_without_id Key; its id is ignored. A state key without a registration covers all registrations.
	 * @param \DateTimeImmutable|null $since        Exclusive lower bound on `updated`.
	 *
	 * @return string[]
	 */
	public function list_ids( Doc_Key $k_without_id, ?\DateTimeImmutable $since ): array {
		global $wpdb;
		list( $where, $args ) = self::where( $k_without_id, false );
		if ( null !== $since ) {
			$where .= ' AND updated > %s';
			$args[] = Timestamp::to_db( $since );
		}
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT doc_id FROM ' . Tables::name( 'documents' ) . " WHERE {$where} ORDER BY updated, id", $args ) );
		return array_map( 'strval', $ids );
	}

	/**
	 * Delete every state and agent profile document of an agent (erasure).
	 *
	 * @param Agent_Identity $a Agent.
	 *
	 * @return integer Documents deleted.
	 */
	public function delete_for_agent( Agent_Identity $a ): int {
		global $wpdb;
		return self::run(
			$wpdb->prepare(
				'DELETE FROM ' . Tables::name( 'documents' ) . " WHERE kind IN ('state','agent_profile') AND agent_hash = UNHEX(%s)",
				bin2hex( $a->hash() )
			)
		);
	}

	/**
	 * Largest document accepted, in bytes.
	 *
	 * @return integer
	 */
	public static function max_bytes(): int {
		$max = (int) get_option( self::MAX_BYTES_OPTION, self::DEFAULT_MAX_BYTES );
		return $max > 0 ? $max : self::DEFAULT_MAX_BYTES;
	}

	/**
	 * Whether a Content-Type is application/json (parameters ignored).
	 *
	 * @param string $content_type Content-Type.
	 *
	 * @return boolean
	 */
	public static function is_json_type( string $content_type ): bool {
		return 'application/json' === strtolower( trim( explode( ';', $content_type, 2 )[0] ) );
	}

	/**
	 * Insert or replace without preconditions.
	 *
	 * @param Doc_Key  $k   Key.
	 * @param Document $doc Document.
	 *
	 * @return void
	 */
	private function upsert( Doc_Key $k, Document $doc ): void {
		global $wpdb;
		list( $sql, $args ) = self::insert_sql( $k, $doc );
		self::run( $wpdb->prepare( $sql . ' ON DUPLICATE KEY UPDATE content = VALUES(content), content_type = VALUES(content_type), etag = VALUES(etag), updated = VALUES(updated)', $args ) );
	}

	/**
	 * Insert only when absent.
	 *
	 * @param Doc_Key  $k   Key.
	 * @param Document $doc Document.
	 *
	 * @return integer 1 when inserted, 0 when a document already exists.
	 */
	private function insert( Doc_Key $k, Document $doc ): int {
		global $wpdb;
		list( $sql, $args ) = self::insert_sql( $k, $doc );
		return self::run( $wpdb->prepare( $sql . ' ON DUPLICATE KEY UPDATE id = id', $args ) );
	}

	/**
	 * Replace an existing document whose ETag is in `$in` (any when null) and not in `$not_in`.
	 *
	 * @param Doc_Key       $k      Key.
	 * @param Document      $doc    Document.
	 * @param string[]|null $in     Required ETags.
	 * @param string[]|null $not_in Forbidden ETags.
	 *
	 * @return integer Rows changed.
	 */
	private function update( Doc_Key $k, Document $doc, ?array $in, ?array $not_in ): int {
		global $wpdb;
		list( $where, $args ) = self::where( $k, true );
		foreach ( array(
			'IN'     => $in,
			'NOT IN' => $not_in,
		) as $op => $tags ) {
			if ( null !== $tags && array() !== $tags ) {
				$where .= " AND etag {$op} (" . implode( ',', array_fill( 0, count( $tags ), '%s' ) ) . ')';
				$args   = array_merge( $args, $tags );
			}
		}
		$set = array( bin2hex( $doc->content ), $doc->content_type, $doc->etag, Timestamp::to_db( $doc->updated ) );
		return self::run(
			$wpdb->prepare(
				'UPDATE ' . Tables::name( 'documents' ) . " SET content = UNHEX(%s), content_type = %s, etag = %s, updated = %s WHERE {$where}",
				array_merge( $set, $args )
			)
		);
	}

	/**
	 * INSERT statement and arguments for a document.
	 *
	 * @param Doc_Key  $k   Key.
	 * @param Document $doc Document.
	 *
	 * @return array{0:string,1:array}
	 */
	private static function insert_sql( Doc_Key $k, Document $doc ): array {
		$agent = null;
		if ( null !== $k->agent ) {
			$agent = Json::encode_exact( $k->agent->to_array() );
		}
		$sql  = 'INSERT INTO ' . Tables::name( 'documents' ) . ' (kind, activity_hash, agent_hash, registration, doc_id_hash, doc_id, activity_iri, agent_ifi, content_type, content, etag, updated) VALUES (%s, UNHEX(%s), UNHEX(%s), UNHEX(%s), UNHEX(%s), %s, '
			. ( null === $k->activity_iri ? 'NULL' : '%s' ) . ', '
			. ( null === $agent ? 'NULL' : '%s' ) . ', %s, UNHEX(%s), %s, %s)';
		$args = array( $k->kind, bin2hex( $k->activity_hash() ), bin2hex( $k->agent_hash() ), bin2hex( $k->registration_bin() ), bin2hex( $k->doc_id_hash() ), (string) $k->doc_id );
		if ( null !== $k->activity_iri ) {
			$args[] = $k->activity_iri;
		}
		if ( null !== $agent ) {
			$args[] = $agent;
		}
		array_push( $args, $doc->content_type, bin2hex( $doc->content ), $doc->etag, Timestamp::to_db( $doc->updated ) );
		return array( $sql, $args );
	}

	/**
	 * WHERE clause for one document (`$with_id`) or for a context.
	 *
	 * @param Doc_Key $k       Key.
	 * @param boolean $with_id Whether to match the document id and the exact registration.
	 *
	 * @return array{0:string,1:array}
	 */
	private static function where( Doc_Key $k, bool $with_id ): array {
		$where = 'kind = %s AND activity_hash = UNHEX(%s) AND agent_hash = UNHEX(%s)';
		$args  = array( $k->kind, bin2hex( $k->activity_hash() ), bin2hex( $k->agent_hash() ) );
		if ( $with_id || null !== $k->registration() ) {
			$where .= ' AND registration = UNHEX(%s)';
			$args[] = bin2hex( $k->registration_bin() );
		}
		if ( $with_id ) {
			$where .= ' AND doc_id_hash = UNHEX(%s)';
			$args[] = bin2hex( $k->doc_id_hash() );
		}
		return array( $where, $args );
	}

	/**
	 * Run a write and return the affected rows.
	 *
	 * @param string $sql Prepared SQL.
	 *
	 * @return integer
	 * @throws Xapi_Error 500 when the database rejects the statement.
	 */
	private static function run( string $sql ): int {
		global $wpdb;
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			throw new Xapi_Error( 500, 'The document could not be stored.' );
		}
		return (int) $wpdb->rows_affected;
	}

	/**
	 * ETags listed in an If-Match / If-None-Match value, unquoted; `array('*')` for `*`; null when absent.
	 *
	 * @param string|null $header Header value.
	 *
	 * @return string[]|null
	 */
	private static function tags( ?string $header ): ?array {
		if ( null === $header ) {
			return null;
		}
		$tags = array();
		foreach ( explode( ',', $header ) as $part ) {
			$part = trim( $part );
			if ( str_starts_with( $part, 'W/' ) ) {
				$part = substr( $part, 2 );
			}
			$part = trim( $part, '"' );
			if ( '' !== $part ) {
				$tags[] = '*' === $part ? '*' : strtolower( $part );
			}
		}
		return $tags;
	}

	/**
	 * Whether the current state satisfies the preconditions.
	 *
	 * @param Document|null $current  Current document.
	 * @param string[]|null $if_match If-Match tags.
	 * @param string[]|null $none     If-None-Match tags.
	 *
	 * @return boolean
	 */
	private static function passes( ?Document $current, ?array $if_match, ?array $none ): bool {
		if ( null !== $if_match && ( null === $current || ! self::matches( $if_match, $current->etag ) ) ) {
			return false;
		}
		if ( null !== $none && null !== $current && self::matches( $none, $current->etag ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether an ETag is in a tag list (`*` matches any).
	 *
	 * @param string[] $tags Tags.
	 * @param string   $etag ETag.
	 *
	 * @return boolean
	 */
	private static function matches( array $tags, string $etag ): bool {
		return in_array( '*', $tags, true ) || in_array( $etag, $tags, true );
	}

	/**
	 * Decode a JSON object, keeping object-ness (stdClass), or null when it is not one.
	 *
	 * @param string $json JSON.
	 *
	 * @return \stdClass|null
	 */
	private static function decode_object( string $json ): ?\stdClass {
		try {
			$value = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return null;
		}
		return $value instanceof \stdClass ? $value : null;
	}

	/**
	 * Reject a key without an id.
	 *
	 * @param Doc_Key $k Key.
	 *
	 * @return void
	 * @throws Xapi_Error 400.
	 */
	private static function require_id( Doc_Key $k ): void {
		if ( null === $k->doc_id ) {
			throw new Xapi_Error( 400, 'A document id is required.' );
		}
	}

	/**
	 * Enforce the size limit.
	 *
	 * @param string $content Bytes.
	 *
	 * @return void
	 * @throws Xapi_Error 413.
	 */
	private static function assert_size( string $content ): void {
		$max = self::max_bytes();
		if ( strlen( $content ) > $max ) {
			throw new Xapi_Error( 413, sprintf( 'Document is larger than the %d-byte limit.', $max ) );
		}
	}

	/**
	 * Reject a Content-Type the column cannot hold.
	 *
	 * @param string $content_type Content-Type.
	 *
	 * @return void
	 * @throws Xapi_Error 400.
	 */
	private static function assert_content_type( string $content_type ): void {
		if ( strlen( $content_type ) > 255 || 1 !== preg_match( '/\A[\x20-\x7e]*\z/', $content_type ) ) {
			throw new Xapi_Error( 400, 'Invalid Content-Type.' );
		}
	}
}
