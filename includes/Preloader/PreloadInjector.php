<?php
declare(strict_types=1);

namespace WMCyber\ZeroCDN\Localizer\Preloader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PreloadInjector {
	/**
	 * Inject <link rel="preload"> tags into <head> for localized fonts when explicitly configured.
	 */
	public function inject_preload_tags(): void {
		$enabled = (bool) apply_filters( 'wm_zero_cdn_preload_enabled', get_option( 'wm_zero_cdn_preload_enabled', false ) );
		if ( ! $enabled ) {
			return;
		}

		$preloaded_fonts = (array) apply_filters( 'wm_zero_cdn_preload_fonts', get_option( 'wm_zero_cdn_preload_fonts', [] ) );
		if ( empty( $preloaded_fonts ) ) {
			return;
		}

		$upload_dir  = wp_upload_dir();
		$base_url    = $upload_dir['baseurl'] . '/wm-zero-cdn/';
		$base_folder = $upload_dir['basedir'] . '/wm-zero-cdn/';

		// Filter first, then cap: slicing first dropped a valid file whenever a non-WOFF2 entry stood
		// among the first three, and a preload for a file that is not there costs every visitor a 404.
		$printable = [];
		foreach ( $preloaded_fonts as $font_file ) {
			if ( ! is_string( $font_file ) && ! is_numeric( $font_file ) ) {
				continue;
			}
			$font_name = basename( (string) $font_file );
			if ( '' === $font_name || ! str_ends_with( $font_name, '.woff2' ) || ! is_file( $base_folder . $font_name ) ) {
				continue;
			}
			$printable[] = $font_name;
		}

		foreach ( array_slice( $printable, 0, 3 ) as $font_name ) {
			$url = $base_url . $font_name;
			printf(
				"<link rel=\"preload\" href=\"%s\" as=\"font\" type=\"font/woff2\" crossorigin=\"anonymous\" />\n",
				esc_url( $url )
			);
		}
	}
}
