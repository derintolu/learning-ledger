=== Learning Ledger ===
Contributors: derintolu
Tags: xapi, lrs, cmi5, open badges, elearning
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.7
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An xAPI LRS and Open Badges issuer for WordPress, with LMS connectors

== Description ==

Learning Ledger keeps learning records in WordPress. It has two roles, which you enable separately and can run on the same site or on different sites:

* **LRS.** An xAPI Learning Record Store that speaks xAPI 1.0.3 and 2.0 on one endpoint, `/wp-json/learning-ledger/xapi/` (also reachable at `/xapi/`). It manages clients and scopes, sends signed webhooks for new statements, and issues Open Badges 3.0 credentials (signed with Ed25519, `eddsa-rdfc-2022`) when a learner satisfies a course or block.
* **Connector.** Connects an LMS site to an LRS. It sends LMS progress to the LRS as xAPI statements, applies statements from the LRS back to the LMS, plays cmi5 courses, imports and exports course packages, and adds blocks for lessons (Beat, Checkpoint, Rubric, Planted bug, cmi5 lesson, Link my record, My learning record).

Records are kept in the plugin's own tables, never in posts or post meta. Every setting can be made with WP-CLI, and no secret ever has to be typed into WP-CLI or printed by it.

= Integrations =

The connector role works with these LMS plugins through integrations:

* Sensei LMS
* LifterLMS
* Tutor LMS
* LearnPress

Learning Ledger is not affiliated with or endorsed by the makers of these plugins.

= Requirements =

* PHP 8.2 or later.
* WordPress 6.6 or later.
* For the LRS role: MySQL 8.0 or later, or MariaDB 10.6 or later. Enabling the LRS role on an older database fails with an error that says so. The connector role needs nothing beyond what WordPress needs.
* Pretty permalinks (Settings > Permalinks, any setting but Plain). The `/xapi/` alias, badge claim and badge pages, record pages, the issuer's `/.well-known/did.json` and the cmi5 player's sandboxed course frame are rewrite rules, which plain permalinks do not serve.
* For badges, WordPress installed at the root of its domain. The issuer is `did:web:<host>`, which verifiers resolve at `https://<host>/.well-known/did.json` (or `https://<host>/issuer/did.json` after `wp learning-ledger badges did-mode path`); a site in a subdirectory, such as `https://example.org/blog/`, cannot serve either address, so did:web badges are not supported there.

== Installation ==

1. Install and activate the plugin: `wp plugin install learning-ledger --activate`, or upload the zip under Plugins > Add New.
2. Enable the roles you need and set them up with WP-CLI, as below. The Learning Ledger menu in wp-admin shows records, clients, subscriptions, badges and the connector's state.

= LRS site =

    wp learning-ledger role enable lrs

Create a client for each xAPI tool. Generate its secret on your own machine and give WordPress only the SHA-256 of it:

    openssl rand -hex 32 | tr -d '\n' > lrs-secret
    sha256sum lrs-secret | cut -d' ' -f1
    wp learning-ledger client create --id=my-tool --secret-sha256=<the hash> --scopes=statements/write,statements/read/mine

The tool then signs in with HTTP Basic auth, using the client id as the username and the contents of `lrs-secret` as the password.

Create the badge signing key (only its public key is ever shown, in the site's `did.json`) and set the issuer name:

    wp learning-ledger badges keygen
    wp learning-ledger badges issuer-name "My Academy"

Send new statements to a webhook:

    wp learning-ledger subscription create --target=https://hooks.example.org/xapi

Revoke a badge (its status list entry is flipped, so shared copies stop verifying), choose the badge proof format, and re-issue the badges a removed key signed:

    wp learning-ledger badges revoke <badge id> --reason="Issued in error"
    wp learning-ledger badges format di
    wp learning-ledger badges remove-key <kid>
    wp learning-ledger badges reissue --kid=<kid>

`badges format` takes `di` (the default, Data Integrity proofs) or `vc-jwt`. `badges remove-key` first moves the status lists onto the active key, so run `badges rotate` before removing the only key.

If the LRS is behind a reverse proxy or CDN, every client reaches it from the proxy's address, so ten failed sign-ins within a minute, from any of them, lock out all of them. Return the real client address from the `learning_ledger/client_ip` filter, which receives `REMOTE_ADDR` and `$_SERVER`. Trust a forwarded header only when the request came from your own proxy, and take the entry that proxy appended (the rightmost one), never the leftmost, which the client supplies and can rotate to dodge the lockout:

    add_filter( 'learning_ledger/client_ip', function ( $ip, $server ) {
        if ( '10.0.0.5' === $ip && isset( $server['HTTP_X_FORWARDED_FOR'] ) ) {
            $hops = array_map( 'trim', explode( ',', $server['HTTP_X_FORWARDED_FOR'] ) );
            return end( $hops );
        }
        return $ip;
    }, 10, 2 );

Behind Cloudflare, return `$server['HTTP_CF_CONNECTING_IP']` instead (only when `REMOTE_ADDR` is a Cloudflare address), since Cloudflare overwrites that header.

= Connector site =

    wp learning-ledger role enable connector
    wp learning-ledger connector connect --lrs=https://lrs.example.org/xapi/ --client=my-lms

`connector connect` pins the LRS's notification key and generates this site's secret, which stays on the site. An administrator reads its SHA-256 from the `connector.secret_sha256` field of `GET /wp-json/learning-ledger/v1/status` (sign in with an Application Password), and the LRS administrator registers it, bound to the connector site's own learners:

    wp learning-ledger client create --id=my-lms --secret-sha256=<the hash> --scopes=statements/read,actors/erase,records/view-links --bind-actor-home-page=https://lms.example.org

Add scopes for what the connector does beyond reading; `client update` replaces the scope list. Courses that send LMS progress to the LRS (`wp learning-ledger course direction2 <course id> on`) need `statements/write`, limited to the connector's activity IRIs, which start with `https://lms.example.org/activities/` unless the course comes from a package:

    wp learning-ledger client update my-lms --scopes=statements/read,actors/erase,records/view-links,statements/write --write-activity-prefix=https://lms.example.org/activities/

The cmi5 player also needs `tokens/mint`, `void`, `state` and `profile`, and `--write-activity-prefix` must list the IRI base of each player course as well (comma-separated):

    wp learning-ledger client update my-lms --scopes=statements/read,actors/erase,records/view-links,statements/write,tokens/mint,void,state,profile --write-activity-prefix=https://lms.example.org/activities/,https://courses.example.org/json-basics/

Then, on the LRS, subscribe the connector's inbox to its learners' statements, so progress recorded elsewhere reaches the LMS:

    wp learning-ledger subscription create --target=https://lms.example.org/wp-json/learning-ledger/v1/inbox --actor-home-page=https://lms.example.org

To let a course server call the connector's signed routes, trust its Ed25519 public key (only the public key crosses WP-CLI):

    wp learning-ledger connector trust-key --id=course-server --public-key=<base64 public key>

Set a separate content host for cmi5 courses with `wp learning-ledger cmi5 content-host <host>`; without one, the player runs courses in a sandboxed frame.

= Uninstalling =

Deleting the plugin keeps its tables and settings, so records survive a reinstall. To remove them too, add `define( 'LLEDGER_UNINSTALL_DELETE_DATA', true );` to `wp-config.php` before deleting the plugin.

== Frequently Asked Questions ==

= My xAPI client gets 401 "Authorization header missing" =

Some hosts strip the `Authorization` header before PHP sees it: PHP-FPM or CGI without `CGIPassAuth`, and some nginx configurations. The LRS then cannot read Basic credentials and answers 401 with "Authorization header missing (some hosts strip it; see readme)". Tools > Site Health runs a check named "Learning Ledger: Authorization header" that tells you whether your server does this.

To fix it, pass the header through to PHP:

* Apache 2.4.13 or later: add `CGIPassAuth On` to the site's configuration or to `.htaccess`.
* Older Apache: add `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` to `.htaccess` above the WordPress rules.
* nginx with PHP-FPM: add `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` to the PHP location block.

The plugin reads the header from `HTTP_AUTHORIZATION` and from `REDIRECT_HTTP_AUTHORIZATION`. It never edits `.htaccess` itself.

= Sensei progress never shows on my host, although the LRS delivers it =

Some hosts empty WordPress's comment queries, and Sensei LMS keeps lesson progress in comments. The signed course-server status shows this in `host_queries` and `sensei_progress_storage`. Sensei 4.26 can read progress from its own tables instead (High-Performance Progress Storage). On the connector site, run `wp learning-ledger sensei storage status`, then `wp learning-ledger sensei storage tables`. If Sensei already holds progress in comments, the command refuses; `--force` starts Sensei's own migration of that progress, and you run `tables` again once `status` shows `migration: complete`. If Sensei's tables already hold progress that the switch would drop or empty, it refuses unless you add `--drop-tables`, which destroys those rows. The way back is `wp learning-ledger sensei storage comments`: Sensei reads comments again and keeps writing both.

= Does the plugin phone home or track anything? =

No. See "External services" below.

== External services ==

Learning Ledger connects only to addresses that the site's administrator configures. It contains no tracking, analytics or telemetry, loads no remote scripts, styles or fonts, and fetches nothing else at runtime (the JSON-LD contexts used for badges are bundled). Each of these services is run by you or by whoever runs the site at that address, under their own terms and privacy policy.

* **The LRS URL** (connector role), set with `wp learning-ledger connector connect --lrs=<url>`. The connector reads the LRS's `/about` document when pairing. It then sends xAPI statements for LMS progress (the learner's account actor, which is the site's URL plus a user identifier, and the activity, result and timestamps), reads statements for its learners, and asks the LRS for record view links, launch tokens for cmi5 courses and the erasure of a learner's records when a WordPress personal-data erasure runs. Requests carry the connector's client credentials.
* **Webhook URLs** (LRS role), set with `wp learning-ledger subscription create --target=<url>`. When statements matching a subscription are stored, the LRS sends them in signed batches (up to 100 statements, Ed25519 signature) to that URL.
* **Course-server URLs** (connector role), set on a program. The connector sends a signed request to `<course server>/link-codes/redeem` with the one-time code a learner enters to link their record, and to `<course server>/content-changed` with the program key, version and ETag when a program's content changes. When an administrator or a trusted course server imports a course package or a cmi5 package, the connector downloads it from the https URL given for that import.

Site Health also sends one request to the site's own `/xapi/statements` URL to check that the Authorization header arrives.

== Conformance ==

Results of the ADL xAPI LRS conformance suite (xAPI 1.0.3 and 2.0) and the cmi5 CATAPULT LMS test suite are in the repository at https://github.com/derintolu/learning-ledger/blob/main/conformance/SUMMARY.md, with the raw results beside it. The failing tests in this release, each with its reason in `conformance/known-gaps.json`:

* ADL LRS suite: XAPI-00117, XAPI-00127, XAPI-00154, XAPI-00161, XAPI-00164 and XAPI-00167, and the 2.0 test "When comparing Durations (or Statements containing them), any precision beyond 0.01 second precision shall not be included in the comparison", which sends a signed statement. All of them need multipart/mixed attachments, which this release does not support: statements with `fileUrl` attachments in `application/json` are accepted, and multipart/mixed requests get 400 with a clear message.
* CATAPULT: test 101 (`101-one-thousand-aus.xml`, package import). Importing a package with 1,000 AUs takes about 23 seconds, longer than the suite's fixed 5-second timeout.

== Credits and licences ==

Open Badges is a standard of 1EdTech Consortium, Inc. Learning Ledger implements Open Badges 3.0 and acknowledges 1EdTech as the specification's licence requires. Learning Ledger is not certified by or affiliated with 1EdTech.

Learning Ledger is licensed under the GPL, version 2 or later (GPL-2.0-or-later).

Bundled PHP libraries, in `vendor-prefixed/` with their sources and licence files, renamed into the `LearningLedger\Vendor\` namespace:

* accredifysg/php-json-ld 2.2.1: MIT
* accredifysg/php-rdf-canonicalize 1.0.0: MIT
* dflydev/dot-access-data 3.0.3: MIT
* firebase/php-jwt 7.2.1: BSD-3-Clause
* league/commonmark 2.10.4: BSD-3-Clause
* league/config 1.2.0: BSD-3-Clause
* nette/schema 1.4.0: BSD-3-Clause (offered under BSD-3-Clause, GPL-2.0 or GPL-3.0; used under BSD-3-Clause)
* nette/utils 4.1.6: BSD-3-Clause (offered under BSD-3-Clause, GPL-2.0 or GPL-3.0; used under BSD-3-Clause)
* opis/json-schema 2.6.0: Apache-2.0
* opis/string 2.1.0: Apache-2.0
* opis/uri 1.1.0: Apache-2.0
* paragonie/sodium_compat 2.5.2: ISC
* psr/event-dispatcher 1.0.0: MIT
* psr/http-client 1.0.3: MIT
* psr/http-factory 1.1.0: MIT
* psr/http-message 2.0: MIT
* symfony/deprecation-contracts 3.7.1: MIT
* symfony/polyfill-php80 1.43.0: MIT

Bundled data files, unmodified:

* `resources/cmi5/CourseStructure.xsd`: the cmi5 course structure schema from https://github.com/AICC/CMI-5_Spec_Current, Apache-2.0.
* `resources/jsonld/`: the published JSON-LD contexts https://www.w3.org/ns/credentials/v2, https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json and https://purl.imsglobal.org/spec/ob/v3p0/extensions.json.

= Source code =

The blocks and editor fields in `build/` are compiled from `src-js/` in https://github.com/derintolu/learning-ledger. To build them yourself, run `npm ci && npm run build` in a checkout.

== Changelog ==

= 0.1.7 =
* Security: never page-cache private responses. Every REST response of the plugin (signed course-server routes, the inbox, xAPI, links, tokens, erasure, exports and imports, the player routes), success or error, and the record, claim and badge pages cancel page caching (DONOTCACHEPAGE, batcache_cancel()) and carry no-store headers. Page caches such as Batcache ignore request headers, so they replayed signed and authenticated answers to anyone. No route under `/wp-json/learning-ledger/` is page-cached any more, the namespace indexes, unknown routes and errors WordPress returns before the plugin's code included, as the request arrives. The public status, the issuer profile, achievement definitions, did.json and badge status lists skip the page cache too but keep a short public Cache-Control for browsers and verifiers (60 seconds for did.json and status lists).
* New: `DELETE course-server/learners/{id}?revokeBadges=false` erases the learner at the LRS without revoking their claimed badges (default true).
* Fix: deleting a learner also removes their Sensei progress (activity comments and progress table rows) by direct query, which a host that empties comment queries left behind.
* Fix: posts imported while the service user is current get no author, so deleting that user cannot delete them.
* Fix: `wp learning-ledger sensei storage tables` refuses to drop or empty Sensei's progress tables while they hold progress unless `--drop-tables` is given.
* Fix: distribution packages keep the checkpoint rubric and checks; course-xapi-mcp 0.2 counts only the planted bug's explanation and fix checks and the reference solution as secret.
* Fix: an imported package's course version moves with WordPress edits (its patch rises with each content change) and returns to the package's own version on the next import.

= 0.1.6 =
* New: `wp learning-ledger sensei storage tables` makes Sensei read progress from its own tables (High-Performance Progress Storage) for hosts that empty comment queries, taking the steps Sensei's settings screen takes (with `--force`, Sensei migrates existing comment progress first); `... storage comments` is the way back and `... storage status` shows the storage and migration.
* Fix: while a result is applied, Sensei's status-comment lookups that a host emptied are read directly, and Sensei Certificates no longer issues a duplicate certificate on such a host.
* New: the signed course-server status reports Sensei's progress storage as Sensei reads it, whether its progress tables exist, and which capability, granted to the service user in memory only, lets this host's queries through.

= 0.1.5 =
* New: the signed course-server status reports whether this host's post, user and comment queries work without a logged-in user, and Sensei's progress storage.
* Fix: on a host that filters anonymous post or comment queries, inbox processing, authorised course-server requests, content pings and package exports run as a dedicated service user (no role, capabilities, email or usable password; checked before every use), so Sensei progress kept in comments is written and read.

= 0.1.4 =
* Fix: on hosts that filter anonymous post and user queries, LRS deliveries reach Sensei again: the inbox maps the actor to its learner, resolves the activity and checks quizzes with direct queries, and a Sensei course is completed when all its lessons are.

= 0.1.3 =
* Fix: package ZIPs carry a fixed archive comment, so they no longer end in NUL bytes that some hosts strip from responses, truncating the download.
* Fix: WP-CLI, signed course-server and unfiltered_html imports write lessons without kses, which rewrote regex patterns, code and markup.
* Fix: an imported package exports back unchanged: specVersion 0.2, the course description, language, version, allowPractice, foreign extensions, reference solutions, placement title and description, fence languages and raw titles are kept. An empty program takes its AU URL template from the package, and the package's MCP URL is exported while no course server URL is set; an import never sets the course server URL.

= 0.1.2 =
* Fix: on hosts that filter anonymous post queries, Sensei course lessons, lesson quizzes, stable-key lookups and the program list are read directly, so course-server package exports keep their lessons and imports update existing posts.
* New: REST twins of the front-end pages under `learning-ledger/v1/page/` (`did`, `claim/<token>`, `badge/<uuid>`, `badge/<uuid>.json` and `.png`, `record/<token>`) for hosts that pass only REST requests to WordPress. The claim form's nonce field is now `lledger_nonce`.

= 0.1.1 =
* Fix: package export, import and the LRS course import no longer fail with a critical error on REST requests (wp_tempnam() is loaded before use).
* Fix: course-server package lookups by stable key work on hosts that filter anonymous post queries.
* New: `POST actors/erase` accepts `revokeBadges`; false leaves the actor's claimed badges valid and still revokes unclaimed ones.

= 0.1.0 =
* First release.
