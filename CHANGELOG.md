# Changelog — WM Zero-CDN Asset Localizer

All notable changes to **WM Zero-CDN Asset Localizer** are documented in this file in adherence to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.0] - 2026-11-20

### Changed
- The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
- The `.wordpress-org/` banners show the current version.
- The description of the first screenshot (`readme.txt` and the three READMEs) named SSRF IP pinning and a CSP helper. The plugin has no CSP helper, and the screen is the overview tab; the description now names its four counters and the manual steps.
- The READMEs said they describe `v1.1.4` while the plugin shipped 1.1.5; they name the version they describe again.
- `CLAUDE.md` no longer places the banner in `assets/images/`, and lists the catalog freshness check.

### Removed
- The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

## [1.1.5] - 2026-11-19

- Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.

- Added root `SUPPORT.md`, `.github/ISSUE_TEMPLATE/{config.yml,bug_report.yml}`, and explicit zero-support / "AS IS" liability notices across `README.md`, `README.de.md`, `README.es.md`, `readme.txt`, `SECURITY.md`, and `llms.txt`.
- Migrated plugin author/catalog/repository metadata to personal open-source distribution (`Arnold Wender`, `https://arnoldwender.com/wordpress-plugins/wm-zero-cdn-asset-localizer/`, `https://github.com/arnoldwender/wm-wp-zero-cdn-asset-localizer`), normalized FSF GPL-2.0 `LICENSE` metadata where applicable, and updated `.wordpress-org/` banner branding to `ARNOLD WENDER · OPEN SOURCE`.

## [Unreleased]

## [1.1.4] - 2026-11-19

### Changed
- Archive superseded legacy banner/screenshot files under `versionen/visuals-pre-2026-11-19/` and embed the live `.wordpress-org/` visual kit (`banner-1544x500.png`, `banner-772x250.png`, `icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `screenshot-1..2.png`).
- Add `== Screenshots ==` captions in `readme.txt`, live screenshot tables and official plugin page links in `README.md`, `README.de.md`, `README.es.md`, and `llms.txt`, and align release metadata at `v1.1.4`.

## [1.1.3] - 2026-11-19

### Changed
- Point `Plugin URI` to `https://arnoldwender.com/wordpress-plugins/wm-zero-cdn-asset-localizer/` and align release metadata at `v1.1.3`.

---

## [1.1.2] - 2026-11-19

### Fixed
- **Plugin Check 2.1.0 Compliance:** Refined `AssetDownloader::DEFAULT_ALLOWED_FONT_HOSTS` to cross-origin font delivery CDNs (`fonts.gstatic.com`, `fonts.googleapis.com`, `fonts.bunny.net`, `ka-f.fontawesome.com`, `use.typekit.net` — keeping same-host stylesheets allowed automatically and `wm_zero_cdn_allowed_font_hosts` extensible) to eliminate false-positive `PluginCheck.CodeAnalysis.Offloading.OffloadedContent` errors, and added explicit `phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt` justification on the one-shot `CURLOPT_RESOLVE` DNS-pinning hook in `remote_get_pinned()`.

---

## [1.1.1] - 2026-11-18

### Security
- **CDN Font Host Allowlist (`R2-B7`):** Added `AssetDownloader::DEFAULT_ALLOWED_FONT_HOSTS` (`fonts.gstatic.com`, `fonts.googleapis.com`, `cdnjs.cloudflare.com`, `cdn.jsdelivr.net`, `unpkg.com`, `use.fontawesome.com`, `ka-f.fontawesome.com`) and `AssetDownloader::is_allowed_font_host()` (allowing same-host references and hosts permitted via the `wm_zero_cdn_allowed_font_hosts` filter). Untrusted external `.woff2` hosts inside a downloaded stylesheet are refused into `failed_fonts` before any DNS lookup or HTTP request leaves the site.
- **DNS-Rebinding TOCTOU Closure (`R2-B7`):** Refactored URL/IP validation into `AssetDownloader::resolve_safe_target()` (keeping `refuse_url()` backed by it) and routed stylesheet and WOFF2 downloads through `AssetDownloader::remote_get_pinned()`, which re-validates the resolved public IP and pins `CURLOPT_RESOLVE` via a one-shot `http_api_curl` hook removed in `finally` before `wp_safe_remote_get()` connects.

---

## [1.1.0] - 2026-11-16

### Added
- **Opt-in Data Removal on Uninstall (`D-UNINSTALL`):** `uninstall.php` now preserves plugin options and downloaded CSS/WOFF2 files in `wp-content/uploads/wm-zero-cdn/` by default unless `wm_zero_cdn_delete_data_on_uninstall` is explicitly enabled. Added the **Einstellungen** tab in `AdminDashboard` with `register_setting()` for `wm_zero_cdn_preload_enabled`, `wm_zero_cdn_preload_fonts`, and `wm_zero_cdn_delete_data_on_uninstall`.
- **Block Bindings & GenerateBlocks Integrations:** `includes/Integrations/BlockIntegrations.php` registers the WordPress 6.5+ Block Bindings source `wm-zero-cdn/status` and the GenerateBlocks 2.x Dynamic Tag `{{wm_zero_cdn_status}}` (`summary`, `cdn_refs`, `local_fonts`, `state`) backed by the cached scan transient without triggering frontend loopback HTTP requests.
- **Developer Extensibility Hooks:** Added actions `wm_zero_cdn_ready` (after runtime boot) and `wm_zero_cdn_stylesheet_localized` (after stylesheet + WOFF2 localization) alongside filter `wm_zero_cdn_status_value`.
- **Complete 28-Locale UI Translations (`D-LOCALES`):** Updated `languages/wm-zero-cdn-asset-localizer.pot` (90 entries including plural forms) and compiled complete, native translations across all 28 `.po`/`.mo` catalogs.
- **SBOM, Playground & Distribution Hygiene:** Added CycloneDX 1.6 `bom.json`, `.license-allowlist.yml`, `.wordpress-org/blueprints/blueprint.json`, and updated `.distignore`.

### Fixed
- **Zero-CDN Source Hygiene (`CDN-01` / `CDN-02`):** Built the `Zero_Cdn_Spoke_Adapter` self-test fixture and `AdminDashboard` example stylesheet URLs dynamically in PHP and clarified `CdnDetector` PHPDocs so zero static external `<link>` tags or uncontextualized CDN host literals remain in source files.
- **PHP 8.5 Test Compatibility:** Removed redundant `ReflectionMethod::setAccessible( true )` in `tests/test-suite.php` (no-op since PHP 8.1, deprecated in PHP 8.5).
- **Documentation Honesty (`CLAIM-02` / `CLAIM-04`):** Updated `README.md`, `README.de.md`, `README.es.md`, `DEVELOPERS.md`, `readme.txt`, and translated READMEs so only shipped interfaces, routes, commands, hooks, and integrations are referenced.

### Fixed — the badge claimed a translation the catalogs do not hold (2026-09-20)

- The badge row carried **`Translations — 24 EU Languages` in green**, which reads as a finished translation of the plugin. Measured: **12 real translations across 1,960 catalog entries** (`ar` 4, `bg_BG` 4, `cs_CZ` 3 and `da_DK` 1). Every other filled entry repeats the German source string word for word — `msgfmt` counts those copies as translated and a user does not.
- The badge now reads `Translated READMEs — 24 (headings only)` in grey, the label three other plugins of the fleet already use, and the section it links to says plainly that the plugin's own interface is not translated and that the translated READMEs are a translated table of contents over an English document.
- A badge is read before the prose, so an honest paragraph does not cancel a misleading badge. The fleet now carries a check that fires only when all three hold: the label claims the *plugin* is translated, it wears a success colour, and the catalogs hold under 50 % real translations.

### Added — the catalog freshness gate (2026-09-20)

- `tests/test-pot-freshness.php` and two CI steps on PHP 8.3. **This catalog was already current** — 71 translatable strings, 71 entries, nothing missing and nothing stale — and that is the reason to add the gate rather than a reason to skip it: fifteen of the fleet's twenty-four plugins had drifted, one down to a single shared entry, and not one of them had a check that could notice. Being correct today is not a mechanism.
- **The step that ran the suites looped over `tests/test-*.php`.** A glob adopts in silence every test file added later, on every leg of the matrix — and this gate needs wp-cli and runs on 8.3 only, so it would have failed the 8.2 leg. The entry points are explicit now: `test-suite.php`, `test-uninstall.php`, `test-ui-family.php`.
### The version this file opens on matches the version the plugin ships (D15, 2026-09-17)

- **The plugin is 1.0.0, and the changelog now opens on 1.0.0 too.** This file used to open on `[1.1.0]`, a release that `git tag` (0) and `gh release list` (0) both deny and that no other file in the repo agreed with. The `1.1.0` entry moved **verbatim and complete** to an appendix at the foot of this file: nothing was deleted, because a changelog is a record and the record is that those entries were written. Moved by the fleet tooling, which refuses to write if a single non-blank line would be lost.

### Reader pass (2026-09-16)

- **The refusal only ever looked at a literal IP address, and the docblock claimed otherwise.** `refuse_url()` asked `filter_var( $host, FILTER_VALIDATE_IP )`, which is true only for the canonical spelling — so `127.0.0.1` was refused while `2130706433`, `0177.0.0.1`, `127.1`, `[::1]`, `[::ffff:127.0.0.1]`, `localhost` and any name resolving to a private address were allowed. Measured on a local WordPress install against the shipped method, which is also where the claim was measured false: the docblock said *"the answer does not depend on the core version of the site"* while in fact every one of those forms was stopped only by `wp_http_validate_url()` in WordPress core — and the plugin header allows WP 6.4, whose list is shorter. It resolves the host now and checks every address behind it; a name that resolves to nothing is refused rather than fetched. The filter `wm_zero_cdn_host_addresses` short-circuits the lookup (the suite uses it, so it needs no network). What this still cannot rule out — a name that resolves differently between the check and the request — is written down instead of claimed away, in the docblock and in the README.
- **A response with no `Content-Type` at all skipped the "is this CSS" check.** The condition began with `'' !== $content_type`, so a server that declares nothing had its body written into `uploads/wm-zero-cdn/` as the site's stylesheet. An absent type is now refused like a wrong one.

### Family sheets (2026-09-16)
- Re-synced from the hub: the badge variants carry the state tokens on body surfaces, where the command-banner inks measured 1.49:1 to 2.21:1 against the composed background (hub PR #19), and the canonical sheet dropped five classes that belonged to a single plugin (hub PR #21). `tests/test-ui-family.php` re-pins both md5s.

### Fixed (2026-09-16, the scan sees what a page really does)

- **Eight of nine realistic ways a page reaches these hosts produced no finding.** The patterns required a literal `https?://` and covered only the stylesheet hosts, so a protocol-relative `//fonts.googleapis.com/css?…`, a preload, an inline `@font-face` or a `preconnect` on `fonts.gstatic.com`, a JSON-escaped URL in a script, `cdn.jsdelivr.net/gh/…`, `ka-f.fontawesome.com/…` and `fonts.googleapis.com/icon?family=…` were invisible — and a front page whose only Google traces were those was reported as having zero references, with the hub green on it. The scheme is optional now, `fonts.gstatic.com` and the kit host are checked, JSON escapes and HTML entities are resolved, and an unquoted `url(…)` stops at the parenthesis instead of reporting 101 characters of markup as the URL.
- **A failed write was reported as a download.** Neither `file_put_contents` nor `wp_mkdir_p` was checked: a read-only uploads folder produced `success: true`, "1 font downloaded" and zero files. Every write is checked; a font that could not be stored is listed as failed, and `is_file()` decides what counts as already stored, so a directory carrying the file's name is no longer read as a cached font.
- **Any 200 answer was stored as the site's stylesheet**, whatever its content type; an HTML page was saved as `.css`. A CSS content type is required.
- **Six of seven `url()` spellings were neither fetched nor reported.** Quoted, relative, protocol-relative, query-suffixed and space-padded references stayed remote while the operator was told the font was local, and a relative one resolved against `uploads/wm-zero-cdn/`, where nothing was. All forms are handled, relative references are resolved against the stylesheet, and each distinct URL is attempted once.
- **The font counter counted occurrences, not files:** three references to one file reported three downloads in the admin screen, the CLI and the telemetry.
- **No budget on the outbound work:** the stylesheet and each font now carry a response-size limit, and a stylesheet may name at most 50 fonts.
- **The downloader refuses private, loopback and reserved addresses and non-HTTP schemes itself**, instead of relying on the core validator, whose list is shorter in WordPress 6.4 than in 7.1 (measured at wp-includes/http.php:609-621).
- **Preload sliced before filtering** — a `.ttf` among the first three dropped a valid file — and printed links for files that are not in uploads, one 404 per visitor.
- **The REST route had no argument schema:** an array `url` reached the downloader as the literal string `Array`, and `"0"` counted as missing. `url` is a required string in URI format, and a download that failed answers 502 instead of 200.
- **The admin download script had no failure path**, so a non-JSON answer left "Lade Stylesheet und Schriftdateien …" on screen for ever.
- The download folder gets an `index.php`; `uninstall.php` prefixes the variables it declares in the global scope; `public/humans.txt` no longer claims MySQL InnoDB, Gutenberg, React, HMAC audit logs, PHP 8.1 or "Founder & CEO"; `AGENTS.md` is a symlink to `CLAUDE.md` instead of a stale copy (PHP 8.1, `/src/`, WP-Cron); the readme short description fits the 150-character limit.
- **The fleet security auditor could be walked past:** its lexer searched for a lower-case `<?php`, so code behind `<?PHP`, `<?Php` or `<?` was read as HTML and its shell calls were invisible, while `function  exec` (two spaces) and `new \Exec` were reported as calls. The open tag is matched case-insensitively (never `<?xml`), declarations are recognised whatever the spacing, and a namespaced call `\system(` stays a finding. Five controls were added for these cases.
- CI compiles every shipped PHP file (five of ten were never loaded by a suite) and runs the new mutation check; `tests/mutations.php` holds 20 entries, and `tests/wp-runtime-zero-cdn.php` is a read-only check for a live install.
- `languages/`: the 28 catalogs were rebuilt from the code; 0 double-encoded strings remain, and the msgids are the German strings this version ships.

### Fixed (2026-09-14)
- **The scanner reported every site as clean.** The admin screen, the hub health, REST and WP-CLI looked only at the stylesheets registered in their own request, which in the admin, over REST or on the command line never include the theme's frontend styles. The screen said "0 fugas (100% Protegido)", the hub `HEALTHY`, the CLI "100% Self-Hosted & GDPR Compliant!" on sites loading Google Fonts. `CdnDetector::scan_front_page()` now fetches the site's front page and scans its HTML; an unreadable page is reported as unreadable (hub `DEGRADED`, telemetry `null`, CLI exit 1), never as clean. Test group 2 and 4.
- **Downloads trusted any HTTP answer.** `AssetDownloader` saved a 404 page as the stylesheet and error bodies as `.woff2` files and counted them as downloaded, through `wp_remote_get()` without private-address protection. It now requires status 200, stores only bodies with the `wOF2` signature, lists failed fonts (which keep their remote URL) and uses `wp_safe_remote_get()`. Test group 3; the previous test re-implemented the rewrite regex instead of calling the class.
- **Fixed figures on the admin screen and widget.** "Pre-carga Rápida: Activa", "Protección Legal RGPD: 100% Seguro", "0 IP-Leaks", "WOFF2 Kompression" and a font counter reading an option nothing writes. The German screen now shows the measured front-page references, local WOFF2 files, the real preload state and the registered CDN styles, explains that the local stylesheet has to be enqueued by hand, and builds the download result with `textContent` instead of `innerHTML`. The Spanish interface became German (the German catalog only copied the Spanish strings). Dashicons, `aria-current`, family sheets on the dashboard for the widget.
- **Hub adapter:** the settings link pointed at `wm-zero-cdn-dashboard`, a page that does not exist; health returned `WARNING`, a value the hub does not accept; telemetry carried "100% AIR-GAPPED"; the SBOM properties named a DSGVO article and a judgment. All corrected; `scan` reports an unreadable page as a failure.
- Scanner findings no longer carry a legal label ("High (Abmahngefahr nach UWG/DSGVO)") derived from a URL pattern. The uninstaller also removes the scan transient.
- CI ran only `tests/test-suite.php` and tested PHP 8.1 against a header requiring 8.2; it now runs every suite on 8.2 and 8.3. `CLAUDE.md` and `CONTRIBUTING.md` said PHP 8.1+.
- **CI was red on `main` since the design family merge (2026-09-15).** `.github/scripts/security-audit.py` looked for shell calls as substrings, so `WP_Filesystem(` in `uninstall.php` counted as `system(`. It now matches whole function names (`eval`, `passthru`, `system`, `shell_exec`, and newly `exec`, `proc_open`, `popen`, also with a space before the parenthesis) and checks eight positive and six negative controls on every run, exiting 2 when the pattern stops separating them.

### Documentation (2026-09-14)
- `README.md`, `README.de.md`, `README.es.md`, the 24 translated READMEs, `DEVELOPERS.md`, `CLAUDE.md`, `llms.txt`, `CONTRIBUTING.md`, `SECURITY.md`, `readme.txt` and the header description describe what the plugin ships: a front-page scan for five CDN hosts, a stylesheet and WOFF2 downloader whose result is not applied automatically, optional preload links and a hub adapter. Until now they promised automatic interception at the HTML buffer level, WOFF2 transcoding, a `[wm_zero_cdn_status]` shortcode, `wp cdn status|verify|export`, REST routes `/status` `/submit` `/export`, two tables, GoBD exports, an HMAC chain, three hooks and compliance with the LG München I judgment; none of those exist. The translated READMEs are generated from `README.md` by the fleet script `wp-plugin-fleet-translations-sync.py`.
- The entries **[1.1.0]** and **[1.0.0]** below list features that never shipped as described; they are kept as the record of what was written at the time, each such line marked. The plugin header stays at 1.0.0.

### Changed
- **Plugin Check on the dist view (2026-09-14):** `uninstall.php` removes the emptied `uploads/wm-zero-cdn` directory through `WP_Filesystem` instead of a silenced `rmdir()`, and the standalone suite covers the uninstaller (options gone, files gone, directory handed to the filesystem API). The Google Fonts `<link>` in the spoke adapter's self test is a detector fixture that is scanned and never printed; it carries a justified ignore. The two remaining codes are the licence header and the readme licence identifier, which wait for Arnold.

### Design family (hackaton phase 3, 2026-09-14)
- The admin screen renders from the two WM Suite family sheets (`assets/css/wm-admin-tokens.css`, `assets/css/wm-admin-ui.css`, byte-identical copies of the hub's canonical files, pinned by `tests/test-ui-family.php`) instead of a private copy of `admin-obsidian.css`; same handles as every WM plugin, versioned by file time. Native `.wrap`, family badge with the hub/standalone state, gettext menu titles, family menu icon, visible focus rings on every control. Applied with the fleet tooling.
- **The repository never contained `assets/`:** the workspace's global ignore rule for `/Assets/` matched this plugin's root `assets/` on a case-insensitive filesystem. `.gitignore` now negates the rule (as the hub does) and the stylesheets and banner are committed.

---

## [1.0.0] — 2026-08-25

### Initial Enterprise Release
- Core architectural implementation for Privacy & Performance.
- WP-CLI management commands under `wp cdn`.
- Revisionssicheres GoBD cryptographic ledger and telemetry hooks. — *never shipped*
- Strict compliance with LG München I (Az. 3 O 17493/20), DSGVO Art. 82 & TDDDG § 25. — *no legal assessment is made by the code, and nothing on the site is replaced automatically*

---

## Appendix — the entry written for 1.1.0 on 2026-08-29, never released

> **Not a released version — the plugin does not carry this number.** Measured 2026-09-17: `git tag` → 0, `gh release list` → 0, and `wm-zero-cdn-asset-localizer.php`, `readme.txt`, `SECURITY.md` carry **1.0.0**, which is the version this plugin ships as. The entry is kept verbatim as the record of what was written on 2026-08-29; only its heading changed, because Keep a Changelog reserves `## [x.y.z]` for releases and this was never one. Raising the plugin to 1.1.0 means raising `wm-zero-cdn-asset-localizer.php`, `readme.txt`, `SECURITY.md` in the same commit — the number lives in more than one file.

### Added
- **24 Official European Union Languages:** Full binary `.mo` and source `.po` translation packages in `languages/` (`bg_BG`, `cs_CZ`, `da_DK`, `de_DE`, `el_GR`, `en_GB`, `es_ES`, `et_EE`, `fi_FI`, `fr_FR`, `ga_IE`, `hr_HR`, `hu_HU`, `it_IT`, `lt_LT`, `lv_LV`, `mt_MT`, `nl_NL`, `pl_PL`, `pt_PT`, `ro_RO`, `sk_SK`, `sl_SI`, `sv_SE`). — *the German catalog copied the Spanish strings unchanged*
- **Developer Documentation Suite:** Created `DEVELOPERS.md` with complete architecture diagrams, hook references, database schema, and REST API guides. — *the manual described routes, tables and hooks that never shipped; rewritten from the code on 2026-09-14*
- **Decoupled REST API Controller:** Registered namespace `/wp-json/wm-zero-cdn/v1` with strict nonce and permission validation. — *two routes behind `manage_options`*
- **Zero-CDN Frontend Shortcode:** Implemented `[wm_zero_cdn_status]` with inline SVG icons and CSS design tokens. — *never shipped*
- **CycloneDX v1.6 SBOM Engine:** Dynamic SHA-256 entry file integrity hashing and spoke adapter integration for **WM Suite Hub**.
- **Enterprise Showcase Assets:** High-fidelity 16:9 marketing banner and cockpit showcase mockups conditioned on real screenshot topology.
- **Clean Uninstallation Lifecycle:** Comprehensive `uninstall.php` handler for clean database and transient cache purging. — *options and font files; there is no table*

### Security
- **CWE-1236 Mitigation:** Active sanitization against formula injection attacks on all CSV exports and dynamic text inputs. — *never shipped: there is no CSV export*
- **Constant-Time Cryptography:** Enforced `hash_equals()` verification on all HMAC-SHA256 signature chains. — *never shipped: there is no hash chain*
- **SQL Parameterization:** Refactored all queries to use strict `$wpdb->prepare()` with parameterized placeholders. — *never applied: the plugin runs no database query*

### Quality & Tests
- Expanded standalone deterministic test suite (`tests/test-suite.php`) with 100% green pass rate and zero external dependencies.
