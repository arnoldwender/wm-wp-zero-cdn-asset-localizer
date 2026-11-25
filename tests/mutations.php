<?php
/**
 * Mutations: each entry undoes one correction of this release, and tests/run-mutations.sh requires a standalone
 * suite to turn red for every one of them. An entry whose search string no longer occurs exactly once is reported
 * as "not applied" and fails the run, so the list cannot rot in silence.
 *
 * Format: [ description, [ [ relative file, search, replace ], ... ] ]
 *
 * @package WMCyber\ZeroCDN\Localizer
 */

declare(strict_types=1);

return [
	[
		'the refusal looks at the literal host again, so every other spelling of a private address passes',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				'self::host_addresses( $host );',
				"[ '93.184.216.34' ];",
			],
		],
	],
	[
		'a server that declares no content type is treated as having declared CSS again',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				"if ( ! str_contains( \$content_type, 'css' ) ) {",
				"if ( '' !== \$content_type && ! str_contains( \$content_type, 'css' ) ) {",
			],
		],
	],
	[
		'the scanner requires a literal scheme again (a protocol-relative reference disappears)',
		[
			[
				'includes/Scanner/CdnDetector.php',
				'(?:https?:)?//fonts\.googleapis\.com',
				'https?://fonts\.googleapis\.com',
			],
		],
	],
	[
		'the scanner stops looking at fonts.gstatic.com, where the font files live',
		[
			[
				'includes/Scanner/CdnDetector.php',
				'fonts\.gstatic\.com',
				'fonts\.gstatic\.invalid',
			],
		],
	],
	[
		'the scanner stops looking at the Font Awesome kit host',
		[
			[
				'includes/Scanner/CdnDetector.php',
				'(?:use|kit|ka-f)\.fontawesome\.com',
				'(?:use|kit)\.fontawesome\.com',
			],
		],
	],
	[
		// Nowdoc, because these searches carry backslashes and quotes exactly as the source writes them.
		'a matched URL may swallow the markup after it again (unquoted url() in a style block)',
		[
			[
				'includes/Scanner/CdnDetector.php',
				<<<'SEARCH'
//fonts\.googleapis\.com(?:/[^"\'()\s<>\\\\]*)?#i
SEARCH,
				<<<'REPLACE'
//fonts\.googleapis\.com/[^"\']*#i
REPLACE,
			],
		],
	],
	[
		'JSON-escaped slashes are no longer restored before the patterns run',
		[
			[
				'includes/Scanner/CdnDetector.php',
				<<<'SEARCH'
$html = str_replace( '\\/', '/', $html );
SEARCH,
				'',
			],
		],
	],
	[
		'HTML entities survive into the URL the operator copies',
		[
			[
				'includes/Scanner/CdnDetector.php',
				<<<'SEARCH'
html_entity_decode( str_replace( '\\/', '/', $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
SEARCH,
				<<<'REPLACE'
str_replace( '\\/', '/', $raw )
REPLACE,
			],
		],
	],
	[
		'any 200 answer is stored as the stylesheet again, whatever its content type',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				"if ( ! str_contains( \$content_type, 'css' ) ) {",
				'if ( false ) {',
			],
		],
	],
	[
		'the downloader stops refusing private addresses and foreign schemes itself',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				'$refusal = self::refuse_url( $remote_url );',
				'$refusal = null;',
			],
			[
				'includes/Downloader/AssetDownloader.php',
				"if ( null !== \$target['error'] ) {",
				'if ( false ) {',
			],
		],
	],
	[
		'only bare absolute url() references are localized again',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				<<<'SEARCH'
([^\'")\s]+\.woff2(?:\?[^\'")\s]*)?)
SEARCH,
				<<<'REPLACE'
(https?:\/\/[^)\s]+\.woff2)
REPLACE,
			],
		],
	],
	[
		'a relative url() is no longer resolved against the stylesheet',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				'return $base . $directory . $reference;',
				'return null;',
			],
		],
	],
	[
		'a font that was already on disk is counted again on every reference',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				'if ( isset( $resolved[ $font_url ] ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'a font whose write failed is reported as downloaded again',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				'|| false === file_put_contents( $font_dest_path, $font_data )',
				'|| ( file_put_contents( $font_dest_path, $font_data ) && false )',
			],
		],
	],
	[
		'a directory carrying the font file name counts as a cached font again',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				'if ( ! is_file( $font_dest_path ) ) {',
				'if ( ! file_exists( $font_dest_path ) ) {',
			],
		],
	],
	[
		'the download folder is left without its index.php guard',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				'self::write_directory_guard( $base_folder );',
				'// no guard',
			],
		],
	],
	[
		'preload slices the list before filtering it again (a valid file is dropped)',
		[
			[
				'includes/Preloader/PreloadInjector.php',
				'foreach ( $preloaded_fonts as $font_file ) {',
				'foreach ( array_slice( $preloaded_fonts, 0, 3 ) as $font_file ) {',
			],
		],
	],
	[
		'preload prints links for files that are not in uploads',
		[
			[
				'includes/Preloader/PreloadInjector.php',
				'|| ! is_file( $base_folder . $font_name )',
				'',
			],
		],
	],
	[
		'the localize route stops declaring the URI format of its argument',
		[
			[
				'includes/API/RestController.php',
				"'format'   => 'uri',",
				"'format_undeclared' => 'uri',",
			],
		],
	],
	[
		'a failed download answers HTTP 200 again',
		[
			[
				'includes/API/RestController.php',
				'$success ? 200 : 502',
				'200',
			],
		],
	],
	[
		'the admin download script loses its request failure path',
		[
			[
				'includes/Admin/AdminDashboard.php',
				'.catch( function ( err ) {',
				'.then( function ( err ) {',
			],
		],
	],
	[
		'the uninstaller declares unprefixed globals again',
		[
			[
				'uninstall.php',
				'$wm_zero_cdn_options = [',
				'$options = [',
			],
		],
	],
	// The mutation undoes the NORMALISATION, not its call site. Removing the call
	// (`self::unmap_ipv4( $address )` → `$address`) only turns the suite red on PHP 8.2: from 8.3 the
	// private/reserved flags reach through the mapping on their own, so that mutation would survive on
	// the version this gate runs under and report a hole in the gate where there is none.
	[
		'an IPv4-mapped IPv6 address is no longer reduced to its IPv4 form',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				'if ( "\0\0\0\0\0\0\0\0\0\0\xff\xff" !== substr( $packed, 0, 12 ) ) {',
				'if ( true ) {',
			],
		],
	],
	[
		'uninstall deletes options and local font files even without explicit opt-in',
		[
			[
				'uninstall.php',
				'if ( ! $wm_zero_cdn_delete_data ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'register_settings stops registering the opt-in uninstall setting',
		[
			[
				'includes/Admin/AdminDashboard.php',
				"register_setting(\n\t\t\t'wm_zero_cdn_settings',\n\t\t\t'wm_zero_cdn_delete_data_on_uninstall',",
				"register_setting(\n\t\t\t'wm_zero_cdn_settings',\n\t\t\t'wm_zero_cdn_unused_option',",
			],
		],
	],
	[
		'Plugin::boot() stops firing the wm_zero_cdn_ready action',
		[
			[
				'includes/Core/Plugin.php',
				"do_action( 'wm_zero_cdn_ready', \$this );",
				'// ready action omitted',
			],
		],
	],
	[
		'BlockIntegrations reports an unverified site as clean instead of unverified',
		[
			[
				'includes/Integrations/BlockIntegrations.php',
				"'state'       => 'unverified',",
				"'state'       => 'clean',",
			],
		],
	],
	[
		'BlockIntegrations stops registering the wm-zero-cdn/status Block Bindings source',
		[
			[
				'includes/Integrations/BlockIntegrations.php',
				"'wm-zero-cdn/status',",
				"'wm-zero-cdn/disabled',",
			],
		],
	],
	[
		'BlockIntegrations stops registering the wm_zero_cdn_status GenerateBlocks Dynamic Tag',
		[
			[
				'includes/Integrations/BlockIntegrations.php',
				"'tag'         => 'wm_zero_cdn_status',",
				"'tag'         => 'wm_zero_cdn_disabled',",
			],
		],
	],
	[
		'localize_css stops checking the font host allowlist and fetches arbitrary external font hosts',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				'if ( ! self::is_allowed_font_host( (string) $font_url, $remote_url ) ) {',
				'if ( false ) {',
			],
		],
	],
	[
		'is_allowed_font_host ignores the wm_zero_cdn_allowed_font_hosts filter',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				"apply_filters( 'wm_zero_cdn_allowed_font_hosts', self::DEFAULT_ALLOWED_FONT_HOSTS, \$stylesheet_url )",
				'self::DEFAULT_ALLOWED_FONT_HOSTS',
			],
		],
	],
	[
		'remote_get_pinned stops refusing unsafe resolved targets before calling wp_safe_remote_get',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				"if ( null !== \$target['error'] ) {",
				'if ( false ) {',
			],
		],
	],
	[
		'remote_get_pinned stops pinning CURLOPT_RESOLVE on http_api_curl (reopening DNS rebinding TOCTOU)',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				'curl_setopt( $handle, CURLOPT_RESOLVE, [ $pin_entry ] );',
				'// no CURLOPT_RESOLVE pinning',
			],
		],
	],
	[
		'remote_get_pinned leaves its http_api_curl pinning hook attached after the request finishes',
		[
			[
				'includes/Downloader/AssetDownloader.php',
				"remove_action( 'http_api_curl', \$pin_hook, 10 );",
				'// hook leaked',
			],
		],
	],
];
