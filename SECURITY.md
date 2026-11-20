# Security Policy — WM Zero-CDN Asset Localizer & Font Sovereignty Engine

## Supported Versions

| Version | Supported |
| --- | --- |
| `1.2.x` (`1.2.0`) | Current release branch |

---

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities, and **do not** send private inquiries or support emails to `arnoldwender.com`, email addresses, or social/direct messages (private channels are unmonitored for open-source plugins).

Submit security reports **exclusively** via GitHub Private Vulnerability Reporting:

- **Security Advisories:** [github.com/arnoldwender/wm-wp-zero-cdn-asset-localizer/security/advisories/new](https://github.com/arnoldwender/wm-wp-zero-cdn-asset-localizer/security/advisories/new)

> **Best-Effort Only / Zero Warranty / Zero Support:** This open-source repository carries **zero technical or user support** ([SUPPORT.md](SUPPORT.md)) and **zero response-time SLA**. Advisory reports may be reviewed at the author's sole discretion without obligation, timeline, or liability ("AS IS" under [LICENSE](LICENSE)).

### What to Include:
1. Proof of Concept (PoC) script or detailed reproduction steps.
2. Affected component: the REST routes `wm-zero-cdn/v1/scan` and `/localize`, the downloader (outbound requests, files written to `uploads/wm-zero-cdn/`), the admin screen and its settings/script, the preload output, Block Bindings / GenerateBlocks status callbacks, or the `wp cdn` commands.
3. Potential impact assessment (e.g., a request reaching a private address, a non-font file written as `.woff2`, script injected into the admin screen).

### Response SLA:
- **Acknowledgement:** Within 24 hours.
- **Triage & Patch:** Within 72 hours for Critical/High vulnerabilities.
- **CVE Attribution:** Coordinated disclosure with reporter credit.
