# Learning Ledger Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build Learning Ledger, a GPL WordPress plugin with two roles: a conformant xAPI 1.0.3 + 2.0 LRS that issues signed Open Badges 3.0 credentials, and an LMS connector (Sensei first, then LifterLMS, Tutor LMS, LearnPress) with a course-package exporter/importer, signed course-server routes and a cmi5 player.

**Architecture:** One plugin, a module manifest, and two independently switchable roles. Records live in custom InnoDB tables (`{$wpdb->prefix}lledger_*`), never posts. The request path only validates and inserts; everything else (webhooks, badge evaluation, signing, connector apply) runs from an own jobs/outbox queue at `shutdown` after the response, with WP-Cron every minute as catch-up. The connector talks to any LRS (including a local one) over HTTP only; LMS specifics live behind one `LMS_Adapter` interface in `integrations/<lms>/`, loaded only when that LMS is active.

**Tech Stack:** PHP 8.2+, WordPress 6.6+, MySQL 8.0+ / MariaDB 10.6+, Composer (Strauss-prefixed vendor code), `ext-sodium` with `paragonie/sodium_compat`, `accredifysg/php-json-ld` + `accredifysg/php-rdf-canonicalize`, `opis/json-schema`, `firebase/php-jwt` (fallback only), PHPUnit 9.6 + WordPress test suite in `@wordpress/env`, WPCS 3, `@wordpress/scripts` for blocks, Node (CI only) for the ADL suite, CATAPULT and the badge differential test, k6 for load tests.

**Spec:** `docs/superpowers/specs/2026-10-27-wordpress-lrs-plugin-design.md` (revision 3). Binding inputs beside it: `designs/decisions.md` and `designs/reconciliation.md` in the session scratchpad (`/private/tmp/claude-501/-Users-cedarstone-Projects-learn-wp-reimagined/5376001d-2862-4ef6-85e3-26e5bd4cbe50/scratchpad/`). Interfaces must match `/Users/cedarstone/Projects/learning-mcp/docs/superpowers/specs/2026-10-27-course-xapi-mcp-design.md` (the standard) and `/Users/cedarstone/Projects/learn-wp-reimagined/docs/superpowers/specs/2026-10-27-sensei-backend-prototype-design.md` (the prototype). Section references below (§n) are to the plugin spec unless prefixed.

## Global Constraints

- Plugin header: `Plugin Name: Learning Ledger`, slug and text domain `learning-ledger`, `License: GPL-2.0-or-later`, `Requires PHP: 8.2`, `Requires at least: 6.6`, `Tested up to: 7.1`, description "An xAPI LRS and Open Badges issuer for WordPress, with LMS connectors".
- LRS role requires MySQL 8.0+ or MariaDB 10.6+; enabling it on anything older fails with a clear error. The connector role has no database floor beyond WordPress's.
- Prefixes: tables `{$wpdb->prefix}lledger_*`, options and meta `lledger_*` / `_lledger_*`, PHP namespace `LearningLedger\`, hooks `learning_ledger/*`, constants `LLEDGER_*`, capabilities `lledger_*`, WP-CLI `wp learning-ledger …`, blocks `learning-ledger/*`.
- REST: admin and connector routes in `learning-ledger/v1`; xAPI in `learning-ledger/xapi`, aliased by rewrite at `/xapi/`. Rewrites only via `add_rewrite_rule`; never `.htaccess`.
- xAPI 1.0.3 and 2.0.0 on the same endpoint, chosen by `X-Experience-API-Version`; every response carries it.
- Records never go in posts or postmeta. Every SQL statement with input uses `$wpdb->prepare()`. Every admin form and AJAX/REST write checks a capability and a nonce (cookie auth) or the route's own auth. Every output in wp-admin is escaped; statement JSON is never echoed into HTML unescaped.
- No HTTP call and nothing but at most one INSERT during a learner's page view or LMS action. Deferred work runs at `shutdown` after `fastcgi_finish_request()` where available, with WP-Cron every minute as catch-up.
- Secrets never cross WP-CLI or appear in output: only SHA-256 hashes and public keys do. No `wp config set` of secrets.
- Fixed values: statements request 1 MB and 500 statements per batch; documents 2 MB; GET `limit` default 100, max 500; consistent-through `now − 1 s`; log inserts slower than 500 ms; client rate 20 req/s sustained, burst 100; failed auth 10/min per IP then 429 + `Retry-After`; claim and view links single-use where stated, 15-minute TTL; child tokens default TTL 8 h, no void; cmi5 fetch URL 5 minutes, one use; signed requests 5-minute window, nonces kept 10 minutes; anonymous learners 30/min and 2,000/day per key; webhook batch 100, timeout 5 s, retries 1, 5, 15, 60 min then every 6 h for 72 h; catch-up poll every 15 min; unmatched inbox rows kept 30 days; status lists 131,072 entries, new list at 80%; cmi5 package caps 1 GB unpacked and 20,000 files; Site Health notice when both roles are on and the LRS holds more than 1,000,000 statements.
- IRIs from the standard, verbatim: carried-over `https://course-xapi-mcp.sqwrlz.com/xapi/extensions/carried-over`, waive-recommended `https://course-xapi-mcp.sqwrlz.com/xapi/extensions/waive-recommended`, cxm XML namespace `https://course-xapi-mcp.sqwrlz.com/course/v1`; earned verb `http://specification.openbadges.org/xapi/verbs/earned` with result extension `badgeassertion`; cmi5 namespace `https://w3id.org/xapi/profiles/cmi5/v1/CourseStructure.xsd`.
- Bundled third-party PHP code is prefixed with Strauss into `vendor-prefixed/` (namespace `LearningLedger\Vendor\`, classmap prefix `LearningLedger_Vendor_`) and must be MIT, BSD-3-Clause or Apache-2.0. No LGPL (`socialweb-php/rdf` is banned). JSON-LD contexts are bundled; nothing is fetched at runtime.
- Tests run only in throwaway WordPress instances (`@wordpress/env`). The owner's Local site at `/Users/cedarstone/Local Sites/tutorlms-exploration/app/public` may be read for reference and never written.
- Browser checks use the `agent-browser` CLI only. Never Puppeteer or Playwright, never driving Chrome by hand. (CATAPULT's LTS bundles its own jest-puppeteer harness; that is the suite's internal runner, invoked unchanged by its script, and is the one exception the spec allows in §9.6.)
- Git: commit to `main`. Once `git remote get-url origin` succeeds (the owner creates `derintolu/learning-ledger`), push `origin main` immediately after every commit. Never push to `production`, never create a local `production` branch. Never run destructive git commands without committing first.
- Parallel waves: each parallel task runs in its own git worktree on branch `task-NN`; the controller rebases it onto `main`, fast-forwards `main`, and pushes immediately. A task touches only the files its **Files** block lists. Workers running integration tests set `WP_ENV_PORT=$((8800 + 2*NN))` and `WP_ENV_TESTS_PORT=$((8801 + 2*NN))` so wp-env instances don't collide.
- Documentation (`readme.txt`, `*.md`, `conformance/SUMMARY.md`) is shown to the owner as a full diff and committed only after approval. No other docs are created.
- No emojis anywhere (code, copy, commits).
- Commit messages: conventional prefix (`feat:`, `test:`, `chore:`, `ci:`), ending with the line `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- PHP code style: WordPress Coding Standards (`WordPress-Extra`) plus `PHPCompatibilityWP` for 8.2+. Classes `Upper_Snake_Case` in `class-*.php`, interfaces in `interface-*.php`, one class per file; directories are namespace segments (e.g. `LearningLedger\Lrs\Http\Xapi_Router` lives at `src/Lrs/Http/class-xapi-router.php`). Pure-PHP classes throw `LearningLedger\Util\Invalid_Input`; HTTP layers map exceptions to responses.
- Standard commands (used in every task): `composer test:unit -- --filter <Class>_Test`, `composer test:integration -- --filter <Class>_Test` (needs `npx wp-env start` and `npm run build`), `composer lint`. "PASS" means PHPUnit exits 0 and `composer lint` reports no errors for the touched files.

## Review Focus

1. **`Authorization` header stripped by the host** (PHP-FPM/CGI without `CGIPassAuth`, some nginx configs): a reasonable admin expects Basic auth to work or to get a 401 that says why. Test in Task 17: `test_basic_auth_read_from_redirect_http_authorization` and `test_missing_header_401_mentions_authorization_header`.
2. **`since` / `until` with time-zone offsets and sub-second precision** (`2026-10-27T10:00:00.123456+02:00`): a client expects UTC-correct, microsecond-exact filtering against `DATETIME(6)`. Test in Task 38: `test_since_with_offset_and_microseconds`.
3. **Two concurrent PUTs of the same `statementId`** (outbox retries racing a webhook-triggered resend): expects 204 for identical content, 409 for different, never 500 from a duplicate-key error. Test in Task 26: `test_duplicate_key_race_maps_to_204_or_409`.
4. **Documents without a registration, agent or activity** (Agent Profile, Activity Profile, State without registration): MySQL treats `NULL` as distinct in UNIQUE keys, so two PUTs would create two rows. Expects one document. Test in Task 27: `test_state_without_registration_is_single_row`.
5. **Trailing slash on the connector's actor `homePage`** (`https://site` vs `https://site/`): the actor the connector assigns must pass the LRS binding registered from its status route, and incoming statements must match back to the user. Test in Task 24: `test_home_page_is_untrailingslashed_and_round_trips`.

---

## File Structure

```
learning-ledger.php            header, constants, autoloaders, Plugin::instance()->boot()
uninstall.php                  drops tables/options only when LLEDGER_UNINSTALL_DELETE_DATA is true
readme.txt                     wordpress.org readme (External services, 1EdTech attribution)
composer.json  package.json    all runtime and dev deps declared in Task 1
phpcs.xml.dist phpunit.xml.dist phpunit-unit.xml.dist phpunit-http.xml.dist .wp-env.json
.github/workflows/             ci.yml (T1), conformance.yml (T2), nightly.yml (T31), release.yml (T69)
bin/                           conformance-lrs.sh, summarise-conformance.php, catapult-lts.sh (T2), build-zip.sh (T69), load-test.sh (T68)
src/autoload.php               WP-style PSR-4 mapping to class-*.php / interface-*.php
src/Kernel/                    Plugin, Module, Roles, Capabilities, Activation, Status_Route, Rewrites, Role_Command, Site_Health
src/Db/  src/Audit/            Schema (every table), Installer, Db_Requirements, Tables, Audit_Log
src/Util/  src/Crypto/         Uuid, Json, Timestamp, Duration, Clock, Invalid_Input / Ed25519, Secret_Box, Tokens, Multibase, Signed_Request, Notification_Signature, Hmac
src/Xapi/                      Version, Agent_Identity, Actor_Validator, Primitives, Statement_Validator, Formatter
src/Cmi5/                      Course_Structure(_Parser), Au, Block, Satisfaction_Evaluator, Au_Fact, Vocab
src/Package/                   Package, Package_Writer, Package_Validator, Secrets, Limits
src/Queue/                     Job_Queue, Job_Handler, Shutdown_Runner, Cron_Route
src/Lrs/Http|Auth|Statements|Documents|Resources|Clients|Tokens|Courses|Export|Notify|Links|Privacy|Admin
src/Badges/Canon|Signing|Issuer|Status|Pipeline|Claim|Delivery|Revocation|Jwt
src/Connector/Lms|Pairing|Identity|Ledger|Outbox|Inbox|Programs|CourseServer|Packages|Privacy
src/Blocks/  src-js/blocks/    beat, checkpoint, rubric, planted-bug, cmi5-lesson (built to build/blocks/)
src/Player/Store|Origin|Import|Launch|Session|Testing
integrations/sensei|lifterlms|tutor|learnpress/   bootstrap.php + adapter classes
resources/                     cmi5/CourseStructure.xsd, cxm/schema/1.0/*.json, jsonld/*.jsonld, w3c/ (test vectors)
tests/unit|integration|http|node|catapult|load|Support|fixtures
conformance/                   raw suite results + SUMMARY.md
```

### Module manifest (fixed in Task 3; each class is created by the task named)

`LearningLedger\Kernel\Plugin::MODULES` maps class to role (`core`, `lrs`, `connector`). `boot()` instantiates a class only if it exists and its role is enabled, then calls `register()`. Every later task creates exactly its own module class; nobody edits the manifest.

| Class | Role | Task |
| --- | --- | --- |
| `Kernel\Status_Route`, `Kernel\Site_Health` | core | 3, 55 |
| `Queue\Queue_Module` | core | 15 |
| `Lrs\Http\Xapi_Router` | lrs | 16 |
| `Lrs\Auth\Auth_Hooks` | lrs | 17 |
| `Lrs\Clients\Clients_Module` | lrs | 29 |
| `Lrs\Tokens\Tokens_Module` | lrs | 30 |
| `Lrs\Courses\Courses_Module` | lrs | 41 |
| `Lrs\Cmi5\Cmi5_Guard` | lrs | 39 |
| `Lrs\Export\Export_Module` | lrs | 42 |
| `Lrs\Notify\Notify_Module` | lrs | 43 |
| `Lrs\Privacy\Erase_Module` | lrs | 44 |
| `Lrs\Links\View_Links_Module` | lrs | 51 |
| `Lrs\Admin\Admin_Module` | lrs | 61 |
| `Lrs\Testing\Seed_Command` | lrs | 68 (inert unless `LLEDGER_TESTING`) |
| `Badges\Issuer\Issuer_Module` | lrs | 21 |
| `Badges\Status\Status_List_Module` | lrs | 35 |
| `Badges\Pipeline\Pipeline_Module` | lrs | 52 |
| `Badges\Claim\Claim_Module` | lrs | 57 |
| `Badges\Revocation\Revocation_Module` | lrs | 58 |
| `Badges\Delivery\Delivery_Module` | lrs | 62 |
| `Connector\Lms\Integration_Loader` | connector | 9 |
| `Connector\Programs\Program_Module` | connector | 12 |
| `Blocks\Blocks_Module` | connector | 13 |
| `Blocks\Lesson_Fields_Module` | connector | 14 |
| `Connector\Pairing\Pairing_Module` | connector | 22 |
| `Connector\Identity\Identity_Module` | connector | 24 |
| `Connector\Outbox\Outbox_Module` | connector | 32 |
| `Connector\Inbox\Inbox_Module` | connector | 33 |
| `Connector\Identity\Link_Code_Module` | connector | 34 |
| `Connector\Packages\Export_Module` | connector | 36 |
| `Connector\Packages\Import_Module` | connector | 37 |
| `Connector\CourseServer\Course_Server_Module` | connector | 45 |
| `Player\Origin\Content_Origin` | connector | 49 (its `register()` runs during plugin boot, before any user is determined, see Task 49) |
| `Player\Import\Player_Import_Module` | connector | 50 |
| `Player\Launch\Launch_Module` | connector | 53 |
| `Connector\Privacy\Connector_Privacy_Module` | connector | 54 |
| `Player\Session\Session_Module` | connector | 59 |
| `Player\Testing\Catapult_Routes` | connector | 64 (registers only when `LLEDGER_CATAPULT_TEST_ROUTES` is true) |

### Shared hooks (names fixed here)

| Hook | Kind | Fired by | Args |
| --- | --- | --- | --- |
| `learning_ledger/status` | filter | Status_Route (T3) | `array $payload, bool $privileged` |
| `learning_ledger/about_extension` | filter | About handler (T28) | `array $ext` (the value under key `https://github.com/derintolu/learning-ledger`) |
| `learning_ledger/statement_guards` | filter | Statement_Store (T26) | `Statement_Guard[] $guards, Principal $p` |
| `learning_ledger/statements_stored` | action, inside the insert transaction, INSERTs only | Statement_Store | `Stored_Statement[] $rows, Principal $p` |
| `learning_ledger/statements_committed` | action, after commit | Statement_Store | `Stored_Statement[] $rows` |
| `learning_ledger/statement_voided` | action, after commit | Statement_Store | `string $target_id, Stored_Statement $voiding` |
| `learning_ledger/actor_erased` | action | Actor_Eraser (T44) | `string $actor_hash, array $counts` |
| `learning_ledger/course_imported` | action | Course_Repository (T41) | `string $course_iri, int $version` |
| `learning_ledger/badge_issued` | action | Claim_Handler (T57) | `string $credential_id, array $credential, string $registration` |
| `learning_ledger/badge_revoked` | action | Revoker (T58) | `string $credential_id, string $reason` |
| `learning_ledger/jobs/<type>` | action | Job_Queue (T15) | `array $payload` |
| `learning_ledger/program_saved` | action | Program_Module (T12) | `int $program_id` |
| `learning_ledger/connector_evaluated` | action | Inbox_Processor (T33) | `int $program_id, int $user, string $registration, Satisfaction_Result $e` |
| `learning_ledger/cmi5_registration_created` | action | Launch_Controller (T53) | `int $user, int $program_id, string $registration` |
| `learning_ledger/cmi5_return` | action | Return_Controller (T53) | `string $session_id` |
| `learning_ledger/badge_sign` | filter | Claim_Handler (T57) | `array\|string $signed, array $credential, Issuer_Key $key` |
| `learning_ledger/did_verification_methods` | filter | Did_Document (T21) | `array $methods` |

---

## Waves

Interfaces in each wave are fixed by the Interfaces blocks below before the wave starts. Tasks within a wave touch disjoint files and may run in parallel. Task sections below are grouped by topic and numbered for reference; this list is the authoritative order.

- **Wave 0 (scaffold):** 1, 2
- **Wave 1 (shared foundations):** 4, 5, 6, 7, 8, 9, 11, 13, 14
- **Wave 2 (kernel and cores):** 3, 10, 12, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24
- **Wave 3 (LRS resources, LRS course import, connector outbox/inbox, Sensei read side, package builder/importer, status list, AU store):** 25, 26, 27, 28, 29, 30, 31, 32, 33, 35, 37, 40, 41
- **Wave 4 (queries, cmi5 guard, export, notifications, erasure, record linking, course-server routes, Sensei directions 1 and 2 and display, content origin, player import, badge pipeline):** 34, 38, 39, 42, 43, 44, 45, 46, 47, 48, 49, 50, 52
- **Wave 5 (package routes and ping, view links, launch, connector privacy, Site Health, certificates):** 36, 51, 53, 54, 55, 56
- **Wave 6 (claim and signing, cmi5 sessions and satisfaction, cmi5-lesson block):** 57, 59, 60
- **Wave 7 (revocation, delivery, differential verification, VC-JWT fallback, CATAPULT gate, ADL gate):** 58, 62, 63, 64, 65, 66
- **Wave 8 (admin screens, LifterLMS, Tutor LMS, LearnPress, load test):** 61, 67a, 67b, 67c, 68
- **Wave 9 (release packaging):** 69
- **Wave 10 (controller step, needs owner):** 70

Critical path: 1 → 4 → 17 → 26 → 38 → 51 → 57 → 58 → 61 → 69 → 70.

---
## Tasks 1–2: Scaffold

### Task 1: Plugin scaffold, tooling and CI

**Files:**
- Create: `src/Util/class-invalid-input.php`, `learning-ledger.php`, `uninstall.php`, `LICENSE` (GPL-2.0 text), `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `phpcs.xml.dist`, `phpunit.xml.dist`, `phpunit-unit.xml.dist`, `phpunit-http.xml.dist`, `.wp-env.json`, `src/autoload.php`, `tests/bootstrap-unit.php`, `tests/bootstrap-integration.php`, `tests/bootstrap-http.php`, `tests/unit/test-autoload.php`, `tests/integration/test-plugin-loads.php`, `.github/workflows/ci.yml`
- Modify: `.gitignore` (add `/vendor-prefixed/`, `/build/`, `/.cache/`, `/.wp-env.override.json`, `/conformance/raw/tmp/`)

**Interfaces:**
- Produces: constants `LEARNING_LEDGER_VERSION = '0.1.0'`, `LEARNING_LEDGER_FILE`, `LEARNING_LEDGER_DIR`, `LEARNING_LEDGER_URL`; `learning-ledger.php` calls `\LearningLedger\Kernel\Plugin::instance()->boot()` only `if ( class_exists( … ) )` and registers activation/deactivation callbacks `\LearningLedger\Kernel\Activation::activate|deactivate` behind the same guard, so Wave 1 tasks can load the plugin before Task 3 lands.
- Produces: `LearningLedger\Util\Invalid_Input extends \InvalidArgumentException` with `__construct( string $message, string $error_code = 'invalid', string $path = '' )`, `error_code(): string`, `path(): string` (every pure class throws it; created here so Wave 1 tasks share it).
- Produces: `LearningLedger\autoload_candidates( string $class ): string[]` in `src/autoload.php`. Mapping: `LearningLedger\X\Y\Foo_Bar` → `src/X/Y/class-foo-bar.php` then `interface-foo-bar.php` then `trait-foo-bar.php`; `LearningLedger\Integrations\<Name>\…` → `integrations/<strtolower(Name)>/…` with the same file rule; `LearningLedger\Tests\…` → `tests/Support/…`. `vendor-prefixed/autoload.php` is required when present.
- Produces composer scripts: `test:unit`, `test:integration` (`npx wp-env run tests-cli --env-cwd=wp-content/plugins/learning-ledger vendor/bin/phpunit -c phpunit.xml.dist`), `test:http` (`phpunit -c phpunit-http.xml.dist`, base URL from env `LLEDGER_TEST_BASE_URL`, default `http://localhost:8888`), `lint`, `lint:fix`, `prefix` (Strauss), `conformance:lrs` (`bin/conformance-lrs.sh`), `conformance:catapult` (`bin/catapult-lts.sh`). npm scripts: `build` and `start` (`wp-scripts` with `--webpack-src-dir=src-js` `--output-path=build`), `test:node` (`node --test tests/node`).
- Produces dependencies (declared once here so later tasks never edit `composer.json`/`package.json`): require `php >=8.2`, `paragonie/sodium_compat ^2.1`, `accredifysg/php-json-ld ^2.2`, `accredifysg/php-rdf-canonicalize ^1.0`, `opis/json-schema ^2.4`, `league/commonmark ^2.6`, `firebase/php-jwt ^7.2`; require-dev `phpunit/phpunit ^9.6`, `yoast/phpunit-polyfills ^4.0`, `wp-phpunit/wp-phpunit`, `wp-coding-standards/wpcs ^3.1`, `phpcompatibility/phpcompatibility-wp`, `dealerdirect/phpcodesniffer-composer-installer`; Strauss (`brianhenryie/strauss`) as a dev phar. npm dev: `@wordpress/env`, `@wordpress/scripts`, `jsonld`, `@digitalbazaar/vc`, `@digitalbazaar/data-integrity`, `@digitalbazaar/eddsa-rdfc-2022-cryptosuite`, `@digitalbazaar/ed25519-multikey`. Exact versions resolved and locked at install.
- Produces test layout: `phpunit-unit.xml.dist` suite `unit` = `tests/unit` (no WordPress); `phpunit.xml.dist` suite `integration` = `tests/integration` (WordPress test suite via `wp-phpunit`, bootstrap loads `sensei-lms` when present, then this plugin), excluding groups `lifterlms-contract`, `tutor-contract`, `learnpress-contract` by default (they need their LMS installed through `.wp-env.override.json`); test files are `test-*.php`.
- Produces `.wp-env.json`: `phpVersion` `8.2`, `plugins` `["."]`, tests env plugins `[".", "https://downloads.wordpress.org/plugin/sensei-lms.4.26.4.zip"]`, config `LLEDGER_TESTING: true`. Database is wp-env's MariaDB LTS (meets the 10.6 floor).

- [ ] **Step 1: Write the failing tests**

```php
// tests/unit/test-autoload.php
class Autoload_Test extends \PHPUnit\Framework\TestCase {
	public function test_maps_class_interface_and_integration_paths() {
		$c = \LearningLedger\autoload_candidates( 'LearningLedger\Lrs\Http\Xapi_Router' );
		$this->assertStringEndsWith( 'src/Lrs/Http/class-xapi-router.php', $c[0] );
		$this->assertStringEndsWith( 'src/Lrs/Http/interface-xapi-router.php', $c[1] );
		$i = \LearningLedger\autoload_candidates( 'LearningLedger\Integrations\Sensei\Sensei_Adapter' );
		$this->assertStringEndsWith( 'integrations/sensei/class-sensei-adapter.php', $i[0] );
	}
}
// tests/integration/test-plugin-loads.php
class Plugin_Loads_Test extends WP_UnitTestCase {
	public function test_plugin_constants() {
		$this->assertSame( '0.1.0', LEARNING_LEDGER_VERSION );
		$this->assertTrue( function_exists( 'LearningLedger\autoload_candidates' ) );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer install && composer test:unit -- --filter Autoload_Test`
Expected: FAIL (function `autoload_candidates` undefined).

- [ ] **Step 3: Implement the scaffold**

Header exactly as Global Constraints. `uninstall.php` exits unless `WP_UNINSTALL_PLUGIN`; it drops `lledger_*` tables and options only when `LLEDGER_UNINSTALL_DELETE_DATA` is true (default keeps records; losing an LRS by deleting a plugin would be an anti-goal). `phpcs.xml.dist`: rulesets `WordPress-Extra`, `PHPCompatibilityWP` with `testVersion 8.2-`, `WordPress.WP.I18n` text domain `learning-ledger`, `WordPress.NamingConventions.PrefixAllGlobals` prefixes `lledger,learning_ledger,LearningLedger,LLEDGER,LEARNING_LEDGER`; exclude `vendor/`, `vendor-prefixed/`, `node_modules/`, `build/`, `resources/`, `.cache/`; in `tests/` exclude the file-name and doc-comment sniffs. CI `ci.yml` on push and pull_request: job `lint` (PHP 8.2, `composer lint`); job `unit` (matrix PHP 8.2, 8.5); job `integration` (matrix WP `6.6` and `latest` via `WP_ENV_CORE`; `npm ci`, `npm run build`, `npx wp-env start`, `composer test:integration`).

- [ ] **Step 4: Run to verify pass**

Run: `composer test:unit -- --filter Autoload_Test && npm ci && npm run build && npx wp-env start && composer test:integration -- --filter Plugin_Loads_Test && composer lint`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "chore: scaffold Learning Ledger plugin, tooling and CI"
git remote get-url origin >/dev/null 2>&1 && git push origin main
```

### Task 2: Conformance harness scripts (ADL LRS suite and CATAPULT LTS)

**Files:**
- Create: `bin/conformance-lrs.sh`, `bin/catapult-lts.sh`, `bin/summarise-conformance.php`, `bin/versions.env`, `conformance/known-gaps.json`, `conformance/raw/.gitkeep`, `tests/catapult/adapter.js` (stub exporting the LTS adapter shape; filled in Task 64), `tests/unit/Bin/test-summarise-conformance.php`, `tests/fixtures/conformance/sample-raw.json`, `.github/workflows/conformance.yml`

**Interfaces:**
- Produces: `bin/conformance-lrs.sh --xapi <1.0.3|2.0.x> --endpoint <url> --key <k> --secret-file <path> [--grep <pattern>]`. Reads the secret from a file so it never appears in a process list or log. Clones `adlnet/lrs-conformance-test-suite` at the SHA in `bin/versions.env` (`LRS_SUITE_SHA`, pinned to the suite's HEAD when this task runs) into `.cache/lrs-conformance`, runs `node bin/console_runner.js -e <endpoint> -a -u <key> -p <secret> -x <ver>` (check `--help` for the password flag spelling and the JSON output option), and writes `conformance/raw/lrs-<ver>-<YYYYMMDD>.json`.
- Produces: `bin/catapult-lts.sh --site <url> --content-host <url> --key <k> --secret-file <path>`. Clones `adlnet/CATAPULT` at `CATAPULT_SHA` into `.cache/catapult`, installs the LTS CLI, links `tests/catapult/adapter.js` in as the LMS adapter and runs the suite, writing `conformance/raw/catapult-lts-<YYYYMMDD>.json`. The LTS drives its own bundled browser harness; we invoke it unchanged.
- Produces: `lledger_summarise_conformance( array $raw, array $known_gaps, string $title ): string` in `bin/summarise-conformance.php` (CLI entry only when run directly), returning a Markdown table `| Requirement | Result | Reason |` with every failing requirement id listed and a reason from `known-gaps.json` (`{"<requirement id>": "<reason>"}`) or `UNEXPLAINED`. CLI: `php bin/summarise-conformance.php conformance/raw/*.json > conformance/SUMMARY.md`.
- Produces: `.github/workflows/conformance.yml` on `workflow_dispatch` and tags `v*`: starts wp-env, `wp learning-ledger role enable lrs`, generates a secret with `openssl rand -hex 32` into a file, registers only its SHA-256 with `wp learning-ledger client create --id=conformance --secret-sha256=<hash> --scopes=all,void,state,profile,define`, runs the suite for `1.0.3` and `2.0.x`, then a CATAPULT job with `LLEDGER_CATAPULT_TEST_ROUTES` and `LLEDGER_CMI5_CONTENT_HOST=127.0.0.1:8888` written to `.wp-env.override.json`. Uploads `conformance/raw/` as an artifact. (These jobs fail until Tasks 26 to 66 land; that is expected.)

- [ ] **Step 1: Write the failing test**

```php
class Summarise_Conformance_Test extends \PHPUnit\Framework\TestCase {
	public function test_lists_failures_with_known_reason_or_unexplained() {
		require_once dirname( __DIR__, 3 ) . '/bin/summarise-conformance.php';
		$raw = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/conformance/sample-raw.json' ), true );
		$md  = lledger_summarise_conformance( $raw, [ 'XAPI-00100' => 'multipart attachments cut in v1 (§6.9)' ], 'xAPI 2.0' );
		$this->assertStringContainsString( '| XAPI-00100 | fail | multipart attachments cut in v1 (§6.9) |', $md );
		$this->assertStringContainsString( '| XAPI-00200 | fail | UNEXPLAINED |', $md );
		$this->assertStringNotContainsString( 'XAPI-00300', $md ); // passing ids are counted, not listed
	}
}
```

The fixture mirrors the suite's real JSON shape: run the suite once against any public test LRS or read its reporter source to copy the structure, then reduce it to three requirements (two failing, one passing).

- [ ] **Step 2: Run to verify failure**

Run: `composer test:unit -- --filter Summarise_Conformance_Test`
Expected: FAIL (file missing).

- [ ] **Step 3: Implement the scripts and workflow** as specified in Interfaces. Both shell scripts use `set -euo pipefail`, print `--help`, and never echo the secret.

- [ ] **Step 4: Run to verify pass**

Run: `composer test:unit -- --filter Summarise_Conformance_Test && bash -n bin/conformance-lrs.sh && bash -n bin/catapult-lts.sh && bin/conformance-lrs.sh --help`
Expected: PASS, help text printed.

- [ ] **Step 5: Commit**

```bash
git add bin conformance tests/catapult tests/unit/Bin tests/fixtures/conformance .github/workflows/conformance.yml
git commit -m "ci: wire ADL LRS conformance suite and CATAPULT LTS as scripts"
git remote get-url origin >/dev/null 2>&1 && git push origin main
```

---

## Tasks 4–14: Shared foundations

### Task 4: Database schema, installer and audit log

**Files:**
- Create: `src/Db/class-schema.php`, `src/Db/class-installer.php`, `src/Db/class-db-requirements.php`, `src/Db/class-tables.php`, `src/Audit/class-audit-log.php`, `tests/integration/Db/test-installer.php`, `tests/integration/Audit/test-audit-log.php`

**Interfaces:**
- Produces: `Tables::name( string $short ): string` (`$wpdb->prefix . 'lledger_' . $short`); `Schema::VERSION = 1`; `Schema::tables(): array<string,string>` (short name → `CREATE TABLE` for `dbDelta`, `ENGINE=InnoDB`, `$wpdb->get_charset_collate()`); `Installer::install(): void` (dbDelta all, set option `lledger_schema_version`, autoload off); `Installer::maybe_upgrade(): void` (on `plugins_loaded`, when the stored version is lower); `Db_Requirements::check(): true|\WP_Error` (MySQL ≥ 8.0 or MariaDB ≥ 10.6; the server string comes from `$wpdb->db_server_info()` passed through filter `learning_ledger/db_version_string` for tests); `Audit_Log::record( string $event, array $data, ?string $actor = null ): void` (rejects keys named `body`, `statement`, `email`).
- Produces the tables (key columns; every table has the PK shown and the named indexes; binary hashes are `BINARY(20)` SHA-1, UUIDs `BINARY(16)`, times `DATETIME(6)` UTC). Absent registration, agent or activity in a unique key is stored as zero bytes, never NULL (Review Focus 4).
  - `statements`: `seq` BIGINT UNSIGNED AUTO_INCREMENT PK, `statement_id` UNIQUE, `stored`, `timestamp`, `version` VARCHAR(8), `verb_hash`, `actor_hash`, `actor_home_hash` BINARY(20) NULL, `object_kind` VARCHAR(16), `object_hash`, `registration` NULL, `authority_client` VARCHAR(80), `is_voiding` TINYINT, `voids_id` NULL, `voided` TINYINT, `content_sha256` BINARY(32), `body` LONGTEXT. Indexes `(actor_hash,seq)`, `(actor_home_hash,seq)`, `(verb_hash,seq)`, `(object_hash,seq)`, `(registration,seq)`, `(stored)`, `(voided,seq)`, `(voids_id)`.
  - `statement_refs` PK (`ref_hash`,`ref_kind`,`seq`); `ref_kind` VARCHAR(32). `pending_voids` PK `target_id`, `voiding_seq`.
  - `activities` PK `iri_hash`, `iri` TEXT, `definition` LONGTEXT, `updated`. `agents` PK `ifi_hash`, `ifi` JSON, `person_id` BIGINT, `updated`.
  - `documents`: `id` PK, `kind` VARCHAR(20), `activity_hash`, `agent_hash`, `registration` BINARY(16) NOT NULL, `doc_id_hash`, `doc_id` TEXT, `activity_iri` TEXT, `agent_ifi` JSON, `content_type`, `content` LONGBLOB, `etag` CHAR(40), `updated`; UNIQUE (`kind`,`activity_hash`,`agent_hash`,`registration`,`doc_id_hash`).
  - `clients`: `id` VARCHAR(64) PK, `name`, `secret_sha256` BINARY(32), `scopes` JSON, `bindings` JSON, `cors_origins` JSON, `trusted_for_satisfaction` TINYINT, `rate_class` VARCHAR(32), `guard_enabled` TINYINT default 1, `reject_non_account` TINYINT, `expires_at` NULL, `created_at`, `revoked_at` NULL.
  - `tokens`: `token_key` VARCHAR(64) PK, `secret_sha256`, `parent_client`, `registration`, `actor_hash`, `actor` JSON, `activity_id` TEXT, `session_id` CHAR(36), `launch_mode` VARCHAR(10), `cors_origins` JSON, `guard` TINYINT, `expires_at`, `terminated_at` NULL, `revoked_at` NULL, `created_at`; index (`parent_client`), (`registration`).
  - `course_aus`: `au_hash` PK, `course_iri_hash` (maps each AU id to its imported course). `courses`: `id` PK, `course_iri_hash` UNIQUE, `course_iri` TEXT, `version` INT, `etag` VARCHAR(80), `xml` LONGTEXT, `tree` JSON, `badges` JSON, `badge_issuer` JSON, `custom_rules` TINYINT, `owner_client`, `updated`.
  - `subscriptions`: `id` PK, `target_url`, `filters` JSON, `status` VARCHAR(12), `failing_since` NULL, `cursor_seq` BIGINT, `created_at`. `outbox` (LRS deliveries): `id` PK, `subscription_id`, `seq`, `status` VARCHAR(12), `attempts`, `next_attempt_at`, `event_id` CHAR(36) NULL, `delivered_at` NULL; index (`status`,`next_attempt_at`).
  - `badges`: `id` CHAR(36) PK (credential uuid), `achievement_hash`, `achievement` JSON, `registration`, `actor_hash`, `course_iri_hash`, `trigger_statement_id`, `status` VARCHAR(12) (pending, issued, revoked, held), `narrative` TEXT, `waived_count` INT, `recipient_hash` VARCHAR(80) NULL, `salt` VARCHAR(40) NULL, `credential` LONGTEXT NULL, `status_list_id` NULL, `status_index` NULL, `valid_from` NULL, `issued_at` NULL, `revoked_at` NULL, `shared` TINYINT, `created_at`; UNIQUE (`achievement_hash`,`registration`); index (`actor_hash`), (`registration`).
  - `status_lists`: `id` PK, `purpose` VARCHAR(16), `size` INT, `used` INT, `bits` LONGBLOB, `credential` LONGTEXT, `updated`. `keys`: `kid` VARCHAR(64) PK, `purpose` VARCHAR(16) (badge, notify), `public_key` VARBINARY(32), `secret_sealed` TEXT NULL, `created_at`, `retired_at` NULL, `removed_at` NULL.
  - `links`: `token_hash` BINARY(32) PK, `kind` VARCHAR(12) (claim, view), `actor_hash`, `subject` VARCHAR(80), `expires_at`, `used_at` NULL, `created_by`; index (`kind`,`actor_hash`,`subject`).
  - `audit`: `id` PK, `at`, `event` VARCHAR(40), `actor` VARCHAR(80), `data` JSON. `ratelimit`: `bucket` VARCHAR(120) PK, `tokens` DOUBLE, `updated_at`.
  - `jobs`: `id` PK, `type` VARCHAR(60), `payload` JSON, `dedupe_key` VARCHAR(191) NULL UNIQUE, `run_after`, `attempts`, `locked_until` NULL, `lock_token` CHAR(32) NULL, `last_error` TEXT NULL, `created_at`, `done_at` NULL; index (`done_at`,`run_after`).
  - Connector tables (prefixed `conn_` so both roles can share one install; §6.4 and §9.3 both say `lledger_outbox`): `conn_outbox` (`id`, `statement_id` UNIQUE, `course_id`, `body`, `status`, `attempts`, `next_attempt_at`, `created_at`), `conn_inbox` (`statement_id` PK, `registration`, `actor_hash`, `verb_id` VARCHAR(255), `object_id` TEXT, `stored`, `body`, `user_id` NULL, `status` VARCHAR(12) pending/unmatched/processed/flagged, `source` VARCHAR(8), `received_at`, `processed_at` NULL; index (`status`,`received_at`), (`registration`), (`actor_hash`)), `conn_events` (`event_id` PK, `received_at`), `conn_cursor` (`name` PK, `value`), `identity_links` (`actor_hash` PK, `actor` JSON, `user_id`, `linked_at`; index `user_id`), `waivers` (PK `user_id`,`lesson_id`; `registration`, `statement_id`, `reason`, `carried_over`, `created_at`), `applied` (PK `user_id`,`object_kind`,`object_id`,`status`; `statement_id`, `score_scaled` NULL, `success` NULL, `flagged` TINYINT, `applied_at`), `registrations` (PK `user_id`,`scope_key` VARCHAR(191); `registration`, `created_at`, `ended_at` NULL), `trusted_keys` (`key_id` PK, `public_key` VARBINARY(32), `capabilities` JSON, `limits` JSON, `created_at`, `revoked_at` NULL), `nonces` (PK `key_id`,`nonce`; `seen_at`), `cmi5_packages` (`id`, `program_id`, `source_url`, `store_dir`, `status`, `cursor`, `bytes`, `files`, `error`, `created_at`), `cmi5_files` (PK `package_id`,`path_hash`; `path` TEXT, `sha256`, `mime`, `size`), `cmi5_sessions` (`session_id` CHAR(36) PK, `registration`, `user_id`, `au_id` TEXT, `au_hash`, `lesson_id`, `launch_mode`, `token_key` NULL, `fetch_hash` BINARY(32) NULL, `fetch_expires_at`, `fetch_used_at` NULL, `launched_at`, `terminated_at` NULL, `abandoned_at` NULL, `status` VARCHAR(12); index (`user_id`,`au_hash`,`status`)).

- [ ] **Step 1: Write the failing tests**

```php
class Installer_Test extends WP_UnitTestCase {
	public function test_install_creates_every_table_and_records_version() {
		\LearningLedger\Db\Installer::install();
		foreach ( array_keys( \LearningLedger\Db\Schema::tables() ) as $short ) {
			$this->assertSame( \LearningLedger\Db\Tables::name( $short ), $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', \LearningLedger\Db\Tables::name( $short ) ) ) );
		}
		$this->assertSame( 1, (int) get_option( 'lledger_schema_version' ) );
	}
	public function test_install_is_idempotent() { /* run twice; no error; column count of statements unchanged */ }
	public function test_statement_indexes_exist() { /* SHOW INDEX: actor_hash, actor_home_hash, verb_hash, object_hash, registration, stored, voided each end in seq where specified */ }
	public function test_db_requirements() {
		add_filter( 'learning_ledger/db_version_string', fn() => '5.7.44' );
		$this->assertWPError( \LearningLedger\Db\Db_Requirements::check() );
		add_filter( 'learning_ledger/db_version_string', fn() => '10.6.18-MariaDB', 20 );
		$this->assertTrue( \LearningLedger\Db\Db_Requirements::check() );
	}
}
class Audit_Log_Test extends WP_UnitTestCase {
	public function test_records_counts_and_rejects_body() {
		\LearningLedger\Audit\Audit_Log::record( 'erase', [ 'statements' => 3 ], 'client:course-server' );
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \LearningLedger\Db\Tables::name( 'audit' ) ) );
		$this->expectException( \InvalidArgumentException::class );
		\LearningLedger\Audit\Audit_Log::record( 'x', [ 'body' => '{}' ] );
	}
}
```

- [ ] **Step 2: Run to verify failure** — Run: `composer test:integration -- --filter 'Installer_Test|Audit_Log_Test'`. Expected: FAIL (classes missing).
- [ ] **Step 3: Implement** the classes above. `dbDelta` needs two spaces after `PRIMARY KEY` and one index per line; write the DDL to that format.
- [ ] **Step 4: Run to verify pass** — same command plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git add src/Db src/Audit tests/integration/Db tests/integration/Audit && git commit -m "feat: custom table schema, installer and audit log"`, then push if the remote exists.

### Task 5: Utilities (UUID, JSON, timestamps, durations, clock)

**Files:**
- Create: `src/Util/class-uuid.php`, `class-json.php`, `class-timestamp.php`, `class-duration.php`, `class-clock.php` (all in `src/Util/`), `tests/unit/Util/test-uuid.php`, `test-json.php`, `test-timestamp.php`, `test-duration.php`

**Interfaces:**
- Consumes: `Invalid_Input` (Task 1).
- `Uuid::v4(): string`, `Uuid::v5( string $ns, string $name ): string`, `Uuid::is_valid( string $s ): bool`, `Uuid::normalize( string $s ): string` (lowercase; throws), `Uuid::to_bin( string $s ): string`, `Uuid::from_bin( string $b ): string`, `Uuid::NIL_BIN` (16 zero bytes).
- `Json::decode_strict( string $body, int $max_bytes, int $max_depth = 32 ): mixed` (throws `Invalid_Input` codes `too_large`, `too_deep`, `malformed`); `Json::encode_exact( mixed $v ): string` (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION`, throws on failure); `Json::is_list( array $a ): bool`; `Json::equal( mixed $a, mixed $b ): bool` (deep, order-insensitive for objects, order-sensitive for lists).
- `Timestamp::parse( string $v, string $xapi_version ): \DateTimeImmutable` (2.0.0: RFC 3339 with offset required, `-00:00` rejected; 1.0.3: ISO 8601, missing offset treated as UTC); `Timestamp::to_db( \DateTimeImmutable ): string` (`Y-m-d H:i:s.u`, UTC); `Timestamp::from_db( string ): \DateTimeImmutable`; `Timestamp::to_xapi( \DateTimeImmutable ): string` (`Y-m-d\TH:i:s.u\Z`, UTC).
- `Duration::parse( string $iso ): float` seconds (throws); `Duration::format( float $seconds ): string` (e.g. `PT1H2M3.5S`, hundredths max).
- `Clock::now(): \DateTimeImmutable` (UTC, microseconds); `Clock::freeze( ?\DateTimeImmutable $t ): void` for tests.

- [ ] **Step 1: Write the failing tests** (unit)

```php
public function test_uuid_v5_rfc_vector() {
	$this->assertSame( '2ed6657d-e927-568b-95e1-2665a8aea6a2', Uuid::v5( '6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'www.example.com' ) );
}
public function test_uuid_bin_round_trip_and_normalize() {
	$u = 'A1B2C3D4-0000-4000-8000-00000000000F';
	$this->assertSame( strtolower( $u ), Uuid::from_bin( Uuid::to_bin( $u ) ) );
}
public function test_json_limits() {
	$this->expectExceptionCode( 0 ); // assert error_code() === 'too_deep' for 33 nested arrays; 'too_large' for max_bytes+1
}
public function test_encode_exact_keeps_slashes_and_unicode() {
	$this->assertSame( '{"a":"http://x/é","n":1.0}', Json::encode_exact( [ 'a' => 'http://x/é', 'n' => 1.0 ] ) );
}
public function test_json_equal_ignores_key_order() { $this->assertTrue( Json::equal( [ 'a' => 1, 'b' => [ 1, 2 ] ], [ 'b' => [ 1, 2 ], 'a' => 1 ] ) ); }
public function test_timestamp_rules() {
	$this->assertSame( '2026-10-27 08:00:00.123456', Timestamp::to_db( Timestamp::parse( '2026-10-27T10:00:00.123456+02:00', '2.0.0' ) ) );
	$this->expectException( Invalid_Input::class ); Timestamp::parse( '2026-10-27T10:00:00', '2.0.0' );
}
public function test_duration() { $this->assertSame( 5403.5, Duration::parse( 'PT1H30M3.5S' ) ); $this->assertSame( 'PT1H30M3.5S', Duration::format( 5403.5 ) ); }
```

- [ ] **Step 2: Run to verify failure** — `composer test:unit -- --filter 'Uuid_Test|Json_Test|Timestamp_Test|Duration_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** the signatures above. Depth check walks the decoded value (`json_decode` depth argument `max_depth + 1` then verify).
- [ ] **Step 4: Run to verify pass** — same filter plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git add src/Util tests/unit/Util && git commit -m "feat: utility classes for UUIDs, strict JSON, timestamps and durations"`; push if remote.

### Task 6: Crypto and signed messages

**Files:**
- Create in `src/Crypto/`: `class-ed25519.php`, `class-secret-box.php`, `class-tokens.php`, `class-multibase.php`, `class-signed-request.php`, `class-signature-error.php`, `interface-nonce-store.php`, `class-memory-nonce-store.php`, `class-notification-signature.php`, `class-hmac.php`; tests `tests/unit/Crypto/test-ed25519.php`, `test-secret-box.php`, `test-multibase.php`, `test-signed-request.php`, `test-notification-signature.php`

**Interfaces:**
- `Ed25519::keypair(): array{public:string,secret:string}` (raw bytes, secret 64 bytes); `Ed25519::sign( string $msg, string $secret ): string`; `Ed25519::verify( string $sig, string $msg, string $public ): bool`; `Ed25519::from_seed( string $seed32 ): array{public,secret}`; `Ed25519::backend(): string` (`native` or `compat`); `Ed25519::force_backend( ?string $b ): void` (tests). Compat calls the Strauss-prefixed `LearningLedger_Vendor_ParagonIE_Sodium_Compat` (check the actual prefixed name after `composer prefix`).
- `Secret_Box::seal( string $plain, string $context ): string` (base64 of nonce‖ciphertext); `Secret_Box::open( string $sealed, string $context ): string` (throws `\RuntimeException`). Key = `sodium_crypto_generichash( $context, $root, 32 )` where `$root` = constant `LLEDGER_ENCRYPTION_KEY` if defined, else `AUTH_KEY . SECURE_AUTH_KEY`; `Secret_Box::key_source(): string` (`constant` or `salts`) for Site Health.
- `Tokens::random( int $bytes = 32 ): string` (base64url, no padding); `Tokens::hash( string $token ): string` (raw SHA-256).
- `Multibase::base58btc_encode( string $raw ): string` (prefix `z`), `base58btc_decode`, `base64url_encode` (prefix `u`), `base64url_decode`; `Multibase::ed25519_multikey( string $public_raw ): string` (multicodec `0xed 0x01` + key, base58btc, starts `z6Mk`); `Multibase::from_ed25519_multikey( string ): string`.
- `Signed_Request::canonical( string $method, string $path_and_query, string $body, string $timestamp, string $nonce ): string` = `strtoupper(method) . "\n" . path_and_query . "\n" . hex(sha256(body)) . "\n" . timestamp . "\n" . nonce`. Timestamp is Unix seconds as a decimal string; nonce is base64url, 16 to 64 chars; signature header is standard base64 of the 64-byte detached signature. Headers: `X-Learning-Ledger-Key`, `X-Learning-Ledger-Timestamp`, `X-Learning-Ledger-Nonce`, `X-Learning-Ledger-Signature`.
- `Signed_Request::sign( string $key_id, string $secret, string $method, string $path_and_query, string $body, ?int $now = null ): array<string,string>` (headers); `Signed_Request::verify( array $headers, string $method, string $path_and_query, string $body, callable $public_key_for, Nonce_Store $nonces, int $now ): string` returns the key id or throws `Signature_Error` with `reason()` one of `missing_header`, `unknown_key`, `stale`, `replay`, `bad_signature`. Window 300 s each way; nonce TTL 600 s.
- `Nonce_Store::remember( string $key_id, string $nonce, int $ttl ): bool` (false if already seen). `Memory_Nonce_Store` for tests.
- `Notification_Signature::sign( string $body, string $secret, int $ts ): string` (base64 over `ts . "." . body`); `Notification_Signature::verify( string $sig_b64, string $ts, string $body, string $public ): bool`.
- `Hmac::sign( string $data, string $purpose ): string` (raw HMAC-SHA256 with key from `wp_salt( 'auth' ) . $purpose`, overridable by `Hmac::set_key_for_tests( ?string )`); `Hmac::verify( string $mac, string $data, string $purpose ): bool` (constant time).
- Key ids everywhere in the plugin: `Ed25519::key_id( string $public ): string` = first 16 hex chars of SHA-256 of the public key.

- [ ] **Step 1: Write the failing tests**

```php
public function test_rfc8032_vector_1_both_backends() {
	foreach ( [ 'native', 'compat' ] as $b ) {
		Ed25519::force_backend( $b );
		$kp = Ed25519::from_seed( hex2bin( '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60' ) );
		$this->assertSame( 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a', bin2hex( $kp['public'] ) );
		$this->assertSame( 'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b', bin2hex( Ed25519::sign( '', $kp['secret'] ) ) );
	}
}
public function test_base58btc_vector() { $this->assertSame( 'z2NEpo7TZRRrLZSi2U', Multibase::base58btc_encode( 'Hello World!' ) ); }
public function test_multikey_round_trip_starts_z6mk() { /* encode RFC public key, assertStringStartsWith('z6Mk'), decode equals raw */ }
public function test_canonical_string_exact() {
	$this->assertSame( "POST\n/wp-json/learning-ledger/v1/course-server/learners?x=1\n" . hash( 'sha256', '{}' ) . "\n1761552000\nabc", Signed_Request::canonical( 'post', '/wp-json/learning-ledger/v1/course-server/learners?x=1', '{}', '1761552000', 'abc' ) );
}
public function test_verify_rejects_stale_replay_and_tamper() { /* sign at t; verify at t+301 -> stale; verify twice -> replay; change body -> bad_signature; unknown id -> unknown_key */ }
public function test_secret_box_context_binding() { /* open with other context throws */ }
public function test_notification_signature_round_trip() { /* sign/verify; altered ts fails */ }
```

- [ ] **Step 2: Run to verify failure** — `composer prefix && composer test:unit -- --filter 'Ed25519_Test|Secret_Box_Test|Multibase_Test|Signed_Request_Test|Notification_Signature_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** the classes. Unit tests define `AUTH_KEY`/`SECURE_AUTH_KEY` in `tests/bootstrap-unit.php` if not defined.
- [ ] **Step 4: Run to verify pass** — same command plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git add src/Crypto tests/unit/Crypto && git commit -m "feat: Ed25519, secretbox, multibase and signed message formats"`; push if remote.

### Task 7: xAPI version negotiation, agents, primitive validation and HTTP value objects

**Files:**
- Create in `src/Xapi/`: `class-version.php`, `class-agent-identity.php`, `class-actor-validator.php`, `class-primitives.php`; in `src/Lrs/Http/`: `class-xapi-request.php`, `class-xapi-response.php`, `class-xapi-error.php` (value objects, specified in Task 16's Interfaces and created here so Tasks 16 and 17 can run in parallel); tests `tests/unit/Lrs/test-xapi-response.php`, `tests/unit/Xapi/test-version.php`, `test-agent-identity.php`, `test-actor-validator.php`, `test-primitives.php`

**Interfaces:**
- `Version::negotiate( ?string $header ): string` → `'1.0.3'` for `1.0`, `1.0.0` to `1.0.3`; `'2.0.0'` for `2.0` and `2.0.x`; throws `Invalid_Input` code `version` otherwise (including null). `Version::SUPPORTED = ['1.0.3','2.0.0']`.
- `Agent_Identity::from_array( array $agent ): self` (exactly one of `mbox`, `mbox_sha1sum`, `openid`, `account`; throws `ifi`); `ifi_key(): string` (`mbox:mailto:a@b.org`, `mbox_sha1sum:<hex lowercase>`, `openid:<iri>`, `account:<homePage>|<name>`); `hash(): string` (raw SHA-1 of `ifi_key()`); `home_page(): ?string` (account only); `to_array(): array` (IFI only, with `objectType` `Agent`); `kind(): string`. `Agent_Identity::home_hash( string $home_page ): string` (raw SHA-1 of `home:` . homePage). No case folding or slash trimming of IFIs: they are compared exactly as sent.
- `Actor_Validator::validate( mixed $actor, string $version, string $path = 'actor' ): void` (Agent or Group; identified Group: IFI plus optional `member` of Agents; anonymous Group: `member` required, Agents only; unknown properties rejected; `name` string; `objectType` if present `Agent`/`Group`).
- `Primitives::assert_iri( mixed $v, string $path ): void` (absolute IRI with scheme), `assert_uuid`, `assert_lang_map` (keys are BCP 47 tags), `assert_mbox` (`mailto:` + address), `assert_sha1_hex`, `assert_bool`, `assert_number`, `assert_extensions` (object whose keys are IRIs).

- [ ] **Step 1: Write the failing tests** — key assertions:

```php
$this->assertSame( '1.0.3', Version::negotiate( '1.0.1' ) );
$this->assertSame( '2.0.0', Version::negotiate( '2.0.0' ) );
// Version::negotiate( '0.95' ) and ( null ) throw Invalid_Input code 'version'
$a = Agent_Identity::from_array( [ 'account' => [ 'homePage' => 'https://site.example', 'name' => 'u-1' ] ] );
$this->assertSame( 'account:https://site.example|u-1', $a->ifi_key() );
$this->assertSame( 20, strlen( $a->hash() ) );
// from_array with mbox AND account throws code 'ifi'; mbox without 'mailto:' throws
// Actor_Validator: anonymous Group without member throws; Group member that is a Group throws; extra key 'foo' throws with path 'actor.foo'
```

Plus `Xapi_Response::json( 200, ['a' => 'http://x/é'] )` body is `{"a":"http://x/é"}`; `Xapi_Error` keeps status and headers.

- [ ] **Step 2: Run to verify failure** — `composer test:unit -- --filter 'Version_Test|Agent_Identity_Test|Actor_Validator_Test|Primitives_Test|Xapi_Response_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: xAPI version negotiation, agent identity and primitive validation"`; push if remote.

### Task 8: cmi5 course structure parser and satisfaction evaluator

**Files:**
- Create: `resources/cmi5/CourseStructure.xsd` (copy of the AICC Quartz XSD from the scratchpad `cmi5src/CourseStructure.xsd`, Apache-2.0, with its source URL in `resources/cmi5/SOURCE`), `src/Cmi5/class-vocab.php`, `class-course-structure-parser.php`, `class-course-structure.php`, `class-au.php`, `class-block.php`, `class-au-fact.php`, `class-waiver-policy.php`, `class-satisfaction-evaluator.php`, `class-satisfaction-result.php`; fixtures `tests/fixtures/cmi5/{simple,nested-blocks,cxm-course,invalid}.xml`; tests `tests/unit/Cmi5/test-course-structure-parser.php`, `test-satisfaction-evaluator.php`, `test-au-fact.php`

**Interfaces:**
- `Vocab` constants: cmi5 verbs `LAUNCHED`, `INITIALIZED`, `COMPLETED`, `PASSED`, `FAILED`, `ABANDONED`, `WAIVED`, `TERMINATED`, `SATISFIED` (ADL IRIs `http://adlnet.gov/expapi/verbs/…`, and `https://w3id.org/xapi/adl/verbs/{abandoned,waived,satisfied}`); `CAT_CMI5 = 'https://w3id.org/xapi/cmi5/context/categories/cmi5'`, `CAT_MOVEON = 'https://w3id.org/xapi/cmi5/context/categories/moveon'`; extensions `EXT_SESSIONID`, `EXT_MASTERYSCORE`, `EXT_LAUNCHMODE`, `EXT_LAUNCHURL`, `EXT_MOVEON`, `EXT_LAUNCHPARAMETERS`, `EXT_REASON` (`https://w3id.org/xapi/cmi5/result/extensions/reason`); `CXM_EXT_CARRIED_OVER`, `CXM_EXT_WAIVE_RECOMMENDED` (values in Global Constraints); `CXM_NS`; `EARNED = 'http://specification.openbadges.org/xapi/verbs/earned'`.
- `Course_Structure_Parser::XSD_PATH`; `Course_Structure_Parser::parse( string $xml ): Course_Structure` (DOM with `LIBXML_NONET`, no external entities, `schemaValidate` against the bundled XSD, then trims whitespace of every text node; throws `Invalid_Input` code `xsd` with libxml messages joined).
- `Course_Structure`: `course_id(): string`, `title(): array` (lang map), `children(): array<Au|Block>`, `aus(): Au[]` (document order), `blocks(): Block[]` (all, nested included), `find_au( string $id ): ?Au`, `find_block( string $id ): ?Block`, `au_by_key( string $key ): ?Au`, `xml(): string`, `to_array(): array`, `static from_array( array ): self`.
- `Au`: `id()`, `key(): ?string` (`cxm:key`), `role(): ?string` (`cxm:role`), `title()`, `description()`, `move_on(): string`, `mastery_score(): ?float`, `launch_method(): string` (`AnyWindow` default), `url(): string`, `launch_parameters(): ?string`, `entitlement_key(): ?string`, `activity_type(): ?string`, `parent_block_id(): ?string`, `lesson_src(): ?string`. `Au::activity_id( string $au_id ): string` returns `$au_id . '/au'`.
- `Block`: `id()`, `key()`, `title()`, `children(): array<Au|Block>`, `parent_block_id(): ?string`.
- `Au_Fact::from_statement( array $statement, Course_Structure $s ): ?Au_Fact` — matches the AU by `context.contextActivities.grouping[].id == au@id` or `object.id == au@id . '/au'`; returns null for non-cmi5 statements. Fields: `au_id`, `verb`, `statement_id`, `stored` (string), `success`, `completion`, `score_scaled`, `reason`, `carried_over` (true when result extensions contain `CXM_EXT_CARRIED_OVER`). `Au_Fact::for_block( Block|string ... )` is not needed: `satisfied` statements on blocks/course are informational and are not inputs.
- `Waiver_Policy` constants `ALL` (cmi5 meaning), `EXCLUDE_CARRIED_OVER` (badges with `countWaived`), `NONE` (badges without it).
- `Satisfaction_Evaluator::evaluate( Course_Structure $s, iterable $facts ): Satisfaction_Result` (facts in stored order).
- `Satisfaction_Result`: `au_satisfied( string $au_id, string $policy = Waiver_Policy::ALL ): bool`, `au_waived( string $au_id ): ?array{reason:string,carried_over:bool,statement_id:string}`, `au_satisfying_statement( string $au_id ): ?string`, `block_satisfied( string $block_id, string $policy = ALL ): bool`, `course_satisfied( string $policy = ALL ): bool`, `first_satisfied_block( string $policy = ALL ): ?string` (the block whose satisfaction completed earliest in fact order, for `any-path`), `block_satisfying_statement( string $block_id, string $policy ): ?string` (the fact that completed it), `waived_count( string $block_or_course_id, bool $tested_out_only = true ): int`, `scores(): array<string,float>`.
- moveOn rules: `Passed` needs passed; `Completed` completed; `CompletedAndPassed` both; `CompletedOrPassed` either; `NotApplicable` always satisfied; a waived AU is satisfied under `ALL`, satisfied under `EXCLUDE_CARRIED_OVER` unless carried over, never under `NONE`. A block is satisfied when every child is; the course when every top-level child is.

- [ ] **Step 1: Write the failing tests** — key assertions:

```php
$s = Course_Structure_Parser::parse( file_get_contents( FIX . '/cxm-course.xml' ) );
$this->assertSame( 'https://example.org/courses/json-basics', $s->course_id() );
$this->assertSame( [ 'placement', 'what-is-json', 'json-syntax' ], array_map( fn( $a ) => $a->key(), $s->aus() ) );
$this->assertSame( 'JSON basics', $s->title()['en'] ); // fixture title has surrounding whitespace and newlines
// invalid.xml -> Invalid_Input with error_code() 'xsd'
// Evaluator, block "json" with what-is-json (CompletedAndPassed) and json-syntax (Completed):
//  completed only on what-is-json -> au_satisfied false; + passed -> true
//  json-syntax waived "Tested Out" -> block_satisfied(ALL) true, (NONE) false, (EXCLUDE_CARRIED_OVER) true
//  json-syntax waived Administrative + carried-over ext -> (EXCLUDE_CARRIED_OVER) false
//  placement NotApplicable is satisfied with zero facts
//  first_satisfied_block across two blocks returns the one completed first in fact order
//  waived_count('…/paths/json') === 1
// Au_Fact: statement with object.id "{au}/au" matches; with grouping id == au id matches; unrelated returns null
```

- [ ] **Step 2: Run to verify failure** — `composer test:unit -- --filter 'Course_Structure_Parser_Test|Satisfaction_Evaluator_Test|Au_Fact_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: cmi5 course structure parser and moveOn satisfaction evaluator"`; push if remote.

### Task 9: LMS adapter contract, integration loader and pause guard

**Files:**
- Create in `src/Connector/Lms/`: `interface-lms-adapter.php`, `interface-event-sink.php`, `interface-certificate-provider.php`, `class-neutral-event.php`, `class-result.php`, `class-apply-outcome.php`, `class-activity-ref.php`, `class-lms-object.php`, `class-course-tree.php`, `class-lesson-node.php`, `class-course-spec.php`, `class-lesson-spec.php`, `class-progress.php`, `class-integration-descriptor.php`, `class-integration-state.php`, `class-guarded-adapter.php`, `class-integration-loader.php`; `tests/Support/class-fake-adapter.php`, `tests/Support/class-recording-sink.php`, `tests/integration/Connector/class-adapter-contract-test-case.php`, `tests/integration/Connector/test-guarded-adapter.php`, `test-integration-loader.php`, `tests/fixtures/integrations/fakelms/bootstrap.php`

**Interfaces:**
- `LMS_Adapter` exactly as spec §9.2 (methods `id`, `lms_version`, `capabilities`, `register_event_listeners( Event_Sink $sink )`, `apply( Result $r ): Apply_Outcome`, `resolve( Activity_Ref $ref ): ?Lms_Object`, `course_tree( int $course_id ): Course_Tree`, `upsert_course( Course_Spec $spec ): int`, `upsert_lesson( int $course_id, Lesson_Spec $spec ): int`, `enrol( int $user_id, int $course_id ): bool`, `progress( int $user_id, int $course_id ): Progress`, `is_waived( int $user_id, int $lesson_id ): bool`). Capability strings: `emit_events`, `apply_completion`, `apply_score`, `mark_waived`, `enrol`, `export_course`, `import_course`.
- `Certificate_Provider::certificate_pdf( int $user_id, int $course_id ): ?string` (optional interface an adapter may also implement; see Task 56).
- `Event_Sink::emit( Neutral_Event $e ): void`.
- `Neutral_Event`: constants `ENROLLED`, `COURSE_STARTED`, `LESSON_STARTED`, `LESSON_COMPLETED`, `QUIZ_SUBMITTED`, `QUIZ_GRADED`, `COURSE_COMPLETED`, `PROGRESS_RESET`, `UNENROLLED`; constructor `( string $type, string $lms, int $user_id, int $course_id, ?int $lesson_id = null, ?int $quiz_id = null, ?float $score_scaled = null, ?bool $success = null, ?\DateTimeImmutable $occurred_at = null )`, public readonly properties.
- `Result`: constants `LESSON_COMPLETED`, `LESSON_PASSED`, `LESSON_FAILED`, `LESSON_WAIVED`, `BLOCK_SATISFIED`, `COURSE_SATISFIED`; readonly `type`, `user_id`, `object` (`Lms_Object`), `registration`, `statement_id`, `score_scaled` (?float), `reason` (?string), `carried_over` (bool).
- `Apply_Outcome`: constants `APPLIED`, `SKIPPED`, `DEFERRED`, `FAILED`; `( string $status, string $message = '' )`.
- `Activity_Ref( string $iri )`; `Lms_Object( string $kind /* course|lesson|quiz|program */, int $id )`; `Course_Tree( int $course_id, string $title, ?string $stable_key, Lesson_Node[] $lessons )`; `Lesson_Node( int $id, string $title, ?string $stable_key, int $order, bool $has_pass_required_quiz )`; `Course_Spec( string $stable_key, string $title, string $content, string $status, array $meta )`; `Lesson_Spec( string $stable_key, string $title, string $content, int $order, string $status, array $meta )`; `Progress( int[] $completed_lesson_ids, int[] $tested_out_lesson_ids, bool $course_completed, ?array $certificate )`.
- `Integration_Descriptor( string $id, callable $is_available, callable $factory, string $min_version, string $tested_up_to, callable $version )`. Each `integrations/<id>/bootstrap.php` returns one.
- `Integration_State::status( string $id ): string` (`active`, `paused`); `pause( string $id, string $reason ): void`; `resume( string $id ): void`; `confirm_major( string $id, string $version ): void`; `gate( Integration_Descriptor $d ): string` (newer major than `tested_up_to` and not confirmed → `paused`; newer minor → `active` plus an admin notice). Option `lledger_integration_state`, autoload off. Admin notice on `admin_notices` for users with `lledger_manage_connector`.
- `Guarded_Adapter implements LMS_Adapter, Certificate_Provider`: wraps an adapter; any `\Throwable` pauses the integration and returns a safe value (`apply` → `DEFERRED`, `resolve` → null, `enrol` → false, `progress` → empty Progress); while paused `apply` returns `DEFERRED` without calling the inner adapter; `register_event_listeners` always delegates (events keep queueing).
- `Integration_Loader` (Module): `const IDS = ['sensei','lifterlms','tutor','learnpress']`; on `plugins_loaded` priority 20, when the connector role is on, requires `integrations/<id>/bootstrap.php` if the file exists (directories from filter `learning_ledger/integration_dirs` for tests), keeps available descriptors, wraps each adapter in `Guarded_Adapter`, calls `register_event_listeners` with the sink from filter `learning_ledger/event_sink` (Task 32 supplies the outbox sink; default a no-op). Static accessors `Integration_Loader::adapter( string $id ): ?LMS_Adapter`, `active(): LMS_Adapter[]`, `for_post( int $post_id ): ?LMS_Adapter` (first adapter whose `resolve` handles the post type, via filter `learning_ledger/adapter_post_types` mapping id to post types).
- `Fake_Adapter` (tests): in-memory courses/lessons, records `apply` calls, configurable to throw.
- `Adapter_Contract_Test_Case` (abstract, extends `WP_UnitTestCase`): abstract `make_adapter(): LMS_Adapter`, `make_course( int $lessons ): array{course:int,lessons:int[]}`; concrete tests every adapter must pass: `test_course_tree_lists_lessons_in_order`, `test_apply_lesson_completed_shows_in_progress`, `test_apply_waived_completes_and_is_waived`, `test_apply_is_idempotent`, `test_enrol_then_progress_empty`, `test_upsert_course_and_lesson_idempotent_by_stable_key`, `test_resolve_lesson_iri_round_trip` (via `Program_Iris`, stubbed when no program), `test_events_emitted_for_lesson_completion`.

- [ ] **Step 1: Write the failing tests** — `Guarded_Adapter_Test::test_throwable_pauses_and_defers` (inner throws in `apply` → outcome `DEFERRED`, `Integration_State::status('fake') === 'paused'`, second `apply` not forwarded); `test_major_version_gate` (descriptor tested_up_to `4.26`, version `5.0.0` → `paused`; after `confirm_major` → `active`; `4.27.1` → `active`); `Integration_Loader_Test::test_loads_only_available_and_only_with_connector_role` (fixture dir via filter; role off → nothing loaded).
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Guarded_Adapter_Test|Integration_Loader_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces. Roles are read with `get_option( 'lledger_roles', [] )` directly (Task 3's `Roles` wraps the same option).
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: LMS adapter contract, integration loader and pause guard"`; push if remote.

### Task 11: JSON-LD expansion and RDFC-1.0 canonicalisation with pinned contexts

**Files:**
- Create: `resources/jsonld/credentials-v2.jsonld`, `resources/jsonld/ob-v3p0-context-3.0.3.json`, `resources/jsonld/ob-v3p0-extensions.json`, `resources/jsonld/SOURCES` (URL, retrieval date, SHA-256 of each), `src/Badges/Canon/class-context-loader.php`, `src/Badges/Canon/class-canonicalizer.php`, `tests/fixtures/w3c/rdf-canon/` (the W3C rdf-canon test manifest and its `rdfc10` eval inputs/outputs, pinned commit noted in `SOURCE`), `tests/unit/Badges/test-canonicalizer.php`, `tests/unit/Badges/test-rdf-canon-suite.php`

**Interfaces:**
- `Context_Loader::load( string $url ): array` (only the three bundled URLs: `https://www.w3.org/ns/credentials/v2`, `https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json`, `https://purl.imsglobal.org/spec/ob/v3p0/extensions.json`; anything else throws `Invalid_Input` code `context_not_bundled`; never fetches).
- `Canonicalizer::canonicalize( array $document ): string` (JSON-LD → RDF dataset with the prefixed Accredify `php-json-ld` using `Context_Loader` as the document loader, safe mode on, then RDFC-1.0 N-Quads with the prefixed `php-rdf-canonicalize`); throws `Invalid_Input` code `undefined_term` when expansion would drop a term. `Canonicalizer::canonicalize_nquads( string $nquads ): string` for the W3C suite.

- [ ] **Step 1: Write the failing tests** — `Rdf_Canon_Suite_Test` with a data provider over every `rdfc10` evaluation test in the vendored manifest, asserting output equals the expected N-Quads (record the pass count; the plan expects all to pass); `Canonicalizer_Test::test_undefined_term_throws` (`{"@context":["https://www.w3.org/ns/credentials/v2"],"type":"VerifiableCredential","fooBar":1}`); `test_unbundled_context_throws`; `test_simple_credential_is_deterministic` (canonicalise the same credential with shuffled key order twice, identical output).
- [ ] **Step 2: Run to verify failure** — `composer test:unit -- --filter 'Rdf_Canon_Suite_Test|Canonicalizer_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces. If the Accredify libraries fail any W3C test, stop and report the failing ids to the controller: that is a release blocker for badges (§8.5), not something to patch around.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: pinned JSON-LD contexts and RDFC-1.0 canonicalisation tested against W3C rdf-canon"`; push if remote.

### Task 12: Program post type, stable keys and IRIs

**Files:**
- Create in `src/Connector/Programs/`: `class-program-module.php`, `class-program.php`, `class-program-repository.php`, `class-program-iris.php`, `class-program-command.php`; tests `tests/integration/Connector/Programs/test-program-repository.php`, `test-program-iris.php`

**Interfaces:**
- Post type `lledger_program` (non-public front end, `show_ui` true under a "Learning Ledger" admin menu, `show_in_rest` true, `capability_type` mapped so only `lledger_manage_connector` may edit). Meta registered with `show_in_rest`, `single`, and `auth_callback` = `current_user_can( 'edit_post', $id )`: `_lledger_stable_key` (string `[a-z0-9-]{1,64}`), `_lledger_iri_base` (absolute IRI ending `/`), `_lledger_course_iri` (default `untrailingslashit( iri_base )`), `_lledger_courses` (int[] ordered LMS course ids, one per path), `_lledger_path_keys` (map course id → path key), `_lledger_placement` (JSON `{au_key, guide}`), `_lledger_rules` (string), `_lledger_badges` (JSON array, standard's declaration shape), `_lledger_badge_issuer` (JSON `{signer, claimPage}`), `_lledger_custody` (`lms-launched|author-lrs|server-kept|practice`), `_lledger_course_server_url`, `_lledger_au_url_template` (contains `{key}`), `_lledger_waive_recommended` (bool, default true), `_lledger_structure` (JSON of `Course_Structure::to_array()`), `_lledger_version` (int), `_lledger_etag` (string), `_lledger_player` (bool, set by the cmi5 player importer). `_lledger_stable_key` is also registered for every post type in filter `learning_ledger/stable_key_post_types` (Sensei adds `course`, `lesson`).
- `Program_Repository`: `get( int $id ): ?Program`; `find_by_key( string $key ): ?int`; `for_lms_course( int $course_id ): ?int`; `for_activity( string $iri ): ?int` (longest `_lledger_iri_base` prefix match); `structure( int $id ): ?Course_Structure`; `set_structure( int $id, Course_Structure $s ): void`; `bump_version( int $id, string $etag ): int` (increments only when etag differs).
- `Program` readonly: `id`, `stable_key`, `iri_base`, `course_iri`, `course_ids`, `path_keys`, `placement`, `rules`, `badges`, `badge_issuer`, `custody`, `course_server_url`, `au_url_template`, `waive_recommended`, `version`, `etag`.
- `Program_Iris`: `block_iri( Program $p, string $path_key ): string` = `iri_base . 'paths/' . key`; `au_iri( Program $p, string $lesson_key ): string` = `iri_base . 'lessons/' . key`; `placement_iri( Program $p ): string` = `iri_base . 'placement'`; `au_activity_id( string $au_iri ): string` = `$au_iri . '/au'` (same rule as `Au::activity_id()`); `key_from_iri( Program $p, string $iri ): ?array{kind:string,key:string}` (inverse, accepting the `/au` suffix).
- WP-CLI `wp learning-ledger program update <id> [--course-server-url=] [--custody=] [--iri-base=] [--course-iri=] [--au-url-template=] [--waive-recommended=<on|off>]` and `program list`.
- Fires `learning_ledger/program_saved` on `save_post_lledger_program`.

- [ ] **Step 1: Write the failing tests** — `test_for_activity_matches_longest_base` (two programs, bases `https://a/x/` and `https://a/x/y/`; `https://a/x/y/lessons/k/au` → second); `test_prototype_iris` (base `https://learn-wp-reimagined.space.fast/activities/`, course IRI set to `…/activities/course`; `au_iri(p,'bt-what-is-a-block-theme')` = `https://learn-wp-reimagined.space.fast/activities/lessons/bt-what-is-a-block-theme`; activity id ends `/au`; `key_from_iri` round-trips); `test_meta_auth_denies_subscriber` (REST update of `_lledger_rules` as subscriber → 403); `test_bump_version_only_on_new_etag`; `test_structure_round_trip`.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Program_Repository_Test|Program_Iris_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: program post type grouping path courses into one cmi5 course"`; push if remote.

### Task 13: Blocks `learning-ledger/beat`, `/checkpoint`, `/rubric`

**Files:**
- Create: `src-js/blocks/beat/{block.json,index.js,edit.js,render.php}`, `src-js/blocks/checkpoint/{block.json,index.js,edit.js,render.php}`, `src-js/blocks/rubric/{block.json,index.js,edit.js,render.php}`, `src/Blocks/class-blocks-module.php`, `src/Blocks/class-markdown-allowlist.php`, `tests/integration/Blocks/test-blocks.php`

**Interfaces:**
- `Blocks_Module::register()` registers every directory under `build/blocks/` on `init` (so Tasks 14 and 60 add blocks without editing it).
- `Markdown_Allowlist::BLOCKS = ['core/paragraph','core/heading','core/list','core/list-item','core/code','core/preformatted','core/quote','core/image']` (used by the beat editor and the exporter, Task 25).
- `learning-ledger/beat`: attributes `id` (string, key pattern), `goal` (string), `question` (string); InnerBlocks restricted to the allowlist; the editor shows a notice inside a beat when any inner block is outside the allowlist ("This block will not be exported"). Front end: wrapper + inner content + question; `goal` is not rendered.
- `learning-ledger/checkpoint`: attributes `key`, `kind` (`free-text|code`), `question`, `file` (?string), `maxAttempts` (?int), `checks` (array of `{kind: contains|notContains|regex|json, value, pointer?}`), `branches` (array of `{key, when, success, to, message}`), `fallbackOptions` (array of `{label, outcome}`); one inner `learning-ledger/rubric` allowed. Sidebar: TextControls/SelectControls with add/remove rows for checks, branches and fallback options. Front end renders only the question.
- `learning-ledger/rubric`: `parent: ["learning-ledger/checkpoint"]`, attribute `text`; front end renders an empty string.
- Secrets never appear in `the_content` or REST `content.rendered`; raw attributes stay in `post_content` (visible only with `context=edit` to users who can edit the post).

- [ ] **Step 1: Write the failing tests**

```php
public function test_registered() { foreach ( [ 'beat', 'checkpoint', 'rubric' ] as $b ) { $this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( "learning-ledger/$b" ) ); } }
public function test_rendered_content_hides_secrets() {
	$post = self::factory()->post->create( [ 'post_content' => file_get_contents( FIX . '/blocks/checkpoint-with-secrets.html' ) ] );
	$res  = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/wp/v2/posts/$post" ) );
	$html = $res->get_data()['content']['rendered'];
	$this->assertStringContainsString( 'What does this print?', $html );
	foreach ( [ 'RUBRIC-SECRET', 'regex-secret', 'BRANCH-MESSAGE-SECRET' ] as $s ) { $this->assertStringNotContainsString( $s, $html ); }
}
public function test_edit_context_needs_edit_post() { /* subscriber GET ?context=edit -> 401/403 */ }
public function test_beat_hides_goal_shows_question() { /* ... */ }
```

- [ ] **Step 2: Run to verify failure** — `npm run build && composer test:integration -- --filter Blocks_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** blocks and module.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`; then a visual check in the wp-env editor with `agent-browser` (open a post, insert a beat with a paragraph and a checkpoint with a rubric, screenshot the sidebar). Expected: PASS and both blocks editable.
- [ ] **Step 5: Commit** — `git commit -m "feat: beat, checkpoint and rubric blocks that keep secrets out of rendered content"`; push if remote.

### Task 14: Block `learning-ledger/planted-bug` and lesson/course fields

**Files:**
- Create: `src-js/blocks/planted-bug/{block.json,index.js,edit.js,render.php}`, `src-js/fields/{index.js,lesson-panel.js,course-panel.js}`, `src/Blocks/class-lesson-fields-module.php`, `tests/integration/Blocks/test-planted-bug.php`, `tests/integration/Blocks/test-lesson-fields.php`

**Interfaces:**
- `learning-ledger/planted-bug`: attributes `key`, `file`, `code`, `hint`, `revealAfter` (?int), `explanation`, `fixChecks` (array of check objects as in checkpoint). Front end renders file name and code only.
- `Lesson_Fields_Module`: lesson meta (post types from filter `learning_ledger/lesson_post_types`, default `[]`): `_lledger_starter_files` (JSON `[{path, content}]`), `_lledger_sources` (JSON `[{title, url}]`), `_lledger_minutes` (int), `_lledger_licence` (string). Course (path) meta (filter `learning_ledger/course_post_types`): `_lledger_preview` (JSON `{kind, files:[{path,content}]}`), `_lledger_final_files` (JSON), `_lledger_landing` (JSON object, opaque). All `show_in_rest` with `auth_callback` edit_post and a `sanitize_callback` that rejects non-JSON or wrong shapes (`Json::decode_strict`, 1 MB). An editor sidebar panel (`src-js/fields`) edits them.

- [ ] **Step 1: Write the failing tests** — planted-bug render contains code, not `explanation` or a fixCheck value; meta registered for a post type added via the filter; subscriber cannot update; malformed JSON for `_lledger_starter_files` is rejected (REST 400).
- [ ] **Step 2: Run to verify failure** — `npm run build && composer test:integration -- --filter 'Planted_Bug_Test|Lesson_Fields_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`; agent-browser screenshot of the lesson panel. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: planted-bug block and lesson/course extension fields"`; push if remote.

---
## Tasks 3, 10, 15–24: Kernel and cores

### Task 3: Kernel (plugin, modules, roles, capabilities, status route)

**Files:**
- Create in `src/Kernel/`: `class-plugin.php`, `interface-module.php`, `class-roles.php`, `class-capabilities.php`, `class-activation.php`, `class-status-route.php`, `class-rewrites.php`, `class-role-command.php`; tests `tests/integration/Kernel/test-plugin.php`, `test-roles.php`, `test-capabilities.php`, `test-status-route.php`

**Interfaces:**
- Consumes: `Installer::install()`, `Db_Requirements::check()` (Task 4); `Ed25519::backend()` (Task 6).
- `Module::register(): void`. `Plugin::instance(): self`; `Plugin::boot(): void` (on load: `Installer::maybe_upgrade` hook, then for each entry of `apply_filters( 'learning_ledger/modules', self::MODULES )` whose class exists and whose role is `core` or enabled, `new $class` and `register()`); `Plugin::MODULES` exactly the manifest table in this plan.
- `Roles::ROLES = ['lrs','connector']`, `Roles::OPTION = 'lledger_roles'`; `is_enabled( string $role ): bool`; `enable( string $role ): true|\WP_Error` (`lrs` runs `Db_Requirements::check()` then `Installer::install()`; `connector` runs `Installer::install()`; both schedule a rewrite flush); `disable( string $role ): void`; unknown role → `WP_Error` `unknown_role`.
- `Capabilities`: `MANAGE = 'lledger_manage'`, `VIEW_RECORDS = 'lledger_view_records'`, `MANAGE_BADGES = 'lledger_manage_badges'`, `EXPORT = 'lledger_export'`, `MANAGE_CONNECTOR = 'lledger_manage_connector'`; `install(): void` (administrator only); `remove(): void`.
- `Activation::activate(): void` (`Capabilities::install()`, `Installer::install()`, `Rewrites::schedule_flush()`), `deactivate(): void` (clear cron events `lledger_*`, flush rewrites).
- `Rewrites::schedule_flush(): void` (option `lledger_flush_rewrites`; flushed once at `init` priority 99).
- `Status_Route` (Module): `GET /learning-ledger/v1/status`, public. `Status_Route::payload( bool $privileged ): array` = `{ plugin_version, wp_version, php_version, roles: string[], sodium: native|compat, schema_version }` passed through `learning_ledger/status`. `$privileged` is `current_user_can( Capabilities::MANAGE )` for this route (cookie+nonce or Application Password); Task 45 calls `payload( true )` for signed course-server requests.
- WP-CLI `wp learning-ledger role enable <lrs|connector>` / `role disable <role>` (exit status only; `WP_CLI::error` on `WP_Error`).

- [ ] **Step 1: Write the failing tests** — `test_boot_registers_only_enabled_role_modules` (filter `learning_ledger/modules` adds a test `lrs` module that records `register()` calls; off → not called; `Roles::enable('lrs')` then a fresh boot → called); `test_enable_lrs_refuses_old_db` (`learning_ledger/db_version_string` → `5.7.44`; `Roles::enable('lrs')` is `WP_Error`, option unchanged); `test_unknown_role`; `test_admin_has_five_caps_subscriber_none`; `test_status_public_payload` (has `plugin_version`, `roles`, `sodium` ∈ {native, compat}; no key containing `secret`); `test_status_privileged_and_filter` (filter adds `x` only when privileged; admin with nonce sees it).
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Plugin_Test|Roles_Test|Capabilities_Test|Status_Route_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: kernel with module manifest, roles, capabilities and status route"`; push if remote.

### Task 10: Course package model, writer and validator

**Files:**
- Create in `src/Package/`: `class-limits.php`, `class-package.php`, `class-package-writer.php`, `class-package-validator.php`, `class-validation-result.php`, `class-secrets.php`; `resources/cxm/schema/1.0/course.schema.json`, `resources/cxm/schema/1.0/lesson.schema.json`, `resources/cxm/SOURCE`; fixtures `tests/fixtures/packages/` (`good-full/`, `good-public/`, `bad-xsd/`, `bad-schema/`, `traversal.zip` builder, `bomb.zip` builder); tests `tests/unit/Package/test-package.php`, `test-package-writer.php`, `test-package-validator.php`, `test-secrets.php`

**Interfaces:**
- Consumes: `Course_Structure_Parser::parse()` (Task 8), `Json::decode_strict()` (Task 5).
- `Limits( int $max_bytes = 1073741824, int $max_files = 20000, int $max_ratio = 100 )`.
- `Package::from_zip( string $path, Limits $l ): Package` (ZipArchive; rejects absolute paths, `..`, backslashes, NUL, symlink entries (external attributes `0xA000`), too many files, total size over cap, ratio over cap; throws `Invalid_Input` codes `zip_path`, `zip_symlink`, `zip_too_large`, `zip_too_many`, `zip_ratio`, `no_cmi5_xml`); `Package::from_xml( string $xml ): Package`; `cmi5_xml(): string`; `is_cxm(): bool` (`cxm/course.json` present); `course_json(): ?array`; `lesson_json( string $key ): ?array`; `lesson_keys(): string[]`; `files(): string[]` (paths under `cxm/files/`); `file( string $path ): string`; `entries(): string[]` (all paths, for the player's AU store).
- `Package_Writer::write( string $cmi5_xml, array $course_json, array $lessons /* key => array */, array $files /* path => bytes */ ): string` — ZIP bytes, entries sorted, fixed mtime `1980-01-01 00:00:00`, JSON via `Json::encode_exact` with pretty print off, so the same input gives the same bytes. `Package_Writer::etag( string $zip ): string` = `'"' . hash( 'sha256', $zip ) . '"'`.
- `Secrets::FIELDS` = lesson JSON pointers `/checkpoint/rubric`, `/checkpoint/checks`, `/bug/explanation`, `/bug/fixChecks`, `/referenceSolution`; `Secrets::strip( array $lesson ): array`; `Secrets::has_secrets( array $lesson ): bool`.
- `Package_Validator::validate( Package $p, string $flavour /* full|public */ ): Validation_Result`; checks XSD (via the parser), `cxm/course.json` and each `cxm/lessons/<key>.json` against the bundled JSON Schemas with `opis/json-schema`, every `cxm:lesson src` resolves, `cxm:key` unique and `[a-z0-9-]{1,64}`, branch `to` targets exist and are in the same block, and a `public` package carries no `Secrets::FIELDS`. `Validation_Result`: `ok(): bool`, `errors(): array<array{code:string,path:string,message:string}>` with codes `xsd`, `schema`, `missing_lesson_json`, `duplicate_key`, `bad_key`, `branch_target_unknown`, `branch_cross_path`, `secret_in_public`.
- The bundled schemas are provisional, derived from the standard's design §6.1–6.2 and §11 (`resources/cxm/SOURCE` says so and names the standard design file); they are replaced by the standard's published `/schema/1.0/*.schema.json` when those exist, without code changes.

- [ ] **Step 1: Write the failing tests** — round trip: write a package from `good-full/` inputs, read it back, `lesson_json('what-is-json')` equal; `etag` stable across two writes; validator: `good-full` ok as full, fails as public with `secret_in_public`; `bad-xsd` → `xsd`; `bad-schema` → `schema` with path `/lessons/what-is-json/checkpoint/kind`; traversal zip → `zip_path`; ratio bomb → `zip_ratio`; `Secrets::strip` removes all five pointers and nothing else.
- [ ] **Step 2: Run to verify failure** — `composer test:unit -- --filter 'Package_Test|Package_Writer_Test|Package_Validator_Test|Secrets_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: course package model, deterministic writer and validator"`; push if remote.

### Task 15: Job queue, shutdown runner and cron trigger

**Files:**
- Create in `src/Queue/`: `class-queue-module.php`, `class-job-queue.php`, `class-shutdown-runner.php`, `class-cron-route.php`, `class-cron-command.php`; tests `tests/integration/Queue/test-job-queue.php`, `test-shutdown-runner.php`, `test-cron-route.php`

**Interfaces:**
- `Job_Queue::push( string $type, array $payload, ?string $dedupe_key = null, int $delay = 0 ): void` (one INSERT; duplicate `dedupe_key` among undone jobs is a no-op); `Job_Queue::run( int $max_jobs = 50, int $max_seconds = 20 ): int` (claims with `UPDATE … SET lock_token = %s, locked_until = NOW()+60s WHERE id IN (… due and unlocked …)`, then `do_action( "learning_ledger/jobs/{$type}", $payload )`; a throwing handler sets `last_error`, `attempts++`, `run_after` = backoff 1, 5, 15, 60 min then 6 h; give up after 72 h with an audit row); `Job_Queue::due_count(): int`; `Job_Queue::last_run(): ?string` (option `lledger_queue_last_run`).
- `Shutdown_Runner::request(): void` (sets a flag; on `shutdown`, calls `fastcgi_finish_request()` if it exists, then `Job_Queue::run()` plus any callbacks added with `Shutdown_Runner::add( callable $cb )`); records option `lledger_shutdown_last_run` and whether `fastcgi_finish_request` existed.
- `Queue_Module`: adds cron schedule `lledger_minute` (60 s) and event `lledger_cron_minute` → `Job_Queue::run()` and `do_action( 'learning_ledger/cron_minute' )`; `learning_ledger/cron_quarter_hour` fired every 15 minutes from the same event.
- `Cron_Route`: `GET|POST /learning-ledger/v1/cron` runs the queue when header `X-Learning-Ledger-Cron` or query `key` hashes (SHA-256) to option `lledger_cron_secret_sha256`; otherwise 403. WP-CLI `wp learning-ledger cron secret --sha256=<hex>`.

- [ ] **Step 1: Write the failing tests** — `test_push_dedupes_open_jobs`; `test_run_dispatches_and_marks_done`; `test_failure_backs_off_and_records_error` (handler throws; `run_after` ≈ +60 s; attempts 1); `test_two_runners_do_not_double_claim` (simulate a second claim between select and update: assert each job dispatched once); `test_shutdown_runner_runs_queue_and_records_time`; `test_cron_route_requires_secret_hash`.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Job_Queue_Test|Shutdown_Runner_Test|Cron_Route_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: jobs queue processed at shutdown with cron catch-up"`; push if remote.

### Task 16: xAPI HTTP router

**Files:**
- Create in `src/Lrs/Http/`: `class-xapi-router.php`, `interface-resource-handler.php`, `class-alternate-syntax.php`, `class-cors.php`; `tests/Support/class-xapi-test-client.php`; tests `tests/integration/Lrs/Http/test-xapi-router.php`, `test-alternate-syntax.php`, `test-cors.php`, `tests/http/test-xapi-reachability.php`

**Interfaces:**
- Consumes: `Version::negotiate()` and the value objects `Xapi_Request`, `Xapi_Response`, `Xapi_Error` (Task 7; their API is specified below). `Authenticator` (Task 17) is called as `( new \LearningLedger\Lrs\Auth\Authenticator() )->authenticate( $request, $_SERVER )` when the class exists; otherwise every non-`about` request is 401.
- `Resource_Handler::handle( Xapi_Request $r ): Xapi_Response`.
- `Xapi_Router::HANDLERS` (path → method → class), overridable via filter `learning_ledger/xapi_handlers`: `statements` → `GET` `Lrs\Statements\Statements_Read_Handler` (Task 38), `PUT|POST` `Lrs\Statements\Statements_Write_Handler` (Task 26); `activities/state` → `Lrs\Documents\State_Handler`; `activities/profile` → `Lrs\Documents\Activity_Profile_Handler`; `agents/profile` → `Lrs\Documents\Agent_Profile_Handler` (Task 27); `activities` → `Lrs\Resources\Activities_Handler`; `agents` → `Lrs\Resources\Agents_Handler`; `about` → `Lrs\Resources\About_Handler` (Task 28). A missing class or method → 405/501.
- Routes: `register_rest_route( 'learning-ledger/xapi', '/(?P<resource>…)', [ 'methods' => 'GET, PUT, POST, DELETE', 'permission_callback' => '__return_true' ] )` (auth happens inside, because xAPI errors must be xAPI-shaped); rewrite `^xapi/?(.*)$` → `index.php?rest_route=/learning-ledger/xapi/$matches[1]`.
- `Xapi_Router::is_xapi_request( array $server ): bool` (URI under the REST prefix `learning-ledger/xapi`, under `/xapi/`, or `rest_route` query starting `/learning-ledger/xapi`); filter `application_password_is_api_request` returns false for those requests so core's Application Password check never turns a Basic header into a 401.
- `Xapi_Request::from_rest( \WP_REST_Request $r ): self`; accessors `method()`, `resource()`, `params()`, `param( string $k )`, `header( string $k ): ?string` (case-insensitive), `body(): string`, `version(): string`, `origin(): ?string`, `ip(): string`, `principal(): ?Principal`, `with_principal( Principal $p ): self`.
- `Xapi_Response( int $status, string $body = '', string $content_type = 'application/json', array $headers = [] )`; `Xapi_Response::json( int $status, mixed $data, array $headers = [] ): self` (via `Json::encode_exact`); `Xapi_Error( int $status, string $message, array $headers = [] )` → body `{"error":true,"message":"…"}`.
- Serving: callbacks return a `WP_REST_Response` whose data is the `Xapi_Response`; filter `rest_pre_serve_request` writes status, headers and the raw body for those and returns true. Every response carries `X-Experience-API-Version` (the negotiated one; `2.0.0` for `about`). Missing or bad version header on any resource except `about` → 400.
- `Alternate_Syntax::apply( \WP_REST_Request $r, string $version ): \WP_REST_Request` — only for `1.0.3`, `POST` with query `method` ∈ GET/PUT/POST/DELETE; reads form fields `Authorization`, `X-Experience-API-Version`, `Content-Type`, `Content-Length`, `If-Match`, `If-None-Match` as headers, `content` as the body, the rest as params; any other query parameter alongside `method` → 400; under `2.0.0` → 400.
- `Cors::preflight( string $origin, array $allowed ): Xapi_Response` (204 with `Access-Control-Allow-Origin: <origin>`, `Vary: Origin`, `Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS`, `Access-Control-Allow-Headers: Authorization, Content-Type, X-Experience-API-Version, If-Match, If-None-Match`, `Access-Control-Max-Age: 600`); `Cors::decorate( Xapi_Response $res, ?string $origin, array $allowed ): Xapi_Response` adds `Access-Control-Allow-Origin` and `Access-Control-Expose-Headers: ETag, Last-Modified, X-Experience-API-Version, X-Experience-API-Consistent-Through`. Preflight `allowed` = `Origin_Index::all()` (Task 17) when it exists (preflights carry no credentials); actual responses use the principal's own origins. `null` is matched literally. Never `*`.
- Request size: body over 2 MB → 413 before any handler.
- `Xapi_Test_Client::request( string $method, string $resource, array $query = [], ?string $body = null, array $headers = [] ): Xapi_Response` dispatches through `rest_get_server()` with `X-Experience-API-Version: 2.0.0` unless given; `Xapi_Test_Client::as( string $key, string $secret ): self` adds Basic auth.

- [ ] **Step 1: Write the failing tests** — `test_dispatches_to_handler_and_sets_version_header` (fake handler via filter); `test_missing_version_header_400_except_about`; `test_raw_body_not_reencoded` (handler returns `{"a":"http://x/y"}`; served body identical, no `_links`); `test_body_over_2mb_413`; `test_alternate_syntax_get_via_post_under_103`; `test_alternate_syntax_rejected_under_20`; `test_preflight_allowed_origin_only` (unknown origin → no ACAO header); `test_application_password_filter_false_for_xapi_paths`; HTTP test (`composer test:http`, wp-env dev site): `GET /xapi/about` and `/wp-json/learning-ledger/xapi/about` with `Authorization: Basic Zm9vOmJhcg==` both answer from our router (no core `invalid_username` 401).
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Xapi_Router_Test|Alternate_Syntax_Test|Cors_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same, `composer test:http -- --filter Xapi_Reachability_Test`, `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: xAPI router with raw responses, version header, alternate syntax and CORS"`; push if remote.

### Task 17: LRS authentication, scopes, bindings and rate limits

**Files:**
- Create in `src/Lrs/Auth/`: `class-scopes.php`, `class-client.php`, `class-client-spec.php`, `class-client-repository.php`, `class-child-token.php`, `class-token-spec.php`, `class-token-repository.php`, `class-bindings.php`, `class-principal.php`, `class-authenticator.php`, `class-rate-limiter.php`, `class-auth-fail-limiter.php`, `class-origin-index.php`, `class-client-route-auth.php`, `class-auth-hooks.php`; `tests/Support/class-client-factory.php`; tests `tests/integration/Lrs/Auth/test-authenticator.php`, `test-scopes.php`, `test-bindings.php`, `test-token-repository.php`, `test-rate-limiter.php`

**Interfaces:**
- Consumes: `Xapi_Request`, `Xapi_Error` (Task 16), `Agent_Identity` (Task 7), `Tokens`, `Uuid` (Tasks 5, 6).
- `Scopes`: xAPI `STATEMENTS_WRITE='statements/write'`, `STATEMENTS_READ='statements/read'`, `STATEMENTS_READ_MINE='statements/read/mine'`, `STATE='state'`, `PROFILE='profile'`, `DEFINE='define'`, `ALL_READ='all/read'`, `ALL='all'`; ours `VOID`, `COURSES_WRITE`, `BADGES_ISSUE`, `BADGES_CLAIM_LINKS`, `RECORDS_VIEW_LINKS`, `ACTORS_ERASE`, `TOKENS_MINT`. `Scopes::implies( string $held, string $wanted ): bool`: `all` implies every xAPI scope (not ours); `all/read` implies `statements/read`, `statements/read/mine` and read access to state and profiles; `statements/read` implies `statements/read/mine`. `Scopes::validate( string[] ): void` throws on unknown names.
- `Client_Repository`: `find( string $id ): ?Client`; `create( Client_Spec $s ): void`; `update( string $id, array $changes ): void`; `revoke( string $id ): void`; `all(): Client[]`; results cached in the object cache for 60 s and flushed on change.
- `Client_Spec( string $id, string $name, string $secret_sha256_raw, array $scopes, array $bindings = [], array $cors_origins = [], bool $trusted = false, string $rate_class = 'default', bool $guard_enabled = true, bool $reject_non_account = false, ?\DateTimeImmutable $expires_at = null )`.
- Bindings JSON keys: `actor_ifi` (an `ifi_key`), `actor_home_page`, `registration`, `activity_prefixes` (applies to every write), `write_activity_prefixes` (applies to `statements/write` only). `Bindings::allows_statement( array $st ): bool` (actor binding against the statement actor, registration against `context.registration`, prefixes against an Activity `object.id`; a voiding statement is checked through its target in Task 26, not here); `Bindings::allows_actor( Agent_Identity $a ): bool`; `Bindings::allows_document( string $activity_iri, ?Agent_Identity $a, ?string $registration ): bool`; `Bindings::read_filter(): array{actor_hash?:string,actor_home_hash?:string,registration?:string}` (activity prefixes do not filter reads).
- `Token_Spec( string $registration, array $actor, string $activity_id, string $session_id, string $launch_mode, int $ttl = 28800, array $cors_origins = [], bool $guard = true )`.
- `Token_Repository`: `mint( Client $parent, Token_Spec $s ): array{key:string,secret:string,expires_at:string}` (key `tok_` + 24 hex, secret `Tokens::random(32)`, TTL capped at 28,800 s); `find( string $key ): ?Child_Token`; `revoke( string $key ): void`; `revoke_session( string $session_id ): void`; `mark_terminated( string $key, \DateTimeImmutable $at ): void`; `live_origins(): string[]`; `expired_unterminated( int $limit ): Child_Token[]` (for abandonment, Task 59).
- `Principal`: `client_id(): string`, `is_child(): bool`, `token(): ?Child_Token`, `can( string $scope ): bool` (child tokens: `statements/write`, `statements/read/mine`, `state`, profile read only; never `void` or ours), `can_write_profile(): bool`, `bindings(): Bindings`, `authority(): array` (Agent, `account.homePage` = `untrailingslashit( home_url() )`, `name` = client id, or `<client id>/<token key>` for a child), `authority_key(): string` (client id, or `tok:<token key>`), `trusted_for_satisfaction(): bool` (always false for a child), `cors_origins(): string[]` (client's plus the token's), `guard_enabled(): bool` (child and parent `guard_enabled`), `rejects_non_account(): bool`.
- `Authenticator::authenticate( Xapi_Request $r, array $server ): Principal` — header from the request, else `$server['HTTP_AUTHORIZATION']`, `REDIRECT_HTTP_AUTHORIZATION`, or `PHP_AUTH_USER`/`PHP_AUTH_PW`; `tok_` usernames look up tokens. Failures: 401 `Xapi_Error` with `WWW-Authenticate: Basic realm="Learning Ledger"`; message says `Authorization header missing (some hosts strip it; see readme)` when absent. Revoked, expired or unknown credential → 401. Per-IP failure limit → 429 with `Retry-After`. Per-client bucket from the client's `rate_class` → 429 `Retry-After: 1`.
- `Rate_Limiter::consume( string $bucket, float $rate, int $burst ): bool` (persistent object cache when `wp_using_ext_object_cache()`, else `INSERT … ON DUPLICATE KEY UPDATE` on `lledger_ratelimit`); `Auth_Fail_Limiter::hit( string $ip ): void`, `blocked_for( string $ip ): int` (seconds; 10 failures per 60 s window). Rate classes from option `lledger_rate_classes`, default `{"default":[20,100],"bulk":[200,2000],"unlimited":null}`.
- `Origin_Index::all(): string[]` (union of active clients' `cors_origins` and `Token_Repository::live_origins()`, cached 60 s).
- `Principal::system(): Principal` (all xAPI scopes, authority `account.name` `learning-ledger`, never trusted for satisfaction; used only for the LRS's own `earned` statements, Task 57).
- `Client_Route_Auth::principal( \WP_REST_Request $r, string $scope ): Principal|\WP_Error` — the same Basic authentication for the plugin's client-facing `learning-ledger/v1` routes (`tokens`, `courses`, `view-links`, `badges`, `badges/{id}/claim-links`, `actors/erase`); 401/403/429 as `WP_Error` with status. `Client_Route_Auth::PATHS` lists those route prefixes.
- `Auth_Hooks` (Module, role `lrs`): filters `application_password_is_api_request` to false for `Client_Route_Auth::PATHS`, so core never consumes their Basic header.
- `Client_Factory::create( array $scopes, array $overrides = [] ): array{id:string,secret:string}` (tests).

- [ ] **Step 1: Write the failing tests** — `test_valid_basic_auth_returns_principal`; `test_wrong_secret_401_with_www_authenticate`; `test_basic_auth_read_from_redirect_http_authorization` (Review Focus 1: request without the header, `$server['REDIRECT_HTTP_AUTHORIZATION']` set → principal); `test_missing_header_401_mentions_authorization_header` (Review Focus 1); `test_child_token_principal_scopes` (can `statements/write`, cannot `void`, cannot write profile, authority name ends `/tok_…`, not trusted); `test_expired_and_revoked_token_401`; `test_eleventh_failure_in_a_minute_429`; `test_rate_bucket_429_after_burst`; `test_all_does_not_imply_void_or_ours`; `test_bindings_home_page_and_registration`; `test_read_filter_shapes`; `test_client_route_auth_scope_and_binding` (a `learning-ledger/v1` request with valid Basic auth but without the scope → 403 `WP_Error`; with it → principal; Application Password filter returns false for those paths).
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Authenticator_Test|Scopes_Test|Bindings_Test|Token_Repository_Test|Rate_Limiter_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces. Secrets compared with `hash_equals` on SHA-256.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: LRS client and child-token authentication with scopes, bindings and rate limits"`; push if remote.

### Task 18: Statement validator and normaliser

**Files:**
- Create: `src/Xapi/class-statement-validator.php`, `src/Xapi/class-statement-normalizer.php`, fixtures `tests/fixtures/xapi/valid/*.json` and `tests/fixtures/xapi/invalid/*.json` (each invalid file has a sibling `.expect` holding the expected error path), tests `tests/unit/Xapi/test-statement-validator.php`, `test-statement-normalizer.php`

**Interfaces:**
- Consumes: `Actor_Validator`, `Primitives`, `Version` (Task 7), `Timestamp`, `Duration`, `Uuid` (Task 5).
- `Statement_Validator::validate( array $st, string $version ): void` — throws `Invalid_Input` with a dotted path. Rules: allowed keys per version (`2.0.0` adds `context.contextAgents`, `context.contextGroups`); `id` UUID; `actor`; `verb` (`id` IRI, `display` lang map); `object` Activity (definition fields, interaction types and their component lists), Agent/Group, `StatementRef` (`id` UUID), `SubStatement` (no `id`, `stored`, `version`, `authority`; no nested SubStatement); `result` (`score.scaled` in −1..1, `raw` within `min`..`max`, `min` < `max`, `success`/`completion` booleans, `duration` ISO 8601, extensions); `context` (`registration` UUID, `instructor`, `team` must be a Group, `contextActivities` values object or array of Activities, `revision`/`platform` only when the object is an Activity, `language` BCP 47, `statement` StatementRef, extensions); `timestamp` per version; `version` must start `1.0.` or `2.0.`; `attachments`: each needs `usageType`, `display`, `contentType`, `length`, `sha2`; accepted only with `fileUrl` (multipart is rejected earlier, §6.9); a `voided` verb (`http://adlnet.gov/expapi/verbs/voided`) needs a `StatementRef` object.
- `Statement_Normalizer::normalize( array $st, string $version, array $authority, \DateTimeImmutable $stored ): array` — sets `id` (v4 when absent, lowercased), `stored`, `authority`, `version` (`1.0.0` under 1.0.3 when absent; `2.0.0` under 2.0), `timestamp` (= stored when absent), turns single `contextActivities` objects into arrays. `Statement_Normalizer::comparable( array $st ): array` strips `id`, `stored`, `authority`, `version` and normalises `timestamp` to UTC for the PUT identity check (§6.2: same id with identical content returns 204).

- [ ] **Step 1: Write the failing tests** — data providers over every `valid/*.json` (no exception, both versions where the file name says `both`) and every `invalid/*.json` (exception path equals `.expect`). Cover at least: two IFIs; unknown top-level key; `contextAgents` under 1.0.3; SubStatement with `id`; `score.scaled` 1.5; `revision` with Agent object; anonymous Group without members; attachment without `fileUrl`; voided verb with Activity object; timestamp without offset under 2.0. Normaliser: id added and lowercased; `comparable()` equal for the same statement with keys reordered and timestamp `+02:00` vs UTC.
- [ ] **Step 2: Run to verify failure** — `composer test:unit -- --filter 'Statement_Validator_Test|Statement_Normalizer_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: strict xAPI 1.0.3 and 2.0 statement validation and normalisation"`; push if remote.

### Task 19: Activity and agent repositories

**Files:**
- Create: `src/Lrs/Resources/class-activity-repository.php`, `src/Lrs/Resources/class-agent-repository.php`, tests `tests/integration/Lrs/Resources/test-activity-repository.php`, `test-agent-repository.php`

**Interfaces:**
- `Activity_Repository::merge( string $iri, ?array $definition, bool $may_define ): void` (absent row → insert; present and `$may_define` → top-level fields replaced, language maps merged key by key; present and not `$may_define` → unchanged); `get( string $iri ): ?array` (Activity object `{objectType, id, definition}`; compares the stored `iri` to guard against hash collisions).
- `Agent_Repository::record( Agent_Identity $a ): void` (upsert by hash); `person_for( Agent_Identity $a ): array` (`{"objectType":"Person", "name":[], "mbox":[], "mbox_sha1sum":[], "openid":[], "account":[]}` from all rows sharing `person_id`; a new row's `person_id` is its own id); `delete( Agent_Identity $a ): void`.

- [ ] **Step 1: Write the failing tests** — merge with and without `define`; language-map merge keeps `en-US` and adds `fr`; `get` unknown → null; `person_for` returns arrays with the one IFI; `delete`.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Activity_Repository_Test|Agent_Repository_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: activity definition and agent repositories"`; push if remote.

### Task 20: `eddsa-rdfc-2022` Data Integrity signer and verifier

**Files:**
- Create: `src/Badges/Signing/class-eddsa-rdfc-2022.php`, fixtures `tests/fixtures/w3c/vc-di-eddsa/` (the W3C vc-di-eddsa Recommendation's eddsa-rdfc-2022 example: key pair, unsigned credential, proof options, canonical document and proof N-Quads, both hashes, combined hash and proofValue, each in its own file, with `SOURCE` giving the section URL), tests `tests/unit/Badges/test-eddsa-rdfc-2022.php`

**Interfaces:**
- Consumes: `Canonicalizer` (Task 11), `Ed25519`, `Multibase` (Task 6).
- `Eddsa_Rdfc_2022::proof_config( array $doc, string $verification_method, \DateTimeImmutable $created, string $purpose = 'assertionMethod' ): array` (`type: DataIntegrityProof`, `cryptosuite: eddsa-rdfc-2022`, `created` `Y-m-d\TH:i:s\Z`, `verificationMethod`, `proofPurpose`, `@context` = the document's).
- `Eddsa_Rdfc_2022::hash_data( array $doc_without_proof, array $proof_config ): string` (SHA-256(canon(proof config)) ‖ SHA-256(canon(document)), 64 bytes).
- `Eddsa_Rdfc_2022::sign( array $doc, string $verification_method, string $secret, \DateTimeImmutable $created, string $purpose = 'assertionMethod' ): array` (returns the document with `proof` and `proofValue` = base58btc multibase of the signature).
- `Eddsa_Rdfc_2022::verify( array $secured, callable $public_key_for /* (string $verification_method): ?string raw */ ): bool`.

- [ ] **Step 1: Write the failing tests** — `test_w3c_vector_canonical_forms` (canonical document N-Quads and proof N-Quads equal the fixture byte for byte); `test_w3c_vector_hashes`; `test_w3c_vector_proof_value` (signing the fixture credential with the fixture key and `created` equals the published `proofValue`); `test_verify_accepts_vector_and_rejects_tamper` (change `credentialSubject` name → false).
- [ ] **Step 2: Run to verify failure** — `composer test:unit -- --filter Eddsa_Rdfc_2022_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** following the eddsa-rdfc-2022 proof steps.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: eddsa-rdfc-2022 signing verified against the W3C vc-di-eddsa vectors"`; push if remote.

### Task 21: Issuer keys and did:web

**Files:**
- Create in `src/Badges/Issuer/`: `class-issuer-module.php`, `class-issuer-key.php`, `class-key-store.php`, `class-did-document.php`, `class-issuer-profile.php`, `class-badges-command.php`; tests `tests/integration/Badges/Issuer/test-key-store.php`, `test-did-document.php`

**Interfaces:**
- Consumes: `Ed25519`, `Secret_Box`, `Multibase` (Task 6); `Audit_Log` (Task 4).
- `Issuer_Key` readonly: `kid`, `public` (raw), `created_at`, `retired_at`; `secret(): string` (loaded on demand).
- `Key_Store::tier(): string` — `constant` when `LLEDGER_BADGE_KEY` (base64 32-byte seed) is defined; `file` when `LLEDGER_BADGE_KEY_FILE` names a readable 0600 file outside `ABSPATH` (JSON `[{kid, seed_b64, created_at, retired_at}]`); else `database` (`lledger_keys`, purpose `badge`, sealed with `Secret_Box` context `badge-key`). Never `wp_options`.
- `Key_Store::active(): ?Issuer_Key`; `generate(): Issuer_Key` (no-op when one exists); `rotate(): Issuer_Key` (new active, old gets `retired_at`); `remove( string $kid ): void` (compromise: `removed_at`, gone from did.json); `published(): Issuer_Key[]` (all not removed); `find( string $kid ): ?Issuer_Key`. Each change is audited.
- `Did_Document::did(): string` — `did:web:<host>` (host from `home_url()`, port as `%3A<port>`), or `did:web:<host>:issuer` when option `lledger_did_mode` is `path`; `document(): array` — `@context` `["https://www.w3.org/ns/did/v1","https://w3id.org/security/multikey/v1"]`, `verificationMethod` one `Multikey` per published key (`id` = `<did>#<kid>`, `controller`, `publicKeyMultibase`), `assertionMethod` all of their ids; `verification_method_for( Issuer_Key $k ): string`.
- Routes: rewrites `^\.well-known/did\.json$` and `^issuer/did\.json$` → query var `lledger_did`; served on `template_redirect` as `application/json` with `Cache-Control: public, max-age=300` and `Access-Control-Allow-Origin: *`.
- `Did_Document::document()` passes its `verificationMethod` list through filter `learning_ledger/did_verification_methods` (Task 65 adds an RSA `JsonWebKey`).
- `Issuer_Profile::document(): array` (`@context` credentials v2 + OB 3.0.3, `id` = did, `type` `["Profile"]`, `name` = option `lledger_issuer_name`); served at `GET /learning-ledger/v1/issuer`.
- WP-CLI: `badges keygen`, `badges rotate`, `badges remove-key <kid>`, `badges issuer-name <name>`, `badges did-mode <host|path>`.
- Adds `badges: { did, issuer_profile, key_tier }` to `learning_ledger/about_extension` and `badge_key_tier` to the status payload.

- [ ] **Step 1: Write the failing tests** — `test_database_tier_seals_secret` (no plaintext seed in `lledger_keys`, `wp_options` untouched); `test_rotate_keeps_old_key_published`; `test_remove_drops_key_from_did_document`; `test_did_host_and_path_modes` (home `https://lrs.sqwrlz.com` → `did:web:lrs.sqwrlz.com`; path mode → `did:web:lrs.sqwrlz.com:issuer`; `http://localhost:8888` → `did:web:localhost%3A8888`); `test_did_json_route_serves_document`.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Key_Store_Test|Did_Document_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: issuer key storage, rotation and did:web document"`; push if remote.

### Task 22: Connector pairing, secret, signing key and LRS client

**Files:**
- Create in `src/Connector/Pairing/`: `class-pairing-module.php`, `class-connector-settings.php`, `class-connector-secret.php`, `class-connector-keys.php`, `class-lrs-client.php`, `class-lrs-response.php`, `class-pairing-command.php`; tests `tests/integration/Connector/Pairing/test-pairing.php`, `test-lrs-client.php`

**Interfaces:**
- Consumes: `Secret_Box`, `Ed25519`, `Signed_Request`, `Tokens` (Task 6).
- `Connector_Settings::get(): array` / `update( array $changes ): void` — option `lledger_connector` (autoload off): `lrs_url` (public xAPI base, trailing slash), `loopback_url` (optional server-to-server base), `api_base` (from `/about`), `client_id`, `notify_kid`, `notify_public_key` (base64), `allow_auto_enrol` (false), `match_mbox` (false), `poll_minutes` (15). `Connector_Settings::public_xapi_base(): string` (never the loopback).
- `Connector_Secret::get(): ?string` (constant `LLEDGER_CONNECTOR_SECRET`, else option `lledger_connector_secret` sealed with context `connector-secret`, autoload off); `generate(): void` (32 random bytes, only when absent and no constant); `sha256_hex(): ?string`; `source(): ?string` (`constant` or `option`).
- `Connector_Keys::public_b64(): string`, `key_id(): string`, `sign( string $method, string $url, string $body ): array` (headers per `Signed_Request`, path+query taken from the URL); key pair generated on first use, sealed in option `lledger_connector_signing_key` (context `connector-signing`).
- `Lrs_Client::xapi( string $method, string $resource, array $query = [], ?string $body = null, array $headers = [] ): Lrs_Response` (Basic `client_id:secret`, `X-Experience-API-Version: 1.0.3`, base = loopback or public, timeout 10 s); `Lrs_Client::api( string $method, string $path, ?array $json = null ): Lrs_Response` (same auth against `api_base`, e.g. `actors/erase`); `Lrs_Response`: `status`, `headers`, `body`, `json(): mixed`.
- `wp learning-ledger connector connect --lrs=<xapi base> --client=<id>`: `GET <lrs>about`, read extension `https://github.com/derintolu/learning-ledger` → `api_base`, `notifications.kid`, `notifications.public_key`; generate the secret if absent; store settings. `wp learning-ledger connector set <allow-auto-enrol|match-mbox|poll-minutes|loopback-url> <value>`.
- Status additions under `connector`: `paired`, `lrs_url`, `client_id`, `secret_source`, `signing_key_id`, `signing_public_key`, `notify_kid`; privileged adds `secret_sha256`.

- [ ] **Step 1: Write the failing tests** (HTTP faked with `pre_http_request`) — `test_connect_pins_notify_key_and_generates_secret_once`; `test_secret_never_plaintext_in_options` (option value is sealed; `sha256_hex` matches `hash('sha256', get())`); `test_constant_secret_wins`; `test_status_hash_only_when_privileged`; `test_lrs_client_sends_basic_and_version`; `test_public_base_never_loopback`.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Pairing_Test|Lrs_Client_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: connector pairing with self-generated secret, signing key and LRS client"`; push if remote.

### Task 23: Waiver ledger, apply ledger, registrations and echo guard

**Files:**
- Create in `src/Connector/Ledger/`: `class-waiver-ledger.php`, `class-apply-ledger.php`, `class-registration-map.php`, `class-echo-guard.php`; tests `tests/integration/Connector/Ledger/test-waiver-ledger.php`, `test-apply-ledger.php`, `test-registration-map.php`, `test-echo-guard.php`

**Interfaces:**
- `Waiver_Ledger::record( int $user, int $lesson, string $registration, string $statement_id, string $reason, bool $carried_over ): void`; `get( int $user, int $lesson ): ?array`; `is_waived( int $user, int $lesson ): bool`; `clear( int $user, int $lesson ): void` (a lesson later completed for real loses its marker); `for_user( int $user ): array`; `delete_user( int $user ): int`.
- `Apply_Ledger::has( int $user, Lms_Object $o, string $status ): bool`; `record( int $user, Lms_Object $o, string $status, string $statement_id, ?float $score = null, ?bool $success = null ): void`; `score( int $user, int $lesson ): ?array{score_scaled:?float,success:?bool}`; `flag_void( string $statement_id ): int`; `flagged(): array`; `for_user( int $user ): array`; `delete_user( int $user ): int`.
- `Registration_Map::get_or_create( int $user, string $scope_key ): string` (UUID v4); `find( int $user, string $scope_key ): ?string`; `end( int $user, string $scope_key ): void` (the next `get_or_create` mints a new one); `user_for( string $registration ): ?int`; `delete_user( int $user ): int`. Scope keys: `course:<id>` (direction 2), `cmi5:<program id>` (player), `preview:<program id>:<user>` (Browse).
- `Echo_Guard::while_applying( callable $fn ): mixed` (sets a request-scoped flag); `Echo_Guard::active(): bool`; `Echo_Guard::was_applied( int $user, Lms_Object $o, string $status ): bool` (delegates to `Apply_Ledger::has`).

- [ ] **Step 1: Write the failing tests** — record/get/clear waivers; apply ledger idempotent on its PK; `flag_void` marks rows; registration end mints a new UUID; `Echo_Guard::active()` true only inside the callback, false after an exception.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Waiver_Ledger_Test|Apply_Ledger_Test|Registration_Map_Test|Echo_Guard_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: connector waiver and apply ledgers, registrations and echo guard"`; push if remote.

### Task 24: Identity map and anonymous learners

**Files:**
- Create in `src/Connector/Identity/`: `class-identity-module.php`, `class-identity-map.php`; tests `tests/integration/Connector/Identity/test-identity-map.php`

**Interfaces:**
- Consumes: `Agent_Identity` (Task 7); reads `get_option( 'lledger_connector' )['match_mbox']` directly (the option Task 22 owns), so it can run beside Task 22.
- `Identity_Map::home_page(): string` = `untrailingslashit( home_url() )`; `actor_for_user( int $user ): array` (`{"objectType":"Agent","account":{"homePage":…,"name":<uuid>}}`, UUID v4 stored in usermeta `lledger_actor` on first use); `user_for_actor( array $actor ): ?int` (order: own account homePage + `lledger_actor` lookup; `lledger_identity_links`; `mbox` equal to a user email only when `match_mbox` is on); `link( Agent_Identity $a, int $user ): void`; `unlink_user( int $user ): int`; `links_for_user( int $user ): array`; `create_anonymous_learner(): int` (role `lledger_learner`, login `lledger-` + 12 random hex, random 32-byte password, empty email, `user_registered` now) and `delete_learner( int $user ): void` (removes usermeta and links; the user itself is deleted by the caller).
- `Identity_Module` registers role `lledger_learner` (`read` only) on `init` if missing.

- [ ] **Step 1: Write the failing tests** — `test_actor_stable_per_user`; `test_home_page_is_untrailingslashed_and_round_trips` (Review Focus 5: with `home_url` filtered to `https://site.example/`, `actor_for_user()['account']['homePage'] === 'https://site.example'`, and `user_for_actor()` of that actor, and of the same actor with a trailing slash, behaves as documented: exact match only, so the slash variant returns null; and the `actor_home_page` binding the connector publishes in its status equals `home_page()`); `test_explicit_link_matches`; `test_mbox_only_when_enabled`; `test_anonymous_learner_has_no_email_and_only_read`.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter Identity_Map_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces; also add `actor_home_page` (= `home_page()`) to the connector status block so the LRS admin can bind the client to it.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: connector identity map and anonymous learner accounts"`; push if remote.

---
## Tasks 25–41: LRS resources, course import, connector transport, Sensei read side, packages, AU store

### Task 25: Package builder (program to the standard's ZIP)

**Files:**
- Create in `src/Connector/Packages/`: `class-package-builder.php`, `class-built-package.php`, `class-block-to-markdown.php`, `class-lesson-json-builder.php`, `class-cmi5-xml-builder.php`; tests `tests/integration/Connector/Packages/test-package-builder.php`, `tests/unit/Connector/test-block-to-markdown.php`

**Interfaces:**
- Consumes: `Program_Repository`, `Program_Iris` (Task 12); `Integration_Loader::for_post()` and `LMS_Adapter::course_tree()` (Task 9); `Package_Writer`, `Package_Validator`, `Secrets` (Task 10); `Markdown_Allowlist` (Task 13); block attribute names (Tasks 13, 14).
- `Package_Builder::build( int $program_id, string $flavour /* full|public */ ): Built_Package` — throws `Invalid_Input` (`missing_stable_key` with the post id, `validation` with the validator's errors). Stores the parsed structure with `Program_Repository::set_structure()` and bumps the program version when the full-flavour ETag changes.
- `Built_Package` readonly: `zip` (bytes), `etag`, `version`, `flavour`, `warnings` (string[]: skipped blocks).
- `Cmi5_Xml_Builder::build( Program $p, array $paths /* [ [key, Course_Tree] ] */, array $lesson_meta ): string` — `<course id=course_iri>` with `<cxm:course src="cxm/course.json" specVersion="1.0" mcp="…"/>` (`mcp` from the program's course-server URL when set); the placement AU first when the program has one (`cxm:role="placement"`, `moveOn="NotApplicable"`); one `<block id=block_iri cxm:key>` per path course in program order with `<cxm:path src="cxm/course.json#/paths/<key>"/>`; one `<au>` per lesson with `id` = `au_iri`, `cxm:key`, `moveOn` `CompletedAndPassed` when the lesson has a checkpoint block else `Completed`, `launchMethod="OwnWindow"`, `activityType="http://adlnet.gov/expapi/activities/lesson"`, `<url>` from the program's AU URL template with `{key}` replaced, `<cxm:lesson src="cxm/lessons/<key>.json"/>`.
- `Lesson_Json_Builder::build( \WP_Post $lesson ): array{json:array,warnings:string[]}` — from `parse_blocks()`: beats → `beats[]` `{key, goal, content (Markdown), question?}`; checkpoint → `checkpoint` `{key, kind, question, rubric (from its rubric block), checks, branches, maxAttempts?, file?, fallbackOptions?}`; planted-bug → `bug` `{key, file, code, hint?, revealAfter?, explanation, fixChecks}`; lesson meta → `minutes`, `sources`, `starterFiles`, `licence`. Field names follow the standard design §6.2 table and its schemas (Task 10).
- `Block_To_Markdown::convert( array $blocks ): array{markdown:string,skipped:string[]}` (CommonMark for the allowlist only; others are skipped with their block name).
- course.json: `specVersion`, `version`, `language`, `licence`, `rules`, `placement` guide, `custody`, `badges`, `badgeIssuer`, `paths.<key>` (`tagline`, placement hints, and the author-namespaced `preview`, `finalFiles`, `landing` from Task 14 course meta).

- [ ] **Step 1: Write the failing tests** — fixture: a program with two path courses (Fake_Adapter course trees over real posts with beat, checkpoint+rubric, planted-bug blocks); `test_full_package_validates_and_has_secrets`; `test_public_package_validates_without_secrets`; `test_au_ids_and_activity_scheme` (AU id `…/lessons/<key>`); `test_moveon_by_checkpoint_presence`; `test_same_content_same_etag_and_version_bumps_on_change`; `test_unallowlisted_block_skipped_with_warning`; `test_missing_stable_key_fails`; Markdown unit tests for paragraph, heading levels, nested list, code with language, quote, image with alt.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter Package_Builder_Test && composer test:unit -- --filter Block_To_Markdown_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces (XML with `DOMDocument`, namespaces exactly as the standard's example).
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: export a program as the standard's course package in full and public flavours"`; push if remote.

### Task 26: Statement store and write handler (PUT, POST, voiding)

**Files:**
- Create in `src/Lrs/Statements/`: `class-statements-write-handler.php`, `class-statement-store.php`, `class-statement-repository.php`, `class-stored-statement.php`, `class-ref-indexer.php`, `interface-statement-guard.php`; tests `tests/integration/Lrs/Statements/test-statement-store.php`, `test-statements-write-handler.php`, `test-voiding.php`, `test-ref-indexer.php`

**Interfaces:**
- Consumes: `Xapi_Request/Response/Error`, `Resource_Handler` (Task 16); `Principal`, `Scopes` (Task 17); `Statement_Validator`, `Statement_Normalizer` (Task 18); `Activity_Repository`, `Agent_Repository` (Task 19); `Json`, `Uuid`, `Timestamp`, `Clock` (Task 5); `Agent_Identity` (Task 7).
- `Statement_Guard::check( array $statement, Principal $p ): void` (throws `Xapi_Error`).
- `Statement_Store::store( array $statements, Principal $p, string $version ): string[]` (ids, in order; all-or-nothing: validate, run guards from `learning_ledger/statement_guards`, check bindings and scopes, then one transaction). `Statement_Store::put( string $id, array $statement, Principal $p, string $version ): void`. Errors: 400 validation (message carries the path), 400 duplicate id within one batch, 400 voiding a voiding statement, 403 scope/binding/`reject_non_account`, 409 existing id with different content.
- Existing id with identical content (`Json::equal` on `Statement_Normalizer::comparable()`) is a silent success for PUT and POST; a duplicate-key error from a concurrent insert is re-read and resolved the same way (Review Focus 3).
- Voiding: needs `void` scope; the target must satisfy the principal's bindings; a present target gets `voided = 1` in the same transaction; an absent target gets a `pending_voids` row and is voided on arrival; `learning_ledger/statement_voided` fires after commit.
- Row fields: `actor_hash` (`Agent_Identity` hash; anonymous Group = SHA-1 of `anon:` + sorted member `ifi_key`s), `actor_home_hash`, `verb_hash` (SHA-1 of the verb id), `object_kind` and `object_hash` (Activity: SHA-1 of id; Agent/Group: IFI hash; StatementRef: SHA-1 of `ref:<id>`; SubStatement: SHA-1 of `sub:<statement id>`), `registration`, `authority_client` = `Principal::authority_key()`, `content_sha256`, `body` = `Json::encode_exact( normalized )`.
- `Ref_Indexer::refs( array $statement ): array<array{hash:string,kind:string}>` — kinds `actor`, `object-agent`, `authority`, `instructor`, `team`, `context-agent`, `context-group`, `object-activity`, `context-parent`, `context-grouping`, `context-category`, `context-other`, and `substatement-<kind>` for the same inside a SubStatement.
- After insert: `Activity_Repository::merge()` for the object and context activities (`$may_define` = `can('define')`), `Agent_Repository::record()` for every agent seen, `learning_ledger/statements_stored` inside the transaction, `learning_ledger/statements_committed` after. Inserts slower than 500 ms are counted in option `lledger_slow_inserts` and logged with `error_log`.
- `Stored_Statement` readonly: `seq`, `id`, `stored` (xAPI string), `version`, `registration` (?string), `actor_hash`, `authority_client`, `is_voiding`, `voids_id`, `voided`, `body`; `statement(): array`.
- `Statement_Repository::find( string $id ): ?Stored_Statement`; `for_registration( string $registration, bool $include_voided = false ): Stored_Statement[]` (by `seq`); `approx_count(): int` (`MAX(seq)`); `delete_for_actor( string $actor_hash ): int` (used only by Task 44).
- `Statements_Write_Handler`: `PUT` requires `statementId` (a body `id` must match) → 204; `POST` single or array (max 500) → 200 JSON array of ids; body over 1 MB → 413; `Content-Type: multipart/mixed` → 400 `multipart attachments are not supported by this LRS`; non-JSON → 400.

- [ ] **Step 1: Write the failing tests** — `test_post_sets_id_stored_authority_version`; `test_put_identical_204_different_409`; `test_duplicate_key_race_maps_to_204_or_409` (Review Focus 3: insert the row directly between the existence check and the insert using a `query` filter hook, then assert identical → success and different → 409, never a 500); `test_batch_all_or_nothing` (one invalid in 3 → nothing stored); `test_duplicate_id_in_batch_400`; `test_void_requires_scope`; `test_void_sets_flag_and_fires_action`; `test_cannot_void_a_voiding_statement`; `test_pending_void_applied_on_arrival`; `test_binding_rejects_foreign_actor`; `test_reject_non_account_client`; `test_guard_filter_can_reject`; `test_exact_body_bytes_kept` (unicode and slashes unescaped); `test_multipart_rejected`; `test_refs_for_substatement_and_context`.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Statement_Store_Test|Statements_Write_Handler_Test|Voiding_Test|Ref_Indexer_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces. Use `START TRANSACTION`/`COMMIT` through `$wpdb->query()`; roll back on any exception.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: append-only statement store with idempotent PUT, batches and voiding"`; push if remote.

### Task 27: Documents API (State, Agent Profile, Activity Profile)

**Files:**
- Create in `src/Lrs/Documents/`: `class-doc-key.php`, `class-document.php`, `class-document-store.php`, `class-document-handler-base.php`, `class-state-handler.php`, `class-agent-profile-handler.php`, `class-activity-profile-handler.php`; tests `tests/integration/Lrs/Documents/test-document-store.php`, `test-state-handler.php`, `test-profile-handlers.php`

**Interfaces:**
- Consumes: Tasks 16, 17, 5, 7.
- `Doc_Key( string $kind /* state|agent_profile|activity_profile */, ?string $activity_iri, ?Agent_Identity $agent, ?string $registration, ?string $doc_id )` (absent parts stored as zero bytes, Review Focus 4).
- `Document_Store::get( Doc_Key $k ): ?Document`; `put( Doc_Key $k, string $content, string $content_type, ?string $if_match, ?string $if_none_match ): Document` (412 on precondition failure; `UPDATE … WHERE etag = %s` with affected-rows check so two writers cannot both win); `post_merge( Doc_Key $k, string $content, string $content_type, ?string $if_match, ?string $if_none_match ): Document` (both stored and new must be JSON objects, top-level merge; else 400); `delete( Doc_Key $k, ?string $if_match ): void`; `delete_all( Doc_Key $k_without_id ): int` (state only); `list_ids( Doc_Key $k_without_id, ?\DateTimeImmutable $since ): string[]`; `delete_for_agent( Agent_Identity $a ): int` (Task 44).
- `Document`: `content`, `content_type`, `etag` (SHA-1 hex), `updated`. Responses: GET single → body as stored with its `Content-Type`, `ETag: "<sha1>"`, `Last-Modified` (2.0); list → JSON array of ids; PUT/POST/DELETE → 204. Size over 2 MB → 413 (limit from option `lledger_document_max_bytes`, default 2,097,152). The 1.0.3/2.0 rule for writes without a precondition when the document exists (409) is followed per version; the ADL suite is the arbiter.
- Parameters: state `activityId`, `agent` (JSON), `registration?`, `stateId?`, `since?`; agent profile `agent`, `profileId?`, `since?`; activity profile `activityId`, `profileId?`, `since?`.
- Authorisation: clients need `state` / `profile` for writes and `state`/`profile`/`all/read` for reads, within bindings. Child tokens: state only for their own actor, registration and AU activity id, and `stateId=LMS.LaunchData` is read-only (403 on write); agent profile read-only (403 on write, which cmi5 allows); activity profile read-only.

- [ ] **Step 1: Write the failing tests** — `test_state_without_registration_is_single_row` (Review Focus 4: two PUTs without `registration` leave one row and the second content); `test_etag_if_match_412_and_if_none_match_star`; `test_concurrent_writers_one_wins` (two updates with the same old ETag → second 412); `test_post_merges_json_objects_only`; `test_list_ids_since`; `test_delete_all_state_for_registration`; `test_over_2mb_413`; `test_child_token_cannot_write_launchdata_or_profile`; `test_child_token_limited_to_own_registration`; `test_binary_content_round_trip_with_content_type`.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Document_Store_Test|State_Handler_Test|Profile_Handlers_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: State, Agent Profile and Activity Profile with ETag concurrency"`; push if remote.

### Task 28: About, Activities and Agents resources

**Files:**
- Create in `src/Lrs/Resources/`: `class-about-handler.php`, `class-activities-handler.php`, `class-agents-handler.php`; tests `tests/integration/Lrs/Resources/test-about-handler.php`, `test-activities-agents-handlers.php`

**Interfaces:**
- Consumes: Tasks 16, 17, 19.
- `About_Handler` (no auth): `{"version":["1.0.3","2.0.0"],"extensions":{"https://github.com/derintolu/learning-ledger": <ext>}}` where `<ext>` = `apply_filters( 'learning_ledger/about_extension', [ 'api_base' => rest_url( 'learning-ledger/v1/' ), 'plugin_version' => LEARNING_LEDGER_VERSION ] )`.
- `Activities_Handler` GET `activityId` → the stored Activity (`Activity_Repository::get`), or the minimal `{"objectType":"Activity","id":…}` when unknown (follow the suite if it expects otherwise).
- `Agents_Handler` GET `agent` → `Agent_Repository::person_for()`.
- Any authenticated principal may read both; a child token only for its own agent.

- [ ] **Step 1: Write the failing tests** — about lists both versions without auth and carries the extension key; the filter adds fields; activities returns a merged definition; agents returns a Person with the agent's IFI; child token asking for another agent → 403.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'About_Handler_Test|Activities_Agents_Handlers_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: xAPI About, Activities and Agents resources"`; push if remote.

### Task 29: Client management (WP-CLI and admin REST)

**Files:**
- Create in `src/Lrs/Clients/`: `class-clients-module.php`, `class-client-command.php`, `class-clients-controller.php`, `class-client-input.php`; tests `tests/integration/Lrs/Clients/test-client-command.php`, `test-clients-controller.php`

**Interfaces:**
- Consumes: `Client_Repository`, `Client_Spec`, `Scopes` (Task 17); `Audit_Log` (Task 4).
- `Client_Input::from_array( array $in ): Client_Spec` — `id` matches `^[a-z0-9][a-z0-9._-]{1,63}$` and not `tok_…`; `secret_sha256` 64 hex; scopes valid; origins `https?://host[:port]` or the literal `null`; prefixes absolute IRIs; `rate_class` known.
- `wp learning-ledger client create --id=<id> --secret-sha256=<hex> --scopes=<csv> [--name=] [--bind-actor-home-page=] [--bind-actor-ifi=] [--bind-registration=] [--activity-prefix=<csv>] [--write-activity-prefix=<csv>] [--cors-origin=<csv>] [--trusted] [--rate-class=] [--no-guard] [--reject-non-account] [--expires=<RFC 3339>]`; `client update <id> …` (same flags, plus `--secret-sha256` to rotate); `client revoke <id>`; `client list` (never prints hashes). There is no flag that takes a plaintext secret.
- Admin REST (`lledger_manage`, cookie + nonce or Application Password): `GET /learning-ledger/v1/clients`, `POST /clients` (server generates the 32-byte secret, returns it once in the response as `secret`, stores only its hash), `GET|PATCH|DELETE /clients/{id}`.
- Every create, update and revoke writes an audit row (`client.create` etc., no hashes).

- [ ] **Step 1: Write the failing tests** — CLI create stores the hash and scopes, rejects a non-hex hash, rejects unknown scopes, rejects `tok_x`; REST POST as admin returns a secret that authenticates (via `Xapi_Test_Client`), as editor → 403, without nonce under cookie auth → 401; `client list` output contains no 64-hex string; revoke makes the next xAPI call 401; audit rows written.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Client_Command_Test|Clients_Controller_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: LRS client management by secret hash over WP-CLI and admin REST"`; push if remote.

### Task 30: Child token minting API

**Files:**
- Create in `src/Lrs/Tokens/`: `class-tokens-module.php`, `class-tokens-controller.php`; tests `tests/integration/Lrs/Tokens/test-tokens-controller.php`

**Interfaces:**
- Consumes: `Client_Route_Auth`, `Token_Repository`, `Token_Spec`, `Scopes::TOKENS_MINT` (Task 17).
- `POST /learning-ledger/v1/tokens` (client Basic auth, scope `tokens/mint`), JSON `{registration, actor, activity_id, session_id, launch_mode, ttl?, cors_origins?, guard?}` → 201 `{key, secret, expires_at}`. The actor and registration must be within the minting client's bindings; `ttl` ≤ 28,800; `guard` may be false only when the parent client has `guard_enabled` off.
- `DELETE /learning-ledger/v1/tokens/{key}` → 204 (only the parent client).

- [ ] **Step 1: Write the failing tests** — mint works for a `tokens/mint` client and the token authenticates an xAPI POST for its actor and registration; without scope → 403; actor outside binding → 403; ttl over 8 h is capped; another client cannot revoke; revoked token → 401.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter Tokens_Controller_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: tokens/mint API for short-lived child credentials"`; push if remote.

### Task 31: Sensei integration bootstrap and adapter read side

**Files:**
- Create: `integrations/sensei/bootstrap.php`, `integrations/sensei/class-sensei-integration.php`, `integrations/sensei/class-sensei-adapter.php`, `integrations/sensei/class-sensei-iris.php`, `.github/workflows/nightly.yml`, `tests/integration/Integrations/Sensei/test-sensei-contract.php`, `tests/integration/Integrations/Sensei/test-sensei-adapter.php`

**Interfaces:**
- Consumes: Task 9 contracts and contract test case; `Program_Repository`, `Program_Iris` (Task 12); `Waiver_Ledger` (Task 23); `Connector_Settings` (Task 22).
- `bootstrap.php` returns `Integration_Descriptor( 'sensei', fn() => function_exists( 'Sensei' ) && version_compare( Sensei()->version, '4.20.1', '>=' ), fn() => new Sensei_Adapter(), '4.20.1', '4.26', fn() => Sensei()->version )` and calls `Sensei_Integration::boot()`.
- `Sensei_Integration::boot(): void` adds `course`/`lesson` to filters `learning_ledger/stable_key_post_types`, `learning_ledger/lesson_post_types` (lesson), `learning_ledger/course_post_types` (course), `learning_ledger/adapter_post_types` (`sensei` → `course`, `lesson`, `quiz`); adds `lms: { sensei: version, sensei_certificates: version|null }` to the status payload; instantiates `Sensei_Tested_Out_Display` (Task 48) and calls `Sensei_Applier::hooks()` (Task 46) when those classes exist.
- `Sensei_Adapter implements LMS_Adapter, Certificate_Provider`: `id()` `sensei`; `capabilities()` all seven; `apply()` delegates to `Sensei_Applier` (Task 46) when it exists, else `DEFERRED`; `register_event_listeners()` delegates to `Sensei_Listeners` (Task 47) when it exists; `certificate_pdf()` delegates to `Sensei_Certificate_Provider` (Task 56) when it exists, else null; `resolve()` maps program IRIs (via `Program_Iris::key_from_iri` and `_lledger_stable_key`) and direction-2 IRIs (`Sensei_Iris`) to `Lms_Object`; `course_tree()` uses `Sensei()->course->course_lessons( $course_id )` order; `upsert_course()` / `upsert_lesson()` find by `_lledger_stable_key`, create with `wp_insert_post`, update only when title/content/order/meta differ, link a lesson to its course with Sensei's documented lesson meta (`_lesson_course`) and the course's lesson order; `enrol()` uses `Sensei_Course_Manual_Enrolment_Provider`; `progress()` from `Sensei_Utils::user_completed_lesson()`, `Sensei_Utils::user_completed_course()` and `Waiver_Ledger`; `is_waived()` from `Waiver_Ledger`. Never touches Sensei tables, comments or `Sensei\Internal\…`.
- `Sensei_Iris::course( int $id ): string` = activity base fixed on first use in post meta `_lledger_activity_base` (default `home_url( '/activities/courses/<id>' )`); `lesson( int $course, int $lesson ): string` = base . `/lessons/<id>`; `quiz( int $course, int $lesson ): string` = lesson IRI . `/quiz`. Courses in a program use `Program_Iris` instead.
- `nightly.yml`: matrix `lms` = `sensei-lms`, `lifterlms`, `tutor`, `learnpress`; each job skips when `integrations/<dir>/bootstrap.php` is absent, installs the latest wordpress.org release into the tests env through `.wp-env.override.json`, and runs `composer test:integration -- --group <lms>-contract`.

- [ ] **Step 1: Write the failing tests** — `Sensei_Contract_Test extends Adapter_Contract_Test_Case` (`@group sensei-contract`; apply/event contract tests call `markTestSkipped` while `Sensei_Applier`/`Sensei_Listeners` do not exist); `test_descriptor_available_with_sensei`; `test_course_tree_order`; `test_upsert_idempotent_by_stable_key` (second call returns the same id and makes no `post_modified` change); `test_resolve_program_and_direction2_iris`; `test_activity_base_fixed_on_first_use` (change `home_url`, IRI unchanged); `test_enrol_and_progress`.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Sensei_Contract_Test|Sensei_Adapter_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS (apply/event contract tests skipped).
- [ ] **Step 5: Commit** — `git commit -m "feat: Sensei integration bootstrap and adapter read side"`; push if remote.

### Task 32: Connector outbox, statement builder and direction-2 setting

**Files:**
- Create in `src/Connector/Outbox/`: `class-outbox-module.php`, `class-statement-builder.php`, `class-outbox-sink.php`, `class-outbox-repository.php`, `class-outbox-sender.php`, `class-direction2.php`, `class-direction2-command.php`; tests `tests/integration/Connector/Outbox/test-statement-builder.php`, `test-outbox-sink.php`, `test-outbox-sender.php`, `test-direction2.php`

**Interfaces:**
- Consumes: `Event_Sink`, `Neutral_Event`, `Integration_Loader` (Task 9); `Identity_Map` (Task 24); `Registration_Map`, `Echo_Guard` (Task 23); `Lrs_Client` (Task 22); `Shutdown_Runner`, `Job_Queue` (Task 15); `Program_Repository`, `Program_Iris` (Task 12); `Uuid::v5` (Task 5).
- `Statement_Builder::NAMESPACE = '6f1d9c2e-4b7a-5e3c-8a1f-2d4b6c8e0a11'`; `Statement_Builder::build( Neutral_Event $e, callable $iri_for /* (Neutral_Event): array{object:string,parent:string} */ ): ?array` — verbs: enrolled `http://adlnet.gov/expapi/verbs/registered`; lesson started `…/attempted`; lesson completed `…/completed`; quiz submitted `…/answered` (score only, no answer text); quiz graded `…/passed` or `…/failed` with `result.score.scaled` and `success`; course completed `…/completed` on the course; reset `http://id.tincanapi.com/verb/reset`; unenrolled → null. Actor from `Identity_Map::actor_for_user()`; `context.registration` from `Registration_Map::get_or_create( user, 'course:<id>' )` (a course reset ends it); `contextActivities.parent` the course; `id` = `Uuid::v5( NAMESPACE, home_url() . '|' . user . '|' . object IRI . '|' . type . '|' . occurred_at in ms )`; `timestamp` = occurred_at.
- `Direction2::is_on( int $course_id ): bool` — post meta `_lledger_direction2` (`on`/`off`); default on, except off for courses in a program with a course-server URL and forced off for courses in a program with `_lledger_player` set. `wp learning-ledger course direction2 <id> <on|off>`.
- `Outbox_Sink implements Event_Sink` (registered through filter `learning_ledger/event_sink`): returns without writing when `Echo_Guard::active()`, when the event matches `Echo_Guard::was_applied()`, or when direction 2 is off; otherwise one INSERT into `lledger_conn_outbox` and `Shutdown_Runner::request()`. No HTTP.
- `Outbox_Sender::flush( int $limit = 50 ): int` — `PUT statements?statementId=<id>` per row; 2xx → sent; 409 → `conflict` with an audit row; other 4xx → `failed`; 5xx or timeout → retry with the queue's backoff. Runs at `shutdown` and every cron minute.

- [ ] **Step 1: Write the failing tests** — builder: verbs per event, deterministic id for identical input, quiz graded carries `score.scaled` and no `response`; sink: writes one row, writes nothing inside `Echo_Guard::while_applying`, nothing when direction 2 is off, and performs zero HTTP calls (`pre_http_request` asserts not called); sender: 204 → sent, 500 → retried later, 409 → conflict; direction2 defaults.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Statement_Builder_Test|Outbox_Sink_Test|Outbox_Sender_Test|Direction2_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: connector outbox sending on-site learning as idempotent xAPI"`; push if remote.

### Task 33: Connector inbox, catch-up poll and result application

**Files:**
- Create in `src/Connector/Inbox/`: `class-inbox-module.php`, `class-inbox-route.php`, `class-inbox-repository.php`, `class-inbox-processor.php`, `class-result-factory.php`, `class-catch-up-poller.php`; tests `tests/integration/Connector/Inbox/test-inbox-route.php`, `test-inbox-processor.php`, `test-result-factory.php`, `test-catch-up-poller.php`

**Interfaces:**
- Consumes: `Notification_Signature` (Task 6); `Connector_Settings`, `Lrs_Client` (Task 22); `Identity_Map` (Task 24); `Program_Repository` (Task 12); `Course_Structure`, `Au_Fact`, `Satisfaction_Evaluator` (Task 8); `Integration_Loader`, `Result`, `Apply_Outcome` (Task 9); `Apply_Ledger`, `Echo_Guard` (Task 23); `Shutdown_Runner` (Task 15).
- `POST /learning-ledger/v1/inbox` — headers `X-Learning-Ledger-Timestamp`, `X-Learning-Ledger-Key`, `X-Learning-Ledger-Signature`; verifies with the pinned key (`notify_kid` must match), timestamp within 300 s, `event_id` unseen (`lledger_conn_events`); body `{event_id, subscription_id, cursor, statements:[…]}`; stores statements (deduped by id, `source` `webhook`), returns 202 and schedules `Inbox_Processor::process_pending()` at `shutdown`. Bad signature → 401, stale → 401, replay → 409.
- `Inbox_Repository::add( array $statement, string $source ): bool`; `pending( int $limit ): array`; `for_registration( string $registration ): array` (all rows, stored order); `mark( string $id, string $status, ?int $user = null ): void`; `voided_ids( string $registration ): string[]`; `rematch_actor( string $actor_hash ): int` (unmatched → pending); `prune_unmatched( int $days = 30 ): int`; `for_user( int $user ): array`; `delete_user( int $user ): int`.
- `Inbox_Processor::process_pending( int $limit = 100 ): int` — voiding statement → `Apply_Ledger::flag_void( target )` (flag for review; never un-complete); unmatched actor → `unmatched`; no program for the activity → `processed` (ignored); else facts = `Au_Fact::from_statement` over the registration's non-voided rows → `Satisfaction_Evaluator::evaluate( …, ALL )` → `Result_Factory::results()` → for each, `Echo_Guard::while_applying( fn() => $adapter->apply( $r ) )`; `APPLIED`/`SKIPPED` → `Apply_Ledger::record()`; `DEFERRED` → stays pending; `FAILED` → `flagged`. After each registration's evaluation it fires `learning_ledger/connector_evaluated` (`int $program_id, int $user, string $registration, Satisfaction_Result $e`).
- `Result_Factory::results( Program $p, Course_Structure $s, Satisfaction_Result $e, int $user, string $registration, callable $resolve /* (au or block id): ?Lms_Object */ ): Result[]` — per AU satisfied and not yet in the ledger: `LESSON_WAIVED` (reason, carried_over) or `LESSON_COMPLETED`; per AU with passed/failed facts: `LESSON_PASSED`/`LESSON_FAILED` with score; per satisfied block: `BLOCK_SATISFIED` (object = the path course); course satisfied: `COURSE_SATISFIED` (object = program). Never yields a completion for an AU whose moveOn is unmet (a bare `completed` on a `CompletedAndPassed` AU yields nothing).
- `Catch_Up_Poller::run(): int` — on `learning_ledger/cron_quarter_hour` and after a failed delivery recovers: `GET statements?since=<cursor>&limit=500` following `more`, adds rows with `source` `poll`, advances `lledger_conn_cursor` `poll_since` to the newest `stored` seen; works against any conformant LRS.
- Daily cron prunes unmatched rows older than 30 days.

- [ ] **Step 1: Write the failing tests** — route: valid signature → 202 and row stored; tampered body → 401; timestamp 301 s old → 401; replayed `event_id` → 409; processor with Fake_Adapter: `completed` only on a `CompletedAndPassed` AU → no apply; plus `passed` → `LESSON_COMPLETED` and `LESSON_PASSED` once each; waived "Tested Out" → `LESSON_WAIVED`; replaying the batch → no second apply; void of the passed statement → ledger flagged, no un-complete; unknown actor → `unmatched` then `rematch_actor` after a link → applied; adapter paused (`DEFERRED`) → row stays pending; poller follows `more` and dedupes with webhook rows.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Inbox_Route_Test|Inbox_Processor_Test|Result_Factory_Test|Catch_Up_Poller_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: connector inbox with signed webhooks, catch-up poll and moveOn-based apply"`; push if remote.

### Task 34: Record linking by one-time code, and backfill

**Files:**
- Create in `src/Connector/Identity/`: `class-link-code-module.php`, `class-link-code-controller.php`, `class-backfill.php`, `class-learner-command.php`, `src-js/link-record/{index.js,block.json,render.php}` (block `learning-ledger/link-record`: a form for logged-in users); tests `tests/integration/Connector/Identity/test-link-code.php`, `test-backfill.php`

**Interfaces:**
- Consumes: `Identity_Map` (Task 24), `Connector_Keys`, `Lrs_Client` (Task 22), `Program_Repository` (Task 12), `Inbox_Repository`, `Inbox_Processor` (Task 33), `Job_Queue` (Task 15), `Rate_Limiter` (Task 17, when the LRS role is local) or its own transient counter.
- `POST /learning-ledger/v1/identity/link` (logged-in user, REST nonce) `{code, program}` → the connector sends a signed `POST <program course-server URL>/link-codes/redeem` with `{code}`; the course server answers `{actor}`; the connector stores `Identity_Map::link()`, calls `Inbox_Repository::rematch_actor()`, and queues job `identity_backfill`. At most 5 attempts per user per 10 minutes; wrong code → 400 with a generic message.
- `Backfill::run( int $user, array $actor ): int` — `GET statements?agent=<actor>` paged through `more`, rows added with `source` `backfill`, then `Inbox_Processor::process_pending()`.
- `wp learning-ledger learner backfill <user_id>` (for links older than the 30-day unmatched window).

- [ ] **Step 1: Write the failing tests** (course server faked with `pre_http_request`) — happy path links and backfills; the outgoing request carries a valid connector signature over the exact path and body; logged-out → 401; missing nonce → 403; sixth attempt in 10 minutes → 429; statements are never rewritten (LRS receives no write).
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Link_Code_Test|Backfill_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: explicit record linking by one-time code with backfill"`; push if remote.

### Task 35: Bitstring Status List

**Files:**
- Create in `src/Badges/Status/`: `class-status-list-module.php`, `class-status-list-repository.php`, `class-bitstring.php`, `class-status-list-controller.php`; tests `tests/unit/Badges/test-bitstring.php`, `tests/integration/Badges/Status/test-status-list-repository.php`

**Interfaces:**
- Consumes: `Eddsa_Rdfc_2022` (Task 20), `Key_Store`, `Did_Document` (Task 21), `Multibase` (Task 6).
- `Bitstring::SIZE = 131072`; `Bitstring::encode( string $bits ): string` (`u` + base64url(gzencode(bits))); `decode( string $encoded ): string`; `set( string $bits, int $index, bool $on ): string`; `get( string $bits, int $index ): bool` (index 0 is the most significant bit of the first byte).
- `Status_List_Repository::allocate(): array{list_id:int,index:int}` (uniformly random unused index; a new list starts when the current one is 80% used); `set_revoked( int $list_id, int $index ): void` (flips the bit and re-signs); `credential( int $list_id ): array` (signed `BitstringStatusListCredential`: `@context` credentials v2, `id` = `rest_url( "learning-ledger/v1/status-lists/{id}" )`, `type` `["VerifiableCredential","BitstringStatusListCredential"]`, `issuer` = did, `validFrom`, `credentialSubject` `{id: <url>#list, type: BitstringStatusList, statusPurpose: revocation, encodedList, ttl: 300000}`); `entry( int $list_id, int $index ): array` (`BitstringStatusListEntry` with `id` `<url>#<index>`, `statusPurpose` `revocation`, `statusListIndex` as a string, `statusListCredential` `<url>`).
- `GET /learning-ledger/v1/status-lists/{id}` public, `Cache-Control: public, max-age=300`, `Access-Control-Allow-Origin: *`.

- [ ] **Step 1: Write the failing tests** — bit order (set 0 → first byte `0x80`; set 131071 → last byte `0x01`); encode/decode round trip; `allocate` never repeats an index; a new list at 80% (use a test-only size override); `set_revoked` changes the encoded list and the credential verifies with `Eddsa_Rdfc_2022::verify`; route headers.
- [ ] **Step 2: Run to verify failure** — `composer test:unit -- --filter Bitstring_Test && composer test:integration -- --filter Status_List_Repository_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: signed Bitstring Status Lists for badge revocation"`; push if remote.

### Task 37: Package importer (the standard's ZIP into the LMS)

**Files:**
- Create in `src/Connector/Packages/`: `class-import-module.php`, `class-package-importer.php`, `class-import-report.php`, `class-markdown-to-blocks.php`, `class-lesson-blocks-builder.php`, `class-package-fetcher.php`, `class-import-controller.php`, `class-package-command.php`; tests `tests/integration/Connector/Packages/test-package-importer.php`, `tests/unit/Connector/test-markdown-to-blocks.php`

**Interfaces:**
- Consumes: `Package`, `Package_Validator`, `Limits` (Task 10); `Course_Structure_Parser` (Task 8); `Program_Repository` (Task 12); `Integration_Loader`, `LMS_Adapter::upsert_course/upsert_lesson`, `Course_Spec`, `Lesson_Spec` (Task 9); block names (Tasks 13, 14).
- `Package_Importer::import( Package $p, string $adapter_id = 'sensei' ): Import_Report` — rejects the whole package when `Package_Validator` fails (report carries the validator's error codes, nothing written). Program found or created by stable key (`course.json#/key` if present, else the last path segment of the course IRI); program meta from course.json and cmi5.xml (`_lledger_course_iri` = course id; `_lledger_iri_base` = longest common prefix of the AU ids up to a `/`); one LMS course per top-level block via `upsert_course` (stable key = block `cxm:key`); one lesson per AU via `upsert_lesson` in order (stable key = AU `cxm:key`); the placement AU goes to program meta, not a lesson; lessons of this program missing from the package are set to draft and listed in `drafted`; learner progress is never touched; `Program_Repository::set_structure()` at the end. Unchanged content is not rewritten (content hash in `_lledger_import_hash`), so a second import reports no updates.
- `Lesson_Blocks_Builder::build( array $lesson_json ): string` (block markup: `learning-ledger/beat` per beat with core inner blocks from `Markdown_To_Blocks`, then `learning-ledger/checkpoint` with an inner `learning-ledger/rubric`, then `learning-ledger/planted-bug`).
- `Markdown_To_Blocks::convert( string $markdown ): string` (league/commonmark AST → `core/paragraph`, `core/heading`, `core/list`/`core/list-item`, `core/code`, `core/quote`, `core/image`; raw HTML dropped).
- `Package_Fetcher::fetch( string $url, Limits $l ): string` (temp file path; `wp_safe_remote_get` streamed to disk; `https` only unless `LLEDGER_TESTING`; size cap).
- `Import_Report`: `program_id`, `created`, `updated`, `drafted`, `errors` (validator shape), `ok()`.
- `POST /learning-ledger/v1/programs/import` (`lledger_manage_connector`; JSON `{url}` or a ZIP upload) → report JSON (422 when invalid). `wp learning-ledger package import <url>` (exit status only).

- [ ] **Step 1: Write the failing tests** — import the Task 10 `good-full` fixture: program, two Fake_Adapter courses (the Sensei path is covered by Task 31's contract test `test_upsert_course_and_lesson_idempotent_by_stable_key`), lessons in order with the right blocks; secrets present in `post_content` of the full import; second import: `updated` empty; a lesson removed from the package → draft and listed; invalid package → nothing created, report has `xsd`; `test_interrupted_import_resumes_without_duplicates` (adapter throws on the third lesson; re-run completes; one post per stable key); progress of an enrolled learner unchanged after re-import; Markdown unit tests mirror Task 25's.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter Package_Importer_Test && composer test:unit -- --filter Markdown_To_Blocks_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`; then the round-trip check `Package_Builder::build( import( p ) )` equals `p` in structure and lesson JSON (add as `test_round_trip_export_equals_import`). Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: idempotent import of course packages into the LMS"`; push if remote.

### Task 40: AU content store (resumable extraction)

**Files:**
- Create in `src/Player/Store/`: `class-au-store.php`, `class-extract-job.php`, `class-mime-map.php`, `class-content-host.php`; tests `tests/integration/Player/Store/test-au-store.php`

**Interfaces:**
- Consumes: `Package::from_zip`, `Limits` (Task 10); `Job_Queue` (Task 15).
- `Au_Store::root(): string` (`LLEDGER_CMI5_STORE` when defined and outside `ABSPATH`, else `wp_upload_dir()['basedir'] . '/learning-ledger-cmi5'`, created with an empty `index.php`); `Au_Store::begin( string $zip_path, int $program_id ): int` (package row `pending`, job `cmi5_extract`); `Extract_Job::run( array $payload ): void` (extracts up to 200 files or 15 s per run from the saved cursor, writes `<sha256>.bin`, inserts manifest rows, re-queues itself; `ready` or `failed` with the error); `Au_Store::lookup( int $package_id, string $path ): ?array{file:string,mime:string,size:int,sha256:string}` (normalised path, no `..`); `Au_Store::status( int $package_id ): string`; `Au_Store::delete( int $package_id ): void`.
- `Content_Host::get(): ?string` (`LLEDGER_CMI5_CONTENT_HOST` constant, else option `lledger_cmi5_content_host`); `Content_Host::is_safe( string $host ): bool` (differs from the site host and is not covered by `COOKIE_DOMAIN`); `Content_Host::base_url( int $package_id ): string` (`https://<content host>/<package id>/` when safe, else `home_url( "/lledger-au/<package id>/" )`); `Content_Host::mode(): string` (`host` or `sandbox`).
- `Mime_Map::for_path( string $path ): string` (allowlist: html, htm, js, mjs, css, json, xml, txt, svg, png, jpg, jpeg, gif, webp, ico, woff, woff2, ttf, otf, mp3, mp4, webm, ogg, wav, vtt, pdf, wasm; else `application/octet-stream`).

- [ ] **Step 1: Write the failing tests** — a 5-file package extracted at 2 files per run (test override) is `ready` after 3 runs; stored files are named by hash and contain the right bytes; no stored file has a `.php` or `.html` name; traversal and symlink packages fail at `begin`; `lookup( id, '../x' )` → null.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter Au_Store_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: AU content store with hashed files and resumable extraction"`; push if remote.

### Task 41: Course import into the LRS

**Files:**
- Create in `src/Lrs/Courses/`: `class-courses-module.php`, `class-course-repository.php`, `class-course-record.php`, `class-courses-controller.php`; tests `tests/integration/Lrs/Courses/test-course-repository.php`, `test-courses-controller.php`

**Interfaces:**
- Consumes: `Client_Route_Auth`, `Scopes::COURSES_WRITE` (Task 17); `Package`, `Package_Validator` (Task 10); `Course_Structure_Parser` (Task 8).
- `PUT /learning-ledger/v1/courses` (client auth, `courses/write`): body a ZIP (`application/zip`, full or public flavour) or a plain cmi5.xml (`application/xml`/`text/xml`). Computes the ETag of the bytes; unchanged → 200 `{course, version, etag, changed:false}`; changed → stores and returns `{…, changed:true}` (201 for a new course). Only the owning client may re-import a course (403 otherwise). Invalid → 422 with validator errors.
- Stored: `xml`, `tree` (`Course_Structure::to_array`), `badges` (`course.json#/badges`), `badge_issuer`, `custom_rules` (1 when any declaration's `awardOn` is not one of `au-moveon`, `block-satisfied`, `course-satisfied`, `any-path`), `owner_client`, and one `course_aus` row per AU. Lesson JSON and secret fields are not kept.
- `GET /learning-ledger/v1/courses?course=<iri>` (same scope) → `{course, version, etag}`.
- `Course_Repository::get( string $course_iri ): ?Course_Record`; `for_au( string $au_id ): ?Course_Record`; `for_statement( array $statement ): ?Course_Record` (object id with `/au` stripped, or any grouping id, through `course_aus`). `Course_Record`: `course_iri`, `version`, `etag`, `structure(): Course_Structure`, `badges`, `badge_issuer`, `custom_rules`, `owner_client`. Fires `learning_ledger/course_imported`.

- [ ] **Step 1: Write the failing tests** — import ZIP then same bytes → `changed:false`, version unchanged; changed bytes → version 2; plain XML accepted with empty badges; other client re-import → 403; without scope → 403; `for_statement` finds the course from a `{au}/au` object and from a grouping id; no lesson JSON stored (`SELECT` shows no `rubric` text).
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Course_Repository_Test|Courses_Controller_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: LRS course import keeping the AU tree and badge declarations"`; push if remote.

---
## Tasks 38–52: Queries, guard, export, notifications, erasure, course-server routes, Sensei directions, player import, badge pipeline

### Task 38: Statement queries (GET)

**Files:**
- Create in `src/Lrs/Statements/`: `class-statements-read-handler.php`, `class-statement-query.php`, `class-query-params.php`, `class-more-cursor.php`; `src/Xapi/class-formatter.php`; tests `tests/integration/Lrs/Statements/test-statement-query.php`, `test-statements-read-handler.php`, `test-more-cursor.php`, `tests/unit/Xapi/test-formatter.php`

**Interfaces:**
- Consumes: Tasks 16, 17, 26 (`Statement_Repository`, table layout), `Hmac` (Task 6), `Timestamp` (Task 5), `Agent_Identity` (Task 7).
- `Query_Params::from_request( Xapi_Request $r ): self` — `statementId`, `voidedStatementId` (with either, only `format` and `attachments` are allowed, else 400), `agent` (JSON), `verb`, `activity`, `registration`, `related_activities`, `related_agents`, `since`, `until`, `limit` (0 or absent → 100, capped 500), `format` (`exact` default, `ids`, `canonical`), `attachments` (accepted; responses stay `application/json` because multipart is out of scope), `ascending`.
- `Statement_Query::run( Query_Params $q, Principal $p, ?More_Cursor $c = null ): array{rows:Stored_Statement[],more:?string,consistent_through:string}` — non-voided rows only (`voided = 0`), voiding statements included; principal read filter (`Bindings::read_filter`, and `statements/read/mine` → `authority_client = authority_key`); range scans ending in `seq` on the most selective index (registration, then actor, then object, then verb); `related_*` through `lledger_statement_refs`; `since`/`until` compared on `stored` in UTC microseconds; rows stored after `consistent_through` (= `Clock::now() − 1 s`, captured on the first page) never returned.
- `More_Cursor::encode( array $params, int $last_seq, string $upper_bound ): string` (base64url JSON + `.` + HMAC, purpose `more`); `More_Cursor::decode( string $token ): self` (tampered → 400); the `more` URL is `<public xapi base>statements?more=<token>`.
- `Formatter::format( array $statement, string $format, string $accept_language ): array` (`ids`: agents and groups reduced to IFIs (group members kept as IFIs), activities to `{objectType,id}`; `canonical`: each language map reduced to the best `Accept-Language` match, else the first entry; `exact`: unchanged).
- `Statements_Read_Handler`: single → the statement (404 if absent; `statementId` of a voided statement → 404; `voidedStatementId` of a non-voided one → 404); list → `{"statements":[…],"more":"…"|""}`; headers `X-Experience-API-Consistent-Through` and, for 2.0, `Last-Modified` (the newest `stored` in the result or of the single statement). Exact format returns the stored bytes spliced without re-encoding.

- [ ] **Step 1: Write the failing tests** — filters each alone and combined (agent + registration, activity + since); `test_since_with_offset_and_microseconds` (Review Focus 2: rows stored at `…08:00:00.123455Z`, `.123456Z`, `.123457Z`; `since=2026-10-27T10:00:00.123456+02:00` returns only the third); `related_activities` finds a statement whose parent is the activity; `related_agents` finds the instructor; voided excluded from lists and returned by `voidedStatementId`; paging over 250 rows with limit 100 gives 3 pages, stable while new rows arrive between pages; tampered `more` → 400; `ascending`; `format=ids` and `canonical` with `Accept-Language: fr, en;q=0.5`; `statements/read/mine` sees only its own; binding read filter hides foreign actors; `statementId` plus `verb` → 400; exact bytes equal stored body.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Statement_Query_Test|Statements_Read_Handler_Test|More_Cursor_Test' && composer test:unit -- --filter Formatter_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces (SQL built only from fixed fragments plus `$wpdb->prepare` placeholders).
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: statement queries with stable cursor paging and formats"`; push if remote.

### Task 39: cmi5 guard for child tokens

**Files:**
- Create in `src/Lrs/Cmi5/`: `class-cmi5-guard.php`; tests `tests/integration/Lrs/Cmi5/test-cmi5-guard.php`

**Interfaces:**
- Consumes: `Statement_Guard` (Task 26), `Principal`, `Token_Repository` (Task 17), `Statement_Repository::for_registration` (Task 26), `Vocab` (Task 8).
- `Cmi5_Guard implements Statement_Guard, Module` (role `lrs`; adds itself to `learning_ledger/statement_guards`). Applies only when `Principal::is_child()` and `guard_enabled()`. Rejects with 403 (or 400 for malformed): LMS-only verbs from the AU (`launched`, `waived`, `satisfied`, `abandoned`); a cmi5-category statement without the `sessionid` context extension or with a different session than the token's; in `Browse`/`Review`, defined statements other than `initialized` and `terminated`; a second `passed` for the AU in the registration; `failed` after `passed`; a second `completed`; any defined statement after `terminated` in the session; anything at all more than 10 minutes after `terminated`. A stored `terminated` calls `Token_Repository::mark_terminated()`.

- [ ] **Step 1: Write the failing tests** — each rule rejects with the stated status; a valid `initialized`, `completed`, `passed`, `terminated` sequence is accepted; a full client is never guarded; a parent with `guard_enabled` off disables it for its tokens; in Review mode allowed (non-cmi5-category) statements pass while defined ones other than `initialized`/`terminated` are rejected.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter Cmi5_Guard_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: cmi5 ordering guard on AU child tokens"`; push if remote.

### Task 42: NDJSON export and migration import

**Files:**
- Create in `src/Lrs/Export/`: `class-export-module.php`, `class-export-controller.php`, `class-ndjson-exporter.php`, `class-statements-command.php`, `class-migration-importer.php`; tests `tests/integration/Lrs/Export/test-ndjson-exporter.php`, `test-migration-importer.php`

**Interfaces:**
- Consumes: statements table (Task 26), `Agent_Identity`, `Timestamp`, `Audit_Log`.
- `Ndjson_Exporter::stream( array $filters /* since, until, registration, agent */, callable $write ): int` — chunks of 500 by `seq`; each line `{"statement":<exact body>,"stored":"…","content_sha256":"<hex>"}` + `\n`; never loads more than one chunk.
- `GET /learning-ledger/v1/export?since=&until=&registration=&agent=` (`lledger_export`; cookie+nonce or Application Password) streams `application/x-ndjson` with `Content-Disposition: attachment`.
- `wp learning-ledger statements export --file=<path> [--since=] [--until=] [--registration=] [--agent=]` and `statements import --file=<path>` (`Migration_Importer::import( string $path ): array{imported:int,skipped:int,rejected:int}`: keeps `stored` and `authority`, verifies `content_sha256`, skips existing identical ids, audits every run).

- [ ] **Step 1: Write the failing tests** — export of 1,200 rows streams 1,200 lines in `seq` order with memory under a fixed bound; filters; non-exporter user → 403; import of the export into a clean install keeps `stored` and `authority` and the `content_sha256` values; a line with a wrong hash is rejected; an audit row exists.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Ndjson_Exporter_Test|Migration_Importer_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: NDJSON statement export and audited migration import"`; push if remote.

### Task 43: LRS notifications (subscriptions, signed webhooks)

**Files:**
- Create in `src/Lrs/Notify/`: `class-notify-module.php`, `class-notify-keys.php`, `class-subscription-repository.php`, `class-subscriptions-controller.php`, `class-subscription-command.php`, `class-delivery-enqueuer.php`, `class-webhook-sender.php`; tests `tests/integration/Lrs/Notify/test-delivery-enqueuer.php`, `test-webhook-sender.php`, `test-subscriptions.php`

**Interfaces:**
- Consumes: `learning_ledger/statements_stored` (Task 26), `Notification_Signature`, `Ed25519`, `Secret_Box` (Task 6), `Shutdown_Runner`, cron hooks (Task 15).
- `Notify_Keys::active(): array{kid:string,public:string}` (Ed25519, `lledger_keys` purpose `notify`, sealed, created on first use); `sign( string $body, int $ts ): string`.
- About extension adds `notifications: { kid, public_key (base64), subscriptions_url }`.
- `Subscription_Repository::create( string $url, array $filters ): int` (filters: `actor_home_page`, `activity_prefix`, `verbs[]`, `registrations[]`; https only unless `LLEDGER_TESTING`); `matching( Stored_Statement $row ): int[]`; `mark_failing( int $id )`; `mark_active( int $id )`; `all()`; `delete( int $id )`.
- Admin REST `GET|POST /learning-ledger/v1/subscriptions`, `DELETE /subscriptions/{id}` (`lledger_manage`); WP-CLI `wp learning-ledger subscription create --url=<url> [--actor-home-page=] [--activity-prefix=] [--verb=<csv>] [--registration=<csv>]`, `subscription list`, `subscription delete <id>`.
- `Delivery_Enqueuer` (on `statements_stored`): one `lledger_outbox` INSERT per matching subscription per statement, then `Shutdown_Runner::request()`.
- `Webhook_Sender::run( int $max_batches = 10 ): int` — per subscription, up to 100 due rows in `seq` order into one envelope `{event_id (uuid v4), subscription_id, cursor (last seq), statements:[exact bodies]}`; headers `X-Learning-Ledger-Timestamp`, `X-Learning-Ledger-Key`, `X-Learning-Ledger-Signature`; 5 s timeout; 2xx → delivered; otherwise `next_attempt_at` by 1, 5, 15, 60 min then every 6 h; first failure older than 72 h → subscription `failing`, admin notice; a later success sets it `active`. Statement order within a subscription is preserved.

- [ ] **Step 1: Write the failing tests** — filters match/miss; 150 statements → two envelopes (100 + 50) with valid signatures (verified with `Notification_Signature::verify` and the about key); 500 response → retried after 60 s, not lost; 72 h of failures → `failing`; recovery → `active`; nothing sent during the storing request (sender runs at shutdown only).
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Delivery_Enqueuer_Test|Webhook_Sender_Test|Subscriptions_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`; then an end-to-end check on one wp-env install with both roles: connector connected over the loopback URL, subscription created, a statement POSTed to the LRS appears in `lledger_conn_inbox` within one shutdown. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: signed webhook notifications with retries and failing state"`; push if remote.

### Task 44: Actor erasure on the LRS and LRS privacy hooks

**Files:**
- Create in `src/Lrs/Privacy/`: `class-erase-module.php`, `class-actor-eraser.php`, `class-erase-controller.php`, `class-lrs-privacy.php`; tests `tests/integration/Lrs/Privacy/test-actor-eraser.php`, `test-lrs-privacy.php`

**Interfaces:**
- Consumes: `Statement_Repository::delete_for_actor`, refs table (Task 26), `Document_Store::delete_for_agent` (Task 27), `Agent_Repository::delete` (Task 19), `Client_Route_Auth` (Task 17), `Audit_Log`.
- `Actor_Eraser::erase( Agent_Identity $a, string $by ): array{statements:int,refs:int,documents:int,agents:int}` — hard-deletes the actor's statements and their ref rows, their documents and agent row; in other statements' ref rows the actor's hash is replaced by `SHA-1('tombstone')` (bodies stay immutable, a documented limitation); fires `learning_ledger/actor_erased` (badge revocation and badge-row deletion are Task 58's); audits counts only.
- `POST /learning-ledger/v1/actors/erase` (client auth, `actors/erase`, actor within binding) `{actor}` → 200 counts.
- `Lrs_Privacy`: registers `wp_privacy_personal_data_exporters` and `_erasers` for email addresses: actors `mbox: mailto:<email>` and `mbox_sha1sum` of it; exporter returns statements (exact JSON) in pages of 100.

- [ ] **Step 1: Write the failing tests** — erase removes statements, refs, state and agent profile, agent row; another actor's statement naming the erased actor as instructor keeps its body and loses the ref (tombstone); `actor_erased` fired with counts; client outside binding → 403; WordPress privacy eraser for an email removes the mbox actor's statements; audit row has counts and no email.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Actor_Eraser_Test|Lrs_Privacy_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: GDPR actor erasure on the LRS"`; push if remote.

### Task 45: Course-server signed routes on the connector

**Files:**
- Create in `src/Connector/CourseServer/`: `class-course-server-module.php`, `class-trusted-keys.php`, `class-db-nonce-store.php`, `class-signed-request-auth.php`, `class-learners-controller.php`, `class-trust-key-command.php`; tests `tests/integration/Connector/CourseServer/test-signed-request-auth.php`, `test-learners-controller.php`

**Interfaces:**
- Consumes: `Signed_Request`, `Nonce_Store` (Task 6); `Identity_Map` (Task 24); `Integration_Loader` (Task 9); ledgers and `Registration_Map` (Task 23); `Inbox_Repository::delete_user` (Task 33); outbox table (Task 32); `Lrs_Client::api` (Task 22); `Status_Route::payload` (Task 3); `Rate_Limiter` pattern (own counter in `lledger_ratelimit` when the LRS role is off: the table exists on both roles).
- `Trusted_Keys::add( string $key_id, string $public_b64, array $capabilities, array $limits ): void`; `get( string $key_id ): ?array`; `revoke( string $key_id ): void`. Capabilities: `create_learners` (off unless listed), `enrol`, `read_progress`, `delete_learners`, `read_packages`, `import_packages`, `read_certificates`; default when `--capabilities` is omitted: every capability except `create_learners`. Limits default `{per_minute:30, per_day:2000}`.
- `wp learning-ledger connector trust-key --id=<id> --public-key=<base64> [--capabilities=<csv>] [--per-minute=] [--per-day=]`; `connector revoke-key <id>`.
- `Db_Nonce_Store implements Nonce_Store` (`lledger_nonces`, pruned hourly).
- `Signed_Request_Auth::authorize( \WP_REST_Request $r, string $capability ): string|\WP_Error` (key id; uses the raw path and query of the request URI; 401 for signature errors, 403 for a missing capability, 429 for limits).
- Routes under `learning-ledger/v1/course-server/` (no other auth accepted): `POST learners` → 201 `{user_id, actor}` (`Identity_Map::create_anonymous_learner()`); `POST learners/{id}/enrolments` `{course}` → adapter `enrol()`; `GET learners/{id}/progress?program=<id>|course=<id>` → `{courses:[{course, completed:[lesson ids], tested_out:[…], course_completed, certificate}] , program_satisfied}`; `DELETE learners/{id}` → only users whose only role is `lledger_learner` (else 403): deletes the WP user, identity links, waivers, applied rows, registrations, outbox and inbox rows, then `Lrs_Client::api( 'POST', 'actors/erase', {actor} )` (a failed LRS call is retried by a queued job and reported in the response); `GET status` → `Status_Route::payload( true )`. `Course_Server_Module` also registers `Packages_Route` (Task 36) and `Certificates_Route` (Task 56) when those classes exist.

- [ ] **Step 1: Write the failing tests** (signing with a test key pair) — valid request passes; wrong key, stale timestamp, replayed nonce, altered query string each → 401; missing capability → 403; `create_learners` off by default → 403 until granted; 31st learner in a minute → 429; created learner has no email and role `lledger_learner`; enrol + progress returns completed and tested-out lessons; delete removes all connector rows and calls the LRS erase; deleting an administrator id → 403; status route returns the secret hash.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Signed_Request_Auth_Test|Learners_Controller_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: Ed25519-signed course-server routes for learners, enrolment, progress and status"`; push if remote.

### Task 46: Sensei direction 1 (apply results)

**Files:**
- Create: `integrations/sensei/class-sensei-applier.php`, `tests/integration/Integrations/Sensei/test-sensei-applier.php`

**Interfaces:**
- Consumes: `Result`, `Apply_Outcome` (Task 9); `Waiver_Ledger`, `Apply_Ledger`, `Echo_Guard` (Task 23); `Connector_Settings::allow_auto_enrol` (Task 22).
- `Sensei_Applier::apply( Result $r ): Apply_Outcome` — learner not enrolled: enrol when `allow_auto_enrol`, else `DEFERRED`. `LESSON_COMPLETED`: `Sensei_Utils::sensei_start_lesson( $lesson, $user, true )` unless already complete; `Waiver_Ledger::clear()`. `LESSON_WAIVED`: the same completion plus `Waiver_Ledger::record()`. `LESSON_PASSED`/`LESSON_FAILED`: nothing in Sensei's quiz store (the score lives in `Apply_Ledger`). `BLOCK_SATISFIED`: `Sensei_Utils::user_complete_course()` when Sensei has not already completed it. `COURSE_SATISFIED`: user meta `_lledger_program_satisfied_<program id>` = time. While applying, the teacher-email callbacks on `sensei_user_lesson_end` (registered in `includes/class-sensei-emails.php:48`) are removed for this request only and restored after.
- `Sensei_Applier::hooks(): void` — filter `sensei_user_completed_lesson` returns `passed` for a lesson whose ledger entry is passed when Sensei's own answer is "not complete" because of a pass-required quiz.
- Task 31's `Sensei_Integration::boot()` already calls `Sensei_Applier::hooks()` when the class exists; this task only adds the class.

- [ ] **Step 1: Write the failing tests** — completed result completes the lesson and Sensei completes the course after the last lesson (certificate trigger `sensei_user_course_end` fired once); waived result completes and is waived; six waivers send zero teacher emails (`wp_mail` short-circuited counter); a pass-required quiz lesson with a ledger pass is reported completed through the filter; not enrolled and auto-enrol off → `DEFERRED`; applying twice changes nothing; the Sensei contract tests from Task 31 now run unskipped and pass.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Sensei_Applier_Test|Sensei_Contract_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces (public Sensei API only).
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: Sensei applies LRS results as completion, waivers and course completion"`; push if remote.

### Task 47: Sensei direction 2 (event listeners)

**Files:**
- Create: `integrations/sensei/class-sensei-listeners.php`, `tests/integration/Integrations/Sensei/test-sensei-listeners.php`

**Interfaces:**
- Consumes: `Event_Sink`, `Neutral_Event` (Task 9).
- `Sensei_Listeners::register( Event_Sink $sink ): void` maps `sensei_course_enrolment_status_changed` (enrolled / unenrolled), `sensei_user_course_start`, `sensei_user_lesson_start`, `sensei_user_lesson_end`, `sensei_user_quiz_submitted`, `sensei_user_quiz_grade`, `sensei_user_course_end`, `sensei_user_lesson_reset`, `sensei_user_course_reset` to the neutral events (argument lists checked against Sensei 4.26.4's `do_action` calls). Each callback builds one event and calls `$sink->emit()`; nothing else.

- [ ] **Step 1: Write the failing tests** (Recording_Sink) — completing a lesson through `Sensei_Utils::sensei_start_lesson( …, true )` emits `LESSON_STARTED` and `LESSON_COMPLETED`; grading a quiz emits `QUIZ_GRADED` with `score_scaled` 0.8 for 80 and `success`; course reset emits `PROGRESS_RESET`; with the real `Outbox_Sink`, inside `Echo_Guard::while_applying` no outbox row appears (echo guard end to end); the contract test `test_events_emitted_for_lesson_completion` passes.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Sensei_Listeners_Test|Sensei_Contract_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: Sensei on-site learning events feed the connector outbox"`; push if remote.

### Task 48: Sensei "Tested out" display

**Files:**
- Create: `integrations/sensei/class-sensei-tested-out-display.php`, `tests/integration/Integrations/Sensei/test-sensei-tested-out-display.php`

**Interfaces:**
- Consumes: `Waiver_Ledger` (Task 23).
- `Sensei_Tested_Out_Display::__construct()` hooks: `sensei_learning_mode_lesson_status_icon` (label and icon "Tested out" for waived lessons with reason `Tested Out`), `render_block` for Sensei's course-outline lesson block (appends a `Tested out` badge), `sensei_analysis_course_user_lesson` (status column shows "Complete (tested out)"; verify the filter's arguments in 4.26.4 first). Strings translatable in `learning-ledger`; output escaped.

- [ ] **Step 1: Write the failing tests** — each filter adds the label for a waived lesson of the current user and nothing for a normally completed one; output is escaped.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter Sensei_Tested_Out_Display_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`; agent-browser screenshots of the course outline and learning mode for a learner with one waived lesson. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: show tested-out lessons in Sensei's outline, learning mode and reports"`; push if remote.

### Task 49: AU content origin and sandboxed fallback

**Files:**
- Create in `src/Player/Origin/`: `class-content-origin.php`, `class-content-host-command.php`, `class-file-responder.php`; tests `tests/integration/Player/Origin/test-content-origin.php`, `tests/http/test-content-origin-http.php`

**Interfaces:**
- Consumes: `Au_Store::lookup`, `Content_Host` (Task 40).
- `Content_Origin::register()` runs during plugin boot, before `plugins_loaded` finishes and before any user is determined: when `HTTP_HOST` equals a safe content host it serves the file and exits. It serves only manifest paths (404 otherwise), never reads or sets cookies, and sends `X-Content-Type-Options: nosniff`, `Content-Security-Policy: frame-ancestors <site origin>`, `Cache-Control: public, max-age=3600`, the manifest `Content-Type`, and honours single `Range` requests (206).
- Fallback: rewrite `^lledger-au/(\d+)/(.+)$` served on `parse_request` with the same headers plus `Content-Security-Policy: sandbox allow-scripts allow-forms allow-popups allow-modals; frame-ancestors <site origin>` (no `allow-same-origin`).
- `File_Responder::send( array $file, ?string $range, array $headers ): void`.
- WP-CLI `wp learning-ledger cmi5 content-host <host>` (errors when unsafe).

- [ ] **Step 1: Write the failing tests** — unsafe host (same as site, or under `COOKIE_DOMAIN=.example.org` for `au.example.org`) is refused; manifest path served with the headers; unknown path 404; `../` 404; range request 206 with the right bytes; sandbox fallback CSP has `sandbox` and lacks `allow-same-origin`; HTTP test on wp-env with `LLEDGER_CMI5_CONTENT_HOST=127.0.0.1:8888` serves the file at `http://127.0.0.1:8888/<id>/index.html` with no `Set-Cookie`.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter Content_Origin_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same, `composer test:http -- --filter Content_Origin_Http_Test`, `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: serve AU files from a separate origin or a CSP sandbox"`; push if remote.

### Task 50: cmi5 player package import into Sensei

**Files:**
- Create in `src/Player/Import/`: `class-player-import-module.php`, `class-player-importer.php`, `class-player-import-controller.php`, `class-player-import-command.php`; tests `tests/integration/Player/Import/test-player-importer.php`; fixtures: CATAPULT sample packages copied to `tests/fixtures/catapult/` (Apache-2.0, `SOURCE` file)

**Interfaces:**
- Consumes: `Package`, `Package_Validator` (Task 10), `Course_Structure_Parser` (Task 8), `Package_Importer` (Task 37), `Package_Fetcher` (Task 37), `Au_Store` (Task 40), `Content_Host::base_url` (Task 40), `Program_Repository` (Task 12), Sensei adapter `upsert_course`/`upsert_lesson` (Task 31).
- `Player_Importer::import( string $zip_or_xml_path ): Import_Report` — XSD-valid ZIP (32- or 64-bit) or standalone `cmi5.xml` with absolute URLs. A package with `cxm/` goes through `Package_Importer` and is then marked as a player program. Otherwise: program (custody `lms-launched`, meta `_lledger_player` = 1, structure stored), one Sensei course per top-level block (nested blocks flattened into it, order kept), AUs directly under `<course>` in one course titled after the course, one lesson per AU with content `<!-- wp:learning-ledger/cmi5-lesson {"auId":"<au id>"} /-->` and meta `_lledger_au_id`, and no Sensei quiz. Relative AU URLs resolve under `Content_Host::base_url( package )` after `Au_Store::begin()`; absolute URLs stay remote. Re-import is idempotent by AU id.
- `POST /learning-ledger/v1/cmi5/import` (`lledger_manage_connector`; `{url}` or upload) and `wp learning-ledger cmi5 import <url>`.

- [ ] **Step 1: Write the failing tests** — CATAPULT `001-essentials` imports to one program with the right lessons and blocks; a nested-block fixture is flattened; a package with AUs directly under `<course>` gets one course; relative URLs resolve to the content base; an invalid XML is rejected whole; re-import creates nothing new; `Direction2::is_on()` is false for the imported courses.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter Player_Importer_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: import any cmi5 package into Sensei for the player"`; push if remote.

### Task 52: Badge pipeline (evaluate, verify, create pending)

**Files:**
- Create in `src/Badges/Pipeline/`: `class-pipeline-module.php`, `class-badge-evaluator.php`, `class-badge-repository.php`, `class-narrative.php`, `class-badges-controller.php`; tests `tests/integration/Badges/Pipeline/test-badge-evaluator.php`, `test-badges-controller.php`, `tests/unit/Badges/test-narrative.php`

**Interfaces:**
- Consumes: `learning_ledger/statements_committed` (Task 26), `Statement_Repository::for_registration`, `Course_Repository::for_statement` (Task 41), `Au_Fact`, `Satisfaction_Evaluator`, `Waiver_Policy` (Task 8), `Client_Repository` (Task 17), `Job_Queue` (Task 15), `Audit_Log`.
- On `statements_committed`: for each row with a registration whose course has badge declarations, `Job_Queue::push( 'badge_evaluate', {registration, course}, 'badge:<registration>' )`. Nothing else in the request.
- `Badge_Evaluator::evaluate( string $registration, Course_Record $c ): array` (badge ids created) — facts from non-voided statements; per declaration (fields from the standard: Achievement fields, `awardOn`, `countWaived`, and `target` = the lesson or path `cxm:key`, absent for course badges), policy `countWaived ? EXCLUDE_CARRIED_OVER : NONE`: `au-moveon` → `au_satisfied`; `block-satisfied` → `block_satisfied`; `course-satisfied` → `course_satisfied`; `any-path` → `first_satisfied_block`. Trigger statement: the defined statement that met moveOn (`au-moveon`) or the `satisfied` statement on the block/course; it must be stored, non-voided and written by a client with `trusted_for_satisfaction` (child tokens never qualify). When the trusted `satisfied` exists but the recomputation disagrees → badge row `held`, audit, admin notice; when the course has `custom_rules`, a trusted `satisfied` is accepted without recomputation. Idempotent on (achievement, registration).
- `Badge_Repository::create_pending( array $achievement, string $registration, string $actor_hash, string $course_iri, string $trigger_id, string $narrative, int $waived_count ): ?string`; `get( string $id ): ?array`; `for_registration( string $registration ): array`; `for_actor( string $actor_hash ): array`; `set_status( string $id, string $status )`; `store_issued( string $id, array $credential, int $list_id, int $index, string $recipient_hash, string $salt )`; `delete_for_actor( string $actor_hash ): int`.
- `Narrative::for( int $total, int $completed, int $tested_out ): string` → e.g. `4 of 6 lessons completed, 2 tested out by placement`; `6 of 6 lessons completed` when none tested out.
- `GET /learning-ledger/v1/badges?registration=<uuid>` (client auth, scope `badges/claim-links` or `badges/issue`, registration actor within binding) → `[{id, achievement:{id,name,image}, status, created_at}]`. No links in it.

- [ ] **Step 1: Write the failing tests** — a block badge with `countWaived` true is created when one AU was tested out, with narrative `… 1 tested out by placement`; with `countWaived` false it is not; a carried-over waiver never counts; `any-path` fires on the first block only, once; a `satisfied` written by an untrusted client creates nothing; a trusted `satisfied` with unmet recomputation → `held` and an audit row; a voided trigger → nothing; re-running the job creates no duplicate; the storing request performs no evaluation (job queued only); badges route lists the pending badge without any URL field.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Badge_Evaluator_Test|Badges_Controller_Test' && composer test:unit -- --filter Narrative_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: badge pipeline that recomputes satisfaction before creating pending badges"`; push if remote.

---
## Tasks 36, 51–57: Package routes, view links, launch, connector privacy, Site Health, certificates, claim and signing

### Task 36: Package export routes, course-server package routes and content-changed ping

**Files:**
- Create: `src/Connector/Packages/class-export-module.php`, `src/Connector/Packages/class-export-controller.php`, `src/Connector/Packages/class-content-ping.php`, `src/Connector/Packages/class-export-command.php`, `src/Connector/CourseServer/class-packages-route.php`; tests `tests/integration/Connector/Packages/test-export-controller.php`, `test-content-ping.php`, `tests/integration/Connector/CourseServer/test-packages-route.php`

**Interfaces:**
- Consumes: `Package_Builder` (Task 25), `Package_Importer`, `Package_Fetcher` (Task 37), `Signed_Request_Auth` (Task 45), `Connector_Keys` (Task 22), `Job_Queue` (Task 15), `Program_Repository` (Task 12).
- `GET /learning-ledger/v1/programs/{id}/package?flavour=full|public` (`lledger_manage_connector`) → `application/zip`, `ETag`, `X-Package-Version`; `If-None-Match` match → 304. `wp learning-ledger package export <program> --file=<path> [--flavour=public]`.
- `Packages_Route`: `GET course-server/packages/{program}` (capability `read_packages`; full flavour; `{program}` is the id or stable key; ETag/304 as above) and `POST course-server/packages` `{url}` (`import_packages`) → import report.
- `Content_Ping`: on `save_post` of a program, or of a course or lesson belonging to one, `Job_Queue::push( 'content_ping', {program}, 'ping:<program>', 10 )`; the job rebuilds the full package and, when its ETag changed, sends a signed `POST <course-server URL>/content-changed` with `{program: <stable key>, version, etag}`. No HTTP during the save request.

- [ ] **Step 1: Write the failing tests** — editor without `lledger_manage_connector` → 403; 304 on matching `If-None-Match`; signed GET returns the full flavour with secrets; unsigned GET on the course-server path → 401; saving a lesson queues one ping job (two saves → one job) and makes no HTTP call; the job's request carries a valid connector signature and the new ETag; unchanged content sends nothing.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Export_Controller_Test|Content_Ping_Test|Packages_Route_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: package export routes, signed course-server package routes and content-changed ping"`; push if remote.

### Task 51: View links and the read-only record page

**Files:**
- Create in `src/Lrs/Links/`: `class-view-links-module.php`, `class-link-repository.php`, `class-view-links-controller.php`, `class-record-page.php`, `templates/record-page.php`; tests `tests/integration/Lrs/Links/test-view-links.php`, `test-link-repository.php`

**Interfaces:**
- Consumes: `Client_Route_Auth`, `Scopes::RECORDS_VIEW_LINKS` (Task 17), `Statement_Query`, `Formatter` (Task 38), `Ndjson_Exporter` (Task 42), `Tokens` (Task 6).
- `Link_Repository::mint( string $kind /* view|claim */, string $actor_hash, string $subject, int $ttl, string $created_by ): string` (token; stores only `Tokens::hash`; for `claim` deletes earlier unused links of the same actor and subject); `resolve( string $token, string $kind ): ?array` (unexpired, and for `claim` unused); `consume( string $token ): bool` (atomic `UPDATE … WHERE used_at IS NULL`); `prune(): int`.
- `POST /learning-ledger/v1/view-links` (scope `records/view-links`, actor within binding) `{actor}` → `{url, expires_at}`; TTL 900 s. `url` = `home_url( '/records/<token>' )`.
- `Record_Page`: rewrite `^records/([A-Za-z0-9_-]{43})/?$`; renders the actor's statements (newest first, 100 per page, escaped JSON in `<pre>`), links `?download=ndjson` and `?download=json`; headers `Cache-Control: no-store`, `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`. Expired or unknown → 410 page. Multi-use until expiry; read-only.

- [ ] **Step 1: Write the failing tests** — mint requires scope and binding; page shows only that actor's statements, escapes `<script>` inside a statement; NDJSON download matches the exporter; after 15 minutes (frozen clock) → 410; database holds no plaintext token.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'View_Links_Test|Link_Repository_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`; agent-browser screenshot of a record page. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: LRS-minted view links and a read-only record page"`; push if remote.

### Task 53: cmi5 launch, LaunchData, fetch and return

**Files:**
- Create in `src/Player/Launch/`: `class-launch-module.php`, `class-launch-controller.php`, `class-fetch-controller.php`, `class-return-controller.php`, `class-launch-data.php`, `class-lms-statements.php`, `class-session-repository.php`, `class-launch-url.php`; tests `tests/integration/Player/Launch/test-launch.php`, `test-fetch.php`, `test-lms-statements.php`

**Interfaces:**
- Consumes: `Lrs_Client` (Task 22), tokens API (Task 30, via `Lrs_Client::api`), `Registration_Map` (Task 23), `Identity_Map` (Task 24), `Program_Repository` (Task 12), `Au` (Task 8), `Content_Host` (Task 40), Sensei adapter `progress`/enrolment (Task 31), `Uuid`, `Tokens`.
- `Session_Repository::open( int $user, string $au_id, string $registration, int $lesson, string $mode ): string` (session id); `open_for( int $user, string $au_id ): ?array`; `set_token( string $session, string $token_key )`; `set_fetch( string $session, string $fetch_hash, string $expires_at )`; `use_fetch( string $fetch_hash ): ?array` (atomic, unexpired, unused); `mark( string $session, string $status /* terminated|abandoned */, ?string $at = null )`; `expired_open( int $limit ): array`.
- `Lms_Statements` builders (actor, registration, AU activity id `{au@id}/au`, context template with publisher-id grouping, `sessionid`, cmi5 category, LMS authority): `launched( Au $au, array $actor, string $registration, string $session, string $mode, string $launch_url, ?string $launch_parameters ): array`; `abandoned( …, float $duration_seconds ): array`; `waived( Au $au, …, string $reason, bool $carried_over = false ): array`; `satisfied( string $object_id /* block or course id */, array $actor, string $registration, string $session ): array`. Statement ids are `Uuid::v5` of `registration|object|verb|session` so retries are idempotent.
- `Launch_Data::document( Au $au, string $registration, string $session, string $mode, string $return_url ): array` (`contextTemplate`, `launchMode`, `launchParameters`, `masteryScore`, `moveOn`, `returnURL`, `entitlementKey`).
- `POST /learning-ledger/v1/cmi5/launch` (logged-in, REST nonce) `{lesson, mode?}`: learner must be enrolled (Normal); `Review` only when the AU is satisfied; `Browse` only for users who can edit the lesson, in registration scope `preview:<program>:<user>` that never reaches Sensei. Steps: registration (`cmi5:<program>`; on first creation fire `learning_ledger/cmi5_registration_created`); abandon any open session for the AU (`abandoned` with duration, token revoked); new session; PUT `LMS.LaunchData`; create `cmi5LearnerPreferences` from `get_user_locale()` only with `If-None-Match: *`; POST `launched`; fetch token (32 random bytes, hash stored, 300 s); build the launch URL with `endpoint` (= `Connector_Settings::public_xapi_base()`), `fetch`, `actor`, `registration`, `activityId`, merged with the AU's own query string. Response `{launch_url, method: OwnWindow|AnyWindow}`.
- `POST /learning-ledger/v1/cmi5/fetch/{token}` (public; other methods 405): first use mints a child token via `POST tokens` (actor, registration, AU activity id, session, mode, TTL 28,800, CORS origin = AU origin or `null` in sandbox mode, guard on) and answers `{"auth-token": base64(key:secret)}`; reuse or expiry → `{"error-code":"1","error-text":"Already in use or expired"}`; other failures `"error-code":"2"`.
- `GET /learning-ledger/v1/cmi5/return/{session}` → `do_action( 'learning_ledger/cmi5_return', $session )` then a redirect to the lesson permalink.

- [ ] **Step 1: Write the failing tests** (LRS on the same wp-env install over loopback, with a connector client holding `tokens/mint`, `void`, `state`, `profile`, `statements/write`) — launch writes LaunchData with the exact keys, creates preferences once (an existing document is untouched), writes `launched` with the cmi5 context; launch URL parameters present and the endpoint is the public base; relaunch abandons the open session with an `abandoned` statement carrying `duration`; fetch works once and returns error-code 1 the second time and after 5 minutes; the minted token cannot write LaunchData and cannot void; not enrolled → 403; Browse by an editor uses a preview registration.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Launch_Test|Fetch_Test|Lms_Statements_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: cmi5 launch with LaunchData, one-time fetch and child tokens"`; push if remote.

### Task 54: Connector privacy and "My learning record"

**Files:**
- Create in `src/Connector/Privacy/`: `class-connector-privacy-module.php`, `class-connector-exporter.php`, `class-connector-eraser.php`, `src-js/my-record/{block.json,index.js,render.php}`; tests `tests/integration/Connector/Privacy/test-connector-privacy.php`, `test-my-record.php`

**Interfaces:**
- Consumes: `Identity_Map` (Task 24), ledgers and `Registration_Map` (Task 23), `Inbox_Repository` (Task 33), outbox table (Task 32), `Lrs_Client` (Task 22).
- WordPress exporter "Learning Ledger" (keyed by the user's email → user): identity links, actor, outbox rows, inbox rows, waivers, applied rows, plus statements fetched from the LRS for the user's actors (`GET statements?agent=` paged).
- Eraser: deletes those local rows, then `POST actors/erase` on the LRS for each linked actor and the site actor; LRS failure → retried job and a message in the eraser response.
- Block `learning-ledger/my-record` (logged-in only): the user's completed and tested-out lessons, scores from `Apply_Ledger`, and a button that asks the LRS for a view link (`POST view-links`) and redirects to it. No write path for learners.

- [ ] **Step 1: Write the failing tests** — exporter returns all six groups and the LRS statements (HTTP faked); eraser removes local rows and calls erase per actor; the block renders nothing for logged-out users and escapes output; the view-link button requires a REST nonce.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Connector_Privacy_Test|My_Record_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: connector GDPR exporter and eraser, and the My learning record block"`; push if remote.

### Task 55: Site Health checks and runtime report

**Files:**
- Create: `src/Kernel/class-site-health.php`, `tests/integration/Kernel/test-site-health.php`

**Interfaces:**
- Consumes: `Ed25519::backend`, `Secret_Box::key_source` (Task 6), `Key_Store::tier` (Task 21), `Statement_Repository::approx_count` (Task 26), `Roles` (Task 3), `Job_Queue::last_run`, `Shutdown_Runner` options (Task 15), `Content_Host` (Task 40), `Db_Requirements` (Task 4).
- Site Health tests (`site_status_tests`, direct): `lledger_sodium` (recommended when compat), `lledger_badge_key_storage` (recommended when `database`), `lledger_roles_together` (recommended when both roles are on and `approx_count()` > 1,000,000), `lledger_cron` (critical when the queue has not run for 10 minutes), `lledger_shutdown` (recommended when `fastcgi_finish_request` is unavailable), `lledger_content_host` (critical when the configured host is unsafe), `lledger_db` (critical when the LRS role is on and the database is too old). Async loopback tests: `lledger_authorization_header` (Basic header reaches PHP on `/xapi/statements`), `lledger_did_json` (`/.well-known/did.json` reaches WordPress; suggests path mode when not).
- About extension `runtime: { sodium, shutdown_last, shutdown_fastcgi, cron_last, queue_due }` (feeds the Spacefast probes in Task 70).

- [ ] **Step 1: Write the failing tests** — each test returns the right status for its forced condition; the about extension carries `runtime`.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter Site_Health_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: Site Health checks and runtime report"`; push if remote.

### Task 56: Sensei certificate provider and course-server certificate route

**Files:**
- Create: `integrations/sensei/class-sensei-certificate-provider.php`, `src/Connector/CourseServer/class-certificates-route.php`, tests `tests/integration/Integrations/Sensei/test-sensei-certificate-provider.php`

**Interfaces:**
- Consumes: `Certificate_Provider` (Task 9), `Signed_Request_Auth` (Task 45).
- First read Sensei LMS Certificates 2.6.0 (wordpress.org zip) for a public way to find a learner's certificate post for a course and render its PDF without the learner's session. `Sensei_Certificate_Provider::certificate_pdf( int $user, int $course ): ?string` returns PDF bytes, or null when the learner has none.
- `GET course-server/learners/{id}/certificates/{course}` (capability `read_certificates`) → `application/pdf`; 404 when none; 501 `certificate_unavailable` when Certificates is inactive or no public rendering path exists (report this to the controller as a finding, not a failure).

- [ ] **Step 1: Write the failing tests** (Certificates plugin added to the tests env through `.wp-env.override.json`) — completed course → PDF bytes starting `%PDF`; incomplete → 404; plugin inactive → 501; unsigned → 401.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter Sensei_Certificate_Provider_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS (or the documented 501 finding).
- [ ] **Step 5: Commit** — `git commit -m "feat: learner certificate PDFs for a course server"`; push if remote.

### Task 57: Claim links, claim page and signing (issuance)

**Files:**
- Create in `src/Badges/Claim/`: `class-claim-module.php`, `class-claim-links-controller.php`, `class-claim-page.php`, `class-claim-handler.php`, `class-credential-builder.php`, `class-earned-statement.php`, `templates/claim-page.php`; tests `tests/integration/Badges/Claim/test-claim-links.php`, `test-claim-handler.php`, `test-credential-builder.php`

**Interfaces:**
- Consumes: `Badge_Repository` (Task 52), `Link_Repository` (Task 51), `Status_List_Repository` (Task 35), `Key_Store`, `Did_Document` (Task 21), `Eddsa_Rdfc_2022` (Task 20), `Statement_Store` and `Principal::system()` (Tasks 26, 17), `Statement_Repository::find`, `Audit_Log`.
- `POST /learning-ledger/v1/badges/{id}/claim-links` (scope `badges/claim-links`) `{actor}`: actor must be the badge's actor and inside the binding; pending badges only (issued → 409); mints a `claim` link (single use, 900 s, newest wins) → `{url, expires_at}` with `url` = `home_url( '/badges/claim/<token>' )`.
- `Claim_Page`: rewrite `^badges/claim/([A-Za-z0-9_-]{43})/?$`; GET shows the achievement and an email form (and a "share publicly" checkbox); POST (with `wp_nonce_field` and the token) → `Claim_Handler::claim()`; the result page offers the JSON download, PNG and copy-link (Task 62 serves them). `no-store`, `noindex`.
- `Claim_Handler::claim( string $token, string $email, bool $share ): array` (credential) — consumes the link atomically; salt = 16 random bytes hex; `identityHash` = `'sha256$' . hash( 'sha256', $email . $salt )`; allocates a status index; builds, signs (active key), stores; status `issued`; writes `earned`; fires `learning_ledger/badge_issued`; audits. The email itself is never stored.
- `Credential_Builder::build( array $badge, array $achievement, string $did, string $issuer_name, string $identity_hash, string $salt, array $status_entry, string $evidence_narrative, \DateTimeImmutable $valid_from ): array` — `@context` [credentials v2, OB 3.0.3]; `id` `urn:uuid:<badge id>`; `type` `["VerifiableCredential","OpenBadgeCredential"]`; `issuer` `{id: did, type: ["Profile"], name}`; `validFrom`; `name`; `credentialSubject` `{type:["AchievementSubject"], identifier:[{type:"IdentityObject", identityHash, identityType:"emailAddress", hashed:true, salt}], achievement:{id, type:["Achievement"], name, description, criteria:{narrative}, image?, achievementType?, alignment?}}`; `evidence` `[{id:"urn:uuid:<trigger statement id>", type:["Evidence"], narrative, genre:"xAPI statement"}]`; `credentialStatus` the entry. No xAPI actor anywhere, hashed or not.
- `Earned_Statement::build( array $actor, string $registration, string $achievement_id, string $credential_id, string $trigger_id ): array` — verb `http://specification.openbadges.org/xapi/verbs/earned`, object the achievement as an Activity, `result.extensions["http://specification.openbadges.org/xapi/extensions/badgeassertion"]` = credential id, `context.statement` = StatementRef to the trigger, same registration; stored as `Principal::system()` (authority `learning-ledger`).
- Signing goes through filter `learning_ledger/badge_sign` (`array|string $signed, array $credential, Issuer_Key $key`); the default is `Eddsa_Rdfc_2022::sign()`, and Task 65 replaces it in VC-JWT mode.

- [ ] **Step 1: Write the failing tests** — minting needs scope and the right actor; minting again invalidates the first link; claim with a used or expired token → 410; claim produces a credential that `Eddsa_Rdfc_2022::verify` accepts against the did document; JSON contains neither the actor's account name nor its hash nor the email; `identityHash` recomputes from email+salt; `earned` stored with the badgeassertion extension and context statement; second claim impossible; `badge_issued` fired once; signing happens nowhere but `Claim_Handler` (grep test: no other caller of `Eddsa_Rdfc_2022::sign` outside `src/Badges/Status` and `src/Badges/Claim`).
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Claim_Links_Test|Claim_Handler_Test|Credential_Builder_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`; agent-browser run through the claim page on wp-env (open link, enter email, see download options). Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: claim links and claim-time signing of Open Badges 3.0 credentials"`; push if remote.

---

## Tasks 58–65: Revocation, sessions, cmi5-lesson block, admin screens, delivery, verification, fallback

### Task 58: Revocation and re-issue

**Files:**
- Create in `src/Badges/Revocation/`: `class-revocation-module.php`, `class-revoker.php`, `class-reissuer.php`, `class-revoke-command.php`; tests `tests/integration/Badges/Revocation/test-revoker.php`, `test-reissuer.php`

**Interfaces:**
- Consumes: `Badge_Repository` (Task 52), `Status_List_Repository` (Task 35), `Key_Store` (Task 21), `Eddsa_Rdfc_2022` (Task 20), hooks `learning_ledger/statement_voided` (Task 26) and `learning_ledger/actor_erased` (Task 44).
- `Revoker::revoke( string $badge_id, string $reason ): void` (issued → bit flipped and list re-signed, status `revoked`; pending/held → `revoked`; fires `learning_ledger/badge_revoked`; audit). Listeners: a voided trigger statement revokes its badges; an erased actor's badges are revoked and then their rows (hash, salt, credential) deleted.
- `Reissuer::reissue_for_key( string $removed_kid ): int` (re-signs every issued credential whose proof used that key with the active key, same `id`, newer `validFrom`).
- WP-CLI `wp learning-ledger badges revoke <id> [--reason=]`, `badges reissue --kid=<kid>`.

- [ ] **Step 1: Write the failing tests** — revoking flips exactly that bit and the status list still verifies; voiding the trigger revokes; erasing the actor revokes then deletes rows; reissue keeps the id and verifies against the new key, and old-key credentials no longer verify after `remove-key`.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Revoker_Test|Reissuer_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: badge revocation on void, erasure and admin request, and re-issue after key removal"`; push if remote.

### Task 59: cmi5 sessions, waivers and satisfaction on Sensei

**Files:**
- Create in `src/Player/Session/`: `class-session-module.php`, `class-abandoner.php`, `class-waive-controller.php`, `class-player-evaluator.php`; tests `tests/integration/Player/Session/test-abandoner.php`, `test-player-evaluator.php`, `test-waive-controller.php`

**Interfaces:**
- Consumes: `Session_Repository`, `Lms_Statements` (Task 53), `Token_Repository` via `Lrs_Client::api` (Tasks 30, 22), `Inbox_Repository`, `Inbox_Processor` (Task 33), hook `learning_ledger/connector_evaluated` (Task 33), `Waiver_Ledger` (Task 23), `Program_Repository` (Task 12), `Job_Queue` (Task 15).
- `Abandoner::run(): int` (every cron minute: sessions whose token expired without `terminated` → `abandoned` with duration, session `abandoned`).
- `POST /learning-ledger/v1/cmi5/waive` `{user, lesson, reason}` (user who can edit the lesson, or `lledger_manage_connector`; REST nonce) → `waived` statement with the reason; reasons `Tested Out`, `Equivalent AU`, `Equivalent Outside Activity`, `Administrative`.
- `Player_Evaluator` listens to `learning_ledger/connector_evaluated` for player programs: writes `satisfied` once per registration per newly satisfied block and for the course (`Lms_Statements::satisfied`, deterministic ids); for course-xapi-mcp packages with `_lledger_waive_recommended` on, an AU statement carrying the `waive-recommended` extension produces `waived` "Tested Out" for each listed AU not yet satisfied. On `learning_ledger/cmi5_return` it pulls the registration's statements (`GET statements?registration=`) into the inbox and processes them before the redirect. On `learning_ledger/cmi5_registration_created` it evaluates immediately so `NotApplicable` AUs are satisfied.
- Sensei completion comes from the existing inbox path (Task 33 + Task 46); this task adds only the LMS statements.

- [ ] **Step 1: Write the failing tests** — expired token without `terminated` → one `abandoned`; a waive request writes `waived` and the Sensei lesson completes with the reason in the ledger; a block satisfied by AU statements yields exactly one block `satisfied` even after repeated evaluation; `waive-recommended` → `waived` "Tested Out" for the listed AUs; a `NotApplicable` AU is satisfied at registration creation; the return route processes statements before redirecting.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter 'Abandoner_Test|Player_Evaluator_Test|Waive_Controller_Test'`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: cmi5 abandonment, waivers and satisfied statements for player courses"`; push if remote.

### Task 60: Block `learning-ledger/cmi5-lesson`

**Files:**
- Create: `src-js/blocks/cmi5-lesson/{block.json,index.js,edit.js,view.js,render.php}`, `tests/integration/Blocks/test-cmi5-lesson.php`

**Interfaces:**
- Consumes: launch route (Task 53), `Session_Repository`, `Waiver_Ledger`, Sensei adapter `progress()`/`course_tree()` (Task 31).
- Attribute `auId`. Front end: status (not started, in progress, satisfied, waived with reason) and buttons Launch (Normal), Review (when satisfied), Browse (lesson editors). `view.js` (`viewScriptModule`) calls the launch route with the REST nonce; `OwnWindow` → `window.location`; `AnyWindow` → an iframe in the lesson. Logged-out users see a sign-in prompt. Editor: a warning notice when the lesson has a pass-required Sensei quiz (`Lesson_Node::has_pass_required_quiz`).

- [ ] **Step 1: Write the failing tests** — render for not-enrolled, in-progress, satisfied and waived states; Browse button only for editors; escaped output; the editor warning flag in the block's REST-preloaded data.
- [ ] **Step 2: Run to verify failure** — `npm run build && composer test:integration -- --filter Cmi5_Lesson_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`; agent-browser: launch CATAPULT `001-essentials` from a lesson on wp-env and screenshot the AU running in the iframe and the satisfied status after it finishes. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: cmi5 lesson block with launch, review and browse"`; push if remote.

### Task 61: wp-admin screens

**Files:**
- Create in `src/Lrs/Admin/`: `class-admin-module.php`, `class-statements-screen.php`, `class-learner-screen.php`, `class-clients-screen.php`, `class-subscriptions-screen.php`, `class-badges-screen.php`, `class-erase-screen.php`, `class-connector-screen.php`; tests `tests/integration/Lrs/Admin/test-admin-screens.php`

**Interfaces:**
- Consumes: `Statement_Query` (Task 38), `Client_Repository` (Task 17), `Subscription_Repository` (Task 43), `Badge_Repository` (Task 52), `Revoker` (Task 58), `Actor_Eraser` (Task 44), `Integration_State` (Task 9), `Apply_Ledger::flagged` (Task 23).
- A "Learning Ledger" menu: Statements (filters agent/verb/activity/registration, 50 per page, `lledger_view_records`, JSON escaped in `<pre>`), Learner (per-actor view), Clients (list, revoke; `lledger_manage`), Subscriptions (status, delete), Badges (list, revoke, held cases; `lledger_manage_badges`), Erase actor (form, confirmation step; `lledger_manage`), Connector (pairing state, paused integrations with "confirm major version", flagged voided results; `lledger_manage_connector`). Every action uses `admin-post.php` with a nonce and a capability check; screens appear only for enabled roles.

- [ ] **Step 1: Write the failing tests** — each action without nonce → `wp_die`; without capability → `wp_die`; with both → effect happens; statement JSON containing `</pre><script>` renders escaped.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter Admin_Screens_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces (WP list tables, no custom JS frameworks).
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`; agent-browser screenshots of each screen on wp-env. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: wp-admin screens for records, clients, subscriptions, badges, erasure and connector state"`; push if remote.

### Task 62: Badge delivery (downloads, baked PNG, public page, achievement documents)

**Files:**
- Create in `src/Badges/Delivery/`: `class-delivery-module.php`, `class-png-baker.php`, `class-badge-page.php`, `class-achievement-controller.php`, `templates/badge-page.php`, `resources/badges/default-badge.png`; tests `tests/unit/Badges/test-png-baker.php`, `tests/integration/Badges/Delivery/test-badge-page.php`

**Interfaces:**
- Consumes: `Badge_Repository` (Task 52), `Claim_Page` result (Task 57).
- `Png_Baker::bake( string $png, string $credential_json ): string` (inserts an uncompressed `iTXt` chunk with keyword `openbadgecredential` before `IEND`, CRC correct); `Png_Baker::extract( string $png ): ?string`. The image is the achievement image when it is a media attachment on this site, else the bundled default (no outbound fetch).
- Public page `/badges/<uuid>` (rewrite) only when `shared` = 1 (else 404): achievement, issuer, issued date, "Copy link" for LinkedIn; `/badges/<uuid>.json` and `/badges/<uuid>.png` likewise. The claim result page links the same downloads with a short-lived signed parameter so unshared badges can still be downloaded once by their claimant (HMAC over id + expiry, 15 minutes).
- `GET /learning-ledger/v1/achievements/{hash}` → the Achievement JSON from the declaration, cacheable (`max-age=3600`), public.

- [ ] **Step 1: Write the failing tests** — bake/extract round trip, CRC valid (PNG decodes with `imagecreatefromstring`); unshared badge page 404, shared 200 with escaped fields; signed download works within 15 minutes and fails after; achievement route returns the declaration fields only.
- [ ] **Step 2: Run to verify failure** — `composer test:unit -- --filter Png_Baker_Test && composer test:integration -- --filter Badge_Page_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`; agent-browser screenshot of a shared badge page. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: badge JSON and baked PNG downloads and an opt-in public badge page"`; push if remote.

### Task 63: Differential badge verification in Node (CI only)

**Files:**
- Create: `bin/dev-sign-corpus.php` (WP-CLI-run script, `LLEDGER_TESTING` only), `tests/fixtures/badges/corpus.json` (every Achievement of the prototype's three paths and their lessons, plus Unicode names, a 5,000-character narrative, alignment and result fields), `tests/node/verify-badges.test.mjs`, `tests/node/document-loader.mjs`, `.github/workflows/badges-interop.yml`

**Interfaces:**
- Consumes: the whole issuance path (Tasks 20, 21, 35, 57).
- `bin/dev-sign-corpus.php <out dir>` writes `credentials/*.json`, `did.json`, `status-lists/*.json` signed by the plugin.
- `verify-badges.test.mjs`: for each credential, verify with `@digitalbazaar/vc` + `eddsa-rdfc-2022-cryptosuite` using a loader that serves the bundled contexts and the written did.json; re-canonicalise with `jsonld` and compare to `Canonicalizer::canonicalize` output dumped by the PHP script; verify each status list credential. Node never runs at plugin runtime.
- `badges-interop.yml`: on push to `main` touching `src/Badges/**` and nightly: wp-env, run the script, `npm run test:node`.

- [ ] **Step 1: Write the failing test** — the Node test over an empty corpus directory fails with "no credentials".
- [ ] **Step 2: Run to verify failure** — `npm run test:node`. Expected: FAIL.
- [ ] **Step 3: Implement** the script, loader and workflow.
- [ ] **Step 4: Run to verify pass** — `npx wp-env run cli wp eval-file wp-content/plugins/learning-ledger/bin/dev-sign-corpus.php /tmp/corpus` (local only; never on Spacefast) then `LLEDGER_CORPUS=… npm run test:node`. Expected: every credential and list verifies; canonical forms identical. Any mismatch is a release blocker for badges.
- [ ] **Step 5: Commit** — `git commit -m "test: differential verification of PHP-signed badges with Digital Bazaar"`; push if remote.

### Task 65: VC-JWT fallback switch (degraded mode)

**Files:**
- Create in `src/Badges/Jwt/`: `class-vc-jwt-signer.php`, `class-rsa-key-store.php`; tests `tests/integration/Badges/Jwt/test-vc-jwt-signer.php`

**Interfaces:**
- Consumes: Strauss-prefixed `firebase/php-jwt`, `Key_Store` tiers pattern (Task 21), `Did_Document` (Task 21).
- Option `lledger_badge_format` = `di` (default) or `vc-jwt`. `Rsa_Key_Store::active(): array{kid,private_pem,public_jwk}` (RSA 2048 via `openssl_pkey_new`, same storage tiers, purpose `badge-rsa`). `Vc_Jwt_Signer::sign( array $credential ): string` (RS256, header `kid` = `<did>#<kid>`, payload claims per OB 3.0 §8.2: `iss`, `jti`, `nbf`, `sub`, `vc`). When `vc-jwt` is on, `Did_Document` also publishes the RSA key as a `JsonWebKey` verification method, `Claim_Handler` stores the JWT, and Site Health reports the degraded mode (passes 1EdTech, not LCW). WP-CLI `badges format <di|vc-jwt>`.
- Hooks in through the filters `learning_ledger/badge_sign` (Task 57) and `learning_ledger/did_verification_methods` (Task 21); it edits no other task's files.

- [ ] **Step 1: Write the failing tests** — with `vc-jwt`, a claim yields a JWT that `JWT::decode` verifies with the published JWK; with `di` nothing changes; Site Health shows the degraded notice.
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --filter Vc_Jwt_Signer_Test`. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: VC-JWT RS256 fallback switch for badges"`; push if remote.

---

## Tasks 64, 66: Conformance gates

### Task 64: CATAPULT LMS Test Suite adapter and gate

**Files:**
- Create: `src/Player/Testing/class-catapult-routes.php`, `tests/catapult/adapter.js` (replace the stub), `conformance/raw/catapult-*.json` (results), `conformance/known-gaps.json` (modify: cmi5 entries)
- Modify (fixes only, in player files): `src/Player/**`, `src-js/blocks/cmi5-lesson/**`, `src/Lrs/Cmi5/class-cmi5-guard.php`

**Interfaces:**
- Consumes: Tasks 49, 50, 53, 59, 39.
- `Catapult_Routes` (only when `LLEDGER_CATAPULT_TEST_ROUTES` is true and header `X-Learning-Ledger-Test` equals constant `LLEDGER_CATAPULT_TEST_SECRET`): `POST testing/catapult/setup` (creates a learner, enrols), `POST testing/catapult/import` (path of a CATAPULT package → `Player_Importer`), `POST testing/catapult/launch-url` (`{course, au, user}` → launch URL via the launch code path, no nonce), `POST testing/catapult/waive`, `POST testing/catapult/abandon`, `POST testing/catapult/cleanup`.
- `adapter.js` implements `setup`, `teardown`, `getLrsEndpoint`, `getLrsAuthHeader`, `importCourse`, `getLaunchUrl`, `cleanup`, `hasWaive`/`waiveAU`, `hasAbandon`/`abandonSession` against those routes.

- [ ] **Step 1: Run the suite to get the failing baseline** — `npx wp-env start` with `.wp-env.override.json` setting `LLEDGER_CATAPULT_TEST_ROUTES`, `LLEDGER_CATAPULT_TEST_SECRET`, `LLEDGER_CMI5_CONTENT_HOST=127.0.0.1:8888`; `composer conformance:catapult -- --site http://localhost:8888 --content-host http://127.0.0.1:8888 …`. Expected: failures listed.
- [ ] **Step 2: Fix each failure** in the owning player file with a regression test in that file's test class, one commit per fix (`fix(player): …`).
- [ ] **Step 3: Run again in content-host mode and in sandbox fallback mode** (no content host). Expected: all LTS tests pass in content-host mode; fallback failures only for AUs that need same-origin storage, each listed in `known-gaps.json`.
- [ ] **Step 4: Write `conformance/SUMMARY.md` section** with `bin/summarise-conformance.php`, show the diff to the owner, wait for approval.
- [ ] **Step 5: Commit** — `git add conformance src/Player tests/catapult && git commit -m "test: CATAPULT LMS Test Suite passes for the Sensei cmi5 player"`; push if remote.

### Task 66: ADL LRS conformance gate

**Files:**
- Modify (fixes only): `src/Lrs/**` except `src/Lrs/Cmi5/` (Task 64 owns the guard in this wave), `src/Xapi/**`; `conformance/raw/lrs-1.0.3-*.json`, `conformance/raw/lrs-2.0.x-*.json`, `conformance/known-gaps.json`, `conformance/SUMMARY.md`

**Interfaces:**
- Consumes: the whole LRS role and `bin/conformance-lrs.sh` (Task 2).

- [ ] **Step 1: Run the suite** — wp-env, LRS role on, a client with `all,void,state,profile,define`; `composer conformance:lrs -- --xapi 1.0.3 …` and `--xapi 2.0.x`. Expected: a list of failing requirement ids.
- [ ] **Step 2: Fix each failure** in its owning class with a regression test named after the requirement id, one commit per fix (`fix(lrs): <requirement id> …`). The only accepted remaining failures are multipart attachments (§6.9) and anything the owner approves; each gets a reason in `known-gaps.json`.
- [ ] **Step 3: Re-run both versions.** Expected: every cmi5-relevant requirement (Statements with voiding and queries, State, Agent Profile, About, auth and headers) passes; this is the wordpress.org submission gate (§6.9, §14).
- [ ] **Step 4: Regenerate `conformance/SUMMARY.md`**, show the diff to the owner, wait for approval.
- [ ] **Step 5: Commit** — `git add conformance src && git commit -m "test: ADL LRS conformance results for xAPI 1.0.3 and 2.0"`; push if remote.

---

## Tasks 67–69: Later LMS integrations, load test, release packaging

The three LMS tasks share one shape and touch only their own folder and tests. Each reads the LMS's wordpress.org zip at the pinned version first, confirms the hook and function signatures in §9.4, and runs the shared contract tests.

### Task 67a: LifterLMS integration (10.3.2)

**Files:**
- Create: `integrations/lifterlms/bootstrap.php`, `class-lifterlms-adapter.php`, `class-lifterlms-applier.php`, `class-lifterlms-listeners.php`, `class-lifterlms-iris.php` (all in `integrations/lifterlms/`), `tests/integration/Integrations/Lifterlms/test-lifterlms-contract.php`

**Interfaces:**
- Consumes: Task 9 contract, Tasks 12, 23, 24.
- Descriptor `lifterlms`, available when `function_exists( 'llms' )` and version ≥ 10.0, tested up to `10.3`. Apply via `llms_mark_complete( $user, $object, $type, 'learning-ledger' )` (the trigger string is also the echo-guard label); waived = complete + `Waiver_Ledger`. Listeners: `lifterlms_lesson_completed`, `lifterlms_section_completed`, `lifterlms_course_completed`, `lifterlms_quiz_completed`, plus enrolment hooks. Upsert courses/lessons as `course`/`lesson` posts through LifterLMS's public model classes. Post types registered through the same filters as Task 31.

- [ ] **Step 1: Write the failing contract test** (`@group lifterlms-contract`, LifterLMS 10.3.2 added to the tests env via `.wp-env.override.json`).
- [ ] **Step 2: Run to verify failure** — `composer test:integration -- --group lifterlms-contract`. Expected: FAIL.
- [ ] **Step 3: Implement** the adapter.
- [ ] **Step 4: Run to verify pass** — same plus `composer lint`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: LifterLMS integration"`; push if remote.

### Task 67b: Tutor LMS integration (3.9.7)

Same shape as 67a in `integrations/tutor/` (`class-tutor-adapter.php` etc.), group `tutor-contract`. Descriptor `tutor`, available when `function_exists( 'tutor' )` and `TUTOR_VERSION` ≥ 3.0, tested up to `3.9`. Apply via `\Tutor\Models\LessonModel::mark_lesson_complete( $lesson, $user )`; listeners `tutor_lesson_completed_after`, `tutor_mark_lesson_complete_after`, `tutor_course_complete_after`, `tutor_quiz/attempt_ended`, `tutor_after_enrolled`. Steps 1 to 5 as in 67a with `--group tutor-contract` and commit `feat: Tutor LMS integration`.

### Task 67c: LearnPress integration (4.4.12)

Same shape in `integrations/learnpress/`, group `learnpress-contract`. Descriptor `learnpress`, available when `class_exists( 'LearnPress' )` and `LEARNPRESS_VERSION` ≥ 4.2, tested up to `4.4`. Apply via `LP_User::complete_lesson( $lesson, $course )` and `finish_course( $course )` on `learn_press_get_user( $user )`; listeners `learn-press/user-completed-lesson`, `learn-press/user-course-finished`, `learn-press/user/quiz-finished`. Steps as in 67a with `--group learnpress-contract` and commit `feat: LearnPress integration`.

### Task 68: Load test against the §11 targets

**Files:**
- Create: `src/Lrs/Testing/class-seed-command.php`, `tests/load/ingest.js`, `tests/load/query.js`, `tests/load/state.js`, `bin/load-test.sh`, `conformance/load-<YYYYMMDD>.json` (results)

**Interfaces:**
- `wp learning-ledger dev seed --statements=<n> [--actors=] [--registrations=]` (bulk inserts through `Statement_Store` in batches of 500). `Seed_Command` is the manifest module `Lrs\Testing\Seed_Command` (role `lrs`); its `register()` returns early unless `LLEDGER_TESTING` is true.
- k6 scenarios: ingest 50 statements/s sustained and 500/s for 10 s (batched POST); single POST p95 < 150 ms; filtered GET (agent + registration, activity + since, limit 100) p95 < 300 ms at 10 M rows; `related_*` p95 < 800 ms; State GET/PUT p95 < 80 ms; connector page view overhead < 2 ms (one INSERT, measured with Server-Timing on a Sensei lesson completion).

- [ ] **Step 1: Seed** 10,000,000 statements on a 2 vCPU / 4 GB MySQL 8 host (not wp-env's MariaDB for the headline run) and record the time.
- [ ] **Step 2: Run** `bin/load-test.sh` and write the k6 summary JSON.
- [ ] **Step 3: Fix** any target missed by more than 20% in its owning class (index or query shape), with a regression note in the commit; otherwise report the measured values to the owner for resetting the targets (§11 says they are reset after the first load test).
- [ ] **Step 4: Re-run** and record.
- [ ] **Step 5: Commit** — `git commit -m "test: load test results against the scale targets"`; push if remote.

### Task 69: Release packaging and wordpress.org readiness

**Files:**
- Create: `readme.txt`, `bin/build-zip.sh`, `.github/workflows/release.yml`, `.distignore`

**Interfaces:**
- `readme.txt`: tagline; requirements (PHP 8.2, WP 6.6, MySQL 8.0 / MariaDB 10.6 for the LRS role); setup by WP-CLI with no secrets; the Authorization-header note for hosts that strip it; "External services" (only the LRS URL, webhook URLs and course-server URLs the admin configures; no tracking); integrations (Sensei, LifterLMS, Tutor LMS, LearnPress named only as integrations); conformance links to `conformance/SUMMARY.md` with the failing ids; the 1EdTech attribution line; GPL-2.0-or-later; bundled library licences.
- `bin/build-zip.sh <version>` → `dist/learning-ledger-<version>.zip` (`composer install --no-dev`, `composer prefix`, `npm ci && npm run build`, files filtered by `.distignore`; vendor sources kept readable).
- `release.yml` on tags `v*`: build, run Plugin Check (`wp plugin check learning-ledger`) in wp-env, attach the zip to a GitHub release. A second job deploys to wordpress.org SVN only when repository variable `WPORG_DEPLOY` is `true` (set by the owner after Task 66 passes) using secrets `SVN_USERNAME`/`SVN_PASSWORD`.

- [ ] **Step 1: Write the failing check** — `bin/build-zip.sh 0.1.0 && unzip -l dist/learning-ledger-0.1.0.zip` must contain `learning-ledger.php`, `vendor-prefixed/`, `build/blocks/` and no `tests/`, `node_modules/`, `.github/`; Plugin Check reports no errors.
- [ ] **Step 2: Run to verify failure** — the script does not exist yet. Expected: FAIL.
- [ ] **Step 3: Implement** the script, `.distignore`, workflow and readme. Show the `readme.txt` diff to the owner and wait for approval.
- [ ] **Step 4: Run to verify pass** — the Step 1 check plus `npx wp-env run cli wp plugin check learning-ledger`. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "chore: release packaging, readme and release workflow"`; push if remote.

---

## Task 70: Spacefast deployment

### Task 70: Deploy to Spacefast (controller step, needs owner)

This is not a subagent task. The controller runs it with the owner present, because it needs Spacefast access, DNS for `lrs.sqwrlz.com`, and secrets that only the owner's machine may hold. No secret ever goes through WP-CLI: every command below carries only hashes, public keys, URLs or names.

**Prerequisites:** Tasks 1 to 69 merged (including 67a to 67c); a release zip from Task 69; owner has created the LRS Space (public), pointed `lrs.sqwrlz.com` at it, and has the prototype's Space A (`learn-wp-reimagined`).

- [ ] **Step 1: Owner generates credentials locally** — course-server LRS secret (`openssl rand -hex 32`, kept as Spacefast secrets `LRS_KEY`/`LRS_SECRET` in Space A) and its SHA-256; course-server Ed25519 key pair (private key as Spacefast secret `CONNECTOR_SIGNING_KEY`, public key base64 for the connector).
- [ ] **Step 2: Install on the LRS Space** — publish the zip as a file of the Space, then `POST /v1/spaces/{id}/wp-cli` with `["plugin","install","<share-link zip URL>","--activate","--force"]`, then `["learning-ledger","role","enable","lrs"]`, `["learning-ledger","badges","keygen"]`, `["learning-ledger","badges","issuer-name","Learn WordPress, reimagined"]`, `["learning-ledger","client","create","--id=course-server","--secret-sha256=<hash>","--scopes=all,void,courses/write,tokens/mint,badges/issue,badges/claim-links,records/view-links,actors/erase","--bind-actor-home-page=https://learn-wp-reimagined.space.fast","--trusted"]`. After each, read `GET https://lrs.sqwrlz.com/wp-json/learning-ledger/v1/status` (WP-CLI returns no output).
- [ ] **Step 3: Probe the unverified items (§12.1)** in this order, stopping at a blocker: `Authorization: Basic` reaches PHP (`GET /xapi/statements` with the course-server credential → 200, not 401/403 from the platform); `dbDelta` tables exist (status `schema_version`); sodium (`/about` `runtime.sodium`); `/.well-known/did.json` on `lrs.sqwrlz.com` (else `badges did-mode path` and test `/issuer/did.json`; the issuer must be settled before the first badge); WP-Cron and shutdown timestamps in `/about` `runtime` (else a Spacefast cron hitting `/wp-json/learning-ledger/v1/cron` with a secret registered by hash via `["learning-ledger","cron","secret","--sha256=<hash>"]`); outbound HTTP (a test subscription to a request bin); wp-admin reachability. Use `agent-browser` for any page checks.
- [ ] **Step 4: Install on Space A** — `["plugin","install","sensei-lms","--version=4.26.4","--activate"]`, `["plugin","install","sensei-certificates","--version=2.6.0","--activate"]`, the Learning Ledger zip, `["learning-ledger","role","enable","connector"]`, `["learning-ledger","connector","connect","--lrs=https://lrs.sqwrlz.com/xapi/","--client=learn-site"]`; read Space A's status route as an Application Password admin to get `secret_sha256`; on the LRS Space `["learning-ledger","client","create","--id=learn-site","--secret-sha256=<that hash>","--scopes=statements/read,actors/erase,records/view-links,badges/claim-links","--bind-actor-home-page=https://learn-wp-reimagined.space.fast"]`; `["learning-ledger","subscription","create","--url=https://learn-wp-reimagined.space.fast/wp-json/learning-ledger/v1/inbox","--actor-home-page=https://learn-wp-reimagined.space.fast"]`; on Space A `["learning-ledger","connector","trust-key","--id=course-server","--public-key=<base64>","--capabilities=create_learners,enrol,read_progress,delete_learners,read_packages,import_packages,read_certificates"]`.
- [ ] **Step 5: Verify end to end and record** — package import by share-link URL (`["learning-ledger","package","import","<url>"]`), a signed learner creation from the course server, a statement written by the course server visible in Sensei within 90 s, a badge claimed and verified in the 1EdTech validator (https://vc.1ed.tech), VerifierPlus and an LCW import (agent-browser for the web validators). Optional for the player on Spacefast: import and launch a CATAPULT package (AU streaming and a second hostname are unverified there). Write the probe outcomes into the prototype's spike notes for the owner; nothing in this repo is committed in this task unless a fix is needed, which goes back through a normal task.
