# WM Zero-CDN Asset Localizer — Developer Manual

> Everything below is measured against the code in this repository (v1.1.4, 2026-11-19).

---

## 1. Layout

| Path | Role |
| --- | --- |
| `wm-zero-cdn-asset-localizer.php` | Header, constants (`WM_ZERO_CDN_VERSION` / `_FILE` / `_PATH` / `_URL`), PSR-4 autoloader for `WMCyber\ZeroCDN\Localizer\` → `includes/`, hub spoke registration, `plugins_loaded` boot. |
| `includes/Core/Plugin.php` | Text domain on `init`, `PreloadInjector` on `wp_head` (1), `AdminDashboard` in the admin, `RestController` on `rest_api_init`, `BlockIntegrations`, WP-CLI command `cdn`, `wm_zero_cdn_ready` action. |
| `includes/Scanner/CdnDetector.php` | `scan_html()`, `scan_front_page()`, `scan_enqueued_styles()`, `FRONT_SCAN_TRANSIENT`. |
| `includes/Downloader/AssetDownloader.php` | `localize_css()`, `is_allowed_font_host()`, `DEFAULT_ALLOWED_FONT_HOSTS`, `CURLOPT_RESOLVE` DNS pinning, fires `wm_zero_cdn_stylesheet_localized` on success. |
| `includes/Preloader/PreloadInjector.php` | `inject_preload_tags()`. |
| `includes/Integrations/BlockIntegrations.php` | WordPress 6.5+ Block Bindings source `wm-zero-cdn/status` and GenerateBlocks 2.x Dynamic Tag `{{wm_zero_cdn_status}}`. |
| `includes/API/RestController.php` | `/scan`, `/localize` under `wm-zero-cdn/v1`. |
| `includes/CLI/ZeroCdnCommand.php` | `wp cdn scan`, `wp cdn localize`. |
| `includes/Admin/AdminDashboard.php` | Menu, `register_setting()` for preload and uninstall options, family sheet enqueue, five-tab screen, dashboard widget. |
| `includes/Adapters/Zero_Cdn_Spoke_Adapter.php` | WM Suite Hub contract; returns early when the hub's interface does not exist. |
| `assets/css/` | `wm-admin-tokens.css`, `wm-admin-ui.css` (copies of the hub's canonical sheets, pinned by `tests/test-ui-family.php`). |
| `languages/` | `.pot` template and 28 compiled `.po` / `.mo` locale catalogs. |
| `uninstall.php` | Opt-in uninstaller: preserves options and files unless `wm_zero_cdn_delete_data_on_uninstall` is enabled; when enabled, deletes plugin options (site and network), the scan transient, and `uploads/wm-zero-cdn/` (through `WP_Filesystem`). |

---

## 2. Scanner

| Provider | Pattern (the scheme is optional: `(?:https?:)?//`) |
| --- | --- |
| Google Fonts | `//fonts.googleapis.com/…` |
| Google Fonts | `//fonts.gstatic.com/…` — the host that serves the font files |
| FontAwesome | `//(use\|kit\|ka-f).fontawesome.com/…` |
| CDNJS | `//cdnjs.cloudflare.com/…` |
| jsDelivr | `//cdn.jsdelivr.net/…` |
| Unpkg | `//unpkg.com/…` |

A URL ends at a quote, a parenthesis, white space, a tag boundary or a backslash (`[^"'()\s<>\\]`), so an unquoted `url(…)` in a `<style>` block does not swallow the markup behind it.

- `scan_html( $html )` → `list<{provider, url, host}>`, each URL once. JSON escapes (`https:\/\/…`) are restored before the patterns run and HTML entities are decoded afterwards, so the value the operator copies into the download form is the URL a browser requests. A protocol-relative reference is reported with `https:`.
- `scan_front_page( $use_cache = false )` → `{ok, url, error, found, scanned_at}`. `wp_safe_remote_get( home_url( '/' ), timeout 10, redirection 3 )`; `ok` only on a 200 answer; `error` holds the `WP_Error` message or `HTTP <code>`. The result is stored in the transient `wm_zero_cdn_front_scan` — ten minutes for a measurement, **one minute for a failure**, so a site does not stay "unreadable" for ten minutes after the cause is gone; `$use_cache` reads it first.
- `scan_enqueued_styles()` → CDN stylesheets registered in `$wp_styles` of the **current** request. In admin, REST and CLI requests that set does not contain the theme's frontend styles.

---

## 3. Downloader

`localize_css( string $remote_url ): array`

0. `refuse_url()` (backed by `resolve_safe_target()`) first: only `http` and `https`, and never a host that is — or resolves to — a private, loopback or reserved IP address (including IPv4-mapped IPv6 addresses on PHP 8.2).
1. `remote_get_pinned()` re-validates via `resolve_safe_target()` and attaches a one-shot `http_api_curl` hook setting `CURLOPT_RESOLVE` (`host:port:ip`, removed in `finally`) before calling `wp_safe_remote_get( $remote_url )` with a desktop Chrome user agent (Google then answers with WOFF2 `src`) and `limit_response_size` 2 MB; a `WP_Error`, a status other than 200, a content type that is not CSS, or an empty body returns `success: false` with `error`.
2. `uploads/wm-zero-cdn/` is created if missing — and the answer is `success: false` when it cannot be created. An `index.php` is written beside the files so a server with directory indexing lists nothing.
3. Every `url(…woff2)` is checked against `is_allowed_font_host()` (allows same-host references and `DEFAULT_ALLOWED_FONT_HOSTS`: `fonts.gstatic.com`, `fonts.googleapis.com`, `fonts.bunny.net`, `ka-f.fontawesome.com`, `use.typekit.net`, filterable via `wm_zero_cdn_allowed_font_hosts`), then fetched via `remote_get_pinned()` (`limit_response_size` 8 MB, at most 50 per stylesheet): quoted with `'` or `"` or bare, absolute, protocol-relative or relative to the stylesheet, with or without a query. Each distinct URL is attempted once; a URL rejected by `is_allowed_font_host()` or `refuse_url()` is listed in `failed_fonts` without making a request. The body is written only when the answer is 200, starts with `wOF2` **and the write succeeded**; `is_file()` decides whether a font is already stored. Stored fonts are rewritten to their local URL; failed fonts keep their reference.
4. The stylesheet is written to `local_<sha256(url)[0:16]>.css`; a failed write returns `success: false`.
5. Fires `do_action( 'wm_zero_cdn_stylesheet_localized', $report, $remote_url )` and returns `success`, `remote_url`, `local_css_url`, `downloaded_fonts` (local URLs, one entry per file stored), `failed_fonts`.

The stylesheet is not enqueued and nothing is dequeued. Cross-host font references are restricted to the CDN allowlist (or the `wm_zero_cdn_allowed_font_hosts` filter), and DNS pinning via `CURLOPT_RESOLVE` closes the TOCTOU window between validation and cURL socket connect.

---

## 4. Preload & Hooks

`PreloadInjector::inject_preload_tags()` on `wp_head` (1): returns unless `apply_filters( 'wm_zero_cdn_preload_enabled', get_option( 'wm_zero_cdn_preload_enabled', false ) )` is truthy; takes `apply_filters( 'wm_zero_cdn_preload_fonts', get_option( 'wm_zero_cdn_preload_fonts', [] ) )`, **filters the list first** — a string basename ending in `.woff2` whose file exists in `uploads/wm-zero-cdn/` — and prints the first three as `<link rel="preload" href="<uploads>/wm-zero-cdn/<file>" as="font" type="font/woff2" crossorigin="anonymous" />`.

### Actions
- `wm_zero_cdn_ready( Plugin $plugin )` — fired at the end of `Plugin::boot()`.
- `wm_zero_cdn_stylesheet_localized( array $report, string $remote_url )` — fired after `AssetDownloader::localize_css()` writes a localized stylesheet.

### Filters
- `wm_zero_cdn_preload_enabled( bool $enabled )`
- `wm_zero_cdn_preload_fonts( array $fonts )`
- `wm_zero_cdn_allowed_font_hosts( array $hosts, string $stylesheet_url )`
- `wm_zero_cdn_host_addresses( ?array $addresses, string $host )`
- `wm_zero_cdn_status_value( string $value, string $field, array $snapshot )`

---

## 5. Block Bindings & GenerateBlocks

`BlockIntegrations` registers:
- **WordPress 6.5+ Block Bindings source:** `wm-zero-cdn/status` (`args.key`: `summary` | `cdn_refs` | `local_fonts` | `state`).
- **GenerateBlocks 2.x Dynamic Tag:** `{{wm_zero_cdn_status}}` (`options.field`: `summary` | `cdn_refs` | `local_fonts` | `state`), plus fallback resolution on `generateblocks_dynamic_tag_output`.

Both read the cached `wm_zero_cdn_front_scan` transient and local `.woff2` count on disk so frontend rendering never triggers a synchronous loopback HTTP request.

---

## 6. REST

| Method | Route | Permission | Parameters | Response |
| --- | --- | --- | --- | --- |
| `GET` | `/wp-json/wm-zero-cdn/v1/scan` | `manage_options` | — | `200 { success: <front page read>, data: { front_page: <scan_front_page>, registered_styles: <scan_enqueued_styles> } }` |
| `POST` | `/wp-json/wm-zero-cdn/v1/localize` | `manage_options` | `url`: required string, `format: uri` | `200 { success, data: <localize_css> }`; `400` without or with an invalid `url`; **`502`** when the download failed |

---

## 7. WP-CLI

```bash
wp cdn scan                                              # front page; exits 1 when it cannot be read
wp cdn localize "https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap"
```

---

## 8. Admin

- **Menu:** `add_menu_page( 'wm-zero-cdn', 'Zero-CDN', manage_options, family icon, 85 )`.
- **Settings (`admin_init`):** `register_setting( 'wm_zero_cdn_settings', ... )` for `wm_zero_cdn_preload_enabled`, `wm_zero_cdn_preload_fonts`, and `wm_zero_cdn_delete_data_on_uninstall`.
- **Assets:** family sheets on hooks containing `wm-zero-cdn` and on `index.php` (widget).
- **Screen:** tabs `inventory`, `localize`, `scanner`, `settings`, `glossary`. Unknown tabs fall back to `inventory`; the active tab carries `aria-current`.
- **Widget:** `wm_zero_cdn_widget` — banner from the cached scan (unreadable / no references / N references), tiles for the reference count (`—` when unreadable) and local WOFF2 files, link to the scanner tab.

---

## 9. WM Suite Hub spoke

`Zero_Cdn_Spoke_Adapter`: slug `wm-zero-cdn-asset-localizer`, version from `WM_ZERO_CDN_VERSION`, health `HEALTHY` when the cached front-page scan was read with no reference and `DEGRADED` otherwise, SBOM component with the SHA-256 of the main file (`GPL-2.0-or-later`), settings URL `admin.php?page=wm-zero-cdn`, telemetry `front_page_scan` (`READ` / `UNREADABLE`), `front_page_cdn_refs` (`null` when unreadable), `preload_enabled`, `preloaded_fonts`, `local_cached_woff2`; actions `self_test` and `scan`.

---

## 10. Known limitations

1. Loopback requests fail on hosts that block them, behind HTTP authentication and in containers whose public port differs from the internal one; the scan then reports the page as unreadable.
2. Only the listed hosts, only references in the front page HTML; stylesheet contents, runtime-built URLs and subpages are not checked.
3. Non-WOFF2 `url()` references stay remote; downloaded stylesheets and fonts are served publicly from uploads as fetched.
4. Files are written with `file_put_contents()`, not the filesystem API; every write is checked.
5. `uninstall.php` preserves data unless `wm_zero_cdn_delete_data_on_uninstall` is enabled, and cleans the current site only on a multisite network.
6. WP-CLI runs with no user, so `wp cdn` is available to whoever can run `wp` on the server; the capability check applies to REST and the admin screen.

---

## 11. Tests

```bash
php tests/test-suite.php         # scan_html, scan_front_page, localize_css, preload, REST, hooks/bindings, admin script, uninstaller
php tests/test-uninstall.php     # default preservation + opt-in removal of options, transient, files, directory through WP_Filesystem
php tests/test-pot-freshness.php # languages/*.pot matches every translatable string in code
bash tests/run-mutations.sh      # each entry of tests/mutations.php must turn a suite red when it is undone
wp eval-file wp-content/plugins/wm-zero-cdn-asset-localizer/tests/wp-runtime-zero-cdn.php --user=1   # read-only, safe on a live site
```

---

## 12. Security disclosure

See [SECURITY.md](SECURITY.md).
