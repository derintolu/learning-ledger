# Learning Ledger: a WordPress LRS plugin with LMS integrations (design)

Date: 2026-10-27. Status: design for review, revision 3 (owner answers rounds 2 and 3 and the cross-design rulings applied). Not an implementation plan.

This document describes what the plugin is, how it is shaped and why. It aims to give a WordPress/PHP engineer who knows xAPI enough to plan the work without asking the owner. The owner's decisions are in `designs/decisions.md` and are binding; its final section, "Owner answers, 2026-10-27 (round 3)", supersedes anything earlier, including reconciliation ruling 8. Everything else is marked **Builder's call**, with a one-line reason and what it costs if it turns out wrong. Calls that come from the controller's cross-design rulings in `designs/reconciliation.md` are marked **Builder's call (reconciliation)**, with the ruling number; they keep the three designs (the standard, this plugin, the prototype) consistent at their interfaces. Every Builder's call is provisional until the owner approves this document (reconciliation 15).

Evidence (all under the session scratchpad, `/private/tmp/claude-501/-Users-cedarstone-Projects-learn-wp-reimagined/5376001d-2862-4ef6-85e3-26e5bd4cbe50/scratchpad/`):

- `[LRS]` lrs-options.md: the state of WordPress LRSs, the cmi5 requirements, the ADL suite and the minimal resource list.
- `[OB]` open-badges.md: Open Badges 3.0 and signing.
- `[CMI5]` cmi5-fit.md: cmi5 rules, the mapping and conflicts C1 to C4.
- `[SPEC]` the cmi5 Quartz specification, https://github.com/AICC/CMI-5_Spec_Current/blob/quartz/cmi5_spec.md (cited by section, §9.6).
- `[SF]` spacefast-platform.md and `sf-spike/`: Spacefast.
- `[DEC]` designs/decisions.md.
- `[REC]` designs/reconciliation.md: the cross-design rulings.
- The companion designs, read at the interfaces: the standard (`/Users/cedarstone/Projects/learning-mcp/docs/superpowers/specs/2026-10-27-course-xapi-mcp-design.md`) and the prototype (`/Users/cedarstone/Projects/learn-wp-reimagined/docs/superpowers/specs/2026-10-27-sensei-backend-prototype-design.md`). Where they differ from [REC], this document follows [REC].
- Sensei LMS 4.26.4: source read from https://downloads.wordpress.org/plugin/sensei-lms.latest-stable.zip (mirrors https://github.com/Automattic/sensei). File and line references below are to that release.

---

## 1. Intent

WordPress has no maintained open-source Learning Record Store. The only open plugin, wp-xapi-lrs, stores statements only and has been abandoned since about 2017. GrassBlade is commercial and is not a plugin [LRS §1]. No WordPress plugin signs Open Badges 3.0 credentials [OB §5].

The plugin fills both gaps for anyone who runs WordPress:

- A real xAPI LRS that any xAPI client can use.
- An Open Badges 3.0 issuer that signs badges from records the learner cannot edit.
- Built-in connectors that let the WordPress LMS plugins people already run (Sensei first) send their learning to the LRS and receive results back.

The immediate reason for building it is course-xapi-mcp. Under record-custody option 2, "Author's LRS", a course author needs somewhere to keep the official record, and the obvious place for a WordPress author is their own WordPress [DEC]. The plugin must not depend on course-xapi-mcp, though. It is a general LRS that happens to understand cmi5 well.

### Who uses it

| User | What they need |
| --- | --- |
| A site owner who wants an LRS | A conformant xAPI endpoint, client keys, a way to look at records, and export |
| An LMS site (Sensei, then LifterLMS, Tutor LMS and LearnPress) | Its own learners' activity recorded as xAPI; results from outside tools (agents, cmi5 content) shown as LMS progress; any cmi5 package imported and launched from its lessons (§9.6); certificates working as before; its courses exported and imported as course-xapi-mcp packages |
| A course-xapi-mcp course server | A store for the official record under custody option 2: course import, State for `LMS.LaunchData`, voiding, read-back for the learner's "view my record", and badge issuance on the declared events. On the LMS site: learner creation, enrolment, progress reads and the course package |
| Any xAPI tool (H5P, Articulate/Storyline exports, cmi5 players, custom apps) | Standard xAPI 1.0.3 or 2.0 with Basic auth |
| A learner | To see and export their record and to receive and share badges, but never to change the record |

---

## 2. Goals, non-goals, anti-goals

### Goals

1. A conformant xAPI LRS (Statements with voiding and queries, State, Agent Profile, Activity Profile, About, Activities, Agents). It passes ADL's lrs-conformance-test-suite except where a gap is published honestly.
2. Enough cmi5 support for a cmi5 "LMS" party (such as a course-xapi-mcp server) to keep its record here.
3. A cmi5 player for Sensei: the plugin is the cmi5 LMS side on Sensei sites (import, launch, sessions, moveOn, Sensei completion), and so the custody option-1 LMS there. It passes CATAPULT's LMS Test Suite (§9.6) [DEC round 3].
4. Open Badges 3.0 issuance that verifies in the 1EdTech validator and in the DCC Learner Credential Wallet (LCW) stack.
5. One plugin with two roles (LRS and LMS connector), usable on one install or on two.
6. LMS integrations built in, loaded only when that LMS is active, behind one shared interface. Sensei is first, with all four directions [DEC]. Then LifterLMS, Tutor LMS and LearnPress, in that order [DEC round 2].
7. A course package exporter and importer: the standard's ZIP, out of and into the LMS (§9.5) [REC 2].
8. Runs on ordinary WordPress hosting and on Spacefast.

### Non-goals (v1)

- A cmi5 player for LMSs other than Sensei. The player (§9.6) uses the Sensei adapter; LifterLMS, Tutor LMS and LearnPress get it later through the same interface.
- Reporting and analytics dashboards beyond a plain statement browser and per-learner view. Export exists so that real analytics tools can do this work.
- xAPI attachments sent as multipart content (Builder's call, §6.9).
- OAuth 1.0. Open Badges 2.0. VC-JWT badges (a later fallback only, §8).
- Being an authoring tool. Course-xapi-mcp parts attached to Sensei lessons are inputs, not a course editor.

### Anti-goals: failure even if it works

- **It slows the host site.** Any xAPI or connector work that adds noticeable time to a normal page view, wp-admin screen or LMS action counts as failure.
- **Learners can alter the official record.** Any path, direct or indirect, by which a learner or the learner's agent can write, replace, void or delete official statements counts as failure. That covers a credential handed to the agent, an import tool or a "link record" feature.
- **It only works with our standard.** An LRS that needs course-xapi-mcp, or that rejects valid xAPI from other tools, counts as failure.
- **An LMS integration breaks when that LMS updates.** A fatal error, silent wrong progress or lost events after an LMS update counts as failure. The acceptable outcome is that the integration detects the change, stops writing, keeps queueing and tells the admin.
- **A badge proves nothing.** A badge signed from data the learner could influence, or one that leaks the learner's xAPI identity, counts as failure.
- **The plugin cannot be maintained.** Code that only works on one host, or that depends on internal LMS tables, counts as failure.

---

## 3. Shape: two roles

```
 LMS site (WordPress + Sensei)                     LRS site (WordPress, LRS role only)
 +-------------------------------+                 +----------------------------------+
 | Learning Ledger: connector    |  xAPI (Basic)   | Learning Ledger: LRS             |
 |  - Sensei integration          | --------------> |  /xapi/ statements, state, ...   |
 |  - outbox (on-site learning)   |                 |  custom tables                   |
 |  - inbox (results to apply)    | <-------------- |  webhook outbox (signed)         |
 |  - identity map                |  webhook + poll |  badge issuer, did:web           |
 |  - package export / import     |                 |  claim links, view links         |
 |  - course-server routes        |                 +----------------------------------+
 |  - cmi5 player (§9.6): import, |                        ^            ^           ^
 |    launch, fetch, moveOn,      |                        |            |           |
 |    AU content origin           |                        |            |           |
 +-------------------------------+                         |            |           |
     |    ^  Ed25519-signed requests                       |            |           |
     |    |  (learners, enrolment, progress, package)      |            |           |
     |    +---- course-xapi-mcp server (LMS role) ---------+            |           |
     |          (the one writer of the official record, option 2)       |           |
     |  launch URL + one-time fetch                                     |           |
     +--> AU: on the content origin, remote, or the                     |           |
          standard's launch bridge (option 1) --------------------------------------+
```

The three arrows up into the LRS are, left to right: the course server, any xAPI client, and an AU writing with its child token.

- **LRS role.** Stores statements and documents, answers xAPI, imports course packages (for satisfaction checks and badge declarations), issues and revokes badges, mints claim links and view links, and notifies subscribers.
- **Connector role.** Runs on the LMS site. It turns LMS events into xAPI and sends them to an LRS, for courses where that direction is on. It receives results from the LRS and applies them to the LMS. It keeps the actor-to-user map, exports LMS courses as course-xapi-mcp packages and imports packages into the LMS, and answers a course server's signed requests (§4.4). On Sensei sites it is also the cmi5 LMS: it imports any cmi5 package and launches its AUs (§9.6).
- **One writer per course** (**Builder's call (reconciliation 3)**). For a course driven by a course server, only the course server writes learner statements to the LRS and mints the registration. The connector's own statement sending (direction 2, §9.3) is a per-course setting, off by default for course-server-driven courses. Reason: two writers would produce two registrations and duplicate records for one piece of learning. Cost if wrong: a site that wants both on-site and agent learning recorded for one course needs two courses, or a later merge rule. For a cmi5 player course (§9.6) the connector is the LMS writer and the AU writes its own statements with a child token; direction 2 is off.

Each role is switched on separately in settings (or with WP-CLI). Both can run on one install. In that case the connector talks to the local LRS through the same HTTP interface, using a loopback URL, so the code path is identical. **Builder's call:** keep it as HTTP rather than in-process calls. Reason: one code path to test, and the roles can move apart later without changes. Cost if wrong: about 5 to 20 ms extra per batch on single-install sites.

**Recommendation (owner's decision):** run the LRS on its own WordPress install, because it will hold a great many records and can slow a site down [DEC]. The plugin shows a notice on the Site Health screen when both roles are active on one install and the LRS holds more than 1 million statements (**Builder's call:** the threshold is a guess to be tuned from the load tests in §11).

The connector can point at **any** conformant LRS, not only ours. With another LRS it falls back to the catch-up poll only, because webhooks are our extension (§10).

---

## 4. Configuration and security of each role

### 4.1 LRS role

**Clients.** Every caller is a client record in `lledger_clients`, not a WordPress user:

- an `id` and a public key, used as the Basic username
- a secret, stored as SHA-256
- a name and scopes
- optional bindings: actor IFI, registration, activity-IRI prefix
- an expiry
- a rate-limit class
- a `trusted_for_satisfaction` flag (§8.1)

**Builder's call:** the secret is a 32-byte random value stored as SHA-256 rather than bcrypt. Reason: it is checked on every request, and a slow hash would add 50 to 100 ms per call; a high-entropy secret makes a slow hash unnecessary. Cost if wrong: if a secret were ever chosen by a human, its hash would be easy to brute-force. The plugin therefore never accepts a client-chosen secret below 128 bits.

**Scopes** reuse the xAPI 1.0.3 OAuth scope names, so the meaning is familiar:

- `statements/write`, `statements/read`, `statements/read/mine`
- `state`, `profile`, `define`
- `all/read`, `all`

Ours add seven: `void`, `courses/write`, `badges/issue`, `badges/claim-links`, `records/view-links`, `actors/erase` and `tokens/mint`.

- `void` is separate from `statements/write`, so a writing client cannot void unless it is granted `void`. cmi5 requires that the AU never gets void rights [CMI5 §4.1].
- `courses/write` lets a course server import its package (§7 item 7).
- `badges/claim-links` lets a course server or connector ask for a claim link for one actor and badge (§8.1). `records/view-links` lets it ask for a "view my record" link for one actor (§7). `actors/erase` lets it ask for a GDPR erasure of one actor (§13). Each is limited by the client's actor binding. **Builder's call (reconciliation 5, 10)**; cost if wrong: three more scopes to explain in the admin screen.
- `tokens/mint` lets an LMS-role client (for example a course-xapi-mcp server, a cmi5 LMS or the plugin's own player, §9.6) mint short-lived child credentials for one registration and actor, with no void and a TTL (default 8 h), and revoke them when the session ends. The LMS uses this to back the cmi5 `fetch` auth token. The LMS still implements `fetch`; the LRS only issues the credential behind it. A child token carries its own CORS origins (the AU's), added to the client's list.

**Authentication.**

- xAPI endpoints use HTTP Basic, as the ADL suite and cmi5 expect [LRS §3, CMI5 §2.1].
- OAuth 2.0 client credentials is a later addition.
- OAuth 1.0 is not supported. It was dropped from xAPI 2.0, and the suite's OAuth flags are unreliable [LRS §3].
- The plugin's own admin REST routes (clients, courses, badges, export) use WordPress auth: a cookie plus a REST nonce for wp-admin screens, or Application Passwords for scripts. They are checked against custom capabilities.

**A WordPress gotcha to handle on day one.** WordPress core reads any `Authorization: Basic` header on a REST request as an Application Password attempt. If that attempt fails, `rest_application_password_check_errors` can turn the whole request into a 401 before our callback runs. The xAPI routes must therefore stop core's Application Password check for our namespace, using the `application_password_is_api_request` filter or an early `determine_current_user` handler for our routes. They then authenticate the client themselves in `permission_callback`. To verify in the first spike.

**Capabilities** (granted to `administrator` on activation, never to subscriber or learner roles):

- `lledger_manage` covers settings, clients, keys and webhooks.
- `lledger_view_records` allows browsing statements in wp-admin.
- `lledger_manage_badges` allows issuing configuration and revocation.
- `lledger_export` allows bulk export.

Every admin form and AJAX call checks a capability and a nonce. Every output is escaped.

**Rate limits.** **Builder's call:**

- A token bucket per client: 20 requests per second sustained, with a burst of 100.
- Failed authentication limited per IP: 10 per minute, then 429 with `Retry-After`.
- Kept in the object cache when a persistent one exists, otherwise in a small `lledger_ratelimit` table.

Reason: this stops one runaway client from starving PHP workers. Cost if wrong: bulk importers hit 429 and need a raised class, which is configurable per client.

**CORS.** Browser AUs (cmi5 content) call the LRS cross-origin with an `Authorization` header. The plugin answers preflight requests for an explicit allow-list of origins per client. There is no wildcard while credentials are in use.

### 4.2 Connector role

- **LRS endpoint and credentials.** One client on the LRS, bound to the connector's own actor homePage (§9.3) and activity base. It always has `statements/read`, `actors/erase`, `records/view-links` and `badges/claim-links`. It gets `statements/write` only for the activity bases of courses where direction 2 is on (**Builder's call (reconciliation 3)**; cost if wrong: turning direction 2 on for a course needs the LRS admin to widen the binding). With the cmi5 player (§9.6) it also gets `tokens/mint`, `void`, `state`, `profile` and `statements/write` for player courses. It also holds a webhook subscription.
- **The connector's secret never crosses WP-CLI** (**Builder's call (reconciliation 10)**). The connector generates its own 32-byte secret on `connector connect` (§4.3) and keeps it. Only its SHA-256 is exposed, through the authenticated status route (§4.3), for the LRS admin to register. The secret lives in a `wp-config.php` constant when an operator has put one there (`LLEDGER_CONNECTOR_SECRET`, generated on their own machine). Otherwise it is stored in `wp_options`, encrypted with a key derived from `AUTH_KEY`, and set not to autoload. There is no `wp config set` of secrets. Reason: Spacefast logs every WP-CLI run and cannot edit wp-config.php easily. Cost if wrong: a database dump plus the wp-config salts would expose the connector credential. That credential can only act on the site's own actors, so the damage is limited.
- **Webhook receiver.** `POST /wp-json/learning-ledger/v1/inbox`. It verifies the LRS's Ed25519 signature against the public key pinned at setup (§10). It stores the event and returns 202, then processes it at `shutdown` after responding (§10).
- **Who can change the mapping.** `lledger_manage_connector`, administrator only. Lesson-level course-xapi-mcp fields (§9.3, direction 4) follow the LMS's own edit permission for that lesson, for example `edit_post` on the lesson.

### 4.3 Setup without wp-admin

On Spacefast it is not known whether wp-admin can be reached, and WP-CLI returns no output [SF, DEC]. Every setting therefore has a WP-CLI command that needs no output to succeed, and no secret ever has to be printed:

- `wp learning-ledger role enable lrs`
- `wp learning-ledger client create --id=course-server --secret-sha256=<hash computed locally> --scopes=all,void,courses/write,tokens/mint,badges/issue,badges/claim-links,records/view-links,actors/erase`. The operator (or the course server's provisioning script) generates the secret locally and sends only its hash, so the secret never appears in the Space's activity trail.
- `wp learning-ledger badges keygen`. The key is generated and stored server-side. Only the public key is ever exposed, through did.json.
- `wp learning-ledger cmi5 content-host <host>` sets the AU content origin (§9.6); without it the player uses the sandboxed fallback.
- `wp learning-ledger connector connect --lrs=<url> --client=<id>`. The connector generates its own secret and stores it (§4.2). It also pins the LRS's notification public key from `/about`. The LRS admin then registers the connector with `wp learning-ledger client create --id=<id> --secret-sha256=<hash>` on the LRS, taking the hash from the connector's status route.
- `wp learning-ledger connector trust-key --id=course-server --public-key=<base64 Ed25519 public key>`. It registers a course server's public key for the signed routes in §4.4. Only the public key crosses WP-CLI.
- **Status route.** `GET /wp-json/learning-ledger/v1/status` reports versions, active roles, pairing state, LMS presence, sodium (native or compat) and, to callers with `lledger_manage` (Application Password) or a signed course-server request, the connector's secret hash. It is how a provisioning script reads back what WP-CLI cannot print. A SHA-256 of a 256-bit random secret gives nothing usable to someone who sees it.

### 4.4 Course server to connector routes

**Builder's call (reconciliation 9).** A headless course server (such as the prototype's Spacefast Functions) needs a few LMS operations that the LMS does not offer over a stable REST API. The connector provides them under `learning-ledger/v1/course-server/`:

| Route | Does |
| --- | --- |
| `POST learners` | Creates an anonymous WordPress user (no email, no login by password) and its actor (§9.3, direction 3). Returns user id and actor |
| `POST learners/{id}/enrolments` | Enrols the learner in one LMS course through the adapter's `enrol()` |
| `GET learners/{id}/progress` | The learner's LMS progress for a program or course: completed lessons, tested-out lessons (from the waiver ledger), course completion and certificate |
| `DELETE learners/{id}` | Deletes the learner: the WordPress user, the identity map, waivers and outbox rows, then calls `actors/erase` on the LRS (§13) |
| `GET packages/{program}` | The program's course package, full flavour (§9.5); the signed form of the export route |
| `POST packages` | Imports a package by URL (§9.5) |
| `GET status` | As in §4.3 |

- **Auth: Ed25519 signed requests.** The course server holds the private key; the plugin stores only the public key (`trust-key`, §4.3). Headers: `X-Learning-Ledger-Key` (key id), `X-Learning-Ledger-Timestamp`, `X-Learning-Ledger-Nonce` and `X-Learning-Ledger-Signature`, an Ed25519 signature over `method + "\n" + path + "\n" + SHA-256(body) + "\n" + timestamp + "\n" + nonce`. The timestamp must be within 5 minutes, and nonces are remembered for 10 minutes to stop replay. These routes accept no other auth. Reason: no shared secret crosses WP-CLI, and the same signing code serves notifications (§10). Cost if wrong: a course server in a language without Ed25519 needs a library, which every mainstream language has.
- **Anonymous-user creation is rate-limited and capability-gated.** It is off until an admin grants the trusted key `create_learners`. Each key has a bucket (**Builder's call:** 30 learners per minute, 2,000 per day, configurable), and new users get a dedicated `lledger_learner` role with no capabilities beyond `read`. Reason: an endpoint that creates WordPress users is a spam vector if a key leaks. Cost if wrong: a busy launch hits the daily cap and needs it raised.
- Each route also takes the plugin's capability set: a key can be limited to some of `create_learners`, `enrol`, `read_progress`, `delete_learners`, `read_packages` and `import_packages`.

---

## 5. Naming conventions used below

- REST namespace `learning-ledger/v1` for admin and connector routes.
- The xAPI base is `/wp-json/learning-ledger/xapi/`, with a rewrite alias at `/xapi/`. **Builder's call:** reason, a short, stable endpoint that can be printed in cmi5 launch URLs. Cost if wrong: one rewrite rule to keep working on unusual hosts. The `/wp-json/` form always works.
- Prefixes: tables `{$wpdb->prefix}lledger_*`, options `lledger_*`, PHP namespace `LearningLedger\`, hooks `learning_ledger/*`.

---

## 6. The LRS

### 6.1 Versions

- Support **xAPI 1.0.3 and xAPI 2.0 (IEEE 9274.1.1)** on the same endpoint, selected by the `X-Experience-API-Version` request header.
- `/about` lists both.
- The version is stored on each statement, and responses carry the version the client asked for.

**Builder's call:** support both from the first release. Reason: cmi5 Quartz and almost every existing tool speak 1.0.3, while 2.0 is the current standard and the ADL suite tests both (`-x 2.0.x`) [LRS §3]. Cost if wrong: about twice the conformance matrix, plus 1.0.3's Alternate Request Syntax (`?method=`), which 2.0 dropped and which we must still implement for 1.0.3.

### 6.2 Resources

| Resource | Methods | Notes |
| --- | --- | --- |
| `about` | GET | Versions, plus an `extensions` entry pointing at our webhook and badge discovery URLs |
| `statements` | PUT (with `statementId`), POST (single or array), GET | All query parameters: `statementId`, `voidedStatementId`, `agent`, `verb`, `activity`, `registration`, `related_activities`, `related_agents`, `since`, `until`, `limit`, `format` (ids/exact/canonical), `attachments`, `ascending`. Also `more` paging, `X-Experience-API-Consistent-Through`, and `Last-Modified` (2.0) |
| `activities/state` | GET (single or list with `since`), PUT, POST (JSON merge), DELETE (single or all) | Keyed by activityId + agent + optional registration + stateId |
| `agents/profile` | GET (single or list), PUT, POST, DELETE | cmi5 needs `cmi5LearnerPreferences` |
| `activities/profile` | GET (single or list), PUT, POST, DELETE | Optional for cmi5 [LRS §0]; cheap once State exists, because it uses the same table |
| `activities` | GET | Returns the stored, merged Activity definition |
| `agents` | GET | Returns a Person object built from the IFIs we have seen for that agent |

Server behaviour that matters for conformance [LRS §3]:

- The server sets `id` (if absent), `stored`, `authority` and `version`.
- PUT of an existing id with identical content returns 204. Different content returns 409.
- Strict validation per version: reject unknown properties, require exactly one IFI per agent, use the 2.0 RFC 3339 timestamp rules, and validate Group member rules.
- `format=canonical` applies `Accept-Language` to language maps.

### 6.3 Routing on WordPress

- Routes use `register_rest_route` for portability.
- Our callback writes the raw response body itself, through `rest_pre_serve_request`. This applies to non-JSON documents and to exact statement bytes, so WordPress neither re-encodes them nor adds `_links`.
- Document bodies are read with `WP_REST_Request::get_body()` and stored as bytes with their `Content-Type`.
- Alternate Request Syntax (1.0.3) is dispatched by our own router. WordPress only knows `_method`.
- Each request returns `X-Experience-API-Version`.

### 6.4 Storage: custom tables only

Nothing goes into posts or postmeta. Records live in InnoDB tables, created with `dbDelta` and versioned through a schema option. Requires MySQL 8.0+ or MariaDB 10.6+, for `DATETIME(6)` and reliable InnoDB behaviour. **Builder's call:** reason, these are the versions WordPress hosts ship today. Cost if wrong: old MySQL 5.7 hosts cannot run the LRS role, though they can still run the connector.

| Table | Purpose | Key columns |
| --- | --- | --- |
| `lledger_statements` | One row per stored statement, append-only | `seq` BIGINT PK auto-increment (the stored order and the paging cursor); `statement_id` BINARY(16) UNIQUE; `stored` DATETIME(6); `timestamp` DATETIME(6); `version`; `verb_hash` BINARY(20); `actor_hash` BINARY(20); `object_kind`; `object_hash` BINARY(20); `registration` BINARY(16) NULL; `authority_client`; `is_voiding` TINYINT; `voids_id` BINARY(16) NULL; `voided` TINYINT; `content_sha256`; `body` LONGTEXT (the statement exactly as stored, for `format=exact`) |
| `lledger_statement_refs` | Index rows for `related_agents` and `related_activities` | (`ref_hash`, `ref_kind`, `seq`) PK. Kinds: actor, object-agent, authority, instructor, team, context-agent, object-activity, context-activity (parent/grouping/category/other), substatement-* |
| `lledger_activities` | Merged activity definitions | `iri_hash` PK, `iri` TEXT, `definition` LONGTEXT, `updated` |
| `lledger_agents` | Agents seen, for `/agents` | `ifi_hash` PK, `ifi` JSON, `person_id` (groups IFIs known to be the same person) |
| `lledger_documents` | State, agent profile and activity profile in one table | `kind` (state, agent_profile, activity_profile); `activity_hash`; `agent_hash`; `registration` (state only); `doc_id`; `content_type`; `content` LONGBLOB; `etag` (SHA-1 hex); `updated` DATETIME(6). UNIQUE on (kind, activity_hash, agent_hash, registration, doc_id) |
| `lledger_clients`, `lledger_tokens` | Credentials (§4.1) | Child tokens: parent client, registration, actor hash, CORS origins, launch mode, expiry, `revoked_at` |
| `lledger_courses` | Imported course packages: cmi5.xml plus a parsed AU and block tree with moveOn, and the badge declarations from `cxm/course.json`. Only the public parts are kept (§7 item 7) | `course_iri_hash`, `version`, `etag`, `xml`, `tree` JSON, `badges` JSON, `owner_client` |
| `lledger_outbox` | Webhook deliveries and catch-up cursors (§10) | |
| `lledger_badges`, `lledger_status_lists`, `lledger_keys` | Issuance (§8) | |
| `lledger_links` | Claim links and view links: hash of the token, kind, actor hash, badge or registration, expiry, used flag | `token_hash` PK; (`kind`, `actor_hash`, `subject`) |
| `lledger_audit` | Append-only log: voids, erasures, badge issue/revoke, client changes, key rotation. No statement bodies, no PII | |

**Hashing.** IRIs and IFIs are indexed by SHA-1 hash (20 bytes, binary). Reason: utf8mb4 index prefix limits (191 characters) and long IRIs. The full value is stored beside the hash and compared on read, so a collision cannot return the wrong row.

**Builder's call:** keep statement bodies uncompressed. Reason: `format=exact` must return the stored bytes, and compression makes ad-hoc SQL inspection harder. Cost if wrong: about 1.5 to 2 KB per statement, so roughly 15 to 20 GB at 10 million statements. Row compression can be added later without an API change.

### 6.5 Indexing for very large record sets

Statement queries are served by composite indexes that end in `seq`, so each filter becomes a range scan in stored order:

- `(actor_hash, seq)`, `(verb_hash, seq)`, `(object_hash, seq)`, `(registration, seq)`, `(stored)`.
- `(voided, seq)` keeps voided rows out of normal queries.
- `related_*` queries join `lledger_statement_refs` on (`ref_hash`, `ref_kind`, `seq`).
- Queries that combine filters (agent + activity + registration, the common cmi5 case) use the most selective index. The registration index is usually that one.

**Paging.**

- `more` is an opaque, HMAC-signed cursor holding the query hash, the last `seq` and a fixed upper bound. That bound is the consistent-through time captured on the first page.
- Pages stay stable while new statements arrive. Offset paging is never used.
- Default `limit` is 100 and the maximum is 500. **Builder's call:** reason, it keeps response size and PHP memory bounded. Cost if wrong: clients make more round trips.

**Consistent-through.** The header value is `now − 1 s`, and queries only return rows stored before it. **Builder's call:** reason, auto-increment order and commit order can differ by in-flight transactions, and a small fixed lag hides that. Cost if wrong: a client polling with `since` could miss a statement committed later than one second after its `stored` time. We log any insert slower than 500 ms so this can be measured.

### 6.6 Concurrency and ETags

- Documents carry an ETag: the SHA-1 of the content, quoted. `If-Match` and `If-None-Match` are honoured with 412 on mismatch.
- The version-specific rule on PUT and POST without a precondition (409 where the spec requires it) is followed. The suite is the arbiter on edge cases.
- Writes use `UPDATE … WHERE etag = ?` and check the affected rows, so two concurrent writers cannot both win.
- POST merges top-level JSON object properties, and only when both the stored and the new document are `application/json` objects. Otherwise it returns 400.
- Document size limit: 2 MB (**Builder's call (reconciliation 12)**, confirmed by the owner). cmi5 documents are a few KB; the larger figure fits the prototype's workspace files, which sync to a State document per registration. The limit protects memory. A tool that stores larger state blobs gets 413 and needs a raised limit, which is configurable.

### 6.7 Immutability and voiding

- **No code path updates or deletes a statement body.** The only change ever made to a statement row is the `voided` flag. It is set in the same transaction that inserts the voiding statement.
- A voiding statement cannot itself be voided. Voiding requires the `void` scope.
- Voided statements are left out of normal queries and returned only by `voidedStatementId`, and the voiding statement can still be found.
- If the target of a voiding statement has not arrived yet, the voiding statement is stored and applied when the target arrives. To check against the suite.
- Deletion exists only for GDPR erasure and admin purge of a whole registration (§13). Both write an audit row that holds counts and hashes, not content.
- `content_sha256` per row lets an export prove that bodies were not changed after storage. A hash chain across rows is a possible later step, not v1.

### 6.8 Retention and export

- **Retention.** Statements are kept until erased. There is no automatic expiry in v1. Documents follow xAPI DELETE semantics.
- **Export.** `GET /wp-json/learning-ledger/v1/export?since=&until=&registration=&agent=` streams NDJSON (one statement per line in exact format, plus `stored` and `content_sha256`). It requires `lledger_export`. It is chunked by `seq`, so it never loads everything into memory.
  - The WP-CLI equivalent is for hosts with a shell.
  - On Spacefast the REST export is the only path, because WP-CLI has a 360-second limit and returns no output [SF].
- **Import.** A WP-CLI migration import that keeps the original `stored` and `authority` values. It exists to move an LRS between hosts, and it is documented as the one exception to "the server sets `stored`". **Builder's call:** reason, without it an LRS can never move house. Cost if wrong: an admin could use it to insert back-dated records. It is admin-only and every import is logged in `lledger_audit`.

### 6.9 Conformance

- Run `adlnet/lrs-conformance-test-suite` in CI against a disposable install (wp-env with MySQL, never production, because the suite leaves its data behind [LRS §3]).
  - Run it for `-x 1.0.3` and `-x 2.0.x` on every release candidate.
  - Commit the raw JSON results and a summary table to `conformance/` in the repo, link them from the readme, and list every failing requirement ID with a reason.
  - The cmi5-relevant part of the suite (Statements with voiding and queries, State, Agent Profile, About, and the auth and header requirements) is the gate for the wordpress.org submission (§14) [DEC round 2].
- **Builder's call (agreeing with the owner's allowance):** cut multipart attachments for v1.
  - Statements whose attachments carry only a `fileUrl` are accepted, because they are valid without multipart.
  - `multipart/mixed` requests get 400 with a clear message.
  - Reason: neither cmi5 nor course-xapi-mcp needs attachments, and multipart parsing in WordPress REST is a large, separate job.
  - Cost if wrong: the attachment section of the suite fails (published), and tools that send signed statements (JWS attachments) cannot store them.
- CATAPULT tests cmi5 LMSs and AUs, not LRSs [CMI5 §5]. Its LMS Test Suite applies to the connector's cmi5 player and is a v1 release gate alongside this suite (§9.6) [DEC round 3]. Its results are committed to `conformance/` in the same way.

---

## 7. cmi5 support, and how custody option 2 uses it

What the LRS gives a cmi5 LMS party [LRS §0, CMI5 §2–4]:

1. **Statements with voiding, `void` withheld from AU credentials.** The LMS client has `void`, and the child tokens it mints never do.
2. **State API** for `LMS.LaunchData`, keyed by (activityId, agent, registration). The LMS client writes it. Child tokens get read-only access to State for their own registration and actor, so an AU cannot change LaunchData ([CMI5 §2.1 item 4]: the AU MUST NOT modify it).
3. **Agent Profile** for `cmi5LearnerPreferences`. A child token may get 403 on writing it, which cmi5 explicitly allows.
4. **A privileged reader** that can read all of a learner's data across sessions: the LMS client with `all/read`.
5. **Registrations** as first-class query keys (indexed, §6.5).
6. **An optional cmi5 guard** on child tokens. It rejects statements that break cmi5 §9 ordering rules within the token's session: defined verbs after `terminated`, a second `passed` per registration, `failed` after `passed`, or a missing `sessionid`. It returns 400 or 403 instead of storing them. cmi5 lets the LMS either reject or void; rejecting at the door keeps the record clean. **Builder's call:** on for child tokens, off for full clients. Reason: the LMS client is trusted to void what it needs to. Cost if wrong: a strict guard could reject a legitimate edge case; it can be switched off per client.
7. **Course import** (`PUT /wp-json/learning-ledger/v1/courses`, scope `courses/write`). **Builder's call (reconciliation 4).** The course server sends the standard's course package (the ZIP, §9.5) on publish and again on each content-changed ping, with its ETag; an unchanged ETag is a no-op. A plain cmi5.xml is also accepted, for cmi5 LMSs that are not course servers; such a course has no badge declarations. The XML must be XSD-valid. The LRS keeps the parsed AU and block tree with moveOn, used to recompute satisfaction before signing, and the badge declarations from `cxm/course.json` (§8.1). Lesson JSON and secret fields are not kept: the LRS does not need them, and a public-flavour package is enough. Extension elements in the XML are kept as they are. Reason: the LRS cannot check satisfaction or issue a declared badge without the tree. Cost if wrong: a course server that forgets to re-import after a change gets badges evaluated against the old tree; the import ETag shows which version the LRS holds.

**Custody option 2, step by step (course-xapi-mcp server in the LMS role, mode A in [CMI5 R2]):**

1. The author creates one client for their course server with the scopes `all`, `void`, `courses/write`, `tokens/mint`, `badges/issue`, `badges/claim-links`, `records/view-links` and `actors/erase`, and marks it `trusted_for_satisfaction`. The server imports each course's package.
2. A learner starts the course in their own agent.
   - The server mints the registration and uses the actor the connector assigned (§9.3, direction 3), or an anonymous actor where no LMS site is involved. It is the only writer of learner statements for that course (§3).
   - It writes `LMS.LaunchData` and `launched`.
   - It then writes the AU statements as the session runs: defined statements and the course-xapi-mcp allowed statements.
3. The server writes `waived` (reason "Tested Out") for lessons that placement or branching skips, and `satisfied` for blocks and the course, as cmi5 makes the LMS do.
4. The learner's agent never holds an LRS credential. That is what keeps the record out of the learner's reach [LRS §4, DEC rules].
5. The LRS stores the statements, notifies subscribers (§10) and runs the badge pipeline on the declared events (§8.1).
6. The learner views or exports the record in one of three ways: through the course server's `get_record` (the server reads with its own credential, filtered to that actor); through the connected LMS site (§9.3); or through a "view my record" link. **Builder's call (reconciliation 10):** view links are minted by the LRS through `POST /wp-json/learning-ledger/v1/view-links` (scope `records/view-links`, for an actor within the client's binding), and the course server or connector hands the link to the learner. The link opens a read-only page on the LRS site with an NDJSON or JSON download. Only the token's hash is stored (`lledger_links`). Reason: learners need no account on the LRS install, the actor stays PII-free, and only the LRS creates links to its own records. Cost if wrong: anyone holding a leaked link can read that record until it expires (default 15 minutes).

The same mechanisms serve a third-party cmi5 LMS that wants to use this LRS, and the plugin's own cmi5 player on a Sensei site (§9.6). That LMS gets an LMS-role client and mints child tokens for its AUs.

---

## 8. Open Badges 3.0 issuance

The decisions are the owner's [DEC]: the standard defines when a badge is earned and what it contains; OB 3.0 is referenced, not copied; the issuer is whoever holds the official record. The research basis is [OB §0, §2, §4, §6, §7]. This section covers how the plugin does it.

### 8.1 When

- **Triggers** are the events the course declares in its badge declarations (`cxm/course.json#/badges[]`, kept at import): `awardOn` is `au-moveon`, `block-satisfied`, `course-satisfied` or `any-path` (the first block satisfied in the registration), each with a `countWaived` rule [OB §7.1]. **Builder's call (reconciliation 6):** the plugin implements every one of these rules, as the standard defines them.
- **Waived lessons.** Lessons waived with reason "Tested Out" count toward a block or course badge only when `countWaived` allows; for the prototype the owner decided they count toward path badges [DEC round 2]. Lessons carried over from an earlier record version are waived with reason `Administrative` plus the extension `https://course-xapi-mcp.sqwrlz.com/xapi/extensions/carried-over`, and never count toward a badge, whatever `countWaived` says. The badge's narrative states how many lessons were tested out (for example "4 of 6 lessons completed, 2 tested out by placement").
- Any statement stored for a registration whose course has badge declarations queues an evaluation job. Badge work never runs in the request that stored the statement (§11).
- **Verify before signing.** The job checks that:
  - the trigger statement was written by a client marked `trusted_for_satisfaction`;
  - it is not voided;
  - satisfaction is recomputed from the stored, non-voided statements of that registration against the imported course tree. AUs are matched through the publisher-id grouping activity, because the defined statements use the LMS-generated activityId [CMI5 §3.4]. For course-xapi-mcp courses the activityId follows the standard's scheme, `{au@id}/au`, so the match is also direct (**Builder's call (reconciliation 7)**).

  If the recomputation disagrees, nothing is issued, and the case is logged and shown to an admin. **Builder's call:** reason, it means a bad or compromised course server cannot mint badges just by writing `satisfied`. Cost if wrong: a course whose satisfaction rule our recomputation does not understand (a custom author rule) never issues automatically. Such rules are flagged at import and fall back to "trust the trusted client".
- **One badge per achievement per registration.** It is idempotent on (achievement, registration).
- The badge is **created pending and signed at claim time**, because OB 3.0 needs a recipient [OB §6]. The webhook and `GET /wp-json/learning-ledger/v1/badges?registration=` tell the course server or connector that a pending badge exists; they carry badge metadata, never a link.
- **Claim links. Builder's call (reconciliation 5).** `POST /wp-json/learning-ledger/v1/badges/{badge}/claim-links` (scope `badges/claim-links`) mints a claim link per request. It is bound to the actor and the badge, single-use, valid for 15 minutes, and minting a new one invalidates the previous one. Only the token's hash is stored (`lledger_links`). The course server calls it from its own claim page after it has identified the learner (in the prototype, the studio's claim page); an agent's `get_badges` returns badge metadata and the URL of that claim page, never a bearer link. Reason: a claim link is a bearer credential, and agents' transcripts are not a safe place for one. Cost if wrong: a learner who opens a link after 15 minutes goes back to the claim page for a fresh one. The issuer API is the plugin's; the standard defines only the `earned` statement and the claim-page URL.
- **Claim.** On the LRS's claim page the learner either:
  - enters an email, which is stored only as a salted SHA-256 `IdentityObject` with a fresh 16-byte salt; or
  - connects a wallet that proves control of a DID (VC-API interaction URL, the next step after v1).

  The xAPI actor is never put into a badge, hashed or not [OB §6].
- After signing, the LRS writes an `earned` statement:
  - verb `http://specification.openbadges.org/xapi/verbs/earned`
  - result extension `badgeassertion` = credential id
  - `context.statement` = the satisfied statement
  - the same registration [OB §4.2]
- **Evidence** is `urn:uuid:<satisfied statement id>` plus a narrative. Statements are never embedded [OB §4.3].
- `do_action( 'learning_ledger/badge_issued', … )` lets myCred or GamiPress mirror the award on the site.

### 8.2 Signing

- **Format:** OB 3.0 `OpenBadgeCredential`, VC Data Model 2.0, Data Integrity proof `eddsa-rdfc-2022`, with an Ed25519 key as a Multikey. This is the only format both 1EdTech conformance and the DCC/LCW verifier stack accept [OB §2.1].
- **Crypto:** `ext-sodium` (`sodium_crypto_sign_detached`), with `paragonie/sodium_compat` bundled as the fallback, so a missing extension slows signing down but does not stop it.
- **Canonicalisation:** JSON-LD expansion and RDFC-1.0 in PHP. Candidates are `accredifysg/php-json-ld` with `accredifysg/php-rdf-canonicalize` (MIT, 2026) [OB §2.5].
  - JSON-LD contexts (`credentials/v2`, `ob/v3p0/context-3.0.3.json`, extensions) are bundled and pinned. Nothing is fetched at runtime.
  - Expansion runs in safe mode, so an undefined term fails at issuance, not at verification.
  - Only OB 3.0 and VC 2.0 terms are used [OB §2.4, §4.3].
- **Issuer:** `did:web:<lrs host>`, with did.json served at `/.well-known/did.json` through a rewrite rule. The Issuer Profile, Achievement JSON and status lists are served as cacheable public documents. The issuer's display name is a setting. For the prototype the host is `lrs.sqwrlz.com`, so the issuer is `did:web:lrs.sqwrlz.com`, and the name is "Learn WordPress, reimagined" [DEC round 2].
  - Fallback where `/.well-known` does not reach WordPress (one of the unverified points on Spacefast): a path-based did:web, `did:web:<host>:issuer`, which resolves to `https://<host>/issuer/did.json`. Whether the DCC resolver handles path-based did:web is unverified and must be tested.
- **Key storage**, in order of preference:
  1. A `LLEDGER_BADGE_KEY` constant in wp-config.php.
  2. A file outside the web root with 0600 permissions.
  3. The `lledger_keys` table, encrypted with libsodium `secretbox` under a key derived from a wp-config constant (falling back to `AUTH_KEY` plus `SECURE_AUTH_KEY`), with a Site Health warning.

  Never in plain `wp_options` [OB §2.4]. **Builder's call** on option 3. Reason: Spacefast probably allows neither the constant nor the file. Cost if wrong: anyone holding both a database dump and wp-config.php can sign badges, so a key rotation must follow any such leak.
- **Signing surface.** Signing happens only inside the claim handler, for a pending badge the job created. There is no endpoint that signs arbitrary JSON. Every issuance is audited.
- **Rotation.**
  - `wp learning-ledger badges rotate` adds a new key to `assertionMethod` and signs with it from then on.
  - Old public keys stay in did.json for ever, so earlier badges keep verifying.
  - On compromise, the old key is removed and the affected badges are re-issued with the same id and a newer `validFrom` [OB §2.2].

### 8.3 Revocation

- W3C Bitstring Status List with `statusPurpose: "revocation"`.
- Each list is a signed `BitstringStatusListCredential` of 131,072 entries, GZIP plus multibase. A new list is started when one is 80% full.
- Indexes are assigned at random. Lists are re-signed on each revocation and served with `Cache-Control` matching `ttl` [OB §2.3].
- Revocation is triggered from wp-admin (`lledger_manage_badges`), by GDPR erasure, or automatically when the earning `satisfied` statement is voided. **Builder's call:** automatic revocation on void. Reason: the badge links only to its earning statement, and a voided statement no longer supports it. Cost if wrong: an accidental void revokes a badge, which can be re-issued.

### 8.4 Delivery

On the claim page, and on a public badge page `/badges/<uuid>` that is shown only if the learner chooses to share:

- the JSON credential to download;
- a baked PNG (iTXt `openbadgecredential`) [OB §7.3];
- a "copy link" for LinkedIn;
- next step: "add to wallet" through the VC-API interaction URL for LCW.

The `earned` statement and the webhook tell the course server and the connector, so they can show the badge.

### 8.5 The PHP canonicalisation risk and how to test it

The PHP RDFC and JSON-LD libraries are months old and have no visible adoption [OB §2.5, §7.4]. A one-byte difference in canonical N-Quads gives a signature that no wallet will verify. Tests, all in CI, before any badge is trusted:

1. W3C rdf-canon test suite (the library claims 86 of 86): run it ourselves and pin the library version.
2. W3C `vc-di-eddsa` test vectors: the canonical N-Quads, hash and proof value must match byte for byte.
3. Differential test: sign a corpus of real badges (every Achievement in the prototype, plus Unicode names, long narratives, alignment and result fields) in PHP. Then verify and re-canonicalise them in Node with Digital Bazaar's `jsonld` and `eddsa-rdfc-2022-cryptosuite`. Node runs in CI only, never at runtime.
4. Interop on each release: the 1EdTech validator (https://vc.1ed.tech), VerifierPlus and an LCW import.
5. Status list credentials go through the same tests.
6. **Fallback switch:** VC-JWT RS256 with `firebase/php-jwt` [OB §2.5]. It passes 1EdTech but not LCW, so it is a documented degraded mode, not the default.

Third-party PHP libraries are bundled with namespaces prefixed (Strauss or PHP-Scoper), so they cannot clash with another plugin's copy. The LGPL `socialweb-php/rdf` is avoided because it would turn the combined work into GPL-3 [OB §2.5].

---

## 9. LMS integration architecture

### 9.1 Loading

```
learning-ledger/
  src/Lrs/…            LRS role
  src/Connector/…      connector role (LMS-neutral)
  integrations/
    sensei/            loaded only if Sensei is active (first)
    lifterlms/ tutor/ learnpress/                (next, in that order)
```

The order is the owner's: Sensei, then LifterLMS, Tutor LMS and LearnPress. LearnDash is not in the first wave, and neither is MasterStudy [DEC round 2]. The interface stays general, so either can be added later as one more folder.

- Each integration folder has a small `bootstrap.php` with an `is_available()` check: a class or function exists and the version is at least the minimum supported (for Sensei, `function_exists( 'Sensei' )` and `Sensei()->version`).
- The bootstrap is evaluated on `plugins_loaded` (priority 20) only when the connector role is on. Nothing else in the folder is loaded until then.
- These are not separate plugins [DEC].

### 9.2 One shared interface

The connector core is LMS-neutral. It owns the outbox, inbox, identity map, statement builder, the waiver ledger, the program post type and the package exporter and importer (§9.5). Each integration implements one adapter:

```php
interface LMS_Adapter {
    public function id(): string;                         // 'sensei'
    public function lms_version(): string;
    public function capabilities(): array;                // subset of: emit_events, apply_completion,
                                                          // apply_score, mark_waived, enrol,
                                                          // export_course, import_course
    public function register_event_listeners( Event_Sink $sink ): void;   // LMS hooks -> neutral events
    public function apply( Result $result ): Apply_Outcome;               // neutral result -> LMS progress
    public function resolve( Activity_Ref $ref ): ?Lms_Object;            // IRI -> course/lesson/quiz
    public function course_tree( int $course_id ): Course_Tree;           // for export and IRIs
    public function upsert_course( Course_Spec $spec ): int;              // import: create or update by stable id
    public function upsert_lesson( int $course_id, Lesson_Spec $spec ): int;
    public function enrol( int $user_id, int $course_id ): bool;
    public function progress( int $user_id, int $course_id ): Progress;   // for the course-server route
    public function is_waived( int $user_id, int $lesson_id ): bool;      // reads the shared waiver ledger
}
```

- **Neutral events** (from the LMS): enrolled, course started, lesson started, lesson completed, quiz submitted, quiz graded (score, passed or failed), course completed, progress reset, unenrolled.
- **Neutral results** (to the LMS): lesson completed, lesson passed or failed with an optional score, lesson waived with a reason, course satisfied.

The statement builder turns neutral events into one statement shape for every LMS (§9.3, direction 2). One test suite runs against every adapter.

**Protection against LMS updates (the anti-goal):**

- Adapters call only public LMS functions and documented hooks, never LMS tables.
- Every adapter call is wrapped. A `Throwable` puts the integration into **paused** state: events keep being queued, nothing is applied, and the admin sees a notice.
- Each adapter declares a "tested up to" LMS version. A newer **major** version starts paused until the admin confirms. A newer minor version runs, with a notice.
- CI runs the adapter contract tests every night against the latest release of each LMS. For Sensei the source is the wordpress.org zip.

### 9.3 Sensei: the four directions

What Sensei 4.26.4 offers:

- **Progress storage.** By default Sensei stores progress as comments: `comment_type` `sensei_lesson_status` and `sensei_course_status`, the status in `comment_approved`, details in commentmeta. Opt-in "high-performance progress storage" (HPPS) uses the `wp_sensei_lms_progress` and `wp_sensei_lms_quiz_*` tables. HPPS is still behind `experimental_progress_storage` settings (`includes/internal/services/class-progress-storage-configuration.php:79–99`, `includes/internal/installer/class-schema.php:97–149`).
- **We must therefore never touch either store directly.** We go through `Sensei_Utils` and the repository objects on `Sensei()`, which switch between backends.
- **Public progress functions** in `includes/class-sensei-utils.php`:
  - `sensei_start_lesson( $lesson_id, $user_id, $complete )` at :595. It starts the course if needed, creates lesson progress, completes it when `$complete` is true, and fires `sensei_user_lesson_end`.
  - `update_lesson_status( $user_id, $lesson_id, $status, $metadata )` at :2030.
  - `user_complete_course` at :1592 (completes the course when every lesson is complete, and fires `sensei_user_course_end`).
  - `user_completed_lesson` at :1800, with its filter `sensei_user_completed_lesson`.
  - `sensei_grade_quiz` at :459.
  - Course status is recalculated on `sensei_user_lesson_end` (`includes/class-sensei-course.php:97`), so course completion and certificates follow lesson completion.
- **Hooks:**
  - `sensei_user_course_start`, `sensei_user_lesson_start`, `sensei_user_lesson_end`
  - `sensei_user_quiz_submitted`, `sensei_user_quiz_grade`
  - `sensei_user_course_end`, `sensei_lesson_status_updated`, `sensei_course_status_updated` (with the previous status since 4.20.1)
  - `sensei_user_lesson_reset`, `sensei_user_course_reset`
  - `sensei_course_enrolment_status_changed`, `sensei_manual_enrolment_learner_enrolled` and `sensei_manual_enrolment_learner_withdrawn`
  - `sensei_learning_mode_lesson_status_icon` (`includes/blocks/course-theme/class-course-navigation.php:295`)
- **REST.** Sensei's REST routes are in `sensei-internal/v1` (`includes/rest-api/class-sensei-rest-api-internal.php:25`). They are internal and not a stable contract, so we do not build on them. Sensei 4.26 also registers four read-only Abilities (`sensei/courses-list`, `lessons-list`, `questions-list`, `students-list`; `includes/abilities/class-sensei-abilities.php`), which are useful for discovery but cannot write.
- **Lessons** are a `show_in_rest` post type with the block editor (`includes/class-sensei-posttypes.php`). `Sensei_Lesson_Blocks` sets a default template but does not restrict blocks, so blocks registered by our plugin can be inserted into lessons. Our blocks still need to be checked in the Sensei lesson editor.

**Direction 1: agent results to Sensei progress.**

| Result | How it is applied | Supported or workaround |
| --- | --- | --- |
| Lesson complete: the AU's moveOn is met | `Sensei_Utils::sensei_start_lesson( $lesson, $user, true )` | Supported, public API. **Builder's call (reconciliation 7):** the connector computes moveOn from the program's imported course tree (§9.5) and the stored statements of the registration, and marks the lesson complete only when moveOn is met. A cmi5 `completed` alone does not complete a `CompletedAndPassed` lesson. Cost if wrong: none found; it is what cmi5 means by moveOn |
| Lesson passed or failed with a score, where the lesson has a Sensei quiz | Sensei quizzes are scored from their own submitted answers. There is no public "set quiz result" call. `sensei_grade_quiz()` only changes the grade of an existing submission | **Workaround.** Course-xapi-mcp checkpoints are not Sensei quizzes. Lessons that are delivered through the agent should not carry a pass-required Sensei quiz. The connector records the score in its own ledger and shows it with our lesson block. If a lesson does have a pass-required quiz, `user_completed_lesson()` reads quiz progress (`class-sensei-utils.php:1842–1854`) and says "not complete". The connector then answers through the supported filter `sensei_user_completed_lesson`, returning `passed` for lessons whose ledger entry is passed. Writing quiz progress through the internal repositories is rejected, because it is internal API |
| Lesson waived ("tested out", branching skip) | Sensei has no waived status. Its lesson progress model knows only `in-progress` and `complete`, and its quiz model `in-progress`, `passed`, `failed`, `graded`, `ungraded`. A custom status value would not count as complete anywhere | **Workaround.** Mark the lesson complete with `sensei_start_lesson( …, true )`, so prerequisites, course completion and certificates all work, and record `waived` with its reason in the connector's own `lledger_waivers` table (user, lesson, registration, statement id, reason, carried-over flag). Display: "Tested out" in learning mode through `sensei_learning_mode_lesson_status_icon`; in the course outline through a `render_block` filter on Sensei's outline lesson block; in our lesson block; and in the Sensei admin reports through the `sensei_analysis_course_user_lesson` filter (the exact filter arguments are still to be checked). Sensei's own reports will otherwise show "Complete". That is honest about the outcome but loses the reason, so our ledger is the source of the reason |
| Block satisfied (one path) | The block is one Sensei course (§9.5), so its completion follows from lesson completion (Sensei recomputes on `sensei_user_lesson_end`). If not, `Sensei_Utils::user_complete_course()` | Supported |
| Course satisfied (the program) | Recorded on the program for display; Sensei has no level above the course | Plugin-only |
| Enrolment needed before applying | Sensei manual enrolment provider (`Sensei_Course_Manual_Enrolment_Provider`) | Supported; the admin chooses whether the connector may enrol automatically (off by default) |

Side effects to handle:

- `sensei_user_lesson_end` sends teacher emails (`includes/class-sensei-emails.php:48`). Applying six "tested out" lessons at once would send six emails. While the connector applies waivers, it removes those email actions for that request only. That is a workaround on public hooks.
- **Echo guard** (**Builder's call (reconciliation 3)**): the connector never re-emits statements for LMS changes it applied from the LRS. While applying results it sets a flag so that its own direction-2 listeners skip those hooks, and each applied change is also recorded in the waiver and apply ledger, so a later hook for the same (user, lesson, status) is recognised and dropped.

**Direction 2: Sensei on-site learning to the LRS.** A per-course setting (**Builder's call (reconciliation 3)**): on by default for ordinary Sensei courses, off for courses in a program driven by a course server, because that course server is the course's one writer (§3). When it is on, Sensei hooks write one row to the connector's `lledger_outbox` (one INSERT, no HTTP call). The outbox is sent at `shutdown` after the response, with cron as catch-up (§11), in batches with `PUT`, using a deterministic statement id: a UUIDv5 of (site, user, object, event, event time). Retries are therefore idempotent, because an identical PUT returns 204.

Statement shapes (**Builder's call**, plain xAPI rather than cmi5, because Sensei lessons are not AUs and have no launch session):

| Sensei event | Verb |
| --- | --- |
| enrolled | `http://adlnet.gov/expapi/verbs/registered` |
| lesson started | `http://adlnet.gov/expapi/verbs/attempted` |
| lesson completed | `http://adlnet.gov/expapi/verbs/completed` |
| quiz submitted | `http://adlnet.gov/expapi/verbs/answered` (the score, no answer text by default) |
| quiz graded | `passed` or `failed` with `score.scaled` |
| course completed | `completed` on the course activity |
| reset | `http://id.tincanapi.com/verb/reset`, or none |

- `context.registration` is one UUID per user per course, kept by the connector.
- `contextActivities.parent` is the course.
- Activity IRIs live under an activity base fixed per course on first use, for example `https://site/xapi/courses/{id}/lessons/{id}`. They are stored in post meta and never recomputed, so a later domain change does not split the record. Courses in a program use the program's IRIs instead (the standard's scheme, §9.5).
- Cost if wrong: reports that expect cmi5-shaped statements for on-site learning will not find them. The statement builder is shared, so the shape can be changed in one place.

**Direction 3: identity (xAPI actor and WordPress user).**

- **Actor the connector assigns.** `account { homePage: <site home URL>, name: <random UUID in usermeta lledger_actor> }`. It carries no email or login name, and it is used for all direction-2 statements.
- **Where course-xapi-mcp learning starts on the site** (the prototype's path), the course server asks the connector for a learner through the signed `POST learners` route (§4.4). The connector creates an anonymous WordPress user (no email) and its actor, and returns both. The course server mints the registration (§3) and writes statements about that actor. In the prototype this happens on the learner's first recorded action, and anonymous learners idle for 180 days are deleted through `DELETE learners/{id}` [DEC round 2]; the plugin provides the route, and the idle policy belongs to the course server.
- **Where the learner started in their own agent** with an anonymous actor, linking is explicit.
  1. The course server shows a one-time code.
  2. The logged-in learner enters it on the site.
  3. The connector redeems the code with the course server (a signed request) and stores (actor IFI hash, user id) in `lledger_identity_links`.
  4. It then backfills by querying the LRS for that actor.

  Statements are never rewritten, because the link lives beside the record.
- **Matching order** for an incoming statement:
  1. The connector's own account homePage.
  2. An explicit link.
  3. An `mbox` equal to a user's email, **only** if the admin turns it on. It is off by default, because any writing client can assert an mbox.
- Unmatched statements are kept in the inbox for 30 days, then dropped (**Builder's call**; cost if wrong: a learner who links later than that needs a manual backfill, which is one WP-CLI command).

**Direction 4: Sensei courses become course-xapi-mcp courses.**

- **The program post type. Builder's call (reconciliation 1).** One Sensei course per path, as on Learn. A plugin-owned `lledger_program` post type holds the course-level data: the course IRI, the placement AU and its guide, teaching rules, badge declarations (`awardOn`, `countWaived`) and the custody option. It groups its path courses into **one** cmi5 course: the program is `<course>`, each Sensei course is a `<block>`, and each lesson is an `<au>`. "Course satisfied" maps to the program; "block satisfied" maps to Sensei course completion. Sensei modules are not mapped; a path course's lessons are its AUs in Sensei's order. Reason: Sensei completion and certificates are per course, and the standard keeps one package per course so placement works across paths in one registration. Cost if wrong: a course that wants modules as sub-structure loses them in the package.
- The plugin registers blocks that can be used inside Sensei lessons. The names are fixed before any content migration (**Builder's call (reconciliation 12)**; the slug is the owner's, `learning-ledger`):
  - `learning-ledger/beat` (an ordered teaching beat: id, goal, optional question; inner blocks are its content)
  - `learning-ledger/checkpoint` (key, kind, question, maxAttempts; its checks, branches and fallback options are attributes inside it, edited in the block sidebar)
  - `learning-ledger/rubric` (the rubric text, an inner block of a checkpoint)
  - `learning-ledger/planted-bug` (file, code, hint, explanation, fix checks)
- **Secrecy.** Rubrics, check patterns, bug explanations and fix checks are server-only [CMI5 §6.1].
  - These blocks are dynamic blocks whose front-end render prints nothing, or only the learner-facing part, so their secret attributes never reach `content.rendered`.
  - The raw content is in `post_content`, which is visible only in `context=edit` to users who can edit the lesson.
  - The full package is served only by signed request or to editors (§9.5).
- **Export and import** of the package are in §9.5.

**Summary of what Sensei allows.**

- Supported through public API: completing lessons and courses, enrolment, every event needed for direction 2, extra blocks in lessons, course and lesson meta, creating courses and lessons (they are post types, so the importer uses `wp_insert_post`), and filtering the "completed lesson" answer.
- Needs a workaround:
  - a "tested out" or waived state: Sensei shows it as complete, and the reason lives in our ledger and display filters;
  - setting a quiz result from outside, which is avoided by design;
  - suppressing teacher emails during bulk application.
- Not to be used: Sensei's `sensei-internal/v1` REST routes, its `Sensei\Internal\…` repositories (marked `@internal`), and its progress tables or comments directly.

### 9.4 Fit check against the other LMSs (light)

The first wave, in the owner's order [DEC round 2], then the two kept for later:

| LMS | Mark complete | Completion and quiz hooks | Progress store | Fit notes |
| --- | --- | --- | --- | --- |
| LifterLMS 10.3.2 | `llms_mark_complete( $user_id, $object_id, $object_type, $trigger )` (`includes/functions/llms.functions.person.php:332`) | `lifterlms_lesson_completed`, `lifterlms_section_completed`, `lifterlms_course_completed`, `lifterlms_course_track_completed`, `lifterlms_quiz_completed` (`includes/class.llms.engagements.php:226–233`) | `lifterlms_user_postmeta` table | `$trigger` lets us label our writes (`learning-ledger`), which simplifies the echo guard |
| Tutor LMS 3.9.7 | `\Tutor\Models\LessonModel::mark_lesson_complete( $post_id, $user_id )` (`models/LessonModel.php:145`) | `tutor_lesson_completed_after`, `tutor_mark_lesson_complete_after`, `tutor_course_complete_after`, `tutor_quiz/attempt_ended`, `tutor_after_enrolled` | user meta `_tutor_completed_lesson_id_{id}` | Read from a local install; not on Tutor's documentation site |
| LearnPress 4.4.12 | `LP_User::complete_lesson( $lesson_id, $course_id )`, `finish_course( $course_id )` (`inc/user/abstract-lp-user.php:623, 772`) | `learn-press/user-completed-lesson`, `learn-press/user-course-finished`, `learn-press/user/quiz-finished` | `learnpress_user_items` and `learnpress_user_itemmeta` tables | Fits the interface |
| *Later:* LearnDash (commercial; not on wordpress.org) | `learndash_process_mark_complete( $user_id, $postid, $onlycalculate, $course_id, $force )`, with `$force` since 4.11 (https://developers.learndash.com/function/learndash_process_mark_complete/) | `learndash_lesson_completed`, `learndash_topic_completed`, `learndash_course_completed`, `learndash_before_course_completed` (same page); quiz hook not verified | user meta (key not stated on the page) | Not in the first wave. Topics are a third level (lesson, then topic): the adapter would map topics to AUs or beats. CI needs a licence |
| *Later:* MasterStudy | Not researched | | | Not in the first wave; checked before its adapter is planned |

LifterLMS and LearnPress were read from their wordpress.org zips. All of these LMSs have the same shape: public "mark complete" calls, completion hooks and no waived state. That supports the interface in §9.2, with `mark_waived` always implemented as "complete plus a ledger entry".

### 9.5 The course package: export and import

**Builder's call (reconciliation 2).** The standard's ZIP is the only interchange format: `cmi5.xml` at the root, `cxm/course.json`, `cxm/lessons/<key>.json` and optional `cxm/files/<key>/…`. The plugin produces and consumes it, and the standard's validator is the test of both directions. The exact element and key names come from the standard; the plugin only fills them in. Reason: one format means the course server, the LRS and the LMS cannot drift. Cost if wrong: an LMS feature with no place in the package (Sensei quizzes, modules) is not carried.

**Mapping** (§9.3, direction 4): program to `<course>` and `course.json`; Sensei course to `<block>` and `course.json#/paths/<key>`; lesson to `<au>` and `lessons/<key>.json`, with `moveOn` = `CompletedAndPassed` when a checkpoint exists, otherwise `Completed`; the placement AU from the program. The AU URL is the course server's launch bridge, a program setting.

**IRIs. Builder's call (reconciliation 7).** The standard's scheme: AU ids live under the program's course IRI base, and the activity id of an AU is `{au@id}/au`. Each course and lesson keeps a stable key in post meta (in the prototype, today's slugs such as `bt-what-is-a-block-theme`), and its IRI is built from that key inside the scheme, so renaming a post never changes an IRI.

**Export.**

- `GET /wp-json/learning-ledger/v1/programs/{id}/package?flavour=full|public` and `wp learning-ledger package export <program>`.
- Two flavours. *Full* holds every field, including rubrics, check patterns, bug explanations and fix checks; it is served only by signed course-server request (§4.4) or to users with `lledger_manage_connector`. *Public* has the secret fields stripped and is what an LMS or the LRS's course import may get.
- Each package carries a `version` (incremented on change) and an ETag (the SHA-256 of the ZIP), and honours `If-None-Match`.
- Beat content is exported as CommonMark from an allowlist of core blocks (paragraph, heading, list, code, preformatted, quote, image). Other blocks produce a save-time warning in the editor and are left out.
- **Content-changed ping.** On `save_post` for a program, course or lesson, the connector sends a signed ping (its own Ed25519 key, whose public key the course server reads from the status route) to the program's configured course-server URL. The course server then refetches the package and re-imports it into the LRS (§7 item 7).

**Import.**

- `POST /wp-json/learning-ledger/v1/programs/import` (with a package URL or upload, `lledger_manage_connector` or a signed course-server request) and `wp learning-ledger package import <package URL>`. The WP-CLI form takes a URL, so on Spacefast the ZIP is published as a file of the space and fetched through a share link, as plugin installs are.
- Idempotent by stable key: it creates or updates the program, one LMS course per block and one lesson per AU through the adapter's `upsert_course` and `upsert_lesson`, in order. Beat Markdown becomes `learning-ledger/beat` blocks with core inner blocks; checkpoints, rubrics and planted bugs become their blocks. Re-importing an unchanged package changes nothing.
- It never deletes: a lesson missing from a newer package is moved to draft and reported. Learner progress is never touched by an import.
- A package that fails the standard's schema is rejected whole, with the validator's error codes in the response, so an author's AI can fix it.

### 9.6 The cmi5 player (Sensei)

**Owner's decision** [DEC round 3]: the connector includes the LMS side of cmi5 for Sensei, on whenever the Sensei integration is. "The LMS" below is the connector; "the LRS" is the configured LRS, local or remote. Spec references are to cmi5 Quartz [SPEC].

**Import.** The player imports a ZIP (32- or 64-bit, `cmi5.xml` at the root) or a standalone `cmi5.xml` with absolute URLs. The package must be XSD-valid, and whitespace is stripped from every element [SPEC §13.1, §14]. Entry points are §9.5's; a package with `cxm/` follows §9.5 in full. **Builder's call:** one mapping for every package (§9.3, direction 4). Top-level blocks become Sensei courses under a program and AUs become lessons. Nested blocks are flattened into their top-level course but kept in the stored tree for satisfaction. AUs directly under `<course>` go into one Sensei course. Reason: Sensei has two levels. Cost if wrong: nested blocks don't show in Sensei's outline. Each lesson gets a `learning-ledger/cmi5-lesson` block (status and launch buttons) and no Sensei quiz.

**AU content hosting. Builder's call.**

- **Storage.** AU files go to `LLEDGER_CMI5_STORE`, a directory outside the web root, when that is set. Otherwise they go to `wp-content/uploads/learning-ledger-cmi5/`, stored as `<sha256>.bin` with a manifest (path, hash, MIME type). A direct request therefore gets a download, never HTML or PHP. Path traversal and symlinks are rejected.
- **Content origin.** Files are served from a separate hostname (`LLEDGER_CMI5_CONTENT_HOST`, such as `au.example.org`, pointed at the same install). An early handler answers that host before WordPress determines a user. It serves only manifest paths, ignores and never sets cookies, and sends `nosniff` and `frame-ancestors <site origin>`. WordPress cookies are host-only unless `COOKIE_DOMAIN` covers the content host, and the player refuses such a host.
- **Fallback with one hostname.** Files are served under `/lledger-au/` with a CSP `sandbox` that omits `allow-same-origin`. The AU then runs in an opaque origin and cannot read WordPress cookies or same-origin responses. Its xAPI calls send `Origin: null`, and child-token CORS allows that, because the token authenticates the call, not a cookie.
- Reason: third-party AU JavaScript must never run as the WordPress origin. Cost if wrong: AUs needing their own storage break in the fallback.
- **Remote AUs** (an absolute `url`) are not hosted by the plugin. Their origin goes on the child token's CORS list.

**Launch** (`POST learning-ledger/v1/cmi5/launch`, for an enrolled learner):

1. **Registration.** There is one per user and cmi5 course, created at the first launch, when moveOn is first evaluated, so `NotApplicable` AUs are satisfied at once [SPEC §9.6.1]. A Sensei course reset starts a new one (**Builder's call**; cost if wrong: no reattempt inside a registration).
2. **Session.** Any session still open for the AU is abandoned, and a new `sessionid` is created. The activityId is `{au@id}/au`, which is stable and never equals `au@id` [SPEC §8.1.5] (**Builder's call (reconciliation 7)**, extended to every package). The actor is the direction-3 account actor, with no PII [SPEC §8.1.3].
3. **`LMS.LaunchData`** [SPEC §10.2] holds the contextTemplate, launchMode, moveOn, masteryScore, launchParameters, entitlementKey and a returnURL of `…/cmi5/return/{session}`. A missing `cmi5LearnerPreferences` document is created from `get_user_locale()`, and an existing one is never overwritten [SPEC §11]. Then `launched` is written with its context extensions [SPEC §9.3.1, §9.6.3].
4. **Fetch.** The fetch URL `…/cmi5/fetch/{token}` holds 32 random bytes, stored as a hash. It accepts POST only, works once, and expires 5 minutes after the launch if unused. Later calls get `error-code` 1 [SPEC §8.2]. The auth token it returns is a child credential minted with `tokens/mint` (§4.1):
   one registration and actor, no void, read-only LaunchData, the cmi5 guard (§7 item 6), at most 8 hours, revoked when the session ends [SPEC §8.2.1].
5. **Launch URL.** The AU URL gets `endpoint` (the LRS's public xAPI base, never the loopback), `fetch`, `actor`, `registration` and `activityId`, merged with the AU's own query string [SPEC §8.1]. `OwnWindow` redirects the window. For `AnyWindow` the player uses an iframe in the lesson (**Builder's call**; cost if wrong: frame-averse AUs need `OwnWindow`).

**Modes and sessions. Builder's call.**

- Normal is for enrolled learners, and Review is offered once the AU is satisfied. Browse is only for lesson editors, in a preview registration that never reaches Sensei. In Browse and Review the guard rejects defined statements other than `initialized` and `terminated` [SPEC §10.2.2].
- After `terminated` the guard allows a 10-minute grace, then rejects everything [SPEC §9.3.8].
- A session is abandoned on a relaunch while it is open [SPEC §9.3.6], and also when its token expires without `terminated`. `abandoned` carries the duration [SPEC §9.5.4].

Cost if wrong: an AU left idle for more than 8 hours needs a relaunch.

**Waived, satisfied and Sensei.** The LMS writes `waived` [SPEC §9.3.7] in two cases:

- an admin or teacher waives the AU, with a reason (this is CATAPULT's `waiveAU`);
- for course-xapi-mcp packages, an allowed statement carries the standard's `waive-recommended` extension. This uses reason "Tested Out" and is a per-program setting, on by default.

moveOn is evaluated with the direction-1 code (§9.3) against the stored tree: on the return route before the redirect, on the inbox (webhook or poll, for windows closed without return), at registration and on each waiver.

- A satisfied or waived AU completes its Sensei lesson (`sensei_start_lesson( …, true )`, with the waiver ledger for waivers).
- A satisfied block writes `satisfied` once per registration [SPEC §9.3.9], and Sensei course completion follows.
- A satisfied course writes `satisfied` on the course, which is recorded on the program.

For player courses direction 2 is forced off. The connector writes the LMS statements, the AU writes its own, and nothing else writes (§3). The connector's client adds `tokens/mint`, `void` [SPEC §6.3], `state` and `profile`, plus `statements/write` for player courses (§4.2). **Builder's call:** the LRS must be a Learning Ledger LRS, because child tokens and the guard are ours. Cost if wrong: sites on another LRS wait for a token-issuing proxy.

**course-xapi-mcp, custody option 1.** The package is imported with custody option 1, so its AU URLs are the course server's launch bridge (remote, `OwnWindow`) [standard §8.2].

The bridge fetches server-side, sends `initialized` and hands the session to the learner's agent; the course server writes AU statements with the child token; the connector writes `launched`, waivers and `satisfied` and applies Sensei completion. The course server signs option-1 badges (standard Q1), so §8.1 does not run for player courses in v1.

**Conformance.** CATAPULT's LMS Test Suite is a v1 release gate beside the ADL suite (§6.9). Its adapter (`importCourse`, `getLaunchUrl`, `waiveAU`, `abandonSession` and the rest) calls test-only routes that a constant enables [CMI5 §5]. The suite runs its test AUs in a real browser against a reachable WordPress: wp-env in CI, with the content origin on a second hostname, plus a fallback-mode run. That browser is the suite's own jest-puppeteer harness; our own browser checks use the agent browser CLI, and the manual LTS procedure is the alternative if the owner rules the harness out.

**Risks and unverified items.**

- **Spacefast.** Unverified: plugin-streamed AU files at volume, and a second hostname on the same Space (§12.1). Without one, Spacefast uses the fallback.
- **Large packages** meet upload, memory and WP-CLI's 360-second limits. Import by URL streams to disk, extraction is a resumable job (§11), and the cap is configurable (**Builder's call:** 1 GB unpacked, 20,000 files and a compression-ratio check; cost if wrong: large courses need the cap raised).
- **Sensei quizzes.** A pass-required quiz added to a cmi5 lesson blocks completion. The editor warns, and `sensei_user_completed_lesson` answers (§9.3).
- **AU behaviour** in an iframe, an opaque origin or Review mode: CATAPULT's packages and real exports (Storyline, Rise, H5P) are tested before release.

---

## 10. How the LRS notifies a connected LMS site

- **Subscriptions.** An admin, or a client with `lledger_manage`, creates a subscription on the LRS. It holds the target URL and filters: actor homePage, activity-IRI prefix, verbs, registrations. Typically there is one per connector site.
- **Delivery (push).**
  - After a statement is stored, matching subscriptions get an outbox row (one INSERT per subscription, in the same transaction).
  - A worker sends batches of up to 100 statement envelopes. Each envelope holds `event_id`, `subscription_id`, `cursor` (the last `seq`) and the statements in exact format.
  - Batches go to the connector inbox, with these headers:
    - `X-Learning-Ledger-Timestamp`
    - `X-Learning-Ledger-Key` (key id)
    - `X-Learning-Ledger-Signature` (Ed25519 over `timestamp + "." + body`)
- **Signing.** **Builder's call:** asymmetric signatures rather than an HMAC shared secret. Reason: no secret has to cross WP-CLI or the Spacefast activity trail, and the connector pins the LRS's public key from `/about` at setup. Cost if wrong: a little more code than HMAC, and it relies on sodium or sodium_compat, which we need for badges anyway.
- **Connector checks:**
  - the signature;
  - the timestamp is within 5 minutes;
  - the `event_id` has not been seen before (replay);
  - then it stores the payload and returns 202.

  Processing (identity match, then `apply`) happens at `shutdown` in the same PHP process, after the 202 has been sent (`fastcgi_finish_request()` where available), with the cron worker as catch-up. **Builder's call (reconciliation 11).**
- **Latency target.** A result appears in Sensei within **90 s p95** of being stored in the LRS. The LRS also sends deliveries at `shutdown` after the storing request responds, with cron as catch-up, so the usual lag is a few seconds; 30 s is an aim, not a binding figure. Reason: WP-Cron on a quiet site can lag by minutes, and shutdown-time work removes that dependency without blocking any response. Cost if wrong: a `shutdown` handler that fails silently leaves cron to catch up, which the lag measurement in §11 shows.
- **Failure handling.**
  - Non-2xx or timeout (5 s): retry with exponential backoff (1 min, 5, 15, 60, then every 6 h), for 72 hours.
  - After that the subscription is marked failing and the admin is notified on both sites.
  - Nothing is lost, because of the poll.
- **Catch-up poll (pull).**
  - Every 15 minutes, and immediately after the connector comes back from a failure, the connector runs `GET /xapi/statements?since=<last stored seen>&…` with its read credential, filtered to its activity base and actors, and pages through `more`.
  - This uses plain xAPI, so it works against any conformant LRS.
  - The poll and the webhook feed the same inbox, de-duplicated by statement id.
  - **Builder's call** on 15 minutes. Reason: a learner sees Sensei progress within one poll interval even if webhooks are blocked. Cost if wrong: extra load from many connectors, which is configurable.
- **Ordering.** Application is idempotent and order-tolerant. "Complete" is never undone by a later event in the batch; only an explicit LMS reset undoes it. Voids that arrive later are applied as "flag for review", not as automatic un-completion. **Builder's call:** reason, removing a learner's Sensei progress silently is worse than a review notice. Cost if wrong: an admin has to act on voided results.

---

## 11. Performance

**Design rules:**

1. **Separate install recommended** (owner's decision). On a single install, every xAPI request still costs a full WordPress bootstrap and a PHP worker. Running out of workers is the real way a busy LRS slows a site, more than database load.
2. **Custom tables** with the index plan in §6.5. No postmeta, no `WP_Query`, no autoloaded options larger than 1 KB.
3. **The request path does only what xAPI requires to be synchronous:** validate, insert the statement and its ref rows, update the activity definition, and insert outbox rows. One transaction. Everything else is queued: webhooks, badge evaluation, signing, `earned` statements and status-list re-signing.
4. **The queue is our own outbox and jobs table, processed at `shutdown` after the response has been sent (`fastcgi_finish_request()` where available), with WP-Cron every minute as catch-up** (reconciliation 11, §10). This applies to LRS deliveries, connector inbox processing and the connector's outbox alike. **Builder's call:** rather than Action Scheduler. Reason: a smaller dependency surface, no conflict with the Action Scheduler copy that Sensei and WooCommerce bundle, and full control over batch sizes. Cost if wrong: we reimplement retries and locking. Action Scheduler stays the fallback if ours proves fragile. On hosts with a real system cron, the docs recommend `DISABLE_WP_CRON` plus a system cron.
5. **Connector hooks do one INSERT and return.** No HTTP call ever happens during a learner's page view or LMS action.
6. **Object cache** is used for client lookup, rate limits and activity definitions when a persistent cache exists. The plugin works without one.
7. **A later fast path** if bootstrap cost dominates: a small must-use loader that, for `/xapi/` requests, filters `option_active_plugins` so only this plugin loads. Not in v1. Measure first.

**Scale targets (Builder's call, to be verified by a load test in CI on a modest 2 vCPU / 4 GB MySQL 8 host). Reason: there is no external benchmark, so these numbers mark "a ton of records" concretely. Cost if wrong: the targets are reset after the first load test.**

| Measure | Target |
| --- | --- |
| Stored statements per LRS install | 10 million, with query targets still met |
| Sustained ingest | 50 statements/s, with bursts of 500 statements/s for 10 s (batched POST) |
| Single-statement POST latency | p95 under 150 ms |
| Filtered GET (agent + registration, or activity + since), limit 100 | p95 under 300 ms at 10 million rows |
| `related_activities` / `related_agents` GET | p95 under 800 ms at 10 million rows |
| State GET or PUT | p95 under 80 ms |
| Result visible in Sensei (stored in the LRS to applied by the connector) | p95 under 90 s (reconciliation 11); aim under 30 s with both shutdown paths working |
| Effect on connector page views | under 2 ms added (one INSERT) |

---

## 12. Hosting

### 12.1 Spacefast

Proven by the spike [DEC, SF, `sf-spike/`]:

- A claimed Space has WordPress 7.1.3 and PHP 8.5.12 on WP Cloud. These versions were reported by the spike's `lrs-spike/v1/ping` REST route, so a custom REST route answered publicly.
- Install: publish the plugin zip as a file of the Space, then `POST /v1/spaces/{id}/wp-cli` with `["plugin","install","<share-link zip URL>","--activate","--force"]`.
- Plugins survive republishing.

Limits:

- `eval`, `eval-file`, `shell` and `core version` are disallowed.
- WP-CLI returns success or failure only, with 64 arguments, 4,096 characters per argument, a 360-second limit, and every run logged.
- The Space must be public for outside xAPI clients. A private Space answers outside callers with 403.

That leads to the WP-CLI-first, no-output setup in §4.3, and REST-only export.

**Unverified, to check in the first build spike** (each with a probe and a fallback):

| Item | Probe | Fallback |
| --- | --- | --- |
| `ext-sodium` present | The plugin's Site Health test, plus an `/about` extension field reporting `sodium: native or compat` | `sodium_compat` (slower; fine at our volumes) |
| `/.well-known/did.json` reaches WordPress | Fetch it after activation | Path-based `did:web:<host>:issuer` (§8.2) |
| `Authorization: Basic` reaches PHP on a public Space | The conformance suite's first request | None found in the docs. This one blocks the LRS role on Spacefast and must be answered first |
| Sensei installs (`plugin install sensei-lms --activate`) | WP-CLI exit status, then Sensei's REST presence | None. If it fails, the prototype's Sensei site needs another host |
| WP-Cron runs without visitor traffic | Job timestamps in the `/about` extension | A Spacefast cron (8 per Space, GET to its own path) hitting a cron-trigger route protected by a secret header |
| Outbound HTTP from PHP (webhooks) | Delivery log | Poll-only mode (connector pulls) |
| `dbDelta` custom tables on the Space's MySQL | Activation check | None found; it blocks the LRS role |
| wp-admin access | Open it | WP-CLI and REST-only administration (already designed in) |
| A custom domain (`lrs.sqwrlz.com`) pointed at the LRS Space serves WordPress, `/xapi/` and `/.well-known/` | Fetch `/about` and did.json on that host | None for the badge issuer: the did:web identity is that host for ever, so this must pass before the first badge |
| The `shutdown` path runs after the response (`fastcgi_finish_request()` present) | Timestamps in the `/about` extension | Cron-only processing, still inside the 90 s target if cron runs every minute |
| The Sensei Space can stream uploaded AU files (writable uploads, Range, sustained file responses) | Import and launch a CATAPULT package | None found; it blocks hosted AUs on Spacefast (remote AUs and the launch bridge still work) |
| A second hostname can point at the same Space, as the AU content origin (§9.6) | Fetch a manifest file on that host | The CSP-sandbox fallback on the site host |
| Large package uploads and extraction | Import a package of several hundred MB by share-link URL | Background extraction across shutdown and cron runs; a lower size cap |

### 12.2 Ordinary WordPress hosting

Requirements:

- PHP 8.2+, because the RDFC library requires ^8.2. **Builder's call:** cost, hosts still on PHP 8.1 or older cannot run the LRS role. The connector alone could run on 8.1, but one minimum keeps things simple.
- WordPress 6.6+.
- MySQL 8.0+ or MariaDB 10.6+ for the LRS role.

Recommended:

- a persistent object cache;
- a system cron;
- the LRS on its own subdomain (for example `lrs.example.org`; the prototype's is `lrs.sqwrlz.com` [DEC round 2]), because the did:web issuer identity is that host for ever;
- for the cmi5 player, a separate AU content hostname outside `COOKIE_DOMAIN` and an AU store outside the web root (§9.6).

The plugin must not rely on `.htaccess`, because nginx hosts and Spacefast reject it. Rewrites go through `add_rewrite_rule`.

---

## 13. Privacy and security

- **Personal data in statements.**
  - The plugin recommends `account` actors and offers a per-client setting to reject `mbox`, `mbox_sha1sum` and `openid` actors.
  - Our connector and course-xapi-mcp never send email addresses.
  - Free-text answers in statements are personal data. The connector does not send quiz answer text by default, and the course-xapi-mcp consent rules decide what the course server sends [CMI5 C4]. In the prototype the owner decided that learners' free-text answers are kept in the official record [DEC round 2], so they are covered by export and erasure like any other statement content.
- **The learner can view but never alter the official record.**
  - No learner-facing write path exists:
    - learners hold no LRS credentials;
    - agents hold none;
    - child tokens exist only for AU sessions, with no void, a single registration, read-only LaunchData, and the cmi5 guard;
    - there is no "link or import record" feature for learners.
  - Viewing happens through signed links, the connector's "My learning record" page, or the course server. Export is JSON or NDJSON.
  - Learner-supplied unofficial records (custody option 4) are never imported into the official store.
- **GDPR hooks.** Both roles register `wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers`.
  - **On the connector site** (keyed by WP user): export the user's identity links, outbox and waiver ledger, plus their statements fetched from the LRS. Erase locally, then call the LRS erase endpoint for the linked actors.
  - **On the LRS** (keyed by actor): `POST /wp-json/learning-ledger/v1/actors/erase`, scope `actors/erase`, callable by the course server and the connector for actors within their binding, and by an admin from wp-admin, or by email when actors are mbox (**Builder's call (reconciliation 10)**). It:
    - hard-deletes that actor's statements, ref rows, documents and agent rows;
    - revokes their badges (the status-list bit is flipped, so copies already shared stop verifying) and deletes the badge rows, including the hashed email and salt;
    - writes an audit row with counts only.
  - **Owner's decision** [DEC round 2]: a GDPR erase deletes the learner's official statements and revokes their badges. This is the one sanctioned deletion of official records. Statements by other actors that refer to the erased actor in `context.instructor` and similar fields are kept, with that agent replaced by a tombstone agent only in the ref index. The body stays immutable, so those references remain in `body`, which is a known limitation to document.
- **Secrets.**
  - Client secrets are stored as hashes.
  - The badge key and the connector secret follow §4.2 and §8.2.
  - The `more` cursor is HMAC-signed with a key derived from WordPress salts. Claim links and view links are random tokens stored only as hashes, with expiry (§7, §8.1).
  - Course servers authenticate to the connector with Ed25519 signatures; the plugin stores only their public keys (§4.4).
- **Input handling.**
  - JSON-only parsing with depth and size limits (1 MB per statements request by default, 500 statements per batch; 2 MB per document, §6.6).
  - Prepared statements everywhere.
  - Strict IRI and UUID validation.
  - Everything is escaped on output in wp-admin. Statement JSON is never echoed into HTML without escaping.
- **Badges never carry the xAPI actor.** Evidence is a statement UUID only [OB §4.3, §6].

---

## 14. Licence, distribution, naming

- **Licence:** GPL-2.0-or-later [DEC].
  - Bundled libraries must be GPL-compatible: MIT, BSD-3-Clause and Apache-2.0 under the "or later" route [OB §2.5].
  - The 1EdTech attribution line goes in the readme [OB §7.3].
  - The course-xapi-mcp spec stays Apache-2.0 in its own repo. The plugin implements it and does not copy spec text beyond names.
- **Repository:** `derintolu/learning-ledger` on GitHub, public from the first commit [DEC round 2].
- **Distribution:** develop on GitHub and release to the wordpress.org directory from tagged releases (a deploy action to SVN). The plugin is submitted to wordpress.org after the first release that passes the cmi5-relevant ADL conformance tests (§6.9) [DEC round 2]; until then, releases are GitHub zips. The directory guidelines that shape the code from the first commit:
  - GPL-compatible code with readable sources, so vendor code goes in with its source, prefixed;
  - no external calls unless they are part of the plugin's job and documented. The only calls are to the LRS and webhook URLs the admin configures, listed in the readme's "External services" section;
  - no tracking;
  - unique prefixes;
  - sanitising and escaping;
  - no trademark in the name. "Sensei", "LifterLMS", "Tutor LMS" and "LearnPress" appear only as integrations in the readme.
- **Name (owner's decision)** [DEC round 2]: **Learning Ledger**, slug `learning-ledger` (not taken on wordpress.org as of 2026-10-27), tagline "An xAPI LRS and Open Badges issuer for WordPress, with LMS connectors". It is neutral and not tied to course-xapi-mcp. The slug sets the REST namespaces, the block names (`learning-ledger/*`), the hooks and the WP-CLI command, so it is fixed from the first commit.

---

## 15. Decisions recorded (formerly open questions)

The owner answered these on 2026-10-27 (round 2, and round 3 for item 8) [DEC], except question 7, which the cross-design rulings settled [REC].

1. **Repository:** `derintolu/learning-ledger` on GitHub, public from the first commit (§14).
2. **Name:** "Learning Ledger", slug `learning-ledger`, approved (§14). Block names are therefore `learning-ledger/*` (§9.3).
3. **LRS host and issuer:** the prototype's LRS and badge issuer host is `lrs.sqwrlz.com`, pointed at the LRS Spacefast space, so the issuer is `did:web:lrs.sqwrlz.com` (§8.2, §12). The domain is sqwrlz.com, which replaces appsurd.org everywhere. Badges display the issuer name "Learn WordPress, reimagined".
4. **wordpress.org:** submitted after the first release that passes the cmi5-relevant ADL conformance tests (§6.9, §14).
5. **Erasure:** a GDPR erase deletes the learner's official statements and revokes their badges, as designed (§13).
6. **LMS order:** Sensei, then LifterLMS, Tutor LMS and LearnPress. LearnDash is dropped from the first wave, as is MasterStudy; the interface stays general so they can follow (§9.1, §9.4).
7. **Multi-path courses:** one Sensei course per path, grouped by a program post type into one cmi5 course, program = `<course>`, Sensei course = `<block>`, lesson = `<au>` (**Builder's call (reconciliation 1)**, §9.3, §9.5).

8. **cmi5 player:** v1 includes the cmi5 LMS side for Sensei, in the connector role, tested with CATAPULT's LMS Test Suite; this reverses reconciliation 8 [DEC round 3] (§9.6).

No open questions remain for the owner in this document. The Builder's calls stay provisional until the owner approves it (reconciliation 15).

---

## Changes in this revision

Revision 3 (owner answers, round 3):

- New §9.6: a cmi5 player for Sensei in the connector role. Import of any cmi5 package into Sensei with a `learning-ledger/cmi5-lesson` block; AU files stored outside web-executable paths and served from a separate content origin, with a CSP-sandbox fallback; launch, one-time fetch and child auth tokens; LaunchData and learner preferences; launch modes, sessions and abandonment; LMS-issued `launched`, `waived`, `abandoned` and `satisfied`; moveOn driving Sensei completion; course-xapi-mcp custody option 1 through the standard's launch bridge. This reverses reconciliation 8 [DEC round 3].
- Goals gain the player (goal 3) and the non-goal "not a cmi5 LMS" is replaced by "no player for other LMSs yet"; shape diagram, connector role, one-writer rule, `tokens/mint` (revocation, per-token CORS), connector scopes, `lledger_tokens`, WP-CLI setup, §6.9 (CATAPULT LTS as a v1 gate), §7, §12 hosting risks and recommendations, and §15 item 8 updated to match.

Revision 2:

- Owner answers (round 2) recorded: name and slug approved; repo `derintolu/learning-ledger`, public from the first commit; LRS and issuer host `lrs.sqwrlz.com`; issuer name "Learn WordPress, reimagined"; wordpress.org after the first cmi5-conformant release; GDPR erase deletes statements and revokes badges; LMS order Sensei, LifterLMS, Tutor LMS, LearnPress, with LearnDash out of the first wave. The open questions became §15, "Decisions recorded".
- Program post type grouping path courses into one cmi5 course; course satisfied maps to the program, block satisfied to Sensei course completion (REC 1).
- New §9.5: the standard's ZIP package as the only interchange format, with exporter and importer (full and public flavours, version, ETag, idempotent by stable key, WP-CLI and REST) (REC 2).
- One writer per course; direction 2 is a per-course setting, off for course-server-driven courses; connector `statements/write` only where it is on; echo guard stated (REC 3).
- Course import into the LRS takes the package, on publish and on the content-changed ping, with badge declarations from `course.json` (REC 4).
- Claim links minted per request, bound to actor and badge, single-use, 15-minute TTL, scope `badges/claim-links`; agents get the claim-page URL, never a bearer link (REC 5).
- Badge rules: all `awardOn` values including `any-path`, `countWaived`, the `carried-over` extension that never counts, tested-out count in the narrative (REC 6).
- The standard's IRI scheme (`{au@id}/au`), and Sensei completion only when moveOn is met, computed from the imported tree (REC 7).
- The plugin is not the option-1 LMS in v1 (REC 8). Superseded in revision 3 by the owner's round-3 answer (§9.6).
- New §4.4: course-server routes (learners, enrolment, progress, delete, packages, status) with Ed25519 signed requests and rate-limited, capability-gated anonymous user creation (REC 9).
- Credentials: the connector generates its own secret and exposes only its hash; no `wp config set` of secrets; new scopes `actors/erase` and `records/view-links` (REC 10).
- Latency: 90 s p95 to Sensei, with shutdown-time processing on both sides and cron as catch-up (REC 11).
- State document limit 2 MB; blocks renamed to `learning-ledger/beat`, `/checkpoint` (checks and branches inside), `/rubric`, `/planted-bug`; the separate branch and check blocks dropped (REC 12).
- Builder's calls from the rulings are labelled "Builder's call (reconciliation N)" and marked provisional (REC 15).
