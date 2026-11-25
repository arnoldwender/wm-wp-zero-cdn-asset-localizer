<?php
declare(strict_types=1);

namespace WMCyber\ZeroCDN\Localizer\Downloader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AssetDownloader {
	/** First four bytes of every WOFF2 file. */
	private const WOFF2_SIGNATURE = 'wOF2';

	/** Ceilings for one download, so a stylesheet cannot turn a REST request into an open-ended job. */
	private const MAX_CSS_BYTES   = 2097152;
	private const MAX_FONT_BYTES  = 8388608;
	private const MAX_FONT_FILES  = 50;

	/**
	 * Default cross-origin CDN font hosts allowed when a stylesheet references WOFF2 files on another host.
	 * Same-host references (stylesheet host === font host, e.g. stylesheets hosted directly on cdnjs,
	 * jsDelivr, unpkg, or Font Awesome) are always permitted; additional hosts may be allowed via the
	 * `wm_zero_cdn_allowed_font_hosts` filter.
	 *
	 * @var list<string>
	 */
	public const DEFAULT_ALLOWED_FONT_HOSTS = [
		'fonts.gstatic.com',
		'fonts.googleapis.com',
		'fonts.bunny.net',
		'ka-f.fontawesome.com',
		'use.typekit.net',
	];

	/**
	 * Download a remote stylesheet and the WOFF2 files it references into uploads/wm-zero-cdn/ and
	 * rewrite the font URLs to the local copies. The local stylesheet is NOT enqueued: the site keeps
	 * loading the remote one until the theme or a snippet is pointed at `local_css_url`.
	 *
	 * Until 2026-09-14 every HTTP answer counted: a 404 page was saved as the stylesheet and error
	 * bodies as `.woff2` files, all reported as downloaded, and requests went through wp_remote_get()
	 * without the private-address protection of wp_safe_remote_get(). Until 2026-09-16 a write that
	 * failed was still reported as a download, any 200 body was stored as CSS whatever its type, six
	 * of seven `url()` spellings were neither fetched nor reported, and the font counter counted
	 * duplicates, cache hits and failed writes.
	 *
	 * @return array{success: bool, error?: string, remote_url?: string, local_css_url?: string, downloaded_fonts?: list<string>, failed_fonts?: list<string>}
	 */
	public function localize_css( string $remote_url ): array {
		$refusal = self::refuse_url( $remote_url );
		if ( null !== $refusal ) {
			return [
				'success' => false,
				'error'   => $refusal,
			];
		}

		$upload_dir  = wp_upload_dir();
		$base_folder = $upload_dir['basedir'] . '/wm-zero-cdn/';
		$base_url    = $upload_dir['baseurl'] . '/wm-zero-cdn/';

		// Fetch with a modern browser User-Agent so Google returns WOFF2 definitions.
		$response = self::remote_get_pinned( $remote_url, [
			'timeout'             => 15,
			'limit_response_size' => self::MAX_CSS_BYTES,
			'user-agent'          => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return [
				'success' => false,
				'error'   => sprintf( 'The stylesheet URL answered HTTP %d.', $code ),
			];
		}

		// A 200 answer is not a stylesheet: example.org answered 200 with an HTML page and it was
		// stored as the site's CSS.
		// A server that declares nothing is not treated as having declared CSS: the check used to skip
		// itself on an absent header, so any body from such a server was written into uploads/ as the
		// site's stylesheet.
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( ! str_contains( $content_type, 'css' ) ) {
			return [
				'success' => false,
				'error'   => '' === $content_type
					? 'The URL answered without a content type, so it is not treated as a stylesheet.'
					: sprintf( 'The URL answered with %s, not a stylesheet.', $content_type ),
			];
		}

		$css_content = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $css_content ) ) {
			return [
				'success' => false,
				'error'   => 'Empty response from CDN.',
			];
		}

		if ( ! is_dir( $base_folder ) && ! wp_mkdir_p( $base_folder ) ) {
			return [
				'success' => false,
				'error'   => 'The folder uploads/wm-zero-cdn/ could not be created.',
			];
		}
		self::write_directory_guard( $base_folder );

		$filename        = 'local_' . substr( hash( 'sha256', $remote_url ), 0, 16 ) . '.css';
		$local_file_path = $base_folder . $filename;
		$local_file_url  = $base_url . $filename;

		// Every url(…woff2) spelling a stylesheet may use: quoted or bare, absolute, protocol-relative
		// or relative, with or without a query, with or without padding inside the parentheses.
		$font_pattern     = '/url\(\s*([\'"]?)([^\'")\s]+\.woff2(?:\?[^\'")\s]*)?)\1\s*\)/i';
		$downloaded_fonts = [];
		$failed_fonts     = [];
		$resolved         = [];
		$attempted        = 0;

		$rewritten_css = preg_replace_callback(
			$font_pattern,
			function ( array $matches ) use ( $base_folder, $base_url, $remote_url, &$downloaded_fonts, &$failed_fonts, &$resolved, &$attempted ) {
				$reference = $matches[2];
				$font_url  = self::absolute_url( $reference, $remote_url );

				if ( null === $font_url ) {
					if ( ! isset( $resolved[ $reference ] ) ) {
						$resolved[ $reference ] = false;
						$failed_fonts[]         = $reference;
					}
					return $matches[0];
				}

				// One attempt per distinct URL: the same font referenced once per weight used to be
				// fetched, counted and reported again for every occurrence.
				if ( isset( $resolved[ $font_url ] ) ) {
					return false === $resolved[ $font_url ] ? $matches[0] : 'url(' . $resolved[ $font_url ] . ')';
				}

				if ( ! self::is_allowed_font_host( (string) $font_url, $remote_url ) ) {
					$resolved[ $font_url ] = false;
					$failed_fonts[]        = $font_url;
					return $matches[0];
				}

				if ( null !== self::refuse_url( $font_url ) || $attempted >= self::MAX_FONT_FILES ) {
					$resolved[ $font_url ] = false;
					$failed_fonts[]        = $font_url;
					return $matches[0];
				}

				$font_filename  = 'font_' . substr( hash( 'sha256', $font_url ), 0, 16 ) . '.woff2';
				$font_dest_path = $base_folder . $font_filename;
				$font_dest_url  = $base_url . $font_filename;

				// is_file(), not file_exists(): a directory carrying the file's name counted as a cached
				// font, so the stylesheet pointed at something that never was a font.
				if ( ! is_file( $font_dest_path ) ) {
					++$attempted;
					$font_res  = self::remote_get_pinned( $font_url, [ 'timeout' => 20, 'limit_response_size' => self::MAX_FONT_BYTES ] );
					$font_ok   = ! is_wp_error( $font_res ) && 200 === (int) wp_remote_retrieve_response_code( $font_res );
					$font_data = $font_ok ? (string) wp_remote_retrieve_body( $font_res ) : '';
					// A write that fails leaves no file: reporting it as downloaded told the operator the
					// font was local while the page still asked the remote host for it.
					if ( ! $font_ok || self::WOFF2_SIGNATURE !== substr( $font_data, 0, 4 ) || false === file_put_contents( $font_dest_path, $font_data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- binary font file; the filesystem API is not available during a REST request without credentials.
						$resolved[ $font_url ] = false;
						$failed_fonts[]        = $font_url;
						return $matches[0];
					}
				}

				$resolved[ $font_url ] = $font_dest_url;
				$downloaded_fonts[]    = $font_dest_url;
				return 'url(' . $font_dest_url . ')';
			},
			$css_content
		);

		if ( null === $rewritten_css ) {
			return [
				'success' => false,
				'error'   => 'The stylesheet could not be rewritten.',
			];
		}

		if ( false === file_put_contents( $local_file_path, $rewritten_css ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- see above.
			return [
				'success' => false,
				'error'   => 'The stylesheet could not be written to uploads/wm-zero-cdn/.',
			];
		}

		$report = [
			'success'          => true,
			'remote_url'       => $remote_url,
			'local_css_url'    => $local_file_url,
			// The files that are on disk, each once: the old counter counted duplicates, cache hits and
			// failed writes, and the CLI and the admin screen printed that number. A repeated URL is
			// answered from $resolved before it reaches either list, so no second pass is needed.
			'downloaded_fonts' => array_values( $downloaded_fonts ),
			'failed_fonts'     => array_values( $failed_fonts ),
		];

		if ( function_exists( 'do_action' ) ) {
			do_action( 'wm_zero_cdn_stylesheet_localized', $report, $remote_url );
		}

		return $report;
	}

	/**
	 * Whether a font URL's host may be requested from a downloaded stylesheet.
	 *
	 * Same-host references (stylesheet host === font host) are allowed, and cross-host references are
	 * restricted to `DEFAULT_ALLOWED_FONT_HOSTS` (filterable via `wm_zero_cdn_allowed_font_hosts`).
	 */
	public static function is_allowed_font_host( string $font_url, string $stylesheet_url ): bool {
		$font_host = strtolower( (string) ( wp_parse_url( $font_url, PHP_URL_HOST ) ?? '' ) );
		$css_host  = strtolower( (string) ( wp_parse_url( $stylesheet_url, PHP_URL_HOST ) ?? '' ) );

		if ( '' === $font_host ) {
			return false;
		}
		if ( $font_host === $css_host ) {
			return true;
		}

		$allowed = function_exists( 'apply_filters' )
			? apply_filters( 'wm_zero_cdn_allowed_font_hosts', self::DEFAULT_ALLOWED_FONT_HOSTS, $stylesheet_url )
			: self::DEFAULT_ALLOWED_FONT_HOSTS;

		if ( ! is_array( $allowed ) ) {
			return false;
		}

		$allowed_lower = array_values(
			array_filter(
				array_map(
					static fn( $host ): string => is_string( $host ) ? strtolower( trim( $host ) ) : '',
					$allowed
				),
				static fn( string $host ): bool => '' !== $host
			)
		);

		return in_array( $font_host, $allowed_lower, true );
	}

	/**
	 * Why this URL is not fetched, or null when it may be.
	 *
	 * WordPress 7.1 rejects loopback, private, link-local (169.254.0.0/16, cloud metadata), CGNAT and
	 * reserved ranges in `wp_http_validate_url()` (wp-includes/http.php:609-621, measured 2026-09-16),
	 * but the header allows WordPress 6.4, where that list is shorter.
	 *
	 * Until 2026-09-16 this looked only at the literal host, so it refused `127.0.0.1` and allowed
	 * every other spelling of the same machine. Measured on a local WordPress install against this method: `2130706433`,
	 * `0177.0.0.1`, `127.1`, `[::1]`, `[::ffff:127.0.0.1]` and `localhost` all came back allowed — and
	 * the font URLs come from the remote stylesheet, not from the administrator. The host is resolved
	 * now and every address behind it is checked.
	 *
	 * `remote_get_pinned()` closes the DNS-rebinding TOCTOU window between validation and cURL
	 * socket connect by re-resolving via `resolve_safe_target()` and pinning `CURLOPT_RESOLVE`
	 * to the validated public IP through a one-shot `http_api_curl` hook.
	 */
	private static function refuse_url( string $url ): ?string {
		return self::resolve_safe_target( $url )['error'];
	}

	/**
	 * Validate a URL and resolve its public target IP so both `refuse_url()` and `remote_get_pinned()`
	 * share one check and cURL can pin the validated address via `CURLOPT_RESOLVE`.
	 *
	 * @return array{error: string}|array{error: null, host: string, port: int, ip: string}
	 */
	private static function resolve_safe_target( string $url ): array {
		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = (string) ( $parts['host'] ?? '' );

		if ( '' === $host || ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return [ 'error' => 'Only http and https URLs are downloaded.' ];
		}

		// An IPv6 literal arrives bracketed: [::1].
		$literal   = trim( $host, '[]' );
		$addresses = filter_var( $literal, FILTER_VALIDATE_IP ) ? [ $literal ] : self::host_addresses( $host );

		if ( [] === $addresses ) {
			// A name nobody can resolve is not fetched: refusing is the answer that does not assume.
			return [ 'error' => 'The host name could not be resolved.' ];
		}

		$first_safe_ip = null;
		foreach ( $addresses as $address ) {
			$normalized = self::unmap_ipv4( $address );
			if ( ! filter_var( $normalized, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return [ 'error' => 'The host is, or resolves to, a private, loopback or reserved address.' ];
			}
			if ( null === $first_safe_ip ) {
				$first_safe_ip = $normalized;
			}
		}

		$port = isset( $parts['port'] ) && (int) $parts['port'] > 0
			? (int) $parts['port']
			: ( 'https' === $scheme ? 443 : 80 );

		return [
			'error' => null,
			'host'  => strtolower( $host ),
			'port'  => $port,
			'ip'    => (string) $first_safe_ip,
		];
	}

	/**
	 * Fetch a URL via `wp_safe_remote_get()` with DNS pinned via `CURLOPT_RESOLVE` to the safe IP
	 * returned by `resolve_safe_target()`, closing the DNS-rebinding TOCTOU window.
	 *
	 * @param array<string, mixed> $args Request arguments passed to `wp_safe_remote_get()`.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function remote_get_pinned( string $url, array $args ): mixed {
		$target = self::resolve_safe_target( $url );
		if ( null !== $target['error'] ) {
			if ( class_exists( 'WP_Error' ) ) {
				return new \WP_Error( 'wm_zero_cdn_unsafe_target', $target['error'] );
			}
			return [
				'response' => [ 'code' => 0 ],
				'body'     => '',
				'headers'  => [],
			];
		}

		$host = (string) ( $target['host'] ?? '' );
		$port = (int) ( $target['port'] ?? 443 );
		$ip   = (string) ( $target['ip'] ?? '' );

		$pin_hook = null;
		if ( function_exists( 'add_action' ) && defined( 'CURLOPT_RESOLVE' ) && '' !== $host && '' !== $ip ) {
			$pin_entry = "{$host}:{$port}:{$ip}";
			$pin_hook  = static function ( $handle ) use ( $pin_entry ): void {
				if ( function_exists( 'curl_setopt' ) && ( ( is_object( $handle ) && $handle instanceof \CurlHandle ) || is_resource( $handle ) ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- CURLOPT_RESOLVE DNS pinning on WordPress's own http_api_curl handle to close DNS-rebinding TOCTOU.
					curl_setopt( $handle, CURLOPT_RESOLVE, [ $pin_entry ] );
				}
			};
			add_action( 'http_api_curl', $pin_hook, 10, 1 );
		}

		try {
			return wp_safe_remote_get( $url, $args );
		} finally {
			if ( null !== $pin_hook && function_exists( 'remove_action' ) ) {
				remove_action( 'http_api_curl', $pin_hook, 10 );
			}
		}
	}

	/**
	 * An IPv4-mapped IPv6 address as its IPv4 form; anything else unchanged.
	 *
	 * `::ffff:127.0.0.1` is loopback written as IPv6, and the private/reserved flags of
	 * FILTER_VALIDATE_IP do not reach through the mapping on **PHP 8.2** — they do on 8.3. The header
	 * says `Requires PHP: 8.2`, so on the lowest supported version the downloader accepted
	 * `http://[::ffff:127.0.0.1]/x.css` and would have asked the site's own loopback for it. Measured
	 * 2026-09-19: the suite failed on 8.2 and passed on 8.3, from the same source.
	 *
	 * Written against the packed bytes rather than the text, so every spelling of the mapping is
	 * covered — `::ffff:127.0.0.1` and `::ffff:7f00:1` are the same 16 bytes.
	 */
	private static function unmap_ipv4( string $address ): string {
		$packed = @inet_pton( $address ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a value that is not an address is returned unchanged and refused by the caller.
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $address;
		}
		// ::ffff:0:0/96 — the IPv4-mapped range.
		if ( "\0\0\0\0\0\0\0\0\0\0\xff\xff" !== substr( $packed, 0, 12 ) ) {
			return $address;
		}
		$unmapped = inet_ntop( substr( $packed, 12 ) );
		return false === $unmapped ? $address : $unmapped;
	}

	/**
	 * Every IP address a host name resolves to.
	 *
	 * The filter `wm_zero_cdn_host_addresses` short-circuits the lookup with an array of addresses;
	 * the test suite uses it so the suite needs no network, and a site with a resolver of its own can
	 * answer for a host. Returning an empty array refuses the URL.
	 *
	 * @param string $host Host name.
	 * @return array<int, string>
	 */
	private static function host_addresses( string $host ): array {
		$supplied = apply_filters( 'wm_zero_cdn_host_addresses', null, $host );
		if ( is_array( $supplied ) ) {
			return array_values( array_filter( $supplied, static fn( $a ): bool => (bool) filter_var( (string) $a, FILTER_VALIDATE_IP ) ) );
		}

		$addresses = [];
		$records   = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_A | DNS_AAAA ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a name that does not resolve is refused below, not an error.
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				foreach ( [ 'ip', 'ipv6' ] as $field ) {
					if ( ! empty( $record[ $field ] ) ) {
						$addresses[] = (string) $record[ $field ];
					}
				}
			}
		}

		if ( [] === $addresses ) {
			// gethostbyname() hands back the input unchanged when it fails, and it also resolves the
			// decimal and octal spellings of an IPv4 address that FILTER_VALIDATE_IP does not accept.
			$resolved = @gethostbyname( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.
			if ( $resolved !== $host && filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
				$addresses[] = $resolved;
			}
		}

		return $addresses;
	}

	/** A `url()` reference as an absolute URL, or null when it cannot become one. */
	private static function absolute_url( string $reference, string $stylesheet_url ): ?string {
		if ( preg_match( '#^https?://#i', $reference ) ) {
			return $reference;
		}
		if ( str_starts_with( $reference, '//' ) ) {
			return 'https:' . $reference;
		}

		$parts = wp_parse_url( $stylesheet_url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		$base = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( str_starts_with( $reference, '/' ) ) {
			return $base . $reference;
		}

		$directory = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', (string) $parts['path'] ) : '/';
		return $base . $directory . $reference;
	}

	/** An empty index.php, so a server with directory indexing does not list the downloaded files. */
	private static function write_directory_guard( string $folder ): void {
		$guard = $folder . 'index.php';
		if ( ! is_file( $guard ) ) {
			file_put_contents( $guard, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- see above.
		}
	}
}
