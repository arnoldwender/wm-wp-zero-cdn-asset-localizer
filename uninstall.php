<?php
/**
 * WM Suite Zero-CDN Asset Localizer — Opt-in Uninstaller
 *
 * Triggered when the plugin is deleted via WordPress Admin.
 * Preserves options and locally stored font files by default unless the administrator
 * explicitly enabled `wm_zero_cdn_delete_data_on_uninstall`.
 *
 * @package WMCyber\ZeroCDN\Localizer
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$wm_zero_cdn_delete_data = (bool) get_option( 'wm_zero_cdn_delete_data_on_uninstall', false );
if ( ! $wm_zero_cdn_delete_data && function_exists( 'get_site_option' ) ) {
	$wm_zero_cdn_delete_data = (bool) get_site_option( 'wm_zero_cdn_delete_data_on_uninstall', false );
}

if ( ! $wm_zero_cdn_delete_data ) {
	return;
}

// 1. Delete Options. The variables carry the plugin prefix: this file runs in the global scope, where
//    a name like $options belongs to whoever declared it first.
$wm_zero_cdn_options = [
	'wm_zero_cdn_preload_enabled',
	'wm_zero_cdn_preload_fonts',
	'wm_zero_cdn_delete_data_on_uninstall',
];

foreach ( $wm_zero_cdn_options as $wm_zero_cdn_option ) {
	delete_option( $wm_zero_cdn_option );
	delete_site_option( $wm_zero_cdn_option );
}

// Cached result of the last front-page scan.
delete_transient( 'wm_zero_cdn_front_scan' );

// 2. Remove Local Font Upload Directory. On a network install this is the current site's folder only;
//    the other sites' options, transients and uploads stay (documented in README.md and DEVELOPERS.md).
$wm_zero_cdn_upload_dir = wp_upload_dir();
$wm_zero_cdn_font_dir   = $wm_zero_cdn_upload_dir['basedir'] . '/wm-zero-cdn';

if ( is_dir( $wm_zero_cdn_font_dir ) ) {
	$wm_zero_cdn_files = glob( $wm_zero_cdn_font_dir . '/*' );
	if ( is_array( $wm_zero_cdn_files ) ) {
		foreach ( $wm_zero_cdn_files as $wm_zero_cdn_file ) {
			if ( is_file( $wm_zero_cdn_file ) ) {
				wp_delete_file( $wm_zero_cdn_file );
			}
		}
	}

	// The emptied directory goes through the WordPress filesystem API (Plugin Check forbids a
	// direct rmdir() in distributed code). uninstall.php runs inside a core function, so the
	// filesystem object has to be pulled in explicitly.
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( WP_Filesystem() ) {
		global $wp_filesystem;
		$wp_filesystem->rmdir( $wm_zero_cdn_font_dir );
	}
}
