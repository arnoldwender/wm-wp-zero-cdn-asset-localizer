<?php
declare(strict_types=1);

namespace WMCyber\ZeroCDN\Localizer\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CdnDetector {
	/** Transient holding the last front-page scan (the hub cockpit reads it on every render). */
	public const FRONT_SCAN_TRANSIENT = 'wm_zero_cdn_front_scan';

	/**
	 * Host patterns, without a scheme: a reference counts however the page writes it.
	 *
	 * Until 2026-09-16 every pattern began with a literal `https?://` and covered only the stylesheet
	 * hosts, so eight of nine realistic forms produced no finding at all — a protocol-relative
	 * stylesheet URL, a preload or `@font-face` on Google's static font delivery host (where the font
	 * files themselves live), a `preconnect`, a JSON-escaped URL inside an inline script, jsDelivr
	 * `/gh/` paths, Font Awesome kit hosts and icon-family queries.
	 * A front page whose only Google traces were a preconnect and an inline `@font-face` was reported
	 * as having zero references, and the hub turned green on it.
	 *
	 * The path is optional, because a `preconnect` link targeting a bare origin with no trailing slash
	 * still opens the connection; requiring a slash after the host hid that exact case (measured on a
	 * local WordPress install, 2026-09-16). The stop set `[^"'()\s<>\\]` is what ends a URL in HTML: a
	 * quote, the end of a `url(…)`, white space, a tag boundary or the backslash of a JSON escape.
	 */
	private const CDN_PATTERNS = [
		'Google Fonts' => '#(?:https?:)?//fonts\.googleapis\.com(?:/[^"\'()\s<>\\\\]*)?#i',
		'Google Fonts' . self::STATIC_SUFFIX => '#(?:https?:)?//fonts\.gstatic\.com(?:/[^"\'()\s<>\\\\]*)?#i',
		'FontAwesome'  => '#(?:https?:)?//(?:use|kit|ka-f)\.fontawesome\.com(?:/[^"\'()\s<>\\\\]*)?#i',
		'CDNJS'        => '#(?:https?:)?//cdnjs\.cloudflare\.com(?:/[^"\'()\s<>\\\\]*)?#i',
		'jsDelivr'     => '#(?:https?:)?//cdn\.jsdelivr\.net(?:/[^"\'()\s<>\\\\]*)?#i',
		'Unpkg'        => '#(?:https?:)?//unpkg\.com(?:/[^"\'()\s<>\\\\]*)?#i',
	];

	/** Distinguishes the two Google entries in the pattern list; both report as "Google Fonts". */
	private const STATIC_SUFFIX = ' (gstatic)';

	/**
	 * Find references to the checked CDN hosts in an HTML string.
	 *
	 * A match means the browser loads something from that host, or opens a connection to it, and sends
	 * it the visitor's IP address; no legal classification is derived from the pattern (until
	 * 2026-09-14 every match carried a "High (Abmahngefahr)" label).
	 *
	 * The reported URL is what a browser requests: JSON escapes (`https:\/\/…`) and HTML entities
	 * (`&amp;`) are resolved, because the operator copies this value into the download form.
	 *
	 * @return list<array{provider: string, url: string, host: string}>
	 */
	public function scan_html( string $html ): array {
		$found = [];
		$seen  = [];
		// Inline scripts carry their URLs JSON-escaped (`https:\/\/example-cdn.test\/css2…`), which no
		// pattern anchored on `//` can match; the slashes are restored before the patterns run.
		$html = str_replace( '\\/', '/', $html );

		foreach ( self::CDN_PATTERNS as $provider => $pattern ) {
			$provider = str_replace( self::STATIC_SUFFIX, '', $provider );
			if ( ! preg_match_all( $pattern, $html, $matches ) ) {
				continue;
			}

			foreach ( $matches[0] as $raw ) {
				$url = self::normalise( $raw );
				if ( '' === $url || isset( $seen[ $url ] ) ) {
					continue;
				}
				$seen[ $url ] = true;
				$found[]      = [
					'provider' => $provider,
					'url'      => $url,
					'host'     => (string) wp_parse_url( $url, PHP_URL_HOST ),
				];
			}
		}

		return $found;
	}

	/** A matched string as the browser would request it: unescaped, entity-decoded, with a scheme. */
	private static function normalise( string $raw ): string {
		$url = html_entity_decode( str_replace( '\\/', '/', $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$url = rtrim( trim( $url ), '.,;' );
		if ( str_starts_with( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		return $url;
	}

	/**
	 * Fetch the site's own front page and scan its HTML.
	 *
	 * This is the measurement the admin screen, the hub and WP-CLI rely on. scan_enqueued_styles()
	 * alone cannot answer the question: in an admin, REST or CLI request the theme's frontend styles
	 * are never registered, so it reported "0 external calls, 100 % protected" on sites that load
	 * Google Fonts (measured 2026-09-14). When the page cannot be read the result says so
	 * (`ok` false) instead of reporting a clean site.
	 *
	 * @param bool $use_cache Reuse the last result for ten minutes (hub cockpit, dashboard widget).
	 * @return array{ok: bool, url: string, error: ?string, found: list<array{provider: string, url: string, host: string}>, scanned_at: int}
	 */
	public function scan_front_page( bool $use_cache = false ): array {
		if ( $use_cache ) {
			$cached = get_transient( self::FRONT_SCAN_TRANSIENT );
			if ( is_array( $cached ) && isset( $cached['ok'], $cached['found'] ) ) {
				return $cached;
			}
		}

		$url    = home_url( '/' );
		$result = [
			'ok'         => false,
			'url'        => $url,
			'error'      => null,
			'found'      => [],
			'scanned_at' => time(),
		];

		// wp_safe_remote_get(): the site's own host is allowed, other private addresses are not.
		$response = wp_safe_remote_get( $url, [ 'timeout' => 10, 'redirection' => 3 ] );
		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$result['error'] = sprintf( 'HTTP %d', (int) wp_remote_retrieve_response_code( $response ) );
		} else {
			$result['ok']    = true;
			$result['found'] = $this->scan_html( (string) wp_remote_retrieve_body( $response ) );
		}

		// A failed scan is cached for a minute, a successful one for ten: an unreadable page used to be
		// remembered as long as a measurement, so a site stayed "unreadable" after the cause was fixed.
		set_transient( self::FRONT_SCAN_TRANSIENT, $result, $result['ok'] ? 10 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Scan the stylesheets registered in the CURRENT request for the checked CDN hosts. In the admin,
	 * over REST or on WP-CLI that is not the theme's frontend set; use scan_front_page() for that.
	 *
	 * @return list<array{handle: string, provider: string, src: string}>
	 */
	public function scan_enqueued_styles(): array {
		global $wp_styles;
		if ( ! ( $wp_styles instanceof \WP_Styles ) ) {
			return [];
		}

		$external = [];

		foreach ( $wp_styles->registered as $handle => $style ) {
			$src = (string) $style->src;
			if ( empty( $src ) ) {
				continue;
			}

			foreach ( self::CDN_PATTERNS as $provider => $pattern ) {
				if ( preg_match( $pattern, $src ) ) {
					$external[] = [
						'handle'   => $handle,
						'provider' => str_replace( self::STATIC_SUFFIX, '', $provider ),
						'src'      => $src,
					];
					break;
				}
			}
		}

		return $external;
	}
}
