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
 * dbDelta sees no type change on MariaDB (MySQL 8.0.17+ ignores widths). Column types are
 * lowercase because dbDelta in WordPress 6.6 compares them case-sensitively with DESCRIBE
 * output and would rewrite every column on each install. `stored` is a
 * reserved word in MySQL 8 and is always written in backticks.
 */
final class Schema {

	/**
	 * Schema version, stored in option `lledger_schema_version`.
	 *
	 * 2: statements gain `kind_seq` and `kind_stored`, so StatementRef referrers are found
	 * without reading every statement a filter matches (load test, Task 68).
	 * 3: registrations gain KEY registration (registration-to-scope lookups on every committed
	 * batch) and badges KEY trigger_statement_id (revocation on every voided statement).
	 */
	public const VERSION = 3;

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
			'statements'     => 'seq bigint(20) unsigned NOT NULL AUTO_INCREMENT,
statement_id binary(16) NOT NULL,
`stored` datetime(6) NOT NULL,
timestamp datetime(6) NOT NULL,
version varchar(20) NOT NULL,
verb_hash binary(20) NOT NULL,
actor_hash binary(20) NOT NULL,
actor_home_hash binary(20) NULL,
object_kind varchar(16) NOT NULL,
object_hash binary(20) NOT NULL,
registration binary(16) NULL,
authority_client varchar(80) NOT NULL,
is_voiding tinyint(4) NOT NULL DEFAULT 0,
voids_id binary(16) NULL,
voided tinyint(4) NOT NULL DEFAULT 0,
content_sha256 binary(32) NOT NULL,
body longtext NOT NULL,
PRIMARY KEY  (seq),
UNIQUE KEY statement_id (statement_id),
KEY actor_seq (actor_hash,seq),
KEY actor_home_seq (actor_home_hash,seq),
KEY verb_seq (verb_hash,seq),
KEY object_seq (object_hash,seq),
KEY registration_seq (registration,seq),
KEY stored_at (`stored`),
KEY voided_seq (voided,seq),
KEY voids_id (voids_id),
KEY kind_seq (object_kind,seq),
KEY kind_stored (object_kind,`stored`)',

			'statement_refs' => 'ref_hash binary(20) NOT NULL,
ref_kind varchar(32) NOT NULL,
seq bigint(20) unsigned NOT NULL,
PRIMARY KEY  (ref_hash,ref_kind,seq)',

			'pending_voids'  => 'target_id binary(16) NOT NULL,
voiding_seq bigint(20) unsigned NOT NULL,
PRIMARY KEY  (target_id,voiding_seq)',

			'activities'     => 'iri_hash binary(20) NOT NULL,
iri text NOT NULL,
definition longtext NOT NULL,
updated datetime(6) NOT NULL,
PRIMARY KEY  (iri_hash)',

			// `id` gives each agent row a number; a new row's person_id is its own id (Task 19).
			'agents'         => 'ifi_hash binary(20) NOT NULL,
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
ifi json NOT NULL,
person_id bigint(20) unsigned NOT NULL DEFAULT 0,
updated datetime(6) NOT NULL,
PRIMARY KEY  (ifi_hash),
UNIQUE KEY id (id),
KEY person_id (person_id)',

			'documents'      => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
kind varchar(20) NOT NULL,
activity_hash binary(20) NOT NULL,
agent_hash binary(20) NOT NULL,
registration binary(16) NOT NULL,
doc_id_hash binary(20) NOT NULL,
doc_id text NOT NULL,
activity_iri text NULL,
agent_ifi json NULL,
content_type varchar(255) NOT NULL,
content longblob NOT NULL,
etag char(40) NOT NULL,
updated datetime(6) NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY document (kind,activity_hash,agent_hash,registration,doc_id_hash)',

			'clients'        => 'id varchar(64) NOT NULL,
name varchar(191) NOT NULL,
secret_sha256 binary(32) NOT NULL,
scopes json NOT NULL,
bindings json NOT NULL,
cors_origins json NOT NULL,
trusted_for_satisfaction tinyint(4) NOT NULL DEFAULT 0,
rate_class varchar(32) NOT NULL,
guard_enabled tinyint(4) NOT NULL DEFAULT 1,
reject_non_account tinyint(4) NOT NULL DEFAULT 0,
expires_at datetime(6) NULL,
created_at datetime(6) NOT NULL,
revoked_at datetime(6) NULL,
PRIMARY KEY  (id)',

			'tokens'         => 'token_key varchar(64) NOT NULL,
secret_sha256 binary(32) NOT NULL,
parent_client varchar(64) NOT NULL,
registration binary(16) NOT NULL,
actor_hash binary(20) NOT NULL,
actor json NOT NULL,
activity_id text NOT NULL,
session_id char(36) NOT NULL,
launch_mode varchar(10) NOT NULL,
cors_origins json NOT NULL,
guard tinyint(4) NOT NULL DEFAULT 1,
expires_at datetime(6) NOT NULL,
terminated_at datetime(6) NULL,
revoked_at datetime(6) NULL,
created_at datetime(6) NOT NULL,
PRIMARY KEY  (token_key),
KEY parent_client (parent_client),
KEY registration (registration)',

			'course_aus'     => 'au_hash binary(20) NOT NULL,
course_iri_hash binary(20) NOT NULL,
PRIMARY KEY  (au_hash)',

			'courses'        => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
course_iri_hash binary(20) NOT NULL,
course_iri text NOT NULL,
version int(11) NOT NULL,
etag varchar(80) NOT NULL,
xml longtext NOT NULL,
tree json NOT NULL,
badges json NOT NULL,
badge_issuer json NOT NULL,
custom_rules tinyint(4) NOT NULL DEFAULT 0,
owner_client varchar(64) NOT NULL,
updated datetime(6) NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY course_iri_hash (course_iri_hash)',

			'subscriptions'  => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
target_url text NOT NULL,
filters json NOT NULL,
status varchar(12) NOT NULL,
failing_since datetime(6) NULL,
cursor_seq bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime(6) NOT NULL,
PRIMARY KEY  (id)',

			'outbox'         => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
subscription_id bigint(20) unsigned NOT NULL,
seq bigint(20) unsigned NOT NULL,
status varchar(12) NOT NULL,
attempts int(11) NOT NULL DEFAULT 0,
next_attempt_at datetime(6) NOT NULL,
event_id char(36) NULL,
delivered_at datetime(6) NULL,
PRIMARY KEY  (id),
KEY status_next (status,next_attempt_at)',

			'badges'         => 'id char(36) NOT NULL,
achievement_hash binary(20) NOT NULL,
achievement json NOT NULL,
registration binary(16) NOT NULL,
actor_hash binary(20) NOT NULL,
course_iri_hash binary(20) NOT NULL,
trigger_statement_id binary(16) NOT NULL,
status varchar(12) NOT NULL,
narrative text NOT NULL,
waived_count int(11) NOT NULL DEFAULT 0,
recipient_hash varchar(80) NULL,
salt varchar(40) NULL,
credential longtext NULL,
status_list_id bigint(20) unsigned NULL,
status_index int(10) unsigned NULL,
valid_from datetime(6) NULL,
issued_at datetime(6) NULL,
revoked_at datetime(6) NULL,
shared tinyint(4) NOT NULL DEFAULT 0,
created_at datetime(6) NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY achievement_registration (achievement_hash,registration),
KEY actor_hash (actor_hash),
KEY registration (registration),
KEY trigger_statement_id (trigger_statement_id)',

			'status_lists'   => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
purpose varchar(16) NOT NULL,
size int(11) NOT NULL,
used int(11) NOT NULL DEFAULT 0,
bits longblob NOT NULL,
credential longtext NOT NULL,
updated datetime(6) NOT NULL,
PRIMARY KEY  (id)',

			'keys'           => 'kid varchar(64) NOT NULL,
purpose varchar(16) NOT NULL,
public_key varbinary(600) NOT NULL,
secret_sealed text NULL,
created_at datetime(6) NOT NULL,
retired_at datetime(6) NULL,
removed_at datetime(6) NULL,
PRIMARY KEY  (kid)',

			'links'          => 'token_hash binary(32) NOT NULL,
kind varchar(12) NOT NULL,
actor_hash binary(20) NOT NULL,
subject varchar(80) NOT NULL,
expires_at datetime(6) NOT NULL,
used_at datetime(6) NULL,
created_by varchar(80) NOT NULL,
PRIMARY KEY  (token_hash),
KEY kind_actor_subject (kind,actor_hash,subject)',

			// Shared by both roles.
			'audit'          => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
at datetime(6) NOT NULL,
event varchar(40) NOT NULL,
actor varchar(80) NULL,
data json NOT NULL,
PRIMARY KEY  (id)',

			'ratelimit'      => 'bucket varchar(120) NOT NULL,
tokens double NOT NULL,
updated_at datetime(6) NOT NULL,
PRIMARY KEY  (bucket)',

			'jobs'           => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
type varchar(60) NOT NULL,
payload json NOT NULL,
dedupe_key varchar(191) NULL,
run_after datetime(6) NOT NULL,
attempts int(11) NOT NULL DEFAULT 0,
locked_until datetime(6) NULL,
lock_token char(32) NULL,
last_error text NULL,
created_at datetime(6) NOT NULL,
done_at datetime(6) NULL,
PRIMARY KEY  (id),
UNIQUE KEY dedupe_key (dedupe_key),
KEY done_run (done_at,run_after)',

			// Connector role.
			'conn_outbox'    => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
statement_id binary(16) NOT NULL,
course_id bigint(20) unsigned NOT NULL,
body longtext NOT NULL,
status varchar(12) NOT NULL,
attempts int(11) NOT NULL DEFAULT 0,
next_attempt_at datetime(6) NOT NULL,
created_at datetime(6) NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY statement_id (statement_id)',

			'conn_inbox'     => 'statement_id binary(16) NOT NULL,
registration binary(16) NOT NULL,
actor_hash binary(20) NOT NULL,
verb_id varchar(255) NOT NULL,
object_id text NOT NULL,
`stored` datetime(6) NOT NULL,
body longtext NOT NULL,
user_id bigint(20) unsigned NULL,
status varchar(12) NOT NULL,
source varchar(8) NOT NULL,
received_at datetime(6) NOT NULL,
processed_at datetime(6) NULL,
PRIMARY KEY  (statement_id),
KEY status_received (status,received_at),
KEY registration (registration),
KEY actor_hash (actor_hash)',

			'conn_events'    => 'event_id char(36) NOT NULL,
received_at datetime(6) NOT NULL,
PRIMARY KEY  (event_id)',

			'conn_cursor'    => 'name varchar(64) NOT NULL,
value text NOT NULL,
PRIMARY KEY  (name)',

			'identity_links' => 'actor_hash binary(20) NOT NULL,
actor json NOT NULL,
user_id bigint(20) unsigned NOT NULL,
linked_at datetime(6) NOT NULL,
PRIMARY KEY  (actor_hash),
KEY user_id (user_id)',

			'waivers'        => 'user_id bigint(20) unsigned NOT NULL,
lesson_id bigint(20) unsigned NOT NULL,
registration binary(16) NOT NULL,
statement_id binary(16) NOT NULL,
reason text NOT NULL,
carried_over tinyint(4) NOT NULL DEFAULT 0,
created_at datetime(6) NOT NULL,
PRIMARY KEY  (user_id,lesson_id)',

			'applied'        => 'user_id bigint(20) unsigned NOT NULL,
object_kind varchar(16) NOT NULL,
object_id bigint(20) unsigned NOT NULL,
status varchar(32) NOT NULL,
statement_id binary(16) NOT NULL,
score_scaled double NULL,
success tinyint(4) NULL,
flagged tinyint(4) NOT NULL DEFAULT 0,
applied_at datetime(6) NOT NULL,
PRIMARY KEY  (user_id,object_kind,object_id,status)',

			'registrations'  => 'user_id bigint(20) unsigned NOT NULL,
scope_key varchar(191) NOT NULL,
registration binary(16) NOT NULL,
created_at datetime(6) NOT NULL,
ended_at datetime(6) NULL,
PRIMARY KEY  (user_id,scope_key),
KEY registration (registration)',

			'trusted_keys'   => 'key_id varchar(64) NOT NULL,
public_key varbinary(32) NOT NULL,
capabilities json NOT NULL,
limits json NOT NULL,
created_at datetime(6) NOT NULL,
revoked_at datetime(6) NULL,
PRIMARY KEY  (key_id)',

			'nonces'         => 'key_id varchar(64) NOT NULL,
nonce varchar(64) NOT NULL,
seen_at datetime(6) NOT NULL,
PRIMARY KEY  (key_id,nonce)',

			'cmi5_packages'  => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
program_id bigint(20) unsigned NOT NULL,
source_url text NOT NULL,
store_dir varchar(255) NOT NULL,
status varchar(12) NOT NULL,
extract_cursor int(10) unsigned NOT NULL DEFAULT 0,
bytes bigint(20) unsigned NOT NULL DEFAULT 0,
files int(10) unsigned NOT NULL DEFAULT 0,
error text NULL,
created_at datetime(6) NOT NULL,
PRIMARY KEY  (id)',

			'cmi5_files'     => 'package_id bigint(20) unsigned NOT NULL,
path_hash binary(20) NOT NULL,
path text NOT NULL,
sha256 binary(32) NOT NULL,
mime varchar(100) NOT NULL,
size bigint(20) unsigned NOT NULL,
PRIMARY KEY  (package_id,path_hash)',

			'cmi5_sessions'  => 'session_id char(36) NOT NULL,
registration binary(16) NOT NULL,
user_id bigint(20) unsigned NOT NULL,
au_id text NOT NULL,
au_hash binary(20) NOT NULL,
lesson_id bigint(20) unsigned NOT NULL,
launch_mode varchar(10) NOT NULL,
token_key varchar(64) NULL,
fetch_hash binary(32) NULL,
fetch_expires_at datetime(6) NOT NULL,
fetch_used_at datetime(6) NULL,
launched_at datetime(6) NOT NULL,
terminated_at datetime(6) NULL,
abandoned_at datetime(6) NULL,
status varchar(12) NOT NULL,
PRIMARY KEY  (session_id),
KEY user_au_status (user_id,au_hash,status)',
		);
	}
}
