# Architecture — WM Zero-CDN Asset Localizer & Font Sovereignty Engine
`wm-zero-cdn-asset-localizer` · version `1.1.2` from the plugin header · WordPress 6.4+ · PHP 8.2+ · text domain `wm-zero-cdn-asset-localizer`

Measured against the source tree. Every figure below comes from the code, not from any document — the commands are given so they can be re-run.

```bash
# the inventory in this file
python3 the fleet's architecture inventory tool wm-zero-cdn-asset-localizer
```

---

## How a request flows

```
wp_head (priority 1)      PreloadInjector::inject_preload_tags()
                            └─ reads wm_zero_cdn_preload_enabled, then wm_zero_cdn_preload_fonts
                               → <link rel="preload"> tags, or nothing

POST /wp-json/wm-zero-cdn/v1/scan       RestController → CdnDetector::scan_front_page()
                                          └─ wp_safe_remote_get() of the front page
                                             → counts references to the five CDN hosts
                                             → unreadable page = ok:false, never "clean"

POST /wp-json/wm-zero-cdn/v1/localize   RestController → AssetDownloader
                                          └─ fetches the remote stylesheet, extracts font URLs,
                                             writes WOFF2 files after checking their signature
                                             → the result is NOT enqueued; nothing is replaced

wp cdn scan | wp cdn localize           ZeroCdnCommand, the same two paths without REST
```

Both REST routes require `manage_options` (`RestController::check_permissions()`), and the admin
script sends the `wp_rest` nonce.

---

## Wiring — what to touch for a given change

| Change | Files, in order | Precedent to copy |
|---|---|---|
| Recognise another CDN host | `includes/Scanner/CdnDetector.php` (the pattern list) → an assertion in `tests/test-suite.php` → an entry in `tests/mutations.php` | the existing `fonts.gstatic.com` and Font Awesome kit patterns, which carry **no fixed scheme** and resolve JSON escapes and HTML entities first |
| Add a REST route | `includes/API/RestController.php::register_routes()` → `check_permissions` on it → assertion + mutation | `/scan` at [`RestController.php:20`](../includes/API/RestController.php#L20) |
| Add a WP-CLI subcommand | a `public function <name>( array $args, array $assoc_args )` on `includes/CLI/ZeroCdnCommand.php` → name it in the class `Usage:` docblock (that is what `wp help` prints) → README | `scan()` at [`ZeroCdnCommand.php:28`](../includes/CLI/ZeroCdnCommand.php#L28) |
| Change what the hub sees | `includes/Adapters/Zero_Cdn_Spoke_Adapter.php` (`get_health_status()`, telemetry) | the existing `DEGRADED` path for an unreadable scan |
| Add an option | write it somewhere that can be reached from a screen, read it, **and add it to `uninstall.php`** — `tests/test-uninstall.php` fails otherwise | the four keys already listed there |

New code boots from `Core\Plugin::boot()`, which is the only place hooks are registered.

---

## Fragile spots

1. **A scan that could not read the front page is not a clean site.** `ok: false`, hub `DEGRADED`, telemetry `null`. Turning a missing measurement into "clean" is the defect fixed on 2026-09-14, and it is the failure mode this plugin is most exposed to: the result looks like success.
2. **Narrowing a scanner pattern silently un-detects a site.** The patterns carry no fixed scheme on purpose. A site that loads Google Fonts must not produce a green screen.
3. **A write that failed is not a download.** `file_put_contents` and `wp_mkdir_p` are checked, `is_file()` decides what is already stored, and a font that could not be written is listed as failed. Never report a figure that is not the number of files on disk.
4. **The downloader refuses before it requests:** `http`/`https` only, never a private, loopback or reserved IP literal, a CSS content type, response-size limits, at most 50 fonts per stylesheet, cross-host `.woff2` URLs restricted to `AssetDownloader::DEFAULT_ALLOWED_FONT_HOSTS` (filterable via `wm_zero_cdn_allowed_font_hosts`), and `remote_get_pinned()` pinning `CURLOPT_RESOLVE` to the validated IP via `http_api_curl` to close DNS-rebinding TOCTOU.
5. **The downloaded files are not enqueued.** Nothing is intercepted or replaced automatically; the operator still has to point the site at the local copies. A README that implies otherwise would be describing a feature that does not exist.
6. **Data preservation on uninstall is opt-in.** `wm_zero_cdn_preload_enabled`, `wm_zero_cdn_preload_fonts` and `wm_zero_cdn_delete_data_on_uninstall` are registered via `register_setting()` on the *Einstellungen* tab; `uninstall.php` leaves options and local WOFF2 files untouched unless `wm_zero_cdn_delete_data_on_uninstall` is enabled.

---

<!-- wm-measured-inventory -->

## Measured inventory

Regenerated from the code, never hand-edited. Everything above this line is written by hand and stays.

```bash
python3 the fleet's architecture inventory tool wm-zero-cdn-asset-localizer --append-to docs/ARCHITECTURE.md
```

## Inventory by layer

**11 PHP files, 1986 lines** (excluding `tests/`, `vendor/`, `node_modules/`, `languages/`).

| Layer | Files | Lines |
|---|---:|---:|
| `(raíz)` | 2 | 132 |
| `includes/API` | 1 | 83 |
| `includes/Adapters` | 1 | 155 |
| `includes/Admin` | 1 | 571 |
| `includes/CLI` | 1 | 81 |
| `includes/Core` | 1 | 63 |
| `includes/Downloader` | 1 | 463 |
| `includes/Integrations` | 1 | 212 |
| `includes/Preloader` | 1 | 51 |
| `includes/Scanner` | 1 | 175 |

---

## Classes

| Class | File |
|---|---|
| `AdminDashboard` | [`includes/Admin/AdminDashboard.php`](../includes/Admin/AdminDashboard.php) |
| `AssetDownloader` | [`includes/Downloader/AssetDownloader.php`](../includes/Downloader/AssetDownloader.php) |
| `BlockIntegrations` | [`includes/Integrations/BlockIntegrations.php`](../includes/Integrations/BlockIntegrations.php) |
| `CdnDetector` | [`includes/Scanner/CdnDetector.php`](../includes/Scanner/CdnDetector.php) |
| `Plugin` | [`includes/Core/Plugin.php`](../includes/Core/Plugin.php) |
| `PreloadInjector` | [`includes/Preloader/PreloadInjector.php`](../includes/Preloader/PreloadInjector.php) |
| `RestController` | [`includes/API/RestController.php`](../includes/API/RestController.php) |
| `ZeroCdnCommand` | [`includes/CLI/ZeroCdnCommand.php`](../includes/CLI/ZeroCdnCommand.php) |
| `Zero_Cdn_Spoke_Adapter` | [`includes/Adapters/Zero_Cdn_Spoke_Adapter.php`](../includes/Adapters/Zero_Cdn_Spoke_Adapter.php) |

---

## Entry points

**REST routes:** `/localize`, `/scan`

**WP-CLI:** `cdn`

---

## Persistence — who writes each key and who reads it

| Key | Written by | Read by |
|---|---|---|
| `wm_zero_cdn_delete_data_on_uninstall` | `includes/Admin/AdminDashboard.php` | `includes/Admin/AdminDashboard.php`, `uninstall.php` |
| `wm_zero_cdn_preload_enabled` | `includes/Admin/AdminDashboard.php` | `includes/Adapters/Zero_Cdn_Spoke_Adapter.php`, `includes/Admin/AdminDashboard.php`, `includes/Preloader/PreloadInjector.php` |
| `wm_zero_cdn_preload_fonts` | `includes/Admin/AdminDashboard.php` | `includes/Adapters/Zero_Cdn_Spoke_Adapter.php`, `includes/Admin/AdminDashboard.php`, `includes/Preloader/PreloadInjector.php` |

Every key has exactly one writing layer and at least one reader.


---

## Gates

- `tests/mutations.php`
- `tests/run-mutations.sh`
- `tests/test-pot-freshness.php`
- `tests/test-suite.php`
- `tests/test-ui-family.php`
- `tests/test-uninstall.php`
- `tests/wp-runtime-zero-cdn.php`
- `.github/workflows/ci.yml`

What CI actually runs is in the workflow files above — a test file that no workflow invokes is not a gate.

---

## Drift detected against the documentation

Nothing measurable: versions agree and every class named in the documents exists.

