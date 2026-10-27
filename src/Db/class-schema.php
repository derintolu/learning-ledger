<?php
/**
 * Custom table definitions.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Db;

/**
 * Every Learning Ledger table, as `CREATE TABLE` statements written for dbDelta:
 * one column or index per line, two spaces after `PRIMARY KEY`.
 *
 * Conventions: SHA-1 hashes of IRIs and IFIs are BINARY(20), UUIDs BINARY(16) unless
 * stated as CHAR(36), times DATETIME(6) in UTC. An absent registration, agent or activity
 * that takes part in a unique key is stored as zero bytes, never NULL, because MySQL treats
 * NULLs as distinct in a unique key. Integer display widths match MariaDB's defaults so
 * dbDelta sees no type change on MariaDB (MySQL 8.0.17+ ignores widths). `stored` is a
 * reserved word in MySQL 8 and is always written in backticks.
 */
final class Schema {

	/**
	 * Schema version, stored in option `lledger_schema_version`.
	 */
	public const VERSION = 1;

	/**
	 * Short table name => `CREATE TABLE` statement.
	 *
	 * @return array<string,string>
	 */
	public static function tables(): array {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$out     = array();
		foreach ( self::definitions() as $short => $body ) {
			$out[ $short ] = 'CREATE TABLE ' . Tables::name( $short ) . " (\n" . $body . "\n) ENGINE=InnoDB " . $collate;
		}
		return $out;
	}

	/**
	 * Column and index lines per table.
	 *
	 * @return array<string,string>
	 */
	private static function definitions(): array {
		return array(
			// LRS role.
			'statements'     => 'seq BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
statement_id BINARY(16) NOT NULL,
`stored` DATETIME(6) NOT NULL,
timestamp DATETIME(6) NOT NULL,
version VARCHAR(8) NOT NULL,
verb_hash BINARY(20) NOT NULL,
actor_hash BINARY(20) NOT NULL,
actor_home_hash BINARY(20) NULL,
object_kind VARCHAR(16) NOT NULL,
object_hash BINARY(20) NOT NULL,
registration BINARY(16) NULL,
authority_client VARCHAR(80) NOT NULL,
is_voiding TINYINT(4) NOT NULL DEFAULT 0,
voids_id BINARY(16) NULL,
voided TINYINT(4) NOT NULL DEFAULT 0,
content_sha256 BINARY(32) NOT NULL,
body LONGTEXT NOT NULL,
PRIMARY KEY  (seq),
UNIQUE KEY statement_id (statement_id),
KEY actor_seq (actor_hash,seq),
KEY actor_home_seq (actor_home_hash,seq),
KEY verb_seq (verb_hash,seq),
KEY object_seq (object_hash,seq),
KEY registration_seq (registration,seq),
KEY stored_at (`stored`),
KEY voided_seq (voided,seq),
KEY voids_id (voids_id)',

			'statement_refs' => 'ref_hash BINARY(20) NOT NULL,
ref_kind VARCHAR(32) NOT NULL,
seq BIGINT(20) UNSIGNED NOT NULL,
PRIMARY KEY  (ref_hash,ref_kind,seq)',

			'pending_voids'  => 'target_id BINARY(16) NOT NULL,
voiding_seq BIGINT(20) UNSIGNED NOT NULL,
PRIMARY KEY  (target_id,voiding_seq)',

			'activities'     => 'iri_hash BINARY(20) NOT NULL,
iri TEXT NOT NULL,
definition LONGTEXT NOT NULL,
updated DATETIME(6) NOT NULL,
PRIMARY KEY  (iri_hash)',

			// `id` gives each agent row a number; a new row's person_id is its own id (Task 19).
			'agents'         => 'ifi_hash BINARY(20) NOT NULL,
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
ifi JSON NOT NULL,
person_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
updated DATETIME(6) NOT NULL,
PRIMARY KEY  (ifi_hash),
UNIQUE KEY id (id),
KEY person_id (person_id)',

			'documents'      => 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
kind VARCHAR(20) NOT NULL,
activity_hash BINARY(20) NOT NULL,
agent_hash BINARY(20) NOT NULL,
registration BINARY(16) NOT NULL,
doc_id_hash BINARY(20) NOT NULL,
doc_id TEXT NOT NULL,
activity_iri TEXT NULL,
agent_ifi JSON NULL,
content_type VARCHAR(255) NOT NULL,
content LONGBLOB NOT NULL,
etag CHAR(40) NOT NULL,
updated DATETIME(6) NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY document (kind,activity_hash,agent_hash,registration,doc_id_hash)',

			'clients'        => 'id VARCHAR(64) NOT NULL,
name VARCHAR(191) NOT NULL,
secret_sha256 BINARY(32) NOT NULL,
scopes JSON NOT NULL,
bindings JSON NOT NULL,
cors_origins JSON NOT NULL,
trusted_for_satisfaction TINYINT(4) NOT NULL DEFAULT 0,
rate_class VARCHAR(32) NOT NULL,
guard_enabled TINYINT(4) NOT NULL DEFAULT 1,
reject_non_account TINYINT(4) NOT NULL DEFAULT 0,
expires_at DATETIME(6) NULL,
created_at DATETIME(6) NOT NULL,
revoked_at DATETIME(6) NULL,
PRIMARY KEY  (id)',

			'tokens'         => 'token_key VARCHAR(64) NOT NULL,
secret_sha256 BINARY(32) NOT NULL,
parent_client VARCHAR(64) NOT NULL,
registration BINARY(16) NOT NULL,
actor_hash BINARY(20) NOT NULL,
actor JSON NOT NULL,
activity_id TEXT NOT NULL,
session_id CHAR(36) NOT NULL,
launch_mode VARCHAR(10) NOT NULL,
cors_origins JSON NOT NULL,
guard TINYINT(4) NOT NULL DEFAULT 1,
expires_at DATETIME(6) NOT NULL,
terminated_at DATETIME(6) NULL,
revoked_at DATETIME(6) NULL,
created_at DATETIME(6) NOT NULL,
PRIMARY KEY  (token_key),
KEY parent_client (parent_client),
KEY registration (registration)',

			'course_aus'     => 'au_hash BINARY(20) NOT NULL,
course_iri_hash BINARY(20) NOT NULL,
PRIMARY KEY  (au_hash)',

			'courses'        => 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
course_iri_hash BINARY(20) NOT NULL,
course_iri TEXT NOT NULL,
version INT(11) NOT NULL,
etag VARCHAR(80) NOT NULL,
xml LONGTEXT NOT NULL,
tree JSON NOT NULL,
badges JSON NOT NULL,
badge_issuer JSON NOT NULL,
custom_rules TINYINT(4) NOT NULL DEFAULT 0,
owner_client VARCHAR(64) NOT NULL,
updated DATETIME(6) NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY course_iri_hash (course_iri_hash)',

			'subscriptions'  => 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
target_url TEXT NOT NULL,
filters JSON NOT NULL,
status VARCHAR(12) NOT NULL,
failing_since DATETIME(6) NULL,
cursor_seq BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
created_at DATETIME(6) NOT NULL,
PRIMARY KEY  (id)',

			'outbox'         => 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
subscription_id BIGINT(20) UNSIGNED NOT NULL,
seq BIGINT(20) UNSIGNED NOT NULL,
status VARCHAR(12) NOT NULL,
attempts INT(11) NOT NULL DEFAULT 0,
next_attempt_at DATETIME(6) NOT NULL,
event_id CHAR(36) NULL,
delivered_at DATETIME(6) NULL,
PRIMARY KEY  (id),
KEY status_next (status,next_attempt_at)',

			'badges'         => 'id CHAR(36) NOT NULL,
achievement_hash BINARY(20) NOT NULL,
achievement JSON NOT NULL,
registration BINARY(16) NOT NULL,
actor_hash BINARY(20) NOT NULL,
course_iri_hash BINARY(20) NOT NULL,
trigger_statement_id BINARY(16) NOT NULL,
status VARCHAR(12) NOT NULL,
narrative TEXT NOT NULL,
waived_count INT(11) NOT NULL DEFAULT 0,
recipient_hash VARCHAR(80) NULL,
salt VARCHAR(40) NULL,
credential LONGTEXT NULL,
status_list_id BIGINT(20) UNSIGNED NULL,
status_index INT(10) UNSIGNED NULL,
valid_from DATETIME(6) NULL,
issued_at DATETIME(6) NULL,
revoked_at DATETIME(6) NULL,
shared TINYINT(4) NOT NULL DEFAULT 0,
created_at DATETIME(6) NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY achievement_registration (achievement_hash,registration),
KEY actor_hash (actor_hash),
KEY registration (registration)',

			'status_lists'   => 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
purpose VARCHAR(16) NOT NULL,
size INT(11) NOT NULL,
used INT(11) NOT NULL DEFAULT 0,
bits LONGBLOB NOT NULL,
credential LONGTEXT NOT NULL,
updated DATETIME(6) NOT NULL,
PRIMARY KEY  (id)',

			'keys'           => 'kid VARCHAR(64) NOT NULL,
purpose VARCHAR(16) NOT NULL,
public_key VARBINARY(600) NOT NULL,
secret_sealed TEXT NULL,
created_at DATETIME(6) NOT NULL,
retired_at DATETIME(6) NULL,
removed_at DATETIME(6) NULL,
PRIMARY KEY  (kid)',

			'links'          => 'token_hash BINARY(32) NOT NULL,
kind VARCHAR(12) NOT NULL,
actor_hash BINARY(20) NOT NULL,
subject VARCHAR(80) NOT NULL,
expires_at DATETIME(6) NOT NULL,
used_at DATETIME(6) NULL,
created_by VARCHAR(80) NOT NULL,
PRIMARY KEY  (token_hash),
KEY kind_actor_subject (kind,actor_hash,subject)',

			// Shared by both roles.
			'audit'          => 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
at DATETIME(6) NOT NULL,
event VARCHAR(40) NOT NULL,
actor VARCHAR(80) NULL,
data JSON NOT NULL,
PRIMARY KEY  (id)',

			'ratelimit'      => 'bucket VARCHAR(120) NOT NULL,
tokens DOUBLE NOT NULL,
updated_at DATETIME(6) NOT NULL,
PRIMARY KEY  (bucket)',

			'jobs'           => 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
type VARCHAR(60) NOT NULL,
payload JSON NOT NULL,
dedupe_key VARCHAR(191) NULL,
run_after DATETIME(6) NOT NULL,
attempts INT(11) NOT NULL DEFAULT 0,
locked_until DATETIME(6) NULL,
lock_token CHAR(32) NULL,
last_error TEXT NULL,
created_at DATETIME(6) NOT NULL,
done_at DATETIME(6) NULL,
PRIMARY KEY  (id),
UNIQUE KEY dedupe_key (dedupe_key),
KEY done_run (done_at,run_after)',

			// Connector role.
			'conn_outbox'    => 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
statement_id BINARY(16) NOT NULL,
course_id BIGINT(20) UNSIGNED NOT NULL,
body LONGTEXT NOT NULL,
status VARCHAR(12) NOT NULL,
attempts INT(11) NOT NULL DEFAULT 0,
next_attempt_at DATETIME(6) NOT NULL,
created_at DATETIME(6) NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY statement_id (statement_id)',

			'conn_inbox'     => 'statement_id BINARY(16) NOT NULL,
registration BINARY(16) NOT NULL,
actor_hash BINARY(20) NOT NULL,
verb_id VARCHAR(255) NOT NULL,
object_id TEXT NOT NULL,
`stored` DATETIME(6) NOT NULL,
body LONGTEXT NOT NULL,
user_id BIGINT(20) UNSIGNED NULL,
status VARCHAR(12) NOT NULL,
source VARCHAR(8) NOT NULL,
received_at DATETIME(6) NOT NULL,
processed_at DATETIME(6) NULL,
PRIMARY KEY  (statement_id),
KEY status_received (status,received_at),
KEY registration (registration),
KEY actor_hash (actor_hash)',

			'conn_events'    => 'event_id CHAR(36) NOT NULL,
received_at DATETIME(6) NOT NULL,
PRIMARY KEY  (event_id)',

			'conn_cursor'    => 'name VARCHAR(64) NOT NULL,
value TEXT NOT NULL,
PRIMARY KEY  (name)',

			'identity_links' => 'actor_hash BINARY(20) NOT NULL,
actor JSON NOT NULL,
user_id BIGINT(20) UNSIGNED NOT NULL,
linked_at DATETIME(6) NOT NULL,
PRIMARY KEY  (actor_hash),
KEY user_id (user_id)',

			'waivers'        => 'user_id BIGINT(20) UNSIGNED NOT NULL,
lesson_id BIGINT(20) UNSIGNED NOT NULL,
registration BINARY(16) NOT NULL,
statement_id BINARY(16) NOT NULL,
reason TEXT NOT NULL,
carried_over TINYINT(4) NOT NULL DEFAULT 0,
created_at DATETIME(6) NOT NULL,
PRIMARY KEY  (user_id,lesson_id)',

			'applied'        => 'user_id BIGINT(20) UNSIGNED NOT NULL,
object_kind VARCHAR(16) NOT NULL,
object_id BIGINT(20) UNSIGNED NOT NULL,
status VARCHAR(32) NOT NULL,
statement_id BINARY(16) NOT NULL,
score_scaled DOUBLE NULL,
success TINYINT(4) NULL,
flagged TINYINT(4) NOT NULL DEFAULT 0,
applied_at DATETIME(6) NOT NULL,
PRIMARY KEY  (user_id,object_kind,object_id,status)',

			'registrations'  => 'user_id BIGINT(20) UNSIGNED NOT NULL,
scope_key VARCHAR(191) NOT NULL,
registration BINARY(16) NOT NULL,
created_at DATETIME(6) NOT NULL,
ended_at DATETIME(6) NULL,
PRIMARY KEY  (user_id,scope_key)',

			'trusted_keys'   => 'key_id VARCHAR(64) NOT NULL,
public_key VARBINARY(32) NOT NULL,
capabilities JSON NOT NULL,
limits JSON NOT NULL,
created_at DATETIME(6) NOT NULL,
revoked_at DATETIME(6) NULL,
PRIMARY KEY  (key_id)',

			'nonces'         => 'key_id VARCHAR(64) NOT NULL,
nonce VARCHAR(64) NOT NULL,
seen_at DATETIME(6) NOT NULL,
PRIMARY KEY  (key_id,nonce)',

			'cmi5_packages'  => 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
program_id BIGINT(20) UNSIGNED NOT NULL,
source_url TEXT NOT NULL,
store_dir VARCHAR(255) NOT NULL,
status VARCHAR(12) NOT NULL,
extract_cursor INT(10) UNSIGNED NOT NULL DEFAULT 0,
bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
files INT(10) UNSIGNED NOT NULL DEFAULT 0,
error TEXT NULL,
created_at DATETIME(6) NOT NULL,
PRIMARY KEY  (id)',

			'cmi5_files'     => 'package_id BIGINT(20) UNSIGNED NOT NULL,
path_hash BINARY(20) NOT NULL,
path TEXT NOT NULL,
sha256 BINARY(32) NOT NULL,
mime VARCHAR(100) NOT NULL,
size BIGINT(20) UNSIGNED NOT NULL,
PRIMARY KEY  (package_id,path_hash)',

			'cmi5_sessions'  => 'session_id CHAR(36) NOT NULL,
registration BINARY(16) NOT NULL,
user_id BIGINT(20) UNSIGNED NOT NULL,
au_id TEXT NOT NULL,
au_hash BINARY(20) NOT NULL,
lesson_id BIGINT(20) UNSIGNED NOT NULL,
launch_mode VARCHAR(10) NOT NULL,
token_key VARCHAR(64) NULL,
fetch_hash BINARY(32) NULL,
fetch_expires_at DATETIME(6) NOT NULL,
fetch_used_at DATETIME(6) NULL,
launched_at DATETIME(6) NOT NULL,
terminated_at DATETIME(6) NULL,
abandoned_at DATETIME(6) NULL,
status VARCHAR(12) NOT NULL,
PRIMARY KEY  (session_id),
KEY user_au_status (user_id,au_hash,status)',
		);
	}
}
