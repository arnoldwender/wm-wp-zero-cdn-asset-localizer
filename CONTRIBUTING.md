# Contributing to WM Zero-CDN Asset Localizer

Thank you for your interest in contributing to **WM Zero-CDN Asset Localizer**. As part of the **WM Enterprise Suite**, this repository adheres to high standards of security, accessibility, and legal compliance.

---

## Zero Support & Issue Scope

**Zero technical, configuration, or private support is provided.** See [SUPPORT.md](SUPPORT.md):

- Report reproducible code defects **exclusively** via public [GitHub Issues](https://github.com/arnoldwender/wm-wp-zero-cdn-asset-localizer/issues).
- **Do not** contact the author privately via email, `arnoldwender.com`, or direct messages — private inquiries are never answered.
- Issues and pull requests carry no response or maintenance SLA, and the software is provided strictly **"AS IS"** without warranty or liability.

---

## Core Development Standards

1. **PHP 8.2+ Strict Typing:** Every PHP file must start with `declare(strict_types=1);` (the header declares `Requires PHP: 8.2`).
2. **Zero-CDN Architecture:** No external resources (CDNs, Google Fonts, external telemetry) may be loaded at runtime by the plugin's own screens.
3. **Honest measurements:** a check that could not measure reports "unreadable", never "clean"; figures on screens come from code, not from fixed strings. `README.md` and `DEVELOPERS.md` describe what the plugin registers and are updated in the same pull request.
4. **Outbound HTTP:** `wp_safe_remote_get()` with status checks; files are written only after their content is verified.
5. **BFSG 2025 / WCAG 2.2:** All user interfaces must achieve a minimum 4.5:1 contrast ratio and provide 44px minimum touch targets; icons are dashicons, never emoji.

---

## Development Workflow

```bash
# 1. Clone the repository
git clone https://github.com/arnoldwender/wm-wp-zero-cdn-asset-localizer.git
cd wm-zero-cdn-asset-localizer

# 2. Run the standalone suites (no WordPress needed)
php tests/test-suite.php
php tests/test-uninstall.php
bash tests/run-mutations.sh

# 3. Plugin Check before a pull request (the plugin-check plugin, in a WordPress install)
wp plugin check wm-zero-cdn-asset-localizer
```

---

## Commit Conventions

- Use conventional format: `[Feat] ...`, `[Fix] ...`, `[Security] ...`, `[Docs] ...`.
- All pull requests must include updated assertions in `tests/test-suite.php` and mutations in `tests/mutations.php`.

For support and liability rules, read [SUPPORT.md](SUPPORT.md).
