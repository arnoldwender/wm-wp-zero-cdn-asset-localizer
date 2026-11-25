<?php
declare(strict_types=1);

namespace WMCyber\ZeroCDN\Localizer\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\ZeroCDN\Localizer\Scanner\CdnDetector;
use WMCyber\ZeroCDN\Localizer\Downloader\AssetDownloader;
use WP_CLI;

final class ZeroCdnCommand {
	/**
	 * Fetch the site's front page and list references to Google Fonts, Font Awesome, cdnjs, jsDelivr and unpkg.
	 *
	 * Exits with an error when the front page cannot be read: a missing measurement is not reported
	 * as a clean site (until 2026-09-14 this scanned the styles registered in the CLI request, which
	 * never include the theme's, and printed "100% Self-Hosted & GDPR Compliant!").
	 *
	 * ## EXAMPLES
	 *
	 *     wp cdn scan
	 *
	 * @when after_wp_load
	 */
	public function scan( array $args, array $assoc_args ): void {
		$scan = ( new CdnDetector() )->scan_front_page( false );
		WP_CLI::line( 'Front page: ' . $scan['url'] );

		if ( ! $scan['ok'] ) {
			WP_CLI::error( 'Front page could not be read (' . (string) $scan['error'] . '); nothing can be said about CDN references.' );
		}

		if ( [] === $scan['found'] ) {
			WP_CLI::success( 'No references to the five checked CDN hosts in the front page HTML.' );
			return;
		}

		WP_CLI::warning( count( $scan['found'] ) . ' CDN reference(s) in the front page HTML:' );
		foreach ( $scan['found'] as $item ) {
			WP_CLI::line( "- [{$item['provider']}] {$item['url']}" );
		}
	}

	/**
	 * Download a remote stylesheet and its WOFF2 files into uploads/wm-zero-cdn/. The local copy is not enqueued.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The remote stylesheet URL to localize.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cdn localize "https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap"
	 *
	 * @when after_wp_load
	 */
	public function localize( array $args, array $assoc_args ): void {
		$url = $args[0] ?? '';
		if ( empty( $url ) ) {
			WP_CLI::error( 'Please specify a URL.' );
		}

		WP_CLI::line( "Localizing remote stylesheet: {$url}" );
		$res = ( new AssetDownloader() )->localize_css( $url );

		if ( ! $res['success'] ) {
			WP_CLI::error( 'Localization failed: ' . ( $res['error'] ?? 'Unknown error' ) );
		}

		WP_CLI::success( 'Stylesheet saved.' );
		WP_CLI::line( '- Local CSS (enqueue it in the theme instead of the remote link): ' . $res['local_css_url'] );
		WP_CLI::line( '- WOFF2 files stored locally: ' . count( $res['downloaded_fonts'] ) );
		if ( [] !== $res['failed_fonts'] ) {
			WP_CLI::warning( count( $res['failed_fonts'] ) . ' font file(s) could not be fetched as WOFF2 and still point to the remote host.' );
		}
	}
}
