=== WM Zero-CDN Asset Localizer & Font Sovereignty Engine ===
Contributors: arnoldwender
Tags: fonts, google-fonts, cdn, woff2, self-hosted
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds CDN references on the front page and downloads a Google Fonts stylesheet with its WOFF2 files; replacing the remote link stays manual.

== Description ==

* **Front-page check:** the plugin fetches the site's own front page and lists every reference to Google Fonts (including fonts.gstatic.com), Font Awesome, cdnjs, jsDelivr and unpkg in its HTML — with or without a scheme, in links, scripts, inline styles or JSON. If the page cannot be read, it says so instead of reporting a clean site.
* **Stylesheet download:** enter a Google Fonts stylesheet URL; the plugin stores the CSS and every WOFF2 file it references (verified by the WOFF2 signature and by the write) in `wp-content/uploads/wm-zero-cdn/` and rewrites the font URLs to the local copies. Private and reserved addresses, non-HTTP URLs, and untrusted external font hosts (outside the stylesheet's own host or the filterable CDN allowlist) are refused, and outbound connections pin the validated IP via `CURLOPT_RESOLVE`. **It does not replace the remote link on the site** — enqueue the local CSS in the theme instead of the remote one.
* **Preload & Settings:** up to three `<link rel="preload">` tags for local WOFF2 files, configurable on the **Zero-CDN › Einstellungen** tab (`wm_zero_cdn_preload_enabled`, `wm_zero_cdn_preload_fonts`) or through filters of the same name, plus an explicit opt-in checkbox (`wm_zero_cdn_delete_data_on_uninstall`) controlling whether uninstalling deletes options and local font files (preserved by default).
* **Block Bindings & GenerateBlocks:** WordPress 6.5+ Block Bindings source `wm-zero-cdn/status` and GenerateBlocks 2.x Dynamic Tag `{{wm_zero_cdn_status}}` exposing measured scan summary, reference count, local WOFF2 file count and check state from cached telemetry.
* **Admin screen & translations:** "Zero-CDN" screen with five tabs, a dashboard widget, and 28 compiled `.po`/`.mo` locale catalogs.
* **REST** (`wm-zero-cdn/v1/scan`, `/localize`) and **WP-CLI** (`wp cdn scan`, `wp cdn localize <url>`), administrators only.

The plugin makes no legal assessment. It requests only the site's own front page and the URLs an administrator enters.

== Frequently Asked Questions ==

= Is technical or private support provided? =

No. This plugin comes with zero support (`0` support). Private inquiries sent via email, `arnoldwender.com`, or social/chat channels are not answered. Reproducible bugs belong solely on GitHub Issues (`https://github.com/arnoldwender/wm-wp-zero-cdn-asset-localizer/issues`) with no guarantee of reply or resolution. The software is provided **"AS IS"** at your sole risk with zero warranty or liability (see `SUPPORT.md` in the repository).

== Screenshots ==

1. Overview tab: front-page CDN references, local WOFF2 files, preload state, CDN stylesheets of the admin request and the manual steps
2. Dashboard widget reporting localized font assets and external CDN detections

== Changelog ==


= 1.2.1 =
* The bundled family stylesheet is identical to WM Suite Hub 1.2.5 again: it starts the admin header below WordPress's help toggle instead of shortening it. This plugin's own screens register no help tab, so they change only where another plugin adds one.
* Fixed: in eleven languages with more than two plural forms, the count of CDN references on the front page used one form for every count above one; each form now matches its count.
* The first screenshot is re-shot with the current hub menu.

= 1.2.0 =
* The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
* The `.wordpress-org/` banners show the current version.
* The first screenshot description named SSRF IP pinning and a CSP helper. The plugin has no CSP helper and the screen is the overview tab; the description now says what it shows.
* Removed: The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

= 1.1.5 =
* Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.
* Added root `SUPPORT.md`, GitHub Issue templates, explicit zero-support & "AS IS" liability notices in READMEs and `readme.txt`, and normalized canonical GPLv2+ `arnoldwender.com` / `arnoldwender/wm-wp-zero-cdn-asset-localizer` links.
* Updated `.wordpress-org/` visual banners to personal `ARNOLD WENDER · OPEN SOURCE` branding.

= 1.1.4 =
* Added live `.wordpress-org/` visual kit assets (banner, icons, and live WordPress screenshots) and updated documentation links for v1.1.4.

= 1.1.3 =
* Point Plugin URI to the official plugin page.

= 1.1.2 =
* Fixed: Refined `AssetDownloader::DEFAULT_ALLOWED_FONT_HOSTS` to cross-origin font delivery hosts (`fonts.gstatic.com`, `fonts.googleapis.com`, `fonts.bunny.net`, `ka-f.fontawesome.com`, `use.typekit.net`) and documented the `CURLOPT_RESOLVE` `http_api_curl` DNS-pinning annotation so WordPress.org Plugin Check 2.1.0 passes with zero errors (same-host CDNs remain automatically permitted and custom hosts remain filterable via `wm_zero_cdn_allowed_font_hosts`).

= 1.1.1 =
* Security: Added a CDN font host allowlist (`AssetDownloader::DEFAULT_ALLOWED_FONT_HOSTS`, filterable via `wm_zero_cdn_allowed_font_hosts`) so untrusted external `.woff2` hosts referenced by a remote stylesheet are refused into `failed_fonts`.
* Security: Closed the DNS-rebinding TOCTOU window in `AssetDownloader` by pinning the validated public IP via `CURLOPT_RESOLVE` on a one-shot `http_api_curl` hook around `wp_safe_remote_get()`.

= 1.1.0 =
* Added: Settings tab (`Einstellungen`) registering `wm_zero_cdn_preload_enabled`, `wm_zero_cdn_preload_fonts`, and opt-in `wm_zero_cdn_delete_data_on_uninstall`; `uninstall.php` now preserves options and local WOFF2 files by default unless deletion is explicitly enabled.
* Added: WordPress 6.5+ Block Bindings source `wm-zero-cdn/status` and GenerateBlocks 2.x Dynamic Tag `{{wm_zero_cdn_status}}`, plus lifecycle actions `wm_zero_cdn_ready` and `wm_zero_cdn_stylesheet_localized` and filter `wm_zero_cdn_status_value`.
* Added: 28 compiled `.po`/`.mo` translation catalogs across all 90 UI strings (including plural forms), CycloneDX 1.6 `bom.json`, and WordPress Playground blueprint.
* Fixed: the check missed protocol-relative references, fonts.gstatic.com, inline @font-face, preconnect and JSON-escaped URLs, so pages that load Google Fonts were reported as clean.
* Fixed: a font or stylesheet that could not be written was reported as downloaded; every write is checked now.
* Fixed: any 200 answer was stored as the stylesheet — a CSS content type is required, and private or reserved addresses are refused (including IPv4-mapped IPv6 addresses on PHP 8.2).
* Fixed: quoted, relative and query-suffixed font references are localized instead of silently left remote, and each file is counted once.
* Fixed: preload filtered after capping and printed links for missing files; the REST route validates its argument and answers 502 when a download fails.
* Fixed: PHP 8.5 deprecation in standalone reflection test helper removed.
* Admin screen and widget: measured figures instead of fixed percentage or "0 IP-Leaks" texts, WM Suite admin family sheets, visible focus rings, and explicit manual-step guidance.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.1 =
The family sheet of WM Suite Hub 1.2.5 and plural forms in eleven translations. No data or settings change.

= 1.2.0 =
Hub users: update WM Suite Hub to 1.2.0 together with this plugin. The hub contract moved to a new PHP namespace; next to an older hub the plugin keeps working, but it is not listed in the cockpit and its admin page has no menu entry until the hub is updated.
